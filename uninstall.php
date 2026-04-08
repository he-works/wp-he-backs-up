<?php
/**
 * He Backs Up 플러그인 삭제 시 실행됩니다.
 * 모든 옵션과 스케줄을 제거합니다. (백업 파일은 유지)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 옵션 삭제
$options = array(
    'hbu_settings',
    'hbu_gdrive_credentials',
    'hbu_gdrive_tokens',
    'hbu_gdrive_folder_id',
    'hbu_backup_registry',
    'hbu_plugin_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// WP-Cron 이벤트 제거
$timestamp = wp_next_scheduled( 'hbu_scheduled_backup' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'hbu_scheduled_backup' );
}

// 트랜지언트 삭제
delete_transient( 'hbu_backup_progress' );
delete_transient( 'hbu_cron_pinged' );
delete_transient( 'hbu_github_release' );
