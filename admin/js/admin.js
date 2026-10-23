/**
 * Sheets to WP Admin JavaScript
 */
(function ($) {
    'use strict';

    function i18n(key, fallback) {
        if (window.gsdcAdmin && gsdcAdmin.i18n && gsdcAdmin.i18n[key]) {
            return gsdcAdmin.i18n[key];
        }
        return fallback;
    }

    function ajaxAction(action, data, onSuccess, onError) {
        $.ajax({
            url: gsdcAdmin.ajax_url,
            type: 'POST',
            data: $.extend({ action: action, nonce: gsdcAdmin.nonce }, data || {}),
            success: function (response) {
                if (response && response.success) {
                    onSuccess(response.data);
                } else {
                    onError(response && response.data ? response.data : 'error');
                }
            },
            error: function () {
                onError(i18n('network_error', '通信エラーが発生しました'));
            }
        });
    }

    function buttonAction(buttonId, confirmKey, confirmDefault, action) {
        $(document).on('click', buttonId, function () {
            if (confirmKey && !confirm(i18n(confirmKey, confirmDefault))) {
                return;
            }
            var $btn = $(this);
            var orig = $btn.text();
            $btn.prop('disabled', true).text('処理中...');

            ajaxAction(action, {},
                function (data) { alert(data); location.reload(); },
                function (data) { $btn.prop('disabled', false).text(orig); alert('エラー: ' + data); }
            );
        });
    }

    $(document).ready(function () {

        $('#gsdc_test_connection').on('click', function () {
            var $btn = $(this);
            var $res = $('#gsdc_test_result');

            $btn.prop('disabled', true);
            $res.html('<span class="gsdc-loading"></span>').attr('class', '');

            ajaxAction('gsdc_test_connection', {
                api_key:  $('#gsdc_api_key').val() || '',
                sheet_id: $('#gsdc_sheet_id').val() || ''
            },
                function (data) { $btn.prop('disabled', false); $res.text(data).attr('class', 'success'); },
                function (data) { $btn.prop('disabled', false); $res.text(data).attr('class', 'error'); }
            );
        });

        buttonAction('#gsdc_clear_cache',   'confirm_clear',   'キャッシュをクリアしますか？',            'gsdc_clear_cache');
        buttonAction('#gsdc_refresh_tabs',  'confirm_refresh', 'すべてのタブを今すぐ再取得しますか？',    'gsdc_refresh_tabs');
        buttonAction('#gsdc_sync_tab_list', null,              null,                                      'gsdc_sync_tab_list');
    });

})(jQuery);
