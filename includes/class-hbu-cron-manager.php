<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HBU_Cron_Manager {

    const HOOK = 'hbu_scheduled_backup';

    public function register_hooks() {
        add_action( self::HOOK, array( $this, 'run_backup' ) );
        add_filter( 'cron_schedules', array( $this, 'add_custom_intervals' ) );
    }

    public function run_backup() {
        HBU_Logger::info( '예약 백업 실행 시작' );
        $result = HBU_Backup_Engine::run( 'cron' );

        if ( $result['success'] ) {
            HBU_Logger::info( '예약 백업 완료: ' . $result['message'] );
        } else {
            HBU_Logger::error( '예약 백업 실패: ' . $result['message'] );
        }
    }

    /**
     * WP-Cron에 주간/월간 인터벌을 추가합니다.
     */
    public function add_custom_intervals( $schedules ) {
        $schedules['hbu_weekly'] = array(
            'interval' => 604800,
            'display'  => '매주',
        );
        $schedules['hbu_monthly'] = array(
            'interval' => 2592000,
            'display'  => '매월',
        );
        return $schedules;
    }

    /**
     * 예약 백업을 등록합니다.
     *
     * @param string $frequency 'daily' | 'hbu_weekly' | 'hbu_monthly'
     */
    public static function schedule( $frequency ) {
        self::unschedule();

        if ( ! in_array( $frequency, array( 'daily', 'hbu_weekly', 'hbu_monthly' ), true ) ) {
            $frequency = 'daily';
        }

        wp_schedule_event( time(), $frequency, self::HOOK );
        HBU_Logger::info( "예약 백업 등록: {$frequency}" );
    }

    /**
     * 예약 백업을 해제합니다.
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
            HBU_Logger::info( '예약 백업 해제 완료' );
        }
    }

    /**
     * 다음 예약 백업 시각을 반환합니다.
     *
     * @return int|false Unix timestamp 또는 false
     */
    public static function next_scheduled() {
        return wp_next_scheduled( self::HOOK );
    }

    /**
     * 관리자 접속 시 wp-cron을 비동기로 핑(ping)하여 예약된 백업이 실행되도록 합니다.
     * 5분에 한 번만 실행되도록 트랜지언트로 제한합니다.
     */
    public static function ping_wp_cron() {
        // 예약 백업이 활성화되어 있지 않으면 실행하지 않음
        if ( ! self::next_scheduled() ) {
            return;
        }

        // 5분에 한 번만 핑
        if ( get_transient( 'hbu_cron_pinged' ) ) {
            return;
        }

        set_transient( 'hbu_cron_pinged', 1, 5 * MINUTE_IN_SECONDS );

        wp_remote_get(
            add_query_arg( 'doing_wp_cron', '', site_url( 'wp-cron.php' ) ),
            array(
                'blocking'  => false,
                'timeout'   => 0.01,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            )
        );
    }
}
