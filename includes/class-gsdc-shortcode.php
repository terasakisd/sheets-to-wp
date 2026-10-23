<?php
/**
 * ショートコード処理クラス（タブ単位キャッシュ対応）
 *
 *   [gs cell="A1"]                    本体（{{gs:A1}} もここに変換される）
 *   [gs_cell]A1[/gs_cell]             エイリアス（後方互換）
 *   [gs_range]A1:B10[/gs_range]       範囲テーブル
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_Shortcode {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('gs',       array($this, 'gs_shortcode'));
        add_shortcode('gs_cell',  array($this, 'gs_cell_shortcode'));
        add_shortcode('gs_range', array($this, 'gs_range_shortcode'));
    }

    public function gs_shortcode($atts) {
        $atts = shortcode_atts(array(
            'cell'    => '',
            'sheet'   => '',
            'default' => '',
            'format'  => '',
        ), $atts, 'gs');

        return $this->render_cell($atts['cell'], $atts);
    }

    public function gs_cell_shortcode($atts, $content = '') {
        $atts = shortcode_atts(array(
            'sheet'   => '',
            'default' => '',
            'format'  => '',
        ), $atts, 'gs_cell');

        return $this->render_cell($content, $atts);
    }

    public function gs_range_shortcode($atts, $content = '') {
        $atts = shortcode_atts(array(
            'class'  => 'gsdc-table',
            'header' => 'false',
            'sheet'  => '',
        ), $atts, 'gs_range');

        $range = trim((string) $content);
        if ($range === '') {
            return '';
        }
        if (!empty($atts['sheet']) && strpos($range, '!') === false) {
            $range = $atts['sheet'] . '!' . $range;
        }

        $data = GSDC_Cache::get_range_value($range);
        if (!is_array($data) || empty($data)) {
            return '';
        }

        $has_header = ($atts['header'] === 'true' || $atts['header'] === '1' || $atts['header'] === true);

        $html = '<table class="' . esc_attr($atts['class']) . '">';

        if ($has_header) {
            $header_row = array_shift($data);
            $html .= '<thead><tr>';
            foreach ((array) $header_row as $cell) {
                $html .= '<th>' . esc_html($cell) . '</th>';
            }
            $html .= '</tr></thead>';
        }

        $html .= '<tbody>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ((array) $row as $cell) {
                $html .= '<td>' . esc_html($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function render_cell($cell_ref, $atts) {
        $cell_ref = trim((string) $cell_ref);
        if ($cell_ref === '') {
            return esc_html($atts['default']);
        }
        if (!empty($atts['sheet']) && strpos($cell_ref, '!') === false) {
            $cell_ref = $atts['sheet'] . '!' . $cell_ref;
        }

        $value = GSDC_Cache::get_cell_value($cell_ref);

        if ($value === null || $value === '') {
            return esc_html($atts['default']);
        }

        if (!empty($atts['format'])) {
            $value = $this->apply_format($value, $atts['format']);
        }
        return esc_html($value);
    }

    private function apply_format($value, $format) {
        switch ($format) {
            case 'number':
                return is_numeric($value) ? number_format((float) $value) : $value;

            case 'currency':
                return is_numeric($value) ? '¥' . number_format((float) $value) : $value;

            case 'date':
                $ts = strtotime($value);
                return $ts ? date_i18n(get_option('date_format'), $ts) : $value;

            case 'datetime':
                $ts = strtotime($value);
                return $ts ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $value;

            default:
                return $value;
        }
    }
}
