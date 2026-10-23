<?php
/**
 * Google Sheets API 連携クラス（タブ単位取得）
 *
 * 取得粒度を「セル」ではなく「タブ」にしたことで、
 * API コール数は タブ数 / TTL のオーダーに縮む。
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_API {

    const API_ENDPOINT = 'https://sheets.googleapis.com/v4/spreadsheets/';
    const HTTP_TIMEOUT = 15;

    /**
     * 資格情報（管理画面設定 > wp-config 定数）
     */
    private static function credentials() {
        return array(
            'api_key'  => function_exists('gsdc_get_api_key')  ? gsdc_get_api_key()  : (string) get_option('gsdc_api_key', ''),
            'sheet_id' => function_exists('gsdc_get_sheet_id') ? gsdc_get_sheet_id() : (string) get_option('gsdc_sheet_id', ''),
        );
    }

    /**
     * タブ全体の値を取得
     *
     * @param string $tab_name
     * @return array|false 2 次元配列 or 失敗時 false
     */
    public static function fetch_tab($tab_name) {
        $c = self::credentials();
        if ($c['api_key'] === '' || $c['sheet_id'] === '' || $tab_name === '') {
            return false;
        }

        // タブ名に特殊文字が含まれる場合はシングルクオートで囲む
        $range = self::quote_tab_name($tab_name);

        $url = self::API_ENDPOINT . rawurlencode($c['sheet_id']) . '/values/' . rawurlencode($range)
             . '?key=' . rawurlencode($c['api_key']);

        $response = self::http_get($url);
        if (is_wp_error($response)) {
            self::log('fetch_tab network error: ' . $response->get_error_message());
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);

        if ($status !== 200) {
            self::log('fetch_tab HTTP ' . $status . ' for tab "' . $tab_name . '"');
            return false;
        }

        if (!isset($data['values']) || !is_array($data['values'])) {
            return array();
        }
        return $data['values'];
    }

    /**
     * スプレッドシート内のタブ名一覧を取得
     *
     * @return string[]
     */
    public static function fetch_tab_names() {
        $c = self::credentials();
        if ($c['api_key'] === '' || $c['sheet_id'] === '') {
            return array();
        }

        $url = self::API_ENDPOINT . rawurlencode($c['sheet_id'])
             . '?fields=' . rawurlencode('sheets.properties.title')
             . '&key=' . rawurlencode($c['api_key']);

        $response = self::http_get($url);
        if (is_wp_error($response)) {
            self::log('fetch_tab_names network error: ' . $response->get_error_message());
            return array();
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            self::log('fetch_tab_names HTTP ' . $status);
            return array();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $names = array();
        if (isset($data['sheets']) && is_array($data['sheets'])) {
            foreach ($data['sheets'] as $sheet) {
                if (isset($sheet['properties']['title'])) {
                    $names[] = (string) $sheet['properties']['title'];
                }
            }
        }
        return $names;
    }

    /**
     * 接続テスト（option を書き換えずに実行可能）
     */
    public static function test_connection($api_key = null, $sheet_id = null) {
        if ($api_key === null)  { $api_key  = (string) get_option('gsdc_api_key', ''); }
        if ($sheet_id === null) { $sheet_id = (string) get_option('gsdc_sheet_id', ''); }

        if ($api_key === '') {
            return array('success' => false, 'message' => 'APIキーが設定されていません。');
        }
        if ($sheet_id === '') {
            return array('success' => false, 'message' => 'スプレッドシートIDが設定されていません。');
        }

        // メタデータ取得（タブ名一覧）で接続確認
        $url = self::API_ENDPOINT . rawurlencode($sheet_id)
             . '?fields=' . rawurlencode('properties.title,sheets.properties.title')
             . '&key=' . rawurlencode($api_key);

        $response = self::http_get($url);
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'ネットワークエラー: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);

        if ($status !== 200) {
            $msg = 'HTTPエラー ' . $status;
            if (isset($data['error']['message'])) {
                $msg .= ': ' . $data['error']['message'];
            }
            if ($status === 403)      { $msg .= ' - APIキーが無効か、Google Sheets APIが有効化されていません。'; }
            elseif ($status === 404)  { $msg .= ' - スプレッドシートが見つかりません。IDを確認してください。'; }
            elseif ($status === 400)  { $msg .= ' - リクエストが無効です。'; }
            elseif ($status === 429)  { $msg .= ' - APIリクエスト上限です。しばらく待って再試行してください。'; }

            return array(
                'success' => false,
                'message' => $msg,
                'debug'   => array(
                    'status_code' => $status,
                    'url'         => str_replace($api_key, 'API_KEY_HIDDEN', $url),
                    'response'    => $data,
                ),
            );
        }

        $tab_names = array();
        if (isset($data['sheets']) && is_array($data['sheets'])) {
            foreach ($data['sheets'] as $sheet) {
                if (isset($sheet['properties']['title'])) {
                    $tab_names[] = $sheet['properties']['title'];
                }
            }
        }

        $title = isset($data['properties']['title']) ? $data['properties']['title'] : '';
        $msg   = '接続成功！';
        if ($title !== '') {
            $msg .= ' シート: "' . $title . '"';
        }
        if (!empty($tab_names)) {
            $msg .= ' / タブ: ' . implode(', ', $tab_names);
        }
        return array('success' => true, 'message' => $msg, 'tabs' => $tab_names);
    }

    // -------- 内部 --------

    private static function http_get($url) {
        return wp_remote_get($url, array(
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => array('Accept' => 'application/json'),
        ));
    }

    private static function quote_tab_name($tab) {
        // 英数字と `_` のみならクオートなしで通る
        if (preg_match('/^[A-Za-z0-9_]+$/', $tab)) {
            return $tab;
        }
        return "'" . str_replace("'", "''", $tab) . "'";
    }

    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GSDC API: ' . $message);
        }
    }
}
