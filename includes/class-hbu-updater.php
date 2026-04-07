<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub Releases 기반 플러그인 자동 업데이트
 *
 * 동작 원리:
 *  1. WordPress가 업데이트 정보를 요청할 때 GitHub API로 최신 릴리스를 조회
 *  2. 현재 버전보다 높으면 업데이트 알림 표시
 *  3. "지금 업데이트" 클릭 시 GitHub Release ZIP을 자동 다운로드·설치
 *
 * 사용 방법:
 *  - GitHub 저장소에 Releases 탭에서 태그를 'v1.1.0' 형식으로 생성
 *  - Release에 플러그인 ZIP 파일을 첨부 (파일명 무관)
 *  - 또는 ZIP 없이 태그만 생성해도 GitHub가 소스 ZIP을 자동 제공
 */
class HBU_Updater {

    const GITHUB_USER = 'he-works';
    const GITHUB_REPO = 'wp-he-backs-up';

    // GitHub API 캐시 시간 (초) — 너무 자주 호출하면 API 한도 초과
    const CACHE_TTL = 43200; // 12시간

    private $plugin_file;
    private $plugin_slug;
    private $current_version;

    public function __construct( $plugin_file ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->current_version = HBU_VERSION;
    }

    public function register_hooks() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_post_install',                 array( $this, 'after_install' ), 10, 3 );
    }

    /**
     * WordPress 업데이트 체크 시 GitHub 최신 버전 정보를 주입합니다.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $latest_version = ltrim( $release['tag_name'], 'v' );

        if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $latest_version,
                'url'         => $release['html_url'],
                'package'     => $this->get_download_url( $release ),
            );
        }

        return $transient;
    }

    /**
     * 플러그인 정보 팝업(View version x.x.x details)에 GitHub 정보를 표시합니다.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release['tag_name'], 'v' );

        return (object) array(
            'name'          => 'He Backs Up',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $latest_version,
            'author'        => '<a href="https://plugin.he-works.co/he-backs-up">He Works</a>',
            'homepage'      => 'https://plugin.he-works.co/he-backs-up',
            'download_link' => $this->get_download_url( $release ),
            'sections'      => array(
                'description' => 'WordPress 사이트 전체(파일 + DB)를 백업하고 복구하는 플러그인. 로컬 서버 및 Google Drive 저장 지원.',
                'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
            ),
            'last_updated'  => $release['published_at'] ?? '',
            'requires'      => '5.0',
            'tested'        => '6.7',
            'requires_php'  => '7.4',
        );
    }

    /**
     * 업데이트 설치 후 폴더명을 올바르게 교정합니다.
     * GitHub ZIP은 'repo-main' 형태의 폴더로 풀리므로 교정이 필요합니다.
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }

        global $wp_filesystem;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );
        $wp_filesystem->move( $result['destination'], $plugin_dir, true );
        $result['destination'] = $plugin_dir;

        // 이동 후 플러그인 재활성화
        activate_plugin( $this->plugin_slug );

        return $result;
    }

    // ── 내부 헬퍼 ──────────────────────────────────────────────────────────

    /**
     * GitHub API에서 최신 릴리스 정보를 가져옵니다 (캐시 적용).
     *
     * @return array|false
     */
    private function get_latest_release() {
        $cache_key = 'hbu_github_release';
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $url      = 'https://api.github.com/repos/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases/latest';
        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $release['tag_name'] ) ) {
            return false;
        }

        set_transient( $cache_key, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * 릴리스에서 다운로드 URL을 추출합니다.
     * 첨부 ZIP이 있으면 우선 사용, 없으면 GitHub 소스 ZIP을 사용합니다.
     *
     * @param array $release
     * @return string
     */
    private function get_download_url( $release ) {
        // Release에 첨부된 assets 중 ZIP 파일 우선 사용
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['content_type'] ) && $asset['content_type'] === 'application/zip' ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // 첨부 ZIP이 없으면 GitHub 자동 생성 소스 ZIP 사용
        return 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/archive/refs/heads/main.zip';
    }
}
