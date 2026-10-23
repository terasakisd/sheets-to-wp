<?php
/**
 * 管理者用デバッグショートコード
 *
 *   [gsdc_test cell="A1"]
 *
 * 接続テスト用。出力には診断情報が含まれるため、
 * ログイン済みかつ manage_options 権限があるユーザにのみ結果を返す。
 */

if (!defined('ABSPATH')) {
    exit;
}

function gsdc_test_shortcode($atts) {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        return '';
    }

    $atts = shortcode_atts(array('cell' => 'A1'), $atts, 'gsdc_test');
    $cell_ref = $atts['cell'];

    $output  = '<div style="border:2px solid #0073aa;padding:15px;margin:10px 0;background:#f0f0f0;">';
    $output .= '<h4 style="margin-top:0;">Sheets to WP テスト</h4>';

    $api_key  = function_exists('gsdc_get_api_key')  ? gsdc_get_api_key()  : (string) get_option('gsdc_api_key', '');
    $sheet_id = function_exists('gsdc_get_sheet_id') ? gsdc_get_sheet_id() : (string) get_option('gsdc_sheet_id', '');

    if (empty($api_key)) {
        return $output . '<p style="color:red;">APIキーが設定されていません</p></div>';
    }
    if (empty($sheet_id)) {
        return $output . '<p style="color:red;">スプレッドシートIDが設定されていません</p></div>';
    }

    $src_api   = get_option('gsdc_api_key', '')  !== '' ? '設定画面' : 'wp-config定数';
    $src_sheet = get_option('gsdc_sheet_id', '') !== '' ? '設定画面' : 'wp-config定数';
    $output .= '<p>APIキー: 設定済み（' . esc_html($src_api) . '）</p>';
    $output .= '<p>シートID: 設定済み（' . esc_html($src_sheet) . '）</p>';
    $output .= '<p>テストセル: <code>' . esc_html($cell_ref) . '</code></p>';

    $value = GSDC_Cache::get_cell_value($cell_ref);

    if ($value === null) {
        $output .= '<p style="color:red;">エラー: セルを取得できませんでした（参照の書式・タブ名・行名/列名を確認してください）</p>';
    } elseif ($value === '') {
        $output .= '<p style="color:orange;">セルは空です</p>';
    } else {
        $output .= '<p style="color:green;font-weight:bold;">取得成功</p>';
        $output .= '<p>セル値: <strong style="font-size:1.2em;color:#0073aa;">' . esc_html($value) . '</strong></p>';
    }

    return $output . '</div>';
}
add_shortcode('gsdc_test', 'gsdc_test_shortcode');
