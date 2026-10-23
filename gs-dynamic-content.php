<?php
/**
 * Plugin Name: Sheets to WP
 * Description: Google スプレッドシートのセルを記事内に埋め込み、シート更新で全記事を自動更新
 * Version: 1.4.1
 * Author: Mikuru Terasaki
 * License: GPL v2 or later
 * Text Domain: sheets-to-wp
 * Update URI: https://github.com/terasakisd/sheets-to-wp
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GSDC_VERSION', '1.4.1');
define('GSDC_PLUGIN_FILE', __FILE__);
define('GSDC_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('GSDC_PLUGIN_URL',  plugin_dir_url(__FILE__));

/* 自動更新の取得元 GitHub リポジトリ（owner/repo） */
define('GSDC_GITHUB_REPO', 'terasakisd/sheets-to-wp');

/**
 * 有効な APIキーを返す（設定画面 > wp-config 定数）
 *
 * wp-config.php に定義しておくと、プラグイン更新後も設定が消えない:
 *   define('GSDC_API_KEY',  '...');
 *   define('GSDC_SHEET_ID', '...');
 */
function gsdc_get_api_key() {
    $v = (string) get_option('gsdc_api_key', '');
    if ($v !== '') {
        return $v;
    }
    if (defined('GSDC_API_KEY') && GSDC_API_KEY !== '') {
        return (string) GSDC_API_KEY;
    }
    return '';
}

/**
 * 有効なスプレッドシートIDを返す（設定画面 > wp-config 定数）
 */
function gsdc_get_sheet_id() {
    $v = (string) get_option('gsdc_sheet_id', '');
    if ($v !== '') {
        return $v;
    }
    if (defined('GSDC_SHEET_ID') && GSDC_SHEET_ID !== '') {
        return (string) GSDC_SHEET_ID;
    }
    return '';
}

require_once GSDC_PLUGIN_DIR . 'includes/class-gsdc-settings.php';
require_once GSDC_PLUGIN_DIR . 'includes/class-gsdc-cache.php';
require_once GSDC_PLUGIN_DIR . 'includes/class-gsdc-api.php';
require_once GSDC_PLUGIN_DIR . 'includes/class-gsdc-shortcode.php';
require_once GSDC_PLUGIN_DIR . 'includes/class-gsdc-updater.php';
require_once GSDC_PLUGIN_DIR . 'includes/test-shortcode.php';
require_once GSDC_PLUGIN_DIR . 'admin/class-gsdc-admin.php';

/**
 * {{gs:A1}} を [gs cell="A1"] へ変換
 */
function gsdc_convert_tags_to_shortcode($content) {
    if (empty($content) || !is_string($content) || strpos($content, '{{gs:') === false) {
        return $content;
    }
    return preg_replace_callback(
        '/\{\{gs:([^\}]+)\}\}/u',
        function ($matches) {
            $cell = trim($matches[1]);
            return '[gs cell="' . esc_attr($cell) . '"]';
        },
        $content
    );
}

add_filter('the_content', 'gsdc_convert_tags_to_shortcode', 1);
add_filter('the_title',   'gsdc_convert_tags_to_shortcode', 1);

add_filter('the_title',         'do_shortcode', 11);
add_filter('single_post_title', 'do_shortcode', 11);
add_filter('widget_title',      'do_shortcode', 11);

/**
 * {{gs:...}} を直接セル値へ解決する
 *
 * 抜粋（excerpt）やテーマ独自の記事一覧など、ショートコードが
 * 実行されない文脈向け。値はショートコードと同様に esc_html して埋め込む。
 */
function gsdc_resolve_tags_to_values($text) {
    if (empty($text) || !is_string($text) || strpos($text, '{{gs:') === false) {
        return $text;
    }
    return preg_replace_callback(
        '/\{\{gs:([^\}]+)\}\}/u',
        function ($matches) {
            $value = GSDC_Cache::get_cell_value(trim($matches[1]));
            return ($value === null) ? '' : esc_html($value);
        },
        $text
    );
}

add_filter('the_excerpt',     'gsdc_resolve_tags_to_values', 1);
add_filter('get_the_excerpt', 'gsdc_resolve_tags_to_values', 20);

// ウィジェット（タイトル・旧テキストウィジェット・ビジュアルテキスト・ブロック）
add_filter('widget_title',         'gsdc_resolve_tags_to_values', 9);
add_filter('widget_text',          'gsdc_resolve_tags_to_values', 9);
add_filter('widget_text_content',  'gsdc_resolve_tags_to_values', 9);
add_filter('widget_block_content', 'gsdc_resolve_tags_to_values', 9);

/**
 * テーマが post_content から直接抜粋を生成する場合への対応
 *
 * フロントエンド表示用に取得された投稿のタグをその場で解決する。
 * 管理画面と REST（ブロックエディタ）では元のタグを保持する。
 */
function gsdc_resolve_tags_in_posts($posts) {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $posts;
    }
    foreach ((array) $posts as $post) {
        if (!is_object($post)) {
            continue;
        }
        if (isset($post->post_content)) {
            $post->post_content = gsdc_resolve_tags_to_values($post->post_content);
        }
        if (isset($post->post_excerpt)) {
            $post->post_excerpt = gsdc_resolve_tags_to_values($post->post_excerpt);
        }
        if (isset($post->post_title)) {
            $post->post_title = gsdc_resolve_tags_to_values($post->post_title);
        }
    }
    return $posts;
}
add_filter('the_posts', 'gsdc_resolve_tags_in_posts', 5);

/**
 * WP コアに無い `weekly` を追加
 */
function gsdc_register_cron_schedules($schedules) {
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => '週1回',
        );
    }
    return $schedules;
}
add_filter('cron_schedules', 'gsdc_register_cron_schedules');

/**
 * 有効化
 */
function gsdc_activate() {
    add_option('gsdc_cache_duration',   3600);
    add_option('gsdc_auto_update',      'yes');
    add_option('gsdc_update_frequency', 'hourly');

    if (get_option('gsdc_auto_update', 'yes') === 'yes' && !wp_next_scheduled('gsdc_update_cache')) {
        wp_schedule_event(time(), (string) get_option('gsdc_update_frequency', 'hourly'), 'gsdc_update_cache');
    }
}
register_activation_hook(__FILE__, 'gsdc_activate');

/**
 * 無効化
 */
function gsdc_deactivate() {
    wp_clear_scheduled_hook('gsdc_update_cache');
}
register_deactivation_hook(__FILE__, 'gsdc_deactivate');

/**
 * Cron による自動更新
 *
 * 既知タブをすべて再フェッチしてキャッシュを差し替える。
 * タブ数ぶんの API コールで済むため、セル数に関わらず軽量。
 */
function gsdc_update_all_cache() {
    if (get_option('gsdc_auto_update') !== 'yes') {
        return;
    }
    $tabs = GSDC_Cache::get_known_tabs();
    if (empty($tabs)) {
        return;
    }
    foreach ($tabs as $tab) {
        GSDC_Cache::refresh_tab($tab);
    }
    update_option('gsdc_last_update', current_time('mysql'), false);
}
add_action('gsdc_update_cache', 'gsdc_update_all_cache');

/**
 * 初期化
 */
function gsdc_init() {
    GSDC_Settings::get_instance();
    GSDC_Shortcode::get_instance();
    GSDC_Updater::get_instance();
    if (is_admin()) {
        GSDC_Admin::get_instance();
    }
}
add_action('plugins_loaded', 'gsdc_init');
