<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Local_Storage {

    private static function backups_dir() {
        return HBU_STORAGE_DIR . '/backups';
    }

    /**
     * 시스템 tmp의 zip 파일을 backups/ 로 이동합니다.
     *
     * @param string $tmp_zip 임시 zip 파일 경로
     * @return string|false   이동된 최종 경로, 실패 시 false
     */
    public static function store( $tmp_zip ) {
        $filename = basename( $tmp_zip );
        $dest     = self::backups_dir() . '/' . $filename;

        if ( ! rename( $tmp_zip, $dest ) ) {
            // rename이 파일시스템 간에 실패할 경우 copy + unlink
            if ( copy( $tmp_zip, $dest ) ) {
                unlink( $tmp_zip );
            } else {
                HBU_Logger::error( '로컬 저장 실패: ' . $tmp_zip . ' → ' . $dest );
                return false;
            }
        }

        HBU_Logger::info( '로컬 저장 완료: ' . $dest );
        return $dest;
    }

    /**
     * 저장된 백업 목록을 반환합니다. (최신순 정렬)
     *
     * @return array
     */
    public static function list_backups() {
        $dir   = self::backups_dir();
        $files = glob( $dir . '/backup_*.zip' );

        if ( ! $files ) {
            return array();
        }

        $backups = array();
        foreach ( $files as $file ) {
            $backups[] = array(
                'filename'   => basename( $file ),
                'path'       => $file,
                'size'       => filesize( $file ),
                'created_at' => filemtime( $file ),
            );
        }

        // 최신순 정렬
        usort( $backups, function( $a, $b ) {
            return $b['created_at'] - $a['created_at'];
        } );

        return $backups;
    }

    /**
     * 특정 백업 파일을 삭제합니다.
     *
     * @param string $filename 파일명 (경로 아님)
     * @return bool
     */
    public static function delete( $filename ) {
        $path = self::get_path( $filename );
        if ( ! $path ) {
            return false;
        }
        $result = unlink( $path );
        if ( $result ) {
            HBU_Logger::info( '로컬 백업 삭제: ' . $filename );
        }
        return $result;
    }

    /**
     * 파일명으로 전체 경로를 반환합니다. (경로 탐색 공격 방지)
     *
     * @param string $filename
     * @return string|false
     */
    public static function get_path( $filename ) {
        // 파일명만 허용 (경로 구분자 차단)
        $filename = basename( $filename );
        $path     = self::backups_dir() . '/' . $filename;

        if ( ! file_exists( $path ) ) {
            return false;
        }

        // realpath로 실제 경로 확인 (path traversal 방지)
        $real = realpath( $path );
        $base = realpath( self::backups_dir() );

        if ( ! $real || strpos( $real, $base ) !== 0 ) {
            return false;
        }

        return $real;
    }

    /**
     * 보존 정책 적용: 오래된 파일부터 삭제합니다.
     *
     * @param int $max_count 최대 보존 개수
     */
    public static function enforce_retention( $max_count ) {
        $backups = self::list_backups();

        if ( count( $backups ) <= $max_count ) {
            return;
        }

        // list_backups()는 최신순이므로 뒤에서부터 삭제
        $to_delete = array_slice( $backups, $max_count );

        foreach ( $to_delete as $backup ) {
            self::delete( $backup['filename'] );
            HBU_Logger::info( '보존 정책으로 삭제: ' . $backup['filename'] );
        }
    }
}
