<?php
/**
 * 管理画面クラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',                      array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts',           array($this, 'enqueue_admin_scripts'));
        add_action('admin_post_gsdc_save_settings',   array($this, 'handle_save_settings'));

        add_action('wp_ajax_gsdc_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_gsdc_clear_cache',     array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_gsdc_refresh_tabs',    array($this, 'ajax_refresh_tabs'));
        add_action('wp_ajax_gsdc_sync_tab_list',   array($this, 'ajax_sync_tab_list'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'Sheets to WP', 'Sheets to WP', 'manage_options',
            'gs-dynamic-content', array($this, 'render_main_page'),
            'dashicons-media-spreadsheet', 30
        );
        add_submenu_page('gs-dynamic-content', '設定',     '設定',     'manage_options', 'gs-dynamic-content', array($this, 'render_main_page'));
        add_submenu_page('gs-dynamic-content', '使用状況', '使用状況', 'manage_options', 'gs-dynamic-usage',   array($this, 'render_usage_page'));
        add_submenu_page('gs-dynamic-content', 'ヘルプ',   'ヘルプ',   'manage_options', 'gs-dynamic-help',    array($this, 'render_help_page'));
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'gs-dynamic') === false) {
            return;
        }
        wp_enqueue_style('gsdc-admin', GSDC_PLUGIN_URL . 'admin/css/admin.css', array(), GSDC_VERSION);
        wp_enqueue_script('gsdc-admin', GSDC_PLUGIN_URL . 'admin/js/admin.js', array('jquery'), GSDC_VERSION, true);
        wp_localize_script('gsdc-admin', 'gsdcAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('gsdc_admin_nonce'),
            'i18n'     => array(
                'confirm_clear'   => 'キャッシュをクリアしますか？\n次回アクセス時に最新データを取得します。',
                'confirm_refresh' => 'すべてのタブを今すぐ再取得しますか？',
                'network_error'   => '通信エラーが発生しました',
            ),
        ));
    }

    /**
     * 設定保存
     */
    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('権限がありません');
        }
        check_admin_referer('gsdc_settings_nonce');

        $api_key          = isset($_POST['gsdc_api_key'])          ? trim(sanitize_text_field(wp_unslash($_POST['gsdc_api_key'])))     : '';
        $sheet_id         = isset($_POST['gsdc_sheet_id'])         ? trim(sanitize_text_field(wp_unslash($_POST['gsdc_sheet_id'])))    : '';
        $cache_duration   = isset($_POST['gsdc_cache_duration'])   ? absint($_POST['gsdc_cache_duration'])                             : 3600;
        $auto_update      = isset($_POST['gsdc_auto_update'])      ? 'yes'                                                             : 'no';
        $update_frequency = isset($_POST['gsdc_update_frequency']) ? sanitize_text_field(wp_unslash($_POST['gsdc_update_frequency'])) : 'hourly';

        $old_sheet_id = gsdc_get_sheet_id();
        update_option('gsdc_api_key',  $api_key);
        update_option('gsdc_sheet_id', $sheet_id);

        // 参照先シートが変わったら古いキャッシュとタブ情報を破棄
        if (gsdc_get_sheet_id() !== $old_sheet_id) {
            GSDC_Cache::clear_all_cache();
            GSDC_Cache::forget_known_tabs();
        }

        if ($cache_duration < 60) {
            $cache_duration = 60;
        }
        $allowed_freq = array('hourly', 'twicedaily', 'daily', 'weekly');
        if (!in_array($update_frequency, $allowed_freq, true)) {
            $update_frequency = 'hourly';
        }

        update_option('gsdc_cache_duration',   $cache_duration);
        update_option('gsdc_auto_update',      $auto_update);
        update_option('gsdc_update_frequency', $update_frequency);

        wp_clear_scheduled_hook('gsdc_update_cache');
        if ($auto_update === 'yes') {
            wp_schedule_event(time(), $update_frequency, 'gsdc_update_cache');
        }

        wp_safe_redirect(add_query_arg(
            array('page' => 'gs-dynamic-content', 'gsdc_saved' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * メイン設定ページ
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!empty($_GET['gsdc_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>';
        }

        $has_api      = gsdc_get_api_key()  !== '';
        $has_sheet    = gsdc_get_sheet_id() !== '';
        $opt_api      = (string) get_option('gsdc_api_key', '');
        $opt_sheet    = (string) get_option('gsdc_sheet_id', '');
        $const_api    = defined('GSDC_API_KEY')  && GSDC_API_KEY  !== '';
        $const_sheet  = defined('GSDC_SHEET_ID') && GSDC_SHEET_ID !== '';
        $cache_duration   = (int)    get_option('gsdc_cache_duration', 3600);
        $auto_update      = (string) get_option('gsdc_auto_update', 'yes');
        $update_frequency = (string) get_option('gsdc_update_frequency', 'hourly');
        $last_update      = (string) get_option('gsdc_last_update', '');

        $frequencies = array(
            'hourly'     => '1時間ごと',
            'twicedaily' => '1日2回',
            'daily'      => '毎日',
            'weekly'     => '毎週',
        );
        ?>
        <div class="wrap">
            <h1>Sheets to WP 設定</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gsdc_save_settings">
                <?php wp_nonce_field('gsdc_settings_nonce'); ?>

                <h2>Google Sheets 接続</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gsdc_api_key">Google API キー</label></th>
                        <td>
                            <input type="password" id="gsdc_api_key" name="gsdc_api_key"
                                   value="<?php echo esc_attr($opt_api); ?>" class="regular-text" autocomplete="off"
                                   <?php if ($opt_api === '' && $const_api) echo 'placeholder="wp-config の GSDC_API_KEY を使用中"'; ?>>
                            <p class="description">
                                空欄の場合は wp-config.php の <code>GSDC_API_KEY</code> 定数が使われます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gsdc_sheet_id">スプレッドシート ID</label></th>
                        <td>
                            <input type="text" id="gsdc_sheet_id" name="gsdc_sheet_id"
                                   value="<?php echo esc_attr($opt_sheet); ?>" class="regular-text" autocomplete="off"
                                   <?php if ($opt_sheet === '' && $const_sheet) echo 'placeholder="wp-config の GSDC_SHEET_ID を使用中"'; ?>>
                            <p class="description">
                                シートの URL <code>https://docs.google.com/spreadsheets/d/<strong>ここの部分</strong>/edit</code>。
                                空欄の場合は wp-config.php の <code>GSDC_SHEET_ID</code> 定数が使われます。
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">接続状態</th>
                        <td>
                            <?php if ($has_api && $has_sheet): ?>
                                <p><strong style="color:#00a32a;">● 接続設定済み</strong></p>
                            <?php else: ?>
                                <p><strong style="color:#d63638;">● 未設定</strong>：API キーとスプレッドシート ID を入力してください（ヘルプページ参照）。</p>
                            <?php endif; ?>
                            <button type="button" id="gsdc_test_connection" class="button button-secondary">接続テスト</button>
                            <span id="gsdc_test_result"></span>
                        </td>
                    </tr>
                </table>

                <h2>キャッシュ・更新設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gsdc_cache_duration">キャッシュ有効期間</label></th>
                        <td>
                            <input type="number" id="gsdc_cache_duration" name="gsdc_cache_duration"
                                   value="<?php echo esc_attr($cache_duration); ?>" min="60" step="60" class="small-text"> 秒
                            <p class="description">
                                最小 60 秒（既定: 3600 = 1時間）。<br>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">自動更新</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gsdc_auto_update" value="yes" <?php checked($auto_update, 'yes'); ?>>
                                有効にする
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gsdc_update_frequency">更新頻度</label></th>
                        <td>
                            <select id="gsdc_update_frequency" name="gsdc_update_frequency">
                                <?php foreach ($frequencies as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($update_frequency, $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                最終更新: <?php echo $last_update ? esc_html($last_update) : 'まだ更新されていません'; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">即時反映</th>
                        <td>
                            <button type="button" id="gsdc_clear_cache" class="button button-primary">キャッシュをクリア</button>
                            <button type="button" id="gsdc_refresh_tabs" class="button">全タブを再取得</button>
                            <p class="description">スプレッドシート更新後、このボタンで即座に反映できます。</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">設定を保存</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * 使用状況ページ
     */
    public function render_usage_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $states      = GSDC_Cache::get_tab_states();
        $default_tab = (string) get_option('gsdc_default_tab', '');
        ?>
        <div class="wrap">
            <h1>使用状況</h1>

            <div class="gsdc-stats">
                <p>
                    <button type="button" id="gsdc_sync_tab_list" class="button">シートからタブ一覧を再取得</button>
                    <button type="button" id="gsdc_refresh_tabs" class="button">全タブを再取得</button>
                    <button type="button" id="gsdc_clear_cache" class="button">キャッシュをクリア</button>
                </p>

                <h2>キャッシュ中のタブ: <?php echo count($states); ?> 個</h2>

                <?php if ($default_tab !== ''): ?>
                    <p>デフォルト（シート名未指定時のタブ）: <code><?php echo esc_html($default_tab); ?></code></p>
                <?php endif; ?>

                <?php if (!empty($states)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:40%;">タブ名</th>
                                <th style="width:20%;">キャッシュ</th>
                                <th>行数 × 列数</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($states as $s): ?>
                                <tr>
                                    <td><code><?php echo esc_html($s['name']); ?></code></td>
                                    <td>
                                        <?php if ($s['cached']): ?>
                                            <span style="color:#00a32a;">● キャッシュ中</span>
                                        <?php else: ?>
                                            <span style="color:#888;">○ 未キャッシュ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $s['rows']; ?> × <?php echo (int) $s['cols']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>まだタブが記録されていません。</p>
                    <p class="description">
                        記事に <code>{{gs:A1}}</code> や <code>[gs cell="Sheet1!B2"]</code> を配置して表示すると、そのタブが自動的に読み込まれます。
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * ヘルプページ
     */
    public function render_help_page() {
        ?>
        <div class="wrap">
            <h1>Sheets to WP ヘルプ</h1>
            <div class="gsdc-help">
                <h2>使い方</h2>

                <h3>1. Google Sheets API の設定</h3>
                <ol>
                    <li><a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> で API キーを取得</li>
                    <li>Google Sheets API を有効化</li>
                    <li>スプレッドシートを「リンクを知っている全員（閲覧者）」に共有</li>
                    <li>設定ページで API キーとスプレッドシート ID を入力</li>
                </ol>

                <h3>2. 記事内での書き方</h3>
                <h4>方法 1: 短縮記法</h4>
                <pre><code>今年は{{gs:A1}}の目標です
価格は{{gs:B2}}円
{{gs:Sheet1!C3}}</code></pre>

                <h4>方法 2: ショートコード</h4>
                <pre><code>[gs cell="A1"]
[gs_cell sheet="Sheet1"]A1[/gs_cell]
[gs_cell default="データなし"]A1[/gs_cell]
[gs_cell format="currency"]B2[/gs_cell]</code></pre>

                <h4>方法 3: 名前参照（行名.列名）</h4>
                <p>セル番地の代わりに「行名.列名」で指定できます。<strong>1行目を列見出し、A列を行見出し</strong>として使っているシートで有効です。</p>
                <pre><code>{{gs:プロミス.審査時間}}
{{gs:消費者金融!プロミス.審査時間}}
[gs cell="消費者金融!プロミス.審査時間"]</code></pre>
                <table class="widefat striped" style="max-width:500px;margin-bottom:1em;">
                    <thead>
                        <tr><th>サービス名</th><th>審査時間</th><th>金利</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>プロミス</td><td>最短3分</td><td>4.5%〜17.8%</td></tr>
                        <tr><td>アコム</td><td>最短20分</td><td>3.0%〜18.0%</td></tr>
                    </tbody>
                </table>
                <p>上の表なら <code>{{gs:プロミス.審査時間}}</code> → 「最短3分」が表示されます。</p>
                <ul>
                    <li>行の追加・並べ替えをしても参照が壊れません</li>
                    <li>見出しは前後の空白を除いた完全一致で照合されます</li>
                    <li>該当する行名・列名が見つからない場合はデフォルト値（未指定なら空）になります</li>
                </ul>

                <h3>3. フォーマット</h3>
                <ul>
                    <li><code>format="number"</code> - カンマ区切り数値</li>
                    <li><code>format="currency"</code> - ¥記号付き通貨</li>
                    <li><code>format="date"</code> - 日付</li>
                    <li><code>format="datetime"</code> - 日時</li>
                </ul>
            </div>
        </div>
        <?php
    }

    // -------- Ajax --------

    public function ajax_test_connection() {
        check_ajax_referer('gsdc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $api_key  = isset($_POST['api_key'])  ? trim(sanitize_text_field(wp_unslash($_POST['api_key'])))  : '';
        $sheet_id = isset($_POST['sheet_id']) ? trim(sanitize_text_field(wp_unslash($_POST['sheet_id']))) : '';
        // フォームが空なら、管理画面保存値 → プラグイン組み込み値 の順でフォールバック
        if ($api_key === '')  { $api_key  = gsdc_get_api_key(); }
        if ($sheet_id === '') { $sheet_id = gsdc_get_sheet_id(); }

        $result = GSDC_API::test_connection($api_key, $sheet_id);
        if (!empty($result['success'])) {
            wp_send_json_success($result['message']);
        }

        $message = $result['message'];
        if (isset($result['debug']) && defined('WP_DEBUG') && WP_DEBUG) {
            $message .= "\n\nデバッグ情報:\nステータスコード: " . $result['debug']['status_code'];
            $message .= "\nURL: " . $result['debug']['url'];
        }
        wp_send_json_error($message);
    }

    public function ajax_clear_cache() {
        check_ajax_referer('gsdc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }
        GSDC_Cache::clear_all_cache();
        wp_send_json_success('キャッシュをクリアしました');
    }

    /**
     * 既知タブをすべて再フェッチ
     */
    public function ajax_refresh_tabs() {
        check_ajax_referer('gsdc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $tabs = GSDC_Cache::get_known_tabs();
        if (empty($tabs)) {
            wp_send_json_success('対象のタブがありません（記事に {{gs:...}} を配置して表示してください）');
        }

        $ok = 0;
        $ng = 0;
        foreach ($tabs as $tab) {
            if (GSDC_Cache::refresh_tab($tab)) {
                $ok++;
            } else {
                $ng++;
            }
        }
        update_option('gsdc_last_update', current_time('mysql'), false);

        $msg = $ok . '個のタブを更新しました';
        if ($ng > 0) {
            $msg .= '（' . $ng . '件はエラー）';
        }
        wp_send_json_success($msg);
    }

    /**
     * スプレッドシートから最新のタブ名一覧を取ってきて既知タブに反映
     */
    public function ajax_sync_tab_list() {
        check_ajax_referer('gsdc_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません');
        }

        $tabs = GSDC_API::fetch_tab_names();
        if (empty($tabs)) {
            wp_send_json_error('タブ一覧を取得できませんでした。API キーとシート ID を確認してください。');
        }

        // 既知タブを置き換え、デフォルトタブも記録
        update_option(GSDC_Cache::TABS_OPTION, $tabs, false);
        update_option(GSDC_Cache::DEFAULT_TAB_OPTION, $tabs[0], false);

        wp_send_json_success(count($tabs) . '個のタブを登録しました: ' . implode(', ', $tabs));
    }
}
