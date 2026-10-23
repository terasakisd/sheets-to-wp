<?php
/**
 * GitHub Releases 経由の自動更新
 *
 * プラグインヘッダの Update URI (github.com) により、WordPress 5.8+ が
 * `update_plugins_github.com` フィルタで更新情報を問い合わせてくる。
 * GitHub の最新リリースを確認し、新しいバージョンがあれば
 * 管理画面の「更新」から通常のプラグインと同じように更新できる。
 *
 * リリース手順:
 *   1. バージョンを上げて main に push
 *   2. `v1.4.1` のようなタグを push（GitHub Actions が sheets-to-wp.zip を添付）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_Updater {

    const CACHE_KEY = 'gsdc_update_check';
    const CACHE_TTL = 21600; // 6 時間

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!self::repo_configured()) {
            return;
        }
        add_filter('update_plugins_github.com',   array($this, 'check_update'), 10, 3);
        add_filter('upgrader_source_selection',   array($this, 'fix_source_dir'), 10, 4);
        add_action('upgrader_process_complete',   array($this, 'purge_cache'), 10, 2);
    }

    private static function repo_configured() {
        return defined('GSDC_GITHUB_REPO')
            && GSDC_GITHUB_REPO !== ''
            && strpos(GSDC_GITHUB_REPO, 'YOUR_GITHUB_USER') === false
            && preg_match('#^[\w.-]+/[\w.-]+$#', GSDC_GITHUB_REPO);
    }

    /**
     * WordPress からの更新問い合わせに応答
     *
     * @param array|false $update      既に見つかっている更新情報
     * @param array       $plugin_data プラグインヘッダ情報
     * @param string      $plugin_file プラグインのベース名
     * @return array|false
     */
    public function check_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== plugin_basename(GSDC_PLUGIN_FILE)) {
            return $update;
        }

        $release = $this->get_latest_release();
        if ($release === null) {
            return $update;
        }

        if (version_compare($release['version'], GSDC_VERSION, '<=')) {
            return $update;
        }

        return array(
            'slug'    => 'sheets-to-wp',
            'version' => $release['version'],
            'url'     => 'https://github.com/' . GSDC_GITHUB_REPO,
            'package' => $release['package'],
        );
    }

    /**
     * 最新リリース情報を取得（6 時間キャッシュ）
     *
     * @return array|null ['version' => '1.4.1', 'package' => zip URL]
     */
    private function get_latest_release() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['version'], $cached['package'])) {
            return $cached;
        }
        if ($cached === 'error') {
            return null; // 直近の失敗を短期間リトライしない
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . GSDC_GITHUB_REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'sheets-to-wp-updater',
                ),
            )
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, 'error', 600);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            set_transient(self::CACHE_KEY, 'error', 600);
            return null;
        }

        $version = ltrim((string) $data['tag_name'], 'vV');

        // リリースアセットの sheets-to-wp.zip を優先、無ければソース zip
        $package = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name'], $asset['browser_download_url'])
                    && strtolower($asset['name']) === 'sheets-to-wp.zip') {
                    $package = (string) $asset['browser_download_url'];
                    break;
                }
            }
        }
        if ($package === '' && !empty($data['zipball_url'])) {
            $package = (string) $data['zipball_url'];
        }
        if ($package === '') {
            set_transient(self::CACHE_KEY, 'error', 600);
            return null;
        }

        $release = array('version' => $version, 'package' => $package);
        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    /**
     * 展開フォルダ名を `sheets-to-wp` に揃える
     *
     * GitHub のソース zip は `owner-repo-abc1234/` のようなフォルダ名で
     * 展開されるため、そのままだと別プラグイン扱いになってしまう。
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = array()) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(GSDC_PLUGIN_FILE)) {
            return $source;
        }

        global $wp_filesystem;
        $desired = trailingslashit($remote_source) . 'sheets-to-wp/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem && $wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired))) {
            return $desired;
        }
        return new WP_Error('gsdc_rename_failed', '更新パッケージのフォルダ名を変更できませんでした。');
    }

    /**
     * 更新完了後にチェック結果のキャッシュを破棄
     */
    public function purge_cache($upgrader, $hook_extra) {
        if (isset($hook_extra['type']) && $hook_extra['type'] === 'plugin') {
            delete_transient(self::CACHE_KEY);
        }
    }
}
