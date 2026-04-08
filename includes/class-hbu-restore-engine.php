<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Restore_Engine {

    /**
     * 백업을 복구합니다.
     *
     * @param string $backup_id   레지스트리 ID
     * @param string $source      'local' | 'gdrive'
     * @param string $mode        'merge' | 'replace'
     *                            merge   : 덮어쓰기만 (기존 파일 유지)
     *                            replace : 백업 기준 완전 교체 (추가된 파일 삭제)
     * @return array { success: bool, message: string }
     */
    public static function restore( $backup_id, $source, $mode = 'merge' ) {
        @set_time_limit( 0 );
        @wp_raise_memory_limit( 'admin' );

        HBU_Logger::info( "복구 시작: {$backup_id} (source: {$source}, mode: {$mode})" );

        $entry = HBU_Backup_Registry::find_by_id( $backup_id );
        if ( ! $entry ) {
            return array( 'success' => false, 'message' => '백업 항목을 찾을 수 없습니다.' );
        }

        $tmp_dir  = sys_get_temp_dir() . '/hbu_restore_' . $backup_id;
        $zip_path = null;

        // 1단계: zip 파일 확보
        if ( $source === 'local' ) {
            $zip_path = HBU_Local_Storage::get_path( $entry['filename'] );
            if ( ! $zip_path ) {
                return array( 'success' => false, 'message' => '로컬 백업 파일을 찾을 수 없습니다.' );
            }
        } elseif ( $source === 'gdrive' ) {
            if ( empty( $entry['gdrive_file_id'] ) ) {
                return array( 'success' => false, 'message' => 'Google Drive 파일 ID가 없습니다.' );
            }
            $token = HBU_GDrive_Auth::get_valid_token();
            if ( ! $token ) {
                return array( 'success' => false, 'message' => 'Google Drive 토큰을 가져올 수 없습니다.' );
            }
            $tmp_zip    = sys_get_temp_dir() . '/' . $entry['filename'];
            $downloaded = HBU_GDrive_Client::download( $entry['gdrive_file_id'], $token, $tmp_zip );
            if ( ! $downloaded ) {
                return array( 'success' => false, 'message' => 'Google Drive에서 파일 다운로드에 실패했습니다.' );
            }
            $zip_path = $tmp_zip;
        } else {
            return array( 'success' => false, 'message' => '알 수 없는 소스입니다.' );
        }

        // 2단계: zip 압축 해제
        if ( ! class_exists( 'ZipArchive' ) ) {
            return array( 'success' => false, 'message' => 'ZipArchive 확장 모듈이 필요합니다.' );
        }

        wp_mkdir_p( $tmp_dir );

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );
            return array( 'success' => false, 'message' => 'ZIP 파일을 열 수 없습니다.' );
        }
        $zip->extractTo( $tmp_dir );
        $zip->close();

        HBU_Logger::info( 'ZIP 압축 해제 완료: ' . $tmp_dir );

        // 3단계: 백업 메타 읽기 (테이블 prefix 마이그레이션 여부 판단)
        $backup_meta = self::read_backup_meta( $tmp_dir );

        // 4단계: wp-content 파일 복원
        $extracted_content = $tmp_dir . '/wp-content';
        if ( is_dir( $extracted_content ) ) {
            HBU_Logger::info( "wp-content 복원 중... (mode: {$mode})" );

            // replace 모드: 백업에 없는 기존 파일 삭제
            if ( $mode === 'replace' ) {
                self::clean_wp_content_for_replace( $extracted_content );
            }

            $copy_result = self::recursive_copy( $extracted_content, WP_CONTENT_DIR );
            if ( ! $copy_result ) {
                self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );
                return array( 'success' => false, 'message' => 'wp-content 파일 복원에 실패했습니다.' );
            }
            HBU_Logger::info( 'wp-content 복원 완료' );
        }

        // 5단계: 데이터베이스 복원
        $sql_file = $tmp_dir . '/database/dump.sql';
        if ( file_exists( $sql_file ) ) {
            HBU_Logger::info( 'DB 복원 중...' );
            $db_result = self::restore_database( $sql_file, $backup_meta );
            if ( ! $db_result ) {
                self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );
                return array( 'success' => false, 'message' => 'DB 복원에 실패했습니다. 로그를 확인해주세요.' );
            }
            HBU_Logger::info( 'DB 복원 완료' );
        } else {
            HBU_Logger::error( 'dump.sql 파일을 찾을 수 없습니다.' );
        }

        self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );

        HBU_Logger::info( "복구 완료: {$backup_id}" );
        return array( 'success' => true, 'message' => '복구가 완료되었습니다.' );
    }

    // ── 백업 메타 읽기 ────────────────────────────────────────────────────

    private static function read_backup_meta( $tmp_dir ) {
        $meta_file = $tmp_dir . '/backup.json';
        if ( ! file_exists( $meta_file ) ) {
            return array();
        }
        $data = json_decode( file_get_contents( $meta_file ), true );
        return is_array( $data ) ? $data : array();
    }

    // ── replace 모드: 백업에 없는 파일 삭제 ──────────────────────────────

    /**
     * replace 모드 전처리.
     * wp-content/plugins, themes, uploads 에서 백업에 없는 파일을 삭제합니다.
     * he-backs-up 플러그인 자신은 절대 삭제하지 않습니다.
     */
    private static function clean_wp_content_for_replace( $backup_content_dir ) {
        $dirs_to_clean = array( 'plugins', 'themes', 'uploads' );

        foreach ( $dirs_to_clean as $subdir ) {
            $current_dir = WP_CONTENT_DIR . '/' . $subdir;
            $backup_dir  = $backup_content_dir . '/' . $subdir;

            if ( ! is_dir( $current_dir ) ) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $current_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $iterator as $item ) {
                // he-backs-up 플러그인 자신은 절대 삭제 금지
                if ( strpos( $item->getRealPath(), WP_PLUGIN_DIR . '/he-backs-up' ) === 0 ) {
                    continue;
                }

                // 백업에 동일 경로가 없는 파일/폴더만 삭제
                $relative   = ltrim( str_replace( $current_dir, '', $item->getRealPath() ), DIRECTORY_SEPARATOR );
                $backup_counterpart = $backup_dir . '/' . $relative;

                if ( ! file_exists( $backup_counterpart ) ) {
                    if ( $item->isDir() ) {
                        @rmdir( $item->getRealPath() );
                    } else {
                        @unlink( $item->getRealPath() );
                    }
                }
            }
        }

        HBU_Logger::info( 'replace 모드: 불필요한 기존 파일 정리 완료' );
    }

    // ── 데이터베이스 복원 ─────────────────────────────────────────────────

    private static function restore_database( $sql_file, $backup_meta = array() ) {
        global $wpdb, $table_prefix;

        $sql = file_get_contents( $sql_file );
        if ( ! $sql ) {
            HBU_Logger::error( 'SQL 파일 읽기 실패' );
            return false;
        }

        // 테이블 prefix 마이그레이션
        $backup_prefix  = isset( $backup_meta['table_prefix'] ) ? $backup_meta['table_prefix'] : '';
        $current_prefix = $table_prefix;

        if ( $backup_prefix && $backup_prefix !== $current_prefix ) {
            HBU_Logger::info( "테이블 prefix 마이그레이션: '{$backup_prefix}' → '{$current_prefix}'" );
            $sql = self::migrate_table_prefix( $sql, $backup_prefix, $current_prefix );
        }

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

        $statements = self::split_sql( $sql );
        $errors     = 0;

        foreach ( $statements as $statement ) {
            $statement = trim( $statement );
            if ( empty( $statement ) || strpos( $statement, '--' ) === 0 ) {
                continue;
            }
            if ( $wpdb->query( $statement ) === false ) {
                HBU_Logger::error( 'SQL 실행 오류: ' . $wpdb->last_error . ' | SQL: ' . substr( $statement, 0, 100 ) );
                $errors++;
            }
        }

        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

        if ( $errors > 0 ) {
            HBU_Logger::error( "DB 복원 중 {$errors}개 오류 발생" );
            return false;
        }

        return true;
    }

    /**
     * SQL 전체에서 테이블 prefix를 일괄 치환합니다.
     * CREATE TABLE, INSERT INTO, wp_options 내부 role 키도 처리합니다.
     */
    private static function migrate_table_prefix( $sql, $old_prefix, $new_prefix ) {
        // 테이블명 치환: `old_prefix → `new_prefix
        $sql = str_replace( '`' . $old_prefix, '`' . $new_prefix, $sql );

        // wp_options 등 row 값 속의 prefix 문자열 치환 (user_roles, capabilities 등)
        $sql = str_replace(
            array(
                "'" . $old_prefix . "user_roles'",
                '"' . $old_prefix . 'user_roles"',
                "'" . $old_prefix . "capabilities'",
                "'" . $old_prefix . "user_level'",
            ),
            array(
                "'" . $new_prefix . "user_roles'",
                '"' . $new_prefix . 'user_roles"',
                "'" . $new_prefix . "capabilities'",
                "'" . $new_prefix . "user_level'",
            ),
            $sql
        );

        return $sql;
    }

    private static function split_sql( $sql ) {
        $statements  = array();
        $current     = '';
        $in_string   = false;
        $string_char = '';
        $len         = strlen( $sql );

        for ( $i = 0; $i < $len; $i++ ) {
            $char = $sql[ $i ];

            if ( $in_string ) {
                $current .= $char;
                if ( $char === $string_char && ( $i === 0 || $sql[ $i - 1 ] !== '\\' ) ) {
                    $in_string = false;
                }
            } else {
                if ( $char === "'" || $char === '"' ) {
                    $in_string   = true;
                    $string_char = $char;
                    $current    .= $char;
                } elseif ( $char === ';' ) {
                    $statements[] = $current;
                    $current      = '';
                } else {
                    $current .= $char;
                }
            }
        }

        if ( trim( $current ) !== '' ) {
            $statements[] = $current;
        }

        return $statements;
    }

    private static function recursive_copy( $src, $dst ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathname();

            if ( $item->isDir() ) {
                if ( ! file_exists( $target ) ) {
                    wp_mkdir_p( $target );
                }
            } else {
                if ( ! copy( $item->getRealPath(), $target ) ) {
                    HBU_Logger::error( '파일 복사 실패: ' . $item->getRealPath() );
                    return false;
                }
            }
        }

        return true;
    }

    private static function cleanup_tmp( $tmp_dir, $tmp_zip = null ) {
        if ( $tmp_zip && file_exists( $tmp_zip ) ) {
            @unlink( $tmp_zip );
        }
        if ( is_dir( $tmp_dir ) ) {
            self::remove_dir( $tmp_dir );
        }
    }

    private static function remove_dir( $dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getRealPath() );
            } else {
                @unlink( $item->getRealPath() );
            }
        }
        @rmdir( $dir );
    }
}
