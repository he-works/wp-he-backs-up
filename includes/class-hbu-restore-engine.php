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
     * @return array { success: bool, message: string }
     */
    public static function restore( $backup_id, $source ) {
        @set_time_limit( 0 );
        @wp_raise_memory_limit( 'admin' );

        HBU_Logger::info( "복구 시작: {$backup_id} (source: {$source})" );

        $entry = HBU_Backup_Registry::find_by_id( $backup_id );
        if ( ! $entry ) {
            return array( 'success' => false, 'message' => '백업 항목을 찾을 수 없습니다.' );
        }

        $tmp_dir = sys_get_temp_dir() . '/hbu_restore_' . $backup_id;
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

            $tmp_zip  = sys_get_temp_dir() . '/' . $entry['filename'];
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

        // 3단계: wp-content 파일 복원
        $extracted_content = $tmp_dir . '/wp-content';
        if ( is_dir( $extracted_content ) ) {
            HBU_Logger::info( 'wp-content 복원 중...' );
            $copy_result = self::recursive_copy( $extracted_content, WP_CONTENT_DIR );
            if ( ! $copy_result ) {
                self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );
                return array( 'success' => false, 'message' => 'wp-content 파일 복원에 실패했습니다.' );
            }
            HBU_Logger::info( 'wp-content 복원 완료' );
        } else {
            HBU_Logger::info( 'ZIP에 wp-content 디렉토리가 없습니다. 파일 복원 건너뜀.' );
        }

        // 4단계: 데이터베이스 복원
        $sql_file = $tmp_dir . '/database/dump.sql';
        if ( file_exists( $sql_file ) ) {
            HBU_Logger::info( 'DB 복원 중...' );
            $db_result = self::restore_database( $sql_file );
            if ( ! $db_result ) {
                self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );
                return array( 'success' => false, 'message' => 'DB 복원에 실패했습니다. 로그를 확인해주세요.' );
            }
            HBU_Logger::info( 'DB 복원 완료' );
        } else {
            HBU_Logger::error( 'dump.sql 파일을 찾을 수 없습니다.' );
        }

        // 정리
        self::cleanup_tmp( $tmp_dir, $source === 'gdrive' ? $zip_path : null );

        HBU_Logger::info( "복구 완료: {$backup_id}" );
        return array( 'success' => true, 'message' => '복구가 완료되었습니다.' );
    }

    private static function restore_database( $sql_file ) {
        global $wpdb;

        $sql = file_get_contents( $sql_file );
        if ( ! $sql ) {
            HBU_Logger::error( 'SQL 파일 읽기 실패' );
            return false;
        }

        // 외래 키 제약 일시 해제
        $wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

        // SQL을 문장별로 분리하여 실행
        $statements = self::split_sql( $sql );
        $errors     = 0;

        foreach ( $statements as $statement ) {
            $statement = trim( $statement );
            if ( empty( $statement ) || strpos( $statement, '--' ) === 0 ) {
                continue;
            }
            $result = $wpdb->query( $statement );
            if ( $result === false ) {
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
     * SQL 파일을 개별 문장으로 분리합니다.
     */
    private static function split_sql( $sql ) {
        $statements = array();
        $current    = '';
        $in_string  = false;
        $string_char = '';
        $len        = strlen( $sql );

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

    /**
     * 디렉토리를 재귀적으로 복사합니다.
     */
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
