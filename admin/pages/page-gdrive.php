<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function hbu_page_gdrive() {
    $is_connected = HBU_GDrive_Auth::is_connected();
    $folder_id    = get_option( 'hbu_gdrive_folder_id', '' );
    $msg          = isset( $_GET['hbu_msg'] ) ? sanitize_key( $_GET['hbu_msg'] ) : '';

    $notices = array(
        'connected'    => array( 'success', '✓ Google Drive 연결이 완료되었습니다!' ),
        'disconnected' => array( 'success', 'Google Drive 연결이 해제되었습니다.' ),
        'oauth_failed' => array( 'error',   'Google 인증에 실패했습니다. 다시 시도해주세요.' ),
    );
    ?>
    <div class="wrap hbu-wrap">
        <h1>Google Drive 연동</h1>

        <?php if ( $msg && isset( $notices[ $msg ] ) ) :
            list( $type, $text ) = $notices[ $msg ]; ?>
            <div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
                <p><?php echo esc_html( $text ); ?></p>
            </div>
        <?php endif; ?>

        <?php if ( ! $is_connected ) : ?>

            <!-- 미연결 상태 -->
            <div class="hbu-card">
                <h2>Google Drive 연결</h2>
                <p>
                    아래 버튼을 클릭하면 Google 계정 선택 및 권한 허용 페이지로 이동합니다.<br>
                    허용하면 <strong>자동으로 이 페이지로 돌아와</strong> 연결이 완료됩니다.
                </p>
                <p style="color:#8c8f94; font-size:13px;">
                    백업 파일은 <strong>회원님의 Google Drive</strong>에만 저장됩니다.
                    플러그인 개발자는 Drive에 접근할 수 없습니다.
                </p>
                <a href="<?php echo esc_url( HBU_GDrive_Auth::get_auth_url() ); ?>"
                   class="button button-primary button-large hbu-gdrive-connect-btn">
                    <span class="dashicons dashicons-google" style="margin-top:3px;"></span>
                    &nbsp; Google Drive 연결하기
                </a>
            </div>

            <!-- 개인정보/권한 안내 -->
            <div class="hbu-card" style="background:#f6f7f7;">
                <h2 style="font-size:14px; color:#50575e;">🔒 권한 범위 안내</h2>
                <p style="font-size:13px; color:#50575e; margin:0;">
                    이 플러그인은 <strong>drive.file</strong> 스코프만 요청합니다.<br>
                    이는 <em>이 플러그인이 직접 생성한 파일에만 접근</em>할 수 있는 가장 제한적인 권한으로,
                    기존 Drive 파일이나 다른 앱의 파일에는 접근하지 않습니다.
                </p>
            </div>

        <?php else : ?>

            <!-- 연결 완료 상태 -->
            <div class="hbu-card">
                <h2>연결 상태</h2>
                <p style="color:#00a32a; font-size:1.1em; font-weight:600;">
                    ✓ Google Drive 연결됨
                </p>

                <?php if ( $folder_id ) : ?>
                    <table class="form-table" style="max-width:500px;">
                        <tr>
                            <th style="width:130px;">백업 폴더</th>
                            <td><strong>He-Backs-Up</strong></td>
                        </tr>
                        <tr>
                            <th>폴더 ID</th>
                            <td><code><?php echo esc_html( $folder_id ); ?></code></td>
                        </tr>
                    </table>
                <?php endif; ?>

                <hr style="margin:20px 0;">

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                    onsubmit="return confirm('Google Drive 연결을 해제하시겠습니까?\n해제해도 Drive에 저장된 백업 파일은 삭제되지 않습니다.');">
                    <?php wp_nonce_field( 'hbu_gdrive_disconnect' ); ?>
                    <input type="hidden" name="action" value="hbu_gdrive_disconnect">
                    <button type="submit" class="button button-secondary">연결 해제</button>
                </form>
            </div>

            <!-- 설정 안내 -->
            <div class="hbu-card" style="background:#f6f7f7;">
                <h2 style="font-size:14px; color:#50575e;">📁 백업 설정</h2>
                <p style="font-size:13px; color:#50575e; margin:0;">
                    Google Drive 저장을 사용하려면 <a href="<?php echo esc_url( admin_url( 'admin.php?page=hbu-settings' ) ); ?>">설정 페이지</a>에서
                    <strong>Google Drive 저장</strong> 옵션을 활성화해주세요.
                </p>
            </div>

        <?php endif; ?>
    </div>
    <?php
}
