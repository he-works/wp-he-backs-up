<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Activator {

    public static function activate() {
        self::maybe_migrate_storage();
        self::create_storage_directories();
        self::set_default_options();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'hbu_scheduled_backup' );
    }

    /**
     * 구버전(플러그인 폴더 내 storage/)에 백업 파일이 있으면 새 위치로 이동합니다.
     * 플러그인 업데이트로 인한 백업 파일 소실 방지.
     */
    private static function maybe_migrate_storage() {
        $old_backups = WP_PLUGIN_DIR . '/he-backs-up/storage/backups';
        $old_logs    = WP_PLUGIN_DIR . '/he-backs-up/storage/logs';
        $new_backups = HBU_STORAGE_DIR . '/backups';
        $new_logs    = HBU_STORAGE_DIR . '/logs';

        // 구 백업 파일 이동
        if ( is_dir( $old_backups ) ) {
            wp_mkdir_p( $new_backups );
            $files = glob( $old_backups . '/backup_*.zip' );
            if ( $files ) {
                foreach ( $files as $file ) {
                    $dest = $new_backups . '/' . basename( $file );
                    if ( ! file_exists( $dest ) ) {
                        @rename( $file, $dest );
                    }
                }
            }
        }

        // 구 로그 파일 이동
        if ( is_dir( $old_logs ) ) {
            wp_mkdir_p( $new_logs );
            $logs = glob( $old_logs . '/*.log' );
            if ( $logs ) {
                foreach ( $logs as $log ) {
                    $dest = $new_logs . '/' . basename( $log );
                    if ( ! file_exists( $dest ) ) {
                        @rename( $log, $dest );
                    }
                }
            }
        }
    }

    private static function create_storage_directories() {
        $dirs = array(
            HBU_STORAGE_DIR,
            HBU_STORAGE_DIR . '/backups',
            HBU_STORAGE_DIR . '/logs',
        );

        foreach ( $dirs as $dir ) {
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            // 디렉토리 목록 노출 방지용 빈 index.php
            $index = $dir . '/index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, '<?php // Silence is golden.' );
            }
        }

        // Apache: 직접 웹 접근 차단
        $htaccess = HBU_STORAGE_DIR . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" );
        }
    }

    private static function set_default_options() {
        $defaults = array(
            'storage_local_enabled'  => 1,
            'storage_gdrive_enabled' => 0,
            'local_retention_count'  => 10,
            'schedule_enabled'       => 0,
            'schedule_frequency'     => 'hbu_weekly',
        );
        add_option( 'hbu_settings', $defaults );
        add_option( 'hbu_backup_registry', array() );
        update_option( 'hbu_plugin_version', HBU_VERSION );
    }
}
