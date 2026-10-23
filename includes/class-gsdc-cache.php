<?php
/**
 * キャッシュ管理クラス（タブ単位）
 *
 * シート(タブ)ごとに 2 次元配列を 1 つの transient に格納する。
 * セル参照 (A1, Sheet1!A1, 範囲 A1:C10) はこの配列へのインデックス変換で解決する。
 *
 * これにより:
 *  - transient 数はタブ数と等しくなる（セル数に依存しない）
 *  - Google Sheets API のコール数は「タブ数 / TTL」に収まる
 *  - 投稿横断のセル追跡が不要になる
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_Cache {

    /** タブ 2D 配列のキャッシュプレフィックス */
    const TAB_PREFIX = 'gsdc_sheet_';

    /** 読み込み済みタブ名のリスト（管理画面表示・cron 用） */
    const TABS_OPTION = 'gsdc_cached_tabs';

    /** デフォルト（最初の）タブ名のキャッシュ */
    const DEFAULT_TAB_OPTION = 'gsdc_default_tab';

    /**
     * セル値を取得
     *
     * @return string|null 取得できなければ null（デフォルト値へフォールバック想定）
     */
    public static function get_cell_value($cell_ref) {
        list($tab, $a1) = self::parse_ref($cell_ref);

        $tab_data = self::get_tab_data($tab);
        if ($tab_data === null) {
            return null;
        }

        $indices = self::a1_to_indices($a1);
        if ($indices === null) {
            // A1 形式でなければ「行名.列名」の名前参照として解決を試みる
            $indices = self::named_to_indices($tab_data, $a1);
        }
        if ($indices === null) {
            return null;
        }
        list($row, $col) = $indices;

        if (isset($tab_data[$row][$col])) {
            return (string) $tab_data[$row][$col];
        }
        // 範囲外・空セル：空文字として扱う
        return '';
    }

    /**
     * 範囲の値を取得
     *
     * @return array|null 2 次元配列、取得失敗時 null
     */
    public static function get_range_value($range_ref) {
        list($tab, $range_a1) = self::parse_ref($range_ref);

        $tab_data = self::get_tab_data($tab);
        if ($tab_data === null) {
            return null;
        }

        $parts = explode(':', $range_a1);
        if (count($parts) === 1) {
            $idx = self::a1_to_indices($parts[0]);
            if ($idx === null) {
                return null;
            }
            $start = $end = $idx;
        } elseif (count($parts) === 2) {
            $start = self::a1_to_indices($parts[0]);
            $end   = self::a1_to_indices($parts[1]);
            if ($start === null || $end === null) {
                return null;
            }
        } else {
            return null;
        }

        list($r1, $c1) = $start;
        list($r2, $c2) = $end;
        if ($r1 > $r2) { list($r1, $r2) = array($r2, $r1); }
        if ($c1 > $c2) { list($c1, $c2) = array($c2, $c1); }

        $result = array();
        for ($r = $r1; $r <= $r2; $r++) {
            $row = array();
            for ($c = $c1; $c <= $c2; $c++) {
                $row[] = isset($tab_data[$r][$c]) ? (string) $tab_data[$r][$c] : '';
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * タブのデータを取得（必要なら API からフェッチしてキャッシュ）
     *
     * @return array|null 2 次元配列、取得失敗時 null
     */
    public static function get_tab_data($tab_name) {
        $tab_name = (string) $tab_name;
        if ($tab_name === '') {
            $tab_name = (string) self::get_default_tab_name();
            if ($tab_name === '') {
                return null;
            }
        }

        // キャッシュヒット
        $cached = get_transient(self::tab_cache_key($tab_name));
        if ($cached !== false) {
            return is_array($cached) ? $cached : array();
        }

        // 直近でエラーだったタブは短期間リトライしない
        if (get_transient(self::tab_err_key($tab_name))) {
            return null;
        }

        $data = GSDC_API::fetch_tab($tab_name);
        if ($data === false) {
            set_transient(self::tab_err_key($tab_name), 1, 60);
            return null;
        }

        self::set_tab_data($tab_name, $data);
        return $data;
    }

    /**
     * タブデータを強制的に再フェッチ
     */
    public static function refresh_tab($tab_name) {
        delete_transient(self::tab_err_key($tab_name));
        $data = GSDC_API::fetch_tab($tab_name);
        if ($data === false) {
            return false;
        }
        self::set_tab_data($tab_name, $data);
        return true;
    }

    /**
     * タブ 2D データを保存
     */
    public static function set_tab_data($tab_name, array $data) {
        self::mark_tab_as_used($tab_name);
        return set_transient(self::tab_cache_key($tab_name), $data, self::duration());
    }

    /**
     * デフォルト（最初の）タブ名を取得
     */
    public static function get_default_tab_name() {
        $cached = (string) get_option(self::DEFAULT_TAB_OPTION, '');
        if ($cached !== '') {
            return $cached;
        }

        $tabs = GSDC_API::fetch_tab_names();
        if (!empty($tabs)) {
            update_option(self::DEFAULT_TAB_OPTION, $tabs[0], false);
            return $tabs[0];
        }
        return '';
    }

    /**
     * すべてのキャッシュを削除
     *
     * @return int 削除された DB 行の合計（value + timeout 行）
     */
    public static function clear_all_cache() {
        global $wpdb;

        $patterns = array(
            '_transient_' . self::TAB_PREFIX . '%',
            '_transient_timeout_' . self::TAB_PREFIX . '%',
            '_transient_gsdc_err_%',
            '_transient_timeout_gsdc_err_%',
        );

        $deleted = 0;
        foreach ($patterns as $like) {
            $deleted += (int) $wpdb->query(
                $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
            );
        }

        // 既知タブリストとデフォルトタブは残す（再取得で復元される／使い回しで API 節約）
        return $deleted;
    }

    /**
     * 既知タブをリセット（アンインストール用・強制再同期用）
     */
    public static function forget_known_tabs() {
        delete_option(self::TABS_OPTION);
        delete_option(self::DEFAULT_TAB_OPTION);
    }

    /**
     * 読み込み済みタブ名一覧を取得
     *
     * @return string[]
     */
    public static function get_known_tabs() {
        $tabs = get_option(self::TABS_OPTION, array());
        return is_array($tabs) ? array_values(array_unique($tabs)) : array();
    }

    /**
     * タブごとのキャッシュ状態を返す（管理画面用）
     *
     * @return array [ ['name'=>..., 'cached'=>bool, 'rows'=>int, 'cols'=>int], ... ]
     */
    public static function get_tab_states() {
        $tabs = self::get_known_tabs();
        $states = array();
        foreach ($tabs as $tab) {
            $cached = get_transient(self::tab_cache_key($tab));
            if ($cached === false || !is_array($cached)) {
                $states[] = array('name' => $tab, 'cached' => false, 'rows' => 0, 'cols' => 0);
            } else {
                $rows = count($cached);
                $cols = 0;
                foreach ($cached as $row) {
                    if (is_array($row) && count($row) > $cols) {
                        $cols = count($row);
                    }
                }
                $states[] = array('name' => $tab, 'cached' => true, 'rows' => $rows, 'cols' => $cols);
            }
        }
        return $states;
    }

    // -------- 内部ヘルパ --------

    /**
     * セル/範囲参照を (タブ名, A1 記法) に分解
     *
     * 例:
     *   "A1"           → ["", "A1"]
     *   "Sheet1!A1"    → ["Sheet1", "A1"]
     *   "'My Sheet'!B2" → ["My Sheet", "B2"]
     *   "Sheet1!A1:C3" → ["Sheet1", "A1:C3"]
     */
    private static function parse_ref($ref) {
        $ref = (string) $ref;
        if (strpos($ref, '!') === false) {
            return array('', trim($ref));
        }
        list($tab, $a1) = explode('!', $ref, 2);
        $tab = trim($tab);
        // シングルクオートで囲まれたタブ名（スペースや特殊文字含む場合）を外す
        if (strlen($tab) >= 2 && $tab[0] === "'" && substr($tab, -1) === "'") {
            $tab = substr($tab, 1, -1);
            $tab = str_replace("''", "'", $tab);
        }
        return array($tab, trim($a1));
    }

    /**
     * 名前参照「行名.列名」→ [row_index, col_index]（0 始まり）
     *
     * 1 行目を列見出し、A 列を行見出しとして探す。
     * 例: "プロミス.審査時間" → A列が「プロミス」の行 × 1行目が「審査時間」の列
     *
     * 名前自体に「.」が含まれる場合に備え、すべての区切り位置を試し、
     * 行・列の両方が見つかった最初の組み合わせを採用する。
     */
    private static function named_to_indices(array $tab_data, $name_ref) {
        $name_ref = (string) $name_ref;
        if ($name_ref === '' || strpos($name_ref, '.') === false || empty($tab_data)) {
            return null;
        }

        $pos = -1;
        while (($pos = strpos($name_ref, '.', $pos + 1)) !== false) {
            $row_name = trim(substr($name_ref, 0, $pos));
            $col_name = trim(substr($name_ref, $pos + 1));
            if ($row_name === '' || $col_name === '') {
                continue;
            }

            $col = self::find_header_col($tab_data, $col_name);
            $row = self::find_label_row($tab_data, $row_name);
            if ($row !== null && $col !== null) {
                return array($row, $col);
            }
        }
        return null;
    }

    /**
     * 1 行目（見出し行）から列名を探す
     */
    private static function find_header_col(array $tab_data, $col_name) {
        if (!isset($tab_data[0]) || !is_array($tab_data[0])) {
            return null;
        }
        foreach ($tab_data[0] as $c => $cell) {
            if (trim((string) $cell) === $col_name) {
                return (int) $c;
            }
        }
        return null;
    }

    /**
     * A 列（見出し列）から行名を探す（1 行目の見出し行は除く）
     */
    private static function find_label_row(array $tab_data, $row_name) {
        $count = count($tab_data);
        for ($r = 1; $r < $count; $r++) {
            $cell = isset($tab_data[$r][0]) ? $tab_data[$r][0] : '';
            if (trim((string) $cell) === $row_name) {
                return $r;
            }
        }
        return null;
    }

    /**
     * A1 記法 → [row_index, col_index]（0 始まり）
     */
    private static function a1_to_indices($a1) {
        if (!is_string($a1) || !preg_match('/^([A-Za-z]+)([0-9]+)$/', $a1, $m)) {
            return null;
        }
        $col = 0;
        $letters = strtoupper($m[1]);
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }
        return array((int) $m[2] - 1, $col - 1);
    }

    private static function tab_cache_key($tab_name) {
        $sheet_id = function_exists('gsdc_get_sheet_id') ? gsdc_get_sheet_id() : (string) get_option('gsdc_sheet_id', '');
        return self::TAB_PREFIX . md5($sheet_id . '|' . $tab_name);
    }

    private static function tab_err_key($tab_name) {
        $sheet_id = function_exists('gsdc_get_sheet_id') ? gsdc_get_sheet_id() : (string) get_option('gsdc_sheet_id', '');
        return 'gsdc_err_' . md5($sheet_id . '|' . $tab_name);
    }

    private static function duration() {
        $d = (int) get_option('gsdc_cache_duration', 3600);
        return $d > 60 ? $d : 3600;
    }

    private static function mark_tab_as_used($tab_name) {
        $tabs = get_option(self::TABS_OPTION, array());
        if (!is_array($tabs)) {
            $tabs = array();
        }
        if (!in_array($tab_name, $tabs, true)) {
            $tabs[] = $tab_name;
            update_option(self::TABS_OPTION, $tabs, false);
        }
    }
}
