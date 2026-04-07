<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_GDrive_Client {

    const API_BASE    = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';
    const CHUNK_SIZE  = 10485760; // 10MB

    /**
     * 파일을 10MB 청크로 나눠 Google Drive에 업로드합니다. (Resumable Upload)
     * 서버 공간을 최소화하며 대용량 파일에 적합합니다.
     *
     * @param string $local_path    업로드할 로컬 파일 경로
     * @param string $filename      Drive에 저장될 파일명
     * @param string $folder_id     Drive 폴더 ID
     * @param string $access_token  유효한 access_token
     * @return string|false         업로드된 파일 ID, 실패 시 false
     */
    public static function upload_chunked( $local_path, $filename, $folder_id, $access_token ) {
        if ( ! file_exists( $local_path ) ) {
            HBU_Logger::error( 'Drive 업로드 실패: 파일 없음 - ' . $local_path );
            return false;
        }

        $file_size = filesize( $local_path );
        HBU_Logger::info( 'Drive 업로드 시작: ' . $filename . ' (' . size_format( $file_size ) . ')' );

        // 1) Resumable upload session 생성
        $metadata = json_encode( array(
            'name'    => $filename,
            'parents' => array( $folder_id ),
        ) );

        $session_response = wp_remote_post( self::UPLOAD_BASE . '/files?uploadType=resumable', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization'           => 'Bearer ' . $access_token,
                'Content-Type'            => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => 'application/zip',
                'X-Upload-Content-Length' => $file_size,
            ),
            'body' => $metadata,
        ) );

        if ( is_wp_error( $session_response ) ) {
            HBU_Logger::error( 'Drive 세션 생성 실패: ' . $session_response->get_error_message() );
            return false;
        }

        $upload_uri = wp_remote_retrieve_header( $session_response, 'location' );
        if ( ! $upload_uri ) {
            HBU_Logger::error( 'Drive upload URI를 받지 못했습니다.' );
            return false;
        }

        // 2) 청크 업로드
        $handle = fopen( $local_path, 'rb' );
        if ( ! $handle ) {
            HBU_Logger::error( '파일 읽기 실패: ' . $local_path );
            return false;
        }

        $offset    = 0;
        $file_id   = false;

        while ( ! feof( $handle ) ) {
            $chunk      = fread( $handle, self::CHUNK_SIZE );
            $chunk_size = strlen( $chunk );
            $end        = $offset + $chunk_size - 1;

            $chunk_response = wp_remote_request( $upload_uri, array(
                'method'  => 'PUT',
                'timeout' => 120,
                'headers' => array(
                    'Content-Range' => "bytes {$offset}-{$end}/{$file_size}",
                    'Content-Type'  => 'application/zip',
                ),
                'body' => $chunk,
            ) );

            $offset += $chunk_size;

            if ( is_wp_error( $chunk_response ) ) {
                fclose( $handle );
                HBU_Logger::error( 'Drive 청크 업로드 오류: ' . $chunk_response->get_error_message() );
                return false;
            }

            $status_code = wp_remote_retrieve_response_code( $chunk_response );

            if ( in_array( $status_code, array( 200, 201 ), true ) ) {
                // 업로드 완료
                $body    = json_decode( wp_remote_retrieve_body( $chunk_response ), true );
                $file_id = isset( $body['id'] ) ? $body['id'] : false;
                break;
            }

            if ( $status_code !== 308 ) {
                // 308 Resume Incomplete 외 예상치 못한 응답
                fclose( $handle );
                HBU_Logger::error( "Drive 업로드 오류 (HTTP {$status_code}): " . wp_remote_retrieve_body( $chunk_response ) );
                return false;
            }

            HBU_Logger::info( 'Drive 업로드 진행: ' . size_format( $offset ) . ' / ' . size_format( $file_size ) );
        }

        fclose( $handle );

        if ( $file_id ) {
            HBU_Logger::info( 'Drive 업로드 완료. File ID: ' . $file_id );
        } else {
            HBU_Logger::error( 'Drive 업로드 완료됐지만 File ID를 받지 못했습니다.' );
        }

        return $file_id;
    }

    /**
     * Drive 폴더 내 백업 파일 목록을 반환합니다.
     *
     * @param string $folder_id
     * @param string $access_token
     * @return array
     */
    public static function list_files( $folder_id, $access_token ) {
        $url = self::API_BASE . '/files?' . http_build_query( array(
            'q'      => "'{$folder_id}' in parents and trashed=false",
            'fields' => 'files(id,name,size,createdTime)',
            'orderBy' => 'createdTime desc',
            'pageSize' => 100,
        ) );

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            HBU_Logger::error( 'Drive 파일 목록 조회 실패: ' . $response->get_error_message() );
            return array();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return isset( $body['files'] ) ? $body['files'] : array();
    }

    /**
     * Drive 파일을 로컬로 다운로드합니다.
     *
     * @param string $file_id
     * @param string $access_token
     * @param string $dest_path    저장할 로컬 경로
     * @return bool
     */
    public static function download( $file_id, $access_token, $dest_path ) {
        HBU_Logger::info( 'Drive 파일 다운로드 시작: ' . $file_id );

        $url = self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?alt=media';

        $response = wp_remote_get( $url, array(
            'timeout' => 300,
            'stream'  => true,
            'filename' => $dest_path,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            HBU_Logger::error( 'Drive 다운로드 실패: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            HBU_Logger::error( "Drive 다운로드 오류 (HTTP {$status_code})" );
            return false;
        }

        HBU_Logger::info( 'Drive 다운로드 완료: ' . $dest_path );
        return file_exists( $dest_path ) && filesize( $dest_path ) > 0;
    }

    /**
     * Drive에 폴더를 생성하고 폴더 ID를 반환합니다.
     *
     * @param string $name
     * @param string $access_token
     * @return string|false
     */
    public static function create_folder( $name, $access_token ) {
        $response = wp_remote_post( self::API_BASE . '/files', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'name'     => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            HBU_Logger::error( 'Drive 폴더 생성 실패: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['id'] ) ) {
            HBU_Logger::error( 'Drive 폴더 ID를 받지 못했습니다.' );
            return false;
        }

        HBU_Logger::info( 'Drive 폴더 생성 완료: ' . $name . ' (ID: ' . $body['id'] . ')' );
        return $body['id'];
    }

    /**
     * Drive 파일을 삭제합니다.
     *
     * @param string $file_id
     * @param string $access_token
     * @return bool
     */
    public static function delete_file( $file_id, $access_token ) {
        $response = wp_remote_request( self::API_BASE . '/files/' . rawurlencode( $file_id ), array(
            'method'  => 'DELETE',
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            HBU_Logger::error( 'Drive 파일 삭제 실패: ' . $response->get_error_message() );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        return $status_code === 204;
    }
}
