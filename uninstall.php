<?php
/**
 * プラグイン削除時のクリーンアップ
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 設定オプションを削除
$options = array(
    'gsdc_api_key',
    'gsdc_sheet_id',
    'gsdc_cache_duration',
    'gsdc_auto_update',
    'gsdc_update_frequency',
    'gsdc_last_update',
    'gsdc_cached_tabs',
    'gsdc_default_tab',
    // 旧バージョン（1.0.x / 1.1.x）のデータもあれば掃除
    'gsdc_used_cells',
);
foreach ($options as $option) {
    delete_option($option);
}

// Transient をすべて削除
global $wpdb;
$patterns = array(
    '_transient_gsdc_sheet_%',
    '_transient_timeout_gsdc_sheet_%',
    '_transient_gsdc_err_%',
    '_transient_timeout_gsdc_err_%',
    // 旧バージョンのセル単位 / 範囲単位キャッシュ
    '_transient_gsdc_cell_%',
    '_transient_timeout_gsdc_cell_%',
    '_transient_gsdc_range_%',
    '_transient_timeout_gsdc_range_%',
);
foreach ($patterns as $like) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}

// Cron を削除
wp_clear_scheduled_hook('gsdc_update_cache');
