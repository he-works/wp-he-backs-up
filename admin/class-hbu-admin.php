<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Admin {

    public function register_hooks() {
        add_action( 'admin_menu',          array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init',          array( $this, 'handle_gdrive_oauth_callback' ) );
        add_action( 'admin_init',          array( 'HBU_Cron_Manager', 'ping_wp_cron' ) );

        // 폼 처리 훅
        add_action( 'admin_post_hbu_run_backup',        array( $this, 'handle_run_backup' ) );
        add_action( 'admin_post_hbu_save_settings',     array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_hbu_restore',           array( $this, 'handle_restore' ) );
        add_action( 'admin_post_hbu_delete_backup',     array( $this, 'handle_delete_backup' ) );
        add_action( 'admin_post_hbu_gdrive_disconnect', array( $this, 'handle_gdrive_disconnect' ) );
        // B방식: hbu_save_gdrive_credentials 훅 제거 (사용자가 직접 Client ID/Secret 입력 불필요)

        // AJAX: 백업 진행 상태 폴링
        add_action( 'wp_ajax_hbu_backup_progress',   array( $this, 'ajax_backup_progress' ) );
        // AJAX: 복구 nonce 동적 발급
        add_action( 'wp_ajax_hbu_get_restore_nonce', array( $this, 'ajax_get_restore_nonce' ) );
    }

    public function register_menus() {
        add_menu_page(
            'HE BACKS UP',
            'HE BACKS UP',
            'manage_options',
            'hbu-dashboard',
            array( $this, 'page_dashboard' ),
            'dashicons-backup',
            80
        );

        add_submenu_page( 'hbu-dashboard', '대시보드',    '대시보드',    'manage_options', 'hbu-dashboard', array( $this, 'page_dashboard' ) );
        add_submenu_page( 'hbu-dashboard', '설정',       '설정',       'manage_options', 'hbu-settings',  array( $this, 'page_settings' ) );
        add_submenu_page( 'hbu-dashboard', 'Google Drive', 'Google Drive', 'manage_options', 'hbu-gdrive',  array( $this, 'page_gdrive' ) );
    }

    public function enqueue_assets( $hook ) {
        // 플러그인 페이지에서만 로드
        if ( strpos( $hook, 'hbu-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'hbu-admin',
            HBU_PLUGIN_URL . 'assets/css/hbu-admin.css',
            array(),
            HBU_VERSION
        );

        wp_enqueue_script(
            'hbu-admin',
            HBU_PLUGIN_URL . 'assets/js/hbu-admin.js',
            array( 'jquery' ),
            HBU_VERSION,
            true
        );

        wp_localize_script( 'hbu-admin', 'hbuAjax', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'hbu_ajax' ),
        ) );
    }

    // ── 페이지 렌더러 ─────────────────────────────────────────────────

    public function page_dashboard() {
        require_once HBU_PLUGIN_DIR . 'admin/pages/page-dashboard.php';
        hbu_page_dashboard();
    }

    public function page_settings() {
        require_once HBU_PLUGIN_DIR . 'admin/pages/page-settings.php';
        hbu_page_settings();
    }

    public function handle_gdrive_oauth_callback() {
        // hbu-gdrive 페이지에서 토큰 파라미터가 넘어온 경우에만 처리
        if (
            ! isset( $_GET['page'] ) || $_GET['page'] !== 'hbu-gdrive' ||
            ! isset( $_GET['hbu_at'] ) || ! isset( $_GET['hbu_nonce'] )
        ) {
            return;
        }

        $access_token  = sanitize_text_field( wp_unslash( $_GET['hbu_at'] ) );
        $refresh_token = sanitize_text_field( wp_unslash( $_GET['hbu_rt'] ?? '' ) );
        $expires_at    = absint( $_GET['hbu_ex'] ?? 0 );
        $nonce         = sanitize_text_field( wp_unslash( $_GET['hbu_nonce'] ) );

        if ( HBU_GDrive_Auth::save_tokens_from_relay( $access_token, $refresh_token, $expires_at, $nonce ) ) {
            // 첫 연결 시 Drive 폴더 자동 생성
            $token = HBU_GDrive_Auth::get_valid_token();
            if ( $token && ! get_option( 'hbu_gdrive_folder_id' ) ) {
                $folder_id = HBU_GDrive_Client::create_folder( 'He-Backs-Up', $token );
                if ( $folder_id ) {
                    update_option( 'hbu_gdrive_folder_id', $folder_id );
                }
            }
            wp_redirect( admin_url( 'admin.php?page=hbu-gdrive&hbu_msg=connected' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=hbu-gdrive&hbu_msg=oauth_failed' ) );
        }
        exit;
    }

    public function page_gdrive() {
        require_once HBU_PLUGIN_DIR . 'admin/pages/page-gdrive.php';
        hbu_page_gdrive();
    }

    // ── 폼 처리 핸들러 ────────────────────────────────────────────────

    public function handle_run_backup() {
        check_admin_referer( 'hbu_run_backup' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        $result = HBU_Backup_Engine::run( 'manual' );

        if ( $result['success'] ) {
            wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=backup_ok' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=backup_fail&hbu_err=' . rawurlencode( $result['message'] ) ) );
        }
        exit;
    }

    public function handle_save_settings() {
        check_admin_referer( 'hbu_save_settings' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        $settings = array(
            'storage_local_enabled'  => isset( $_POST['storage_local_enabled'] ) ? 1 : 0,
            'storage_gdrive_enabled' => isset( $_POST['storage_gdrive_enabled'] ) ? 1 : 0,
            'local_retention_count'  => max( 1, absint( $_POST['local_retention_count'] ?? 10 ) ),
            'schedule_enabled'       => isset( $_POST['schedule_enabled'] ) ? 1 : 0,
            'schedule_frequency'     => in_array( $_POST['schedule_frequency'] ?? '', array( 'daily', 'hbu_weekly', 'hbu_biweekly' ), true )
                                            ? sanitize_key( $_POST['schedule_frequency'] )
                                            : 'hbu_weekly',
        );

        update_option( 'hbu_settings', $settings );

        // 스케줄 업데이트
        if ( $settings['schedule_enabled'] ) {
            HBU_Cron_Manager::schedule( $settings['schedule_frequency'] );
        } else {
            HBU_Cron_Manager::unschedule();
        }

        wp_redirect( admin_url( 'admin.php?page=hbu-settings&hbu_msg=settings_saved' ) );
        exit;
    }

    public function handle_restore() {
        $backup_id = sanitize_key( $_POST['backup_id'] ?? '' );
        check_admin_referer( 'hbu_restore_' . $backup_id );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        // 확인 문자열 검사
        if ( ( $_POST['confirm_text'] ?? '' ) !== 'RESTORE' ) {
            wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=restore_confirm_fail' ) );
            exit;
        }

        $source = sanitize_key( $_POST['restore_source'] ?? 'local' );
        $result = HBU_Restore_Engine::restore( $backup_id, $source );

        if ( $result['success'] ) {
            wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=restore_ok' ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=restore_fail&hbu_err=' . rawurlencode( $result['message'] ) ) );
        }
        exit;
    }

    public function handle_delete_backup() {
        $backup_id = sanitize_key( $_POST['backup_id'] ?? '' );
        check_admin_referer( 'hbu_delete_' . $backup_id );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        $entry = HBU_Backup_Registry::find_by_id( $backup_id );
        if ( $entry ) {
            if ( in_array( 'local', $entry['locations'], true ) ) {
                HBU_Local_Storage::delete( $entry['filename'] );
            }
            if ( in_array( 'gdrive', $entry['locations'], true ) && ! empty( $entry['gdrive_file_id'] ) ) {
                $token = HBU_GDrive_Auth::get_valid_token();
                if ( $token ) {
                    HBU_GDrive_Client::delete_file( $entry['gdrive_file_id'], $token );
                }
            }
            HBU_Backup_Registry::remove( $backup_id );
        }

        wp_redirect( admin_url( 'admin.php?page=hbu-dashboard&hbu_msg=delete_ok' ) );
        exit;
    }

    public function handle_gdrive_disconnect() {
        check_admin_referer( 'hbu_gdrive_disconnect' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '권한이 없습니다.' );
        }

        HBU_GDrive_Auth::revoke();

        wp_redirect( admin_url( 'admin.php?page=hbu-gdrive&hbu_msg=disconnected' ) );
        exit;
    }

    public function ajax_get_restore_nonce() {
        check_ajax_referer( 'hbu_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '권한 없음', 403 );
        }

        $backup_id = sanitize_key( $_POST['backup_id'] ?? '' );
        wp_send_json_success( array(
            'nonce' => wp_create_nonce( 'hbu_restore_' . $backup_id ),
        ) );
    }

    public function ajax_backup_progress() {
        check_ajax_referer( 'hbu_ajax', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '권한 없음', 403 );
        }

        $stage = get_transient( 'hbu_backup_progress' );
        $labels = array(
            'db_dump' => 'DB 덤프 중...',
            'zipping' => '파일 압축 중...',
            'storing' => '저장 중...',
        );

        wp_send_json_success( array(
            'stage' => $stage ?: 'idle',
            'label' => $stage ? ( $labels[ $stage ] ?? $stage ) : '완료',
        ) );
    }
}
