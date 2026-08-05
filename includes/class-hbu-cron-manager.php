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
     * WP-Cron에 주간/격주 인터벌을 추가합니다.
     */
    public function add_custom_intervals( $schedules ) {
        $schedules['hbu_weekly'] = array(
            'interval' => 604800,   // 1주
            'display'  => '매주',
        );
        $schedules['hbu_biweekly'] = array(
            'interval' => 1209600,  // 2주
            'display'  => '격주',
        );
        return $schedules;
    }

    /**
     * 예약 백업을 등록합니다.
     * - 매일       → 다음 새벽 4시
     * - 매주/격주  → 다음 토요일 새벽 4시
     *
     * @param string $frequency 'daily' | 'hbu_weekly' | 'hbu_biweekly'
     */
    public static function schedule( $frequency ) {
        self::unschedule();

        if ( ! in_array( $frequency, array( 'daily', 'hbu_weekly', 'hbu_biweekly' ), true ) ) {
            $frequency = 'daily';
        }

        $first_run = self::calc_first_run( $frequency );
        wp_schedule_event( $first_run, $frequency, self::HOOK );
        HBU_Logger::info( "예약 백업 등록: {$frequency} (첫 실행: " . gmdate( 'Y-m-d H:i', $first_run ) . ' UTC)' );
    }

    /**
     * 첫 실행 시각을 계산합니다 (사이트 타임존 기준 새벽 4시).
     *
     * @param string $frequency
     * @return int Unix timestamp (UTC)
     */
    private static function calc_first_run( $frequency ) {
        $tz  = wp_timezone();
        $now = new DateTime( 'now', $tz );

        $run = clone $now;
        $run->setTime( 4, 0, 0 );

        if ( $frequency === 'daily' ) {
            // 오늘 새벽 4시가 이미 지났으면 내일로
            if ( $run->getTimestamp() <= $now->getTimestamp() ) {
                $run->modify( '+1 day' );
            }
        } else {
            // 매주/격주 → 토요일(6) 새벽 4시
            $dow              = (int) $run->format( 'w' ); // 0=일, 6=토
            $days_to_saturday = ( 6 - $dow + 7 ) % 7;

            // 오늘이 토요일이면서 아직 4시 전이면 오늘 사용, 아니면 다음 주 토요일
            if ( $days_to_saturday === 0 && $run->getTimestamp() <= $now->getTimestamp() ) {
                $days_to_saturday = 7;
            }

            if ( $days_to_saturday > 0 ) {
                $run->modify( "+{$days_to_saturday} days" );
            }
        }

        return $run->getTimestamp();
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
     * 예약 백업이 정상 동작 중인지 진단합니다.
     *
     * 예약 시각이 1시간 이상 지났는데도 실행되지 않았다면 WP-Cron이 동작하지
     * 않는 환경(트래픽 없음 등)으로 보고 경고를 반환합니다.
     *
     * @return array{status:string, message:string} status: 'ok' | 'warning' | 'inactive'
     */
    public static function get_health() {
        $next = self::next_scheduled();

        if ( ! $next ) {
            return array(
                'status'  => 'inactive',
                'message' => '자동 백업이 꺼져 있습니다.',
            );
        }

        if ( $next < time() - HOUR_IN_SECONDS ) {
            $overdue = human_time_diff( $next, time() );
            return array(
                'status'  => 'warning',
                'message' => "예약 시각이 {$overdue} 지났지만 백업이 실행되지 않았습니다. 사이트 방문자가 거의 없는 환경으로 보입니다. 아래 서버 Cron을 설정해주세요.",
            );
        }

        $last_ping = (int) get_option( 'hbu_last_cron_ping', 0 );
        $ping_info = $last_ping
            ? ' (마지막 확인: ' . human_time_diff( $last_ping, time() ) . ' 전)'
            : '';

        return array(
            'status'  => 'ok',
            'message' => '예약 백업이 정상적으로 대기 중입니다.' . $ping_info,
        );
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
        update_option( 'hbu_last_cron_ping', time(), false );

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
