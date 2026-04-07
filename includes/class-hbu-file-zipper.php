<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_File_Zipper {

    /**
     * wp-content 디렉토리와 DB 덤프 파일을 하나의 zip으로 묶습니다.
     *
     * @param string $db_sql_path  DB 덤프 SQL 파일 경로
     * @param string $dest_zip     생성할 zip 파일 경로
     * @return bool
     */
    public static function zip( $db_sql_path, $dest_zip ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            HBU_Logger::error( 'ZipArchive 클래스를 사용할 수 없습니다. PHP zip 확장 모듈을 활성화해주세요.' );
            return false;
        }

        HBU_Logger::info( 'ZIP 압축 시작: ' . $dest_zip );

        $zip = new ZipArchive();
        $result = $zip->open( $dest_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        if ( $result !== true ) {
            HBU_Logger::error( 'ZIP 파일 생성 실패. 오류 코드: ' . $result );
            return false;
        }

        // DB 덤프 추가
        if ( file_exists( $db_sql_path ) ) {
            $zip->addFile( $db_sql_path, 'database/dump.sql' );
            HBU_Logger::info( 'DB 덤프 파일 추가 완료' );
        } else {
            HBU_Logger::error( 'DB 덤프 파일을 찾을 수 없습니다: ' . $db_sql_path );
        }

        // wp-content 디렉토리 추가
        $wp_content_dir  = WP_CONTENT_DIR;
        $storage_real    = realpath( HBU_STORAGE_DIR );
        $added_count     = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $wp_content_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $file_real = realpath( $file->getRealPath() );

            // storage/ 디렉토리 자체는 제외 (무한 루프 방지)
            if ( $storage_real && strpos( $file_real, $storage_real ) === 0 ) {
                continue;
            }

            // zip 내부 경로: wp-content/... 형식
            $relative_path = 'wp-content/' . ltrim(
                str_replace( $wp_content_dir, '', $file_real ),
                DIRECTORY_SEPARATOR
            );
            $relative_path = str_replace( '\\', '/', $relative_path );

            $zip->addFile( $file_real, $relative_path );
            $added_count++;
        }

        HBU_Logger::info( "ZIP에 파일 {$added_count}개 추가 완료" );

        $zip->close();

        if ( ! file_exists( $dest_zip ) ) {
            HBU_Logger::error( 'ZIP 파일이 생성되지 않았습니다.' );
            return false;
        }

        HBU_Logger::info( 'ZIP 압축 완료: ' . size_format( filesize( $dest_zip ) ) );
        return true;
    }
}
