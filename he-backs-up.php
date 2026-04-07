<?php
/**
 * Plugin Name: He Backs Up
 * Plugin URI:  https://github.com/
 * Description: WordPress 사이트 전체(파일 + 데이터베이스)를 백업하고 복구하는 플러그인. 로컬 서버 및 Google Drive 저장 지원.
 * Version:     1.0.0
 * Author:      He Backs Up
 * License:     GPL-2.0-or-later
 * Text Domain: he-backs-up
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 플러그인 상수 정의
define( 'HBU_VERSION',     '1.0.0' );
define( 'HBU_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'HBU_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'HBU_STORAGE_DIR', HBU_PLUGIN_DIR . 'storage' );

// Google OAuth — 배포자 공유 앱 설정
// CLIENT_ID는 공개해도 괜찮습니다. CLIENT_SECRET은 oauth-relay/callback.php 에만 존재합니다.
define( 'HBU_GDRIVE_CLIENT_ID',  '616293903678-hc1h8ncqih0bbrt2uq6nm603navai15g.apps.googleusercontent.com' );          // ← Google Cloud Console에서 발급받은 값으로 교체
define( 'HBU_OAUTH_RELAY_URL',   'https://plugin.he-works.co/he-backs-up/oauth/callback.php' ); // ← 릴레이 서버 URL로 교체

// 핵심 클래스 로드
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-logger.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-activator.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-backup-registry.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-db-dumper.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-file-zipper.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-local-storage.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-gdrive-auth.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-gdrive-client.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-backup-engine.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-restore-engine.php';
require_once HBU_PLUGIN_DIR . 'includes/class-hbu-cron-manager.php';
require_once HBU_PLUGIN_DIR . 'admin/class-hbu-admin.php';

// 활성화 / 비활성화 훅
register_activation_hook( __FILE__, array( 'HBU_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HBU_Activator', 'deactivate' ) );

// 플러그인 초기화
add_action( 'plugins_loaded', 'hbu_init' );
function hbu_init() {
    $cron = new HBU_Cron_Manager();
    $cron->register_hooks();

    if ( is_admin() ) {
        $admin = new HBU_Admin();
        $admin->register_hooks();
    }
}
