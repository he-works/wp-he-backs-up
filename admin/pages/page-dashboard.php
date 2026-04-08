<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function hbu_page_dashboard() {
    $settings  = get_option( 'hbu_settings', array() );
    $backups   = HBU_Backup_Registry::get_all();
    $local_backups = HBU_Local_Storage::list_backups();

    // 관리자 알림 메시지
    $msg = isset( $_GET['hbu_msg'] ) ? sanitize_key( $_GET['hbu_msg'] ) : '';
    $err = isset( $_GET['hbu_err'] ) ? sanitize_text_field( urldecode( $_GET['hbu_err'] ) ) : '';

    $notices = array(
        'backup_ok'           => array( 'success', '백업이 완료되었습니다.' ),
        'backup_fail'         => array( 'error',   '백업에 실패했습니다.' . ( $err ? ' — ' . $err : '' ) ),
        'restore_ok'          => array( 'success', '복구가 완료되었습니다.' ),
        'restore_fail'        => array( 'error',   '복구에 실패했습니다.' . ( $err ? ' — ' . $err : '' ) ),
        'restore_confirm_fail' => array( 'error',  '확인 문자열이 올바르지 않습니다. "RESTORE"를 정확히 입력해주세요.' ),
        'delete_ok'           => array( 'success', '백업이 삭제되었습니다.' ),
    );
    ?>
    <div class="wrap hbu-wrap">
        <h1>HE BACKS UP <span class="hbu-version">v<?php echo esc_html( HBU_VERSION ); ?></span></h1>

        <?php if ( $msg && isset( $notices[ $msg ] ) ) :
            list( $type, $text ) = $notices[ $msg ]; ?>
            <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
                <p><?php echo esc_html( $text ); ?></p>
            </div>
        <?php endif; ?>

        <!-- 지금 백업 -->
        <div class="hbu-card">
            <h2>지금 백업</h2>
            <p>현재 사이트의 <strong>파일(wp-content)</strong>과 <strong>데이터베이스</strong>를 즉시 백업합니다.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbu-backup-form">
                <?php wp_nonce_field( 'hbu_run_backup' ); ?>
                <input type="hidden" name="action" value="hbu_run_backup">
                <button type="submit" class="button button-primary button-hero" id="hbu-run-btn">
                    지금 백업 시작
                </button>
                <span id="hbu-progress-label" style="display:none; margin-left:12px;"></span>
            </form>

            <?php
            $next = HBU_Cron_Manager::next_scheduled();
            if ( $next ) : ?>
                <p class="hbu-next-run">다음 자동 백업: <strong><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y년 m월 d일 H:i' ) ); ?></strong></p>
            <?php endif; ?>
        </div>

        <!-- 백업 목록 -->
        <div class="hbu-card">
            <h2>백업 목록</h2>
            <?php if ( empty( $backups ) ) : ?>
                <p>아직 백업이 없습니다.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped hbu-table">
                    <thead>
                        <tr>
                            <th>파일명</th>
                            <th>크기</th>
                            <th>날짜</th>
                            <th>저장 위치</th>
                            <th>작업</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $backups as $backup ) :
                            $locations_str = implode( ' + ', array_map( function( $l ) {
                                return $l === 'local' ? '서버' : 'Google Drive';
                            }, $backup['locations'] ) );
                            $has_local  = in_array( 'local', $backup['locations'], true );
                            $has_gdrive = in_array( 'gdrive', $backup['locations'], true );
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $backup['filename'] ); ?></code></td>
                                <td><?php echo esc_html( size_format( $backup['size_bytes'] ) ); ?></td>
                                <td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $backup['created_at'] ), 'Y-m-d H:i' ) ); ?></td>
                                <td><?php echo esc_html( $locations_str ); ?></td>
                                <td>
                                    <!-- 복구 버튼 -->
                                    <button type="button"
                                        class="button hbu-restore-btn"
                                        data-id="<?php echo esc_attr( $backup['id'] ); ?>"
                                        data-has-local="<?php echo $has_local ? '1' : '0'; ?>"
                                        data-has-gdrive="<?php echo $has_gdrive ? '1' : '0'; ?>"
                                        data-filename="<?php echo esc_attr( $backup['filename'] ); ?>">
                                        복구
                                    </button>

                                    <!-- 삭제 버튼 -->
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'hbu_delete_' . $backup['id'] ); ?>
                                        <input type="hidden" name="action" value="hbu_delete_backup">
                                        <input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup['id'] ); ?>">
                                        <button type="submit" class="button hbu-delete-btn"
                                            onclick="return confirm('이 백업을 삭제하시겠습니까?\n삭제하면 복구할 수 없습니다.');">
                                            삭제
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- 최근 로그 -->
        <div class="hbu-card">
            <h2>최근 로그</h2>
            <pre class="hbu-log"><?php echo esc_html( HBU_Logger::get_recent( 50 ) ); ?></pre>
        </div>
    </div>

    <!-- 복구 확인 모달 -->
    <div id="hbu-restore-modal" style="display:none;" class="hbu-modal-overlay">
        <div class="hbu-modal">
            <h2>⚠️ 복구 확인</h2>
            <p>이 작업은 <strong>현재 사이트의 파일과 데이터베이스를 복구합니다.</strong><br>
               되돌릴 수 없으니 신중하게 진행하세요.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hbu-restore-form">
                <?php wp_nonce_field( 'hbu_restore_PLACEHOLDER' ); ?>
                <input type="hidden" name="action" value="hbu_restore">
                <input type="hidden" name="backup_id" id="hbu-restore-backup-id">

                <!-- 복구 소스 선택 -->
                <div id="hbu-source-selector" style="margin-bottom:12px;">
                    <label><strong>복구 소스:</strong></label><br>
                    <label><input type="radio" name="restore_source" value="local"> 서버 로컬</label><br>
                    <label><input type="radio" name="restore_source" value="gdrive"> Google Drive</label>
                </div>
                <input type="radio" name="restore_source" value="local" id="hbu-source-single" style="display:none;">

                <!-- 복구 방식 선택 -->
                <div style="margin-bottom:12px; padding:12px; background:#f6f7f7; border-left:3px solid #d63638;">
                    <label><strong>복구 방식:</strong></label><br><br>
                    <label>
                        <input type="radio" name="restore_mode" value="merge" checked>
                        <strong>병합 복구</strong> — 백업 파일로 덮어씁니다. 백업 이후 추가된 파일은 유지됩니다.
                    </label><br><br>
                    <label>
                        <input type="radio" name="restore_mode" value="replace">
                        <strong>완전 교체</strong> — 백업 시점으로 완전히 되돌립니다. 이후 추가된 파일은 <span style="color:#d63638;">모두 삭제</span>됩니다.
                    </label>
                </div>

                <p>
                    <label>확인을 위해 아래에 <strong>RESTORE</strong> 를 입력해주세요:</label><br>
                    <input type="text" name="confirm_text" placeholder="RESTORE" class="regular-text" required>
                </p>

                <p>
                    <button type="submit" class="button button-primary">복구 시작</button>
                    <button type="button" class="button" id="hbu-modal-cancel">취소</button>
                </p>
            </form>
        </div>
    </div>
    <?php
}
