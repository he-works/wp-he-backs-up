<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Logger {

    private static $log_file = null;

    private static function get_log_file() {
        if ( null === self::$log_file ) {
            self::$log_file = HBU_STORAGE_DIR . '/logs/hbu.log';
        }
        return self::$log_file;
    }

    public static function info( $message ) {
        self::write( 'INFO', $message );
    }

    public static function error( $message ) {
        self::write( 'ERROR', $message );
    }

    private static function write( $level, $message ) {
        $log_file = self::get_log_file();
        $log_dir  = dirname( $log_file );
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $timestamp = gmdate( 'Y-m-d H:i:s' );
        $line = "[{$timestamp} UTC] [{$level}] {$message}" . PHP_EOL;
        @file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * 로그 파일의 마지막 N줄을 반환합니다.
     */
    public static function get_recent( $lines = 100 ) {
        $log_file = self::get_log_file();
        if ( ! file_exists( $log_file ) ) {
            return '';
        }

        $content = file_get_contents( $log_file );
        if ( false === $content ) {
            return '';
        }

        $all_lines = array_filter( explode( PHP_EOL, $content ) );
        $recent    = array_slice( $all_lines, -$lines );
        return implode( PHP_EOL, $recent );
    }

    public static function clear() {
        $log_file = self::get_log_file();
        if ( file_exists( $log_file ) ) {
            file_put_contents( $log_file, '' );
        }
    }
}
