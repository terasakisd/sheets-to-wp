<?php
/**
 * 設定管理クラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class GSDC_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * 設定を登録
     */
    public function register_settings() {
        // API設定
        register_setting('gsdc_settings', 'gsdc_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        register_setting('gsdc_settings', 'gsdc_sheet_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        // キャッシュ設定
        register_setting('gsdc_settings', 'gsdc_cache_duration', array(
            'type' => 'integer',
            'default' => 3600,
            'sanitize_callback' => 'absint',
        ));
        
        // 自動更新設定
        register_setting('gsdc_settings', 'gsdc_auto_update', array(
            'type' => 'string',
            'default' => 'yes',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
        ));
        
        // 更新頻度設定
        register_setting('gsdc_settings', 'gsdc_update_frequency', array(
            'type' => 'string',
            'default' => 'hourly',
            'sanitize_callback' => array($this, 'sanitize_frequency'),
        ));
    }
    
    /**
     * チェックボックスのサニタイズ
     */
    public function sanitize_checkbox($value) {
        return ($value === 'yes' || $value === '1') ? 'yes' : 'no';
    }
    
    /**
     * 更新頻度のサニタイズ
     */
    public function sanitize_frequency($value) {
        $allowed = array('hourly', 'twicedaily', 'daily', 'weekly');
        return in_array($value, $allowed) ? $value : 'hourly';
    }
    
    /**
     * デフォルト設定を取得
     */
    public static function get_defaults() {
        return array(
            'gsdc_api_key' => '',
            'gsdc_sheet_id' => '',
            'gsdc_cache_duration' => 3600,
            'gsdc_auto_update' => 'yes',
            'gsdc_update_frequency' => 'hourly',
        );
    }
}
