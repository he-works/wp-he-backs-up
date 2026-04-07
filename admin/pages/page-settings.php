<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function hbu_page_settings() {
    $settings = wp_parse_args( get_option( 'hbu_settings', array() ), array(
        'storage_local_enabled'  => 1,
        'storage_gdrive_enabled' => 0,
        'local_retention_count'  => 10,
        'schedule_enabled'       => 0,
        'schedule_frequency'     => 'daily',
    ) );

    $msg = isset( $_GET['hbu_msg'] ) ? sanitize_key( $_GET['hbu_msg'] ) : '';
    ?>
    <div class="wrap hbu-wrap">
        <h1>설정</h1>

        <?php if ( $msg === 'settings_saved' ) : ?>
            <div class="notice notice-success is-dismissible"><p>설정이 저장되었습니다.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'hbu_save_settings' ); ?>
            <input type="hidden" name="action" value="hbu_save_settings">

            <!-- 저장소 설정 -->
            <div class="hbu-card">
                <h2>저장소 설정</h2>

                <table class="form-table">
                    <tr>
                        <th>로컬 서버 저장</th>
                        <td>
                            <label>
                                <input type="checkbox" name="storage_local_enabled" value="1"
                                    <?php checked( $settings['storage_local_enabled'], 1 ); ?>>
                                활성화
                            </label>
                            <p class="description">
                                백업 파일 저장 경로: <code><?php echo esc_html( HBU_STORAGE_DIR . '/backups/' ); ?></code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Google Drive 저장</th>
                        <td>
                            <label>
                                <input type="checkbox" name="storage_gdrive_enabled" value="1"
                                    <?php checked( $settings['storage_gdrive_enabled'], 1 ); ?>>
                                활성화
                            </label>
                            <?php if ( ! HBU_GDrive_Auth::is_connected() ) : ?>
                                <p class="description" style="color:#d63638;">
                                    Google Drive가 연결되지 않았습니다.
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hbu-gdrive' ) ); ?>">Google Drive 설정 페이지</a>에서 먼저 연결해주세요.
                                </p>
                            <?php else : ?>
                                <p class="description" style="color:#00a32a;">Google Drive 연결됨 ✓</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 보존 정책 -->
            <div class="hbu-card">
                <h2>백업 보존 설정</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="local_retention_count">최대 로컬 보존 개수</label></th>
                        <td>
                            <input type="number" id="local_retention_count" name="local_retention_count"
                                value="<?php echo esc_attr( $settings['local_retention_count'] ); ?>"
                                min="1" max="999" class="small-text">
                            <p class="description">이 개수를 초과하면 오래된 로컬 백업이 자동으로 삭제됩니다.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- 자동 백업 스케줄 -->
            <div class="hbu-card">
                <h2>자동 백업 스케줄</h2>
                <table class="form-table">
                    <tr>
                        <th>자동 백업</th>
                        <td>
                            <label>
                                <input type="checkbox" name="schedule_enabled" value="1"
                                    <?php checked( $settings['schedule_enabled'], 1 ); ?>>
                                활성화
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="schedule_frequency">백업 주기</label></th>
                        <td>
                            <select id="schedule_frequency" name="schedule_frequency">
                                <option value="daily" <?php selected( $settings['schedule_frequency'], 'daily' ); ?>>매일</option>
                                <option value="hbu_weekly" <?php selected( $settings['schedule_frequency'], 'hbu_weekly' ); ?>>매주</option>
                                <option value="hbu_monthly" <?php selected( $settings['schedule_frequency'], 'hbu_monthly' ); ?>>매월</option>
                            </select>

                            <?php $next = HBU_Cron_Manager::next_scheduled(); ?>
                            <?php if ( $next ) : ?>
                                <p class="description">
                                    다음 예약 백업: <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y년 m월 d일 H:i' ) ); ?></strong>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div class="notice notice-info inline" style="margin-top:12px;">
                    <p>
                        <strong>✓ 관리자 접속 시 자동 실행됩니다.</strong><br>
                        이 플러그인은 관리자가 WordPress 대시보드에 접속할 때마다 예약된 백업을 자동으로 실행합니다.<br>
                        장기간 관리자 접속이 없는 환경에서는 서버 Cron 설정을 추가하면 더욱 안정적입니다:<br>
                        <code>*/5 * * * * curl -s <?php echo esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null 2&gt;&amp;1</code>
                    </p>
                </div>
            </div>

            <p><button type="submit" class="button button-primary">설정 저장</button></p>
        </form>
    </div>
    <?php
}
