<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Backup_Engine {

    /**
     * 백업을 실행합니다.
     *
     * @param string $trigger 'manual' | 'cron'
     * @return array { success: bool, message: string, backup_id: string }
     */
    public static function run( $trigger = 'manual' ) {
        @set_time_limit( 0 );
        @wp_raise_memory_limit( 'admin' );

        HBU_Logger::info( "백업 시작 (trigger: {$trigger})" );

        $settings = get_option( 'hbu_settings', array() );
        $local_enabled  = ! empty( $settings['storage_local_enabled'] );
        $gdrive_enabled = ! empty( $settings['storage_gdrive_enabled'] );

        if ( ! $local_enabled && ! $gdrive_enabled ) {
            HBU_Logger::error( '저장소가 설정되지 않았습니다.' );
            return array( 'success' => false, 'message' => '저장소를 최소 하나 이상 활성화해주세요.' );
        }

        // 디스크 여유 공간 사전 점검
        $disk_check = self::check_disk_space();
        if ( $disk_check !== true ) {
            return array( 'success' => false, 'message' => $disk_check );
        }

        // 백업 파일명 생성
        $backup_id = gmdate( 'Ymd_His' ) . '_' . substr( md5( uniqid() ), 0, 8 );
        $filename  = 'backup_' . $backup_id . '.zip';
        $tmp_dir   = sys_get_temp_dir();
        $tmp_sql   = $tmp_dir . '/' . $backup_id . '_db.sql';
        $tmp_zip   = $tmp_dir . '/' . $filename;

        // 1단계: DB 덤프
        set_transient( 'hbu_backup_progress', 'db_dump', 600 );
        HBU_Logger::info( '1/3 DB 덤프 중...' );

        if ( ! HBU_DB_Dumper::dump( $tmp_sql ) ) {
            self::cleanup( array( $tmp_sql, $tmp_zip ) );
            return array( 'success' => false, 'message' => 'DB 덤프에 실패했습니다. 로그를 확인해주세요.' );
        }

        // 2단계: 파일 압축
        set_transient( 'hbu_backup_progress', 'zipping', 600 );
        HBU_Logger::info( '2/3 파일 압축 중...' );

        if ( ! HBU_File_Zipper::zip( $tmp_sql, $tmp_zip ) ) {
            self::cleanup( array( $tmp_sql, $tmp_zip ) );
            return array( 'success' => false, 'message' => '파일 압축에 실패했습니다. 로그를 확인해주세요.' );
        }

        // DB 덤프 임시파일 즉시 삭제
        @unlink( $tmp_sql );

        // ZIP 메타데이터 추가 (테이블 prefix, WP 버전 등 — 복구 시 prefix 마이그레이션에 사용)
        self::inject_backup_meta( $tmp_zip, $backup_id );

        // ZIP 무결성 검증
        if ( ! self::verify_zip( $tmp_zip ) ) {
            self::cleanup( array( $tmp_zip ) );
            return array( 'success' => false, 'message' => 'ZIP 무결성 검증 실패. 백업 파일이 손상됐습니다.' );
        }

        $zip_size = filesize( $tmp_zip );

        // 레지스트리 엔트리 준비
        $entry = array(
            'id'             => $backup_id,
            'filename'       => $filename,
            'created_at'     => time(),
            'size_bytes'     => $zip_size,
            'locations'      => array(),
            'gdrive_file_id' => '',
        );

        // 3단계: 저장
        set_transient( 'hbu_backup_progress', 'storing', 600 );
        HBU_Logger::info( '3/3 저장 중...' );

        $local_path = null;

        if ( $local_enabled ) {
            $stored = HBU_Local_Storage::store( $tmp_zip );
            if ( $stored ) {
                $entry['locations'][] = 'local';
                $local_path = $stored;
                HBU_Logger::info( '로컬 저장 완료' );
            } else {
                HBU_Logger::error( '로컬 저장 실패' );
            }
        }

        if ( $gdrive_enabled ) {
            $source         = $local_path ? $local_path : $tmp_zip;
            $gdrive_file_id = self::upload_to_gdrive( $source, $filename );
            if ( $gdrive_file_id ) {
                $entry['locations'][]    = 'gdrive';
                $entry['gdrive_file_id'] = $gdrive_file_id;
                HBU_Logger::info( 'Google Drive 업로드 완료: ' . $gdrive_file_id );
            } else {
                HBU_Logger::error( 'Google Drive 업로드 실패' );
            }
        }

        // Google Drive 전용 모드이면 tmp zip 삭제
        if ( ! $local_enabled && file_exists( $tmp_zip ) ) {
            @unlink( $tmp_zip );
            HBU_Logger::info( '서버 공간 절약: 임시 zip 삭제 완료' );
        }

        HBU_Backup_Registry::add( $entry );

        // 보존 정책 적용
        $retention = isset( $settings['local_retention_count'] ) ? (int) $settings['local_retention_count'] : 10;
        if ( $local_enabled && $retention > 0 ) {
            HBU_Local_Storage::enforce_retention( $retention );
        }
        if ( $gdrive_enabled && $retention > 0 ) {
            self::enforce_gdrive_retention( $retention );
        }

        delete_transient( 'hbu_backup_progress' );
        HBU_Logger::info( "백업 완료: {$filename} (" . size_format( $zip_size ) . ')' );

        return array(
            'success'   => true,
            'message'   => '백업이 완료되었습니다: ' . $filename,
            'backup_id' => $backup_id,
        );
    }

    // ── 디스크 여유 공간 점검 ─────────────────────────────────────────────

    /**
     * 백업 전 디스크 여유 공간을 점검합니다.
     * wp-content 크기의 1.5배 이상 여유가 있어야 합니다.
     *
     * @return true|string  충분하면 true, 부족하면 에러 메시지 문자열
     */
    private static function check_disk_space() {
        $free = @disk_free_space( sys_get_temp_dir() );
        if ( $free === false ) {
            return true; // 조회 불가 시 통과
        }

        // wp-content 디렉토리 크기 추정 (파일 수가 많으면 오래 걸릴 수 있으므로 timeout 방어)
        $content_size = self::estimate_dir_size( WP_CONTENT_DIR, 3 ); // 3초 제한

        if ( $content_size > 0 && $free < $content_size * 1.5 ) {
            $need = size_format( (int) ( $content_size * 1.5 ) );
            $have = size_format( $free );
            HBU_Logger::error( "디스크 공간 부족: 필요 {$need}, 여유 {$have}" );
            return "디스크 여유 공간이 부족합니다. 필요: {$need}, 여유: {$have}";
        }

        return true;
    }

    /**
     * 디렉토리 총 크기를 추정합니다 (time_limit 초 제한).
     */
    private static function estimate_dir_size( $dir, $time_limit = 3 ) {
        $size      = 0;
        $deadline  = microtime( true ) + $time_limit;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( microtime( true ) > $deadline ) {
                    break; // 시간 초과 시 지금까지 집계한 값 반환
                }
                if ( $file->isFile() ) {
                    $size += $file->getSize();
                }
            }
        } catch ( Exception $e ) {
            // 무시
        }
        return $size;
    }

    // ── ZIP 메타데이터 주입 ───────────────────────────────────────────────

    /**
     * 완성된 ZIP에 backup.json 메타파일을 추가합니다.
     * 복구 시 테이블 prefix 마이그레이션 등에 사용합니다.
     */
    private static function inject_backup_meta( $zip_path, $backup_id ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return;
        }
        global $table_prefix;

        $meta = array(
            'backup_id'      => $backup_id,
            'created_at'     => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
            'table_prefix'   => $table_prefix,
            'wp_version'     => get_bloginfo( 'version' ),
            'site_url'       => get_option( 'siteurl' ),
            'plugin_version' => HBU_VERSION,
        );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) === true ) {
            $zip->addFromString( 'backup.json', json_encode( $meta, JSON_PRETTY_PRINT ) );
            $zip->close();
        }
    }

    // ── ZIP 무결성 검증 ───────────────────────────────────────────────────

    /**
     * ZIP 파일을 열어 파일 수와 DB 덤프 존재 여부를 확인합니다.
     */
    private static function verify_zip( $zip_path ) {
        if ( ! class_exists( 'ZipArchive' ) || ! file_exists( $zip_path ) ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            HBU_Logger::error( 'ZIP 무결성 검증: 파일 열기 실패' );
            return false;
        }

        $num_files   = $zip->numFiles;
        $has_db_dump = ( $zip->locateName( 'database/dump.sql' ) !== false );
        $zip->close();

        if ( $num_files === 0 ) {
            HBU_Logger::error( 'ZIP 무결성 검증: 파일이 비어있음' );
            return false;
        }

        if ( ! $has_db_dump ) {
            HBU_Logger::error( 'ZIP 무결성 검증: database/dump.sql 없음' );
            return false;
        }

        HBU_Logger::info( "ZIP 무결성 검증 통과: {$num_files}개 파일" );
        return true;
    }

    // ── 내부 헬퍼 ─────────────────────────────────────────────────────────

    private static function upload_to_gdrive( $zip_path, $filename ) {
        $token = HBU_GDrive_Auth::get_valid_token();
        if ( ! $token ) {
            HBU_Logger::error( 'Google Drive 토큰을 가져올 수 없습니다.' );
            return false;
        }

        $folder_id = get_option( 'hbu_gdrive_folder_id', '' );
        if ( ! $folder_id ) {
            $folder_id = HBU_GDrive_Client::create_folder( 'He-Backs-Up', $token );
            if ( $folder_id ) {
                update_option( 'hbu_gdrive_folder_id', $folder_id );
            }
        }

        return HBU_GDrive_Client::upload_chunked( $zip_path, $filename, $folder_id, $token );
    }

    private static function enforce_gdrive_retention( $max_count ) {
        $all_backups    = HBU_Backup_Registry::get_all();
        $gdrive_backups = array_values( array_filter( $all_backups, function( $b ) {
            return in_array( 'gdrive', $b['locations'], true );
        } ) );

        if ( count( $gdrive_backups ) <= $max_count ) {
            return;
        }

        $token     = HBU_GDrive_Auth::get_valid_token();
        $to_delete = array_slice( $gdrive_backups, $max_count );

        foreach ( $to_delete as $backup ) {
            if ( ! empty( $backup['gdrive_file_id'] ) && $token ) {
                HBU_GDrive_Client::delete_file( $backup['gdrive_file_id'], $token );
                HBU_Logger::info( 'Google Drive 보존 정책으로 삭제: ' . $backup['filename'] );
            }
            if ( ! in_array( 'local', $backup['locations'], true ) ) {
                HBU_Backup_Registry::remove( $backup['id'] );
            } else {
                HBU_Backup_Registry::remove_location( $backup['id'], 'gdrive' );
            }
        }
    }

    private static function cleanup( $files ) {
        foreach ( $files as $file ) {
            if ( $file && file_exists( $file ) ) {
                @unlink( $file );
            }
        }
        delete_transient( 'hbu_backup_progress' );
    }
}
