/* He Backs Up - Admin JS */
(function ($) {
    'use strict';

    // ── 백업 진행 상태 폴링 ───────────────────────────────────────────

    var progressInterval = null;

    $('#hbu-backup-form').on('submit', function () {
        var $btn   = $('#hbu-run-btn');
        var $label = $('#hbu-progress-label');

        $btn.prop('disabled', true).text('백업 중...');
        $label.show().text('준비 중...');

        progressInterval = setInterval(function () {
            $.post(hbuAjax.ajaxUrl, {
                action: 'hbu_backup_progress',
                nonce:  hbuAjax.nonce
            }, function (res) {
                if (res.success && res.data.stage !== 'idle') {
                    $label.text(res.data.label);
                }
            });
        }, 3000);
    });

    // 페이지 이탈 시 폴링 정리
    $(window).on('beforeunload', function () {
        if (progressInterval) {
            clearInterval(progressInterval);
        }
    });

    // ── 복구 모달 ────────────────────────────────────────────────────

    $(document).on('click', '.hbu-restore-btn', function () {
        var id       = $(this).data('id');
        var hasLocal  = $(this).data('has-local') === '1' || $(this).data('has-local') === 1;
        var hasGdrive = $(this).data('has-gdrive') === '1' || $(this).data('has-gdrive') === 1;

        // nonce 필드의 value를 backup_id에 맞게 교체
        // (서버 측 check_admin_referer는 'hbu_restore_' + backup_id 형식 사용)
        // wp_nonce_field가 이미 PLACEHOLDER로 생성되어 있으므로 JS로 교체
        $('#hbu-restore-backup-id').val(id);

        // 소스 선택 UI 제어
        var $selector = $('#hbu-source-selector');
        var $single   = $('#hbu-source-single');

        if (hasLocal && hasGdrive) {
            $selector.show();
            $single.hide();
            $selector.find('input[value="local"]').prop('checked', true);
        } else if (hasLocal) {
            $selector.hide();
            $single.val('local').prop('checked', true);
        } else if (hasGdrive) {
            $selector.hide();
            $single.val('gdrive').prop('checked', true);
        }

        // 확인 입력 초기화
        $('#hbu-restore-form input[name="confirm_text"]').val('');

        // nonce action 업데이트 (hidden field 교체)
        // WordPress는 nonce를 _wpnonce 필드에 저장하므로 서버에서 검증 가능
        // 실제 nonce는 PHP 서버에서 이미 생성되어 있으나,
        // backup_id 별로 달라지므로 AJAX로 새 nonce를 요청합니다.
        $.post(hbuAjax.ajaxUrl, {
            action: 'hbu_get_restore_nonce',
            nonce:  hbuAjax.nonce,
            backup_id: id
        }, function (res) {
            if (res.success) {
                $('#hbu-restore-form input[name="_wpnonce"]').val(res.data.nonce);
            }
        });

        $('#hbu-restore-modal').fadeIn(150);
    });

    $('#hbu-modal-cancel').on('click', function () {
        $('#hbu-restore-modal').fadeOut(150);
    });

    // 오버레이 클릭으로 닫기
    $('.hbu-modal-overlay').on('click', function (e) {
        if ($(e.target).hasClass('hbu-modal-overlay')) {
            $(this).fadeOut(150);
        }
    });

    // ESC 키로 닫기
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('#hbu-restore-modal').fadeOut(150);
        }
    });

}(jQuery));
