<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Activator {

    public static function activate() {
        self::create_storage_directories();
        self::set_default_options();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'hbu_scheduled_backup' );
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
            'schedule_frequency'     => 'daily',
        );
        add_option( 'hbu_settings', $defaults );
        add_option( 'hbu_backup_registry', array() );
        add_option( 'hbu_plugin_version', HBU_VERSION );
    }
}
