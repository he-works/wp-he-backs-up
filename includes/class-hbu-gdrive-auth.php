<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Drive OAuth2 인증 관리 (공유 앱 + 릴레이 서버 방식)
 *
 * 흐름:
 *  1. get_auth_url() → 브라우저가 Google 인증 페이지로 이동
 *  2. Google → 릴레이 서버(callback.php)로 code + state 전달
 *  3. 릴레이가 code를 access_token + refresh_token으로 교환
 *  4. 릴레이가 WordPress admin 페이지로 토큰을 URL 파라미터로 리다이렉트
 *  5. save_tokens_from_relay() → nonce 검증 후 암호화 저장
 */
class HBU_GDrive_Auth {

    const TOKEN_OPTION = 'hbu_gdrive_tokens';
    const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';
    const SCOPE        = 'https://www.googleapis.com/auth/drive.file';

    // ── 인증 URL 생성 ────────────────────────────────────────────────────

    /**
     * Google OAuth2 인증 URL을 반환합니다.
     * state 파라미터에 nonce + 복귀 URL을 포함시켜 릴레이 서버가 처리할 수 있게 합니다.
     *
     * @return string
     */
    public static function get_auth_url() {
        // state: nonce + 복귀 URL을 JSON 후 base64 인코딩
        $state = base64_encode( json_encode( [
            'nonce'  => wp_create_nonce( 'hbu_gdrive_relay' ),
            'return' => admin_url( 'admin.php?page=hbu-gdrive' ),
        ] ) );

        $params = [
            'client_id'     => HBU_GDRIVE_CLIENT_ID,
            'redirect_uri'  => HBU_OAUTH_RELAY_URL,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
    }

    // ── 릴레이에서 반환된 토큰 저장 ─────────────────────────────────────

    /**
     * 릴레이 서버가 전달한 토큰 파라미터를 검증하고 암호화 저장합니다.
     *
     * @param string $access_token
     * @param string $refresh_token
     * @param int    $expires_at     Unix timestamp
     * @param string $nonce          원본 WordPress nonce (CSRF 검증용)
     * @return bool
     */
    public static function save_tokens_from_relay( $access_token, $refresh_token, $expires_at, $nonce ) {
        // nonce 검증 (CSRF 방지)
        if ( ! wp_verify_nonce( $nonce, 'hbu_gdrive_relay' ) ) {
            HBU_Logger::error( 'Google Drive 릴레이 nonce 검증 실패. CSRF 공격 가능성.' );
            return false;
        }

        if ( empty( $access_token ) ) {
            HBU_Logger::error( '릴레이에서 access_token을 받지 못했습니다.' );
            return false;
        }

        self::store_tokens( [
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_at'    => (int) $expires_at,
        ] );

        HBU_Logger::info( 'Google Drive 연결 완료 (릴레이 방식)' );
        return true;
    }

    // ── 토큰 갱신 ───────────────────────────────────────────────────────

    /**
     * refresh_token으로 새 access_token을 발급받습니다.
     * 이 교환은 플러그인에서 직접 처리합니다 (Client Secret 불필요 — refresh에만 해당).
     *
     * 참고: Google의 refresh_token 교환은 client_secret이 필요합니다.
     * 따라서 릴레이 서버의 /refresh 엔드포인트를 통해 처리합니다.
     *
     * @return bool
     */
    public static function refresh_token() {
        $tokens = self::get_tokens();

        if ( empty( $tokens['refresh_token'] ) ) {
            HBU_Logger::error( 'refresh_token이 없습니다. Google Drive를 다시 연결해주세요.' );
            return false;
        }

        // 릴레이 서버의 /refresh 엔드포인트에 요청
        $relay_refresh_url = rtrim( HBU_OAUTH_RELAY_URL, '/' );
        // callback.php → refresh.php (같은 디렉토리에 refresh.php 배치)
        $relay_refresh_url = preg_replace( '/callback\.php$/i', 'refresh.php', $relay_refresh_url );

        $response = wp_remote_post( $relay_refresh_url, [
            'timeout' => 30,
            'body'    => [
                'refresh_token' => $tokens['refresh_token'],
                'site_nonce'    => wp_create_nonce( 'hbu_gdrive_refresh' ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            HBU_Logger::error( '토큰 갱신 릴레이 오류: ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            HBU_Logger::error( '토큰 갱신 실패: ' . wp_remote_retrieve_body( $response ) );
            return false;
        }

        $tokens['access_token'] = $body['access_token'];
        $tokens['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
        self::store_tokens( $tokens );

        HBU_Logger::info( 'Google Drive 토큰 자동 갱신 완료' );
        return true;
    }

    /**
     * 유효한 access_token을 반환합니다. 만료 시 자동 갱신합니다.
     *
     * @return string|false
     */
    public static function get_valid_token() {
        $tokens = self::get_tokens();

        if ( empty( $tokens['access_token'] ) ) {
            return false;
        }

        // 만료 60초 전부터 갱신
        if ( time() >= $tokens['expires_at'] - 60 ) {
            if ( ! self::refresh_token() ) {
                return false;
            }
            $tokens = self::get_tokens();
        }

        return $tokens['access_token'];
    }

    // ── 연결 해제 ────────────────────────────────────────────────────────

    /**
     * Google Drive 연결을 해제합니다.
     */
    public static function revoke() {
        $tokens = self::get_tokens();

        if ( ! empty( $tokens['access_token'] ) ) {
            wp_remote_get( self::REVOKE_URL . '?token=' . rawurlencode( $tokens['access_token'] ), [
                'timeout' => 10,
            ] );
        }

        delete_option( self::TOKEN_OPTION );
        delete_option( 'hbu_gdrive_folder_id' );
        HBU_Logger::info( 'Google Drive 연결 해제 완료' );
    }

    /**
     * 현재 Google Drive 연결 여부를 확인합니다.
     *
     * @return bool
     */
    public static function is_connected() {
        $tokens = self::get_tokens();
        return ! empty( $tokens['access_token'] );
    }

    // ── 토큰 저장/불러오기 (암호화) ─────────────────────────────────────

    private static function store_tokens( $tokens ) {
        $encrypted = [
            'access_token'  => self::encrypt( $tokens['access_token'] ),
            'refresh_token' => self::encrypt( $tokens['refresh_token'] ?? '' ),
            'expires_at'    => (int) ( $tokens['expires_at'] ?? 0 ),
        ];
        update_option( self::TOKEN_OPTION, $encrypted );
    }

    private static function get_tokens() {
        $data = get_option( self::TOKEN_OPTION, [] );
        if ( empty( $data ) ) {
            return [];
        }
        return [
            'access_token'  => isset( $data['access_token'] )  ? self::decrypt( $data['access_token'] )  : '',
            'refresh_token' => isset( $data['refresh_token'] ) ? self::decrypt( $data['refresh_token'] ) : '',
            'expires_at'    => isset( $data['expires_at'] )    ? (int) $data['expires_at']               : 0,
        ];
    }

    // ── 암호화 유틸리티 ──────────────────────────────────────────────────

    private static function get_key() {
        return substr( hash( 'sha256', AUTH_KEY . AUTH_SALT ), 0, 32 );
    }

    private static function encrypt( $plaintext ) {
        if ( empty( $plaintext ) ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( $plaintext );
        }
        $iv        = openssl_random_pseudo_bytes( 16 );
        $encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', self::get_key(), 0, $iv );
        return base64_encode( $iv . $encrypted );
    }

    private static function decrypt( $ciphertext ) {
        if ( empty( $ciphertext ) ) {
            return '';
        }
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return base64_decode( $ciphertext );
        }
        $decoded   = base64_decode( $ciphertext );
        $iv        = substr( $decoded, 0, 16 );
        $encrypted = substr( $decoded, 16 );
        return openssl_decrypt( $encrypted, 'AES-256-CBC', self::get_key(), 0, $iv );
    }
}
