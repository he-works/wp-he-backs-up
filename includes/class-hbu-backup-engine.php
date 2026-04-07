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
        // 실행 시간 및 메모리 한도 확장
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

        // 백업 파일명 생성
        $backup_id  = gmdate( 'Ymd_His' ) . '_' . substr( md5( uniqid() ), 0, 8 );
        $filename   = 'backup_' . $backup_id . '.zip';
        $tmp_dir    = sys_get_temp_dir();
        $tmp_sql    = $tmp_dir . '/' . $backup_id . '_db.sql';
        $tmp_zip    = $tmp_dir . '/' . $filename;

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

        $zip_size = filesize( $tmp_zip );

        // 레지스트리 엔트리 준비
        $entry = array(
            'id'            => $backup_id,
            'filename'      => $filename,
            'created_at'    => time(),
            'size_bytes'    => $zip_size,
            'locations'     => array(),
            'gdrive_file_id' => '',
        );

        // 3단계: 저장
        set_transient( 'hbu_backup_progress', 'storing', 600 );
        HBU_Logger::info( '3/3 저장 중...' );

        $local_path = null;

        // 로컬 저장
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

        // Google Drive 업로드
        if ( $gdrive_enabled ) {
            $source = $local_path ? $local_path : $tmp_zip;
            $gdrive_file_id = self::upload_to_gdrive( $source, $filename );
            if ( $gdrive_file_id ) {
                $entry['locations'][]   = 'gdrive';
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

        // 레지스트리 등록
        HBU_Backup_Registry::add( $entry );

        // 보존 정책 적용
        $retention = isset( $settings['local_retention_count'] ) ? (int) $settings['local_retention_count'] : 10;
        if ( $local_enabled && $retention > 0 ) {
            HBU_Local_Storage::enforce_retention( $retention );
        }

        delete_transient( 'hbu_backup_progress' );
        HBU_Logger::info( "백업 완료: {$filename} (" . size_format( $zip_size ) . ')' );

        return array(
            'success'   => true,
            'message'   => '백업이 완료되었습니다: ' . $filename,
            'backup_id' => $backup_id,
        );
    }

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

    private static function cleanup( $files ) {
        foreach ( $files as $file ) {
            if ( $file && file_exists( $file ) ) {
                @unlink( $file );
            }
        }
        delete_transient( 'hbu_backup_progress' );
    }
}
