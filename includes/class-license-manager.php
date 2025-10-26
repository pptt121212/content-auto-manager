<?php
/**
 * 授权管理类
 * 
 * @package ContentAutoManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_License_Manager {
    
    const LICENSE_SERVER_URL = 'https://key.kdjingpai.com/api.php';
    const LICENSE_OPTION = 'content_auto_manager_license_data';
    const PUBLIC_KEY_FILE = 'public_key.pem';
    
    /**
     * 初始化授权管理器
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'add_license_settings'));
        add_action('admin_notices', array(__CLASS__, 'license_admin_notice'));
    }
    
    /**
     * 检查授权是否有效
     */
    public static function is_license_active() {
        $license_data = get_option(self::LICENSE_OPTION);
        
        if (!isset($license_data['status']) || $license_data['status'] !== 'valid') {
            return false;
        }
        
        if (!isset($license_data['verified_by_official']) || $license_data['verified_by_official'] !== true) {
            return false;
        }
        
        $required_fields = array('status', 'domain', 'last_validated', 'verified_by_official');
        foreach ($required_fields as $field) {
            if (!isset($license_data[$field])) {
                return false;
            }
        }
        
        $current_time = time();
        $last_validated = isset($license_data['last_validated']) ? $license_data['last_validated'] : 0;
        if ($last_validated > $current_time || ($current_time - $last_validated) > 365 * 24 * 60 * 60) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证授权完整性
     */
    public static function verify_license_integrity() {
        $license_data = get_option(self::LICENSE_OPTION);
        
        if (!is_array($license_data) || empty($license_data)) {
            return false;
        }
        
        $required_fields = array('status', 'verified_by_official');
        foreach ($required_fields as $field) {
            if (!isset($license_data[$field])) {
                return false;
            }
        }
        
        if ($license_data['status'] === 'valid' && $license_data['verified_by_official'] !== true) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 规范化域名
     */
    public static function normalize_domain($domain) {
        if (!is_string($domain)) {
            return '';
        }
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
        $domain = rtrim($domain, '/');
        
        if (empty($domain) || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return '';
        }
        
        return $domain;
    }
    
    /**
     * 激活授权码
     */
    public static function activate_license($license_key) {
        $url = self::LICENSE_SERVER_URL;
        $domain = self::normalize_domain(home_url());
        
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        
        // 验证服务器域名
        $host = parse_url($url, PHP_URL_HOST);
        $valid_domains = array(base64_decode('a2V5LmtkamluZ3BhaS5jb20='));
        
        $is_valid_host = false;
        foreach ($valid_domains as $valid_domain) {
            if ($host === $valid_domain) {
                $is_valid_host = true;
                break;
            }
        }
        
        if (!$is_valid_host) {
            $error_msg = base64_decode('5omL5bel6aSo5omL6KGM5bqm5Y+w5q2j5paH5pys');
            add_settings_error('content_auto_manager_license', 'license_error', $error_msg);
            update_option(self::LICENSE_OPTION, array(
                'status' => base64_decode('aW52YWxpZF9zZXJ2ZXI='),
                'message' => base64_decode('6aSo5omL6KGM5aSE55CG'),
                'verified_by_official' => false
            ));
            return;
        }
        
        // 发送授权请求
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body' => array(
                'license_key' => $license_key,
                'domain'      => $domain,
            ),
        ));
        
        if (is_wp_error($response)) {
            add_settings_error('content_auto_manager_license', 'license_error', '无法连接到授权服务器: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (!$data || !isset($data->payload) || !isset($data->signature)) {
            add_settings_error('content_auto_manager_license', 'license_error', '授权服务器返回了无效的响应。');
            return;
        }
        
        // 验证签名
        $public_key_path = dirname(__FILE__) . '/' . self::PUBLIC_KEY_FILE;
        if (!file_exists($public_key_path)) {
            add_settings_error('content_auto_manager_license', 'license_error', '插件文件不完整：缺少 public_key.pem。');
            update_option(self::LICENSE_OPTION, array('status' => 'error', 'message' => '缺少公钥'));
            return;
        }
        
        $public_key = file_get_contents($public_key_path);
        $payload_json = base64_decode($data->payload);
        $signature = base64_decode($data->signature);
        
        $is_valid_signature = openssl_verify($payload_json, $signature, $public_key, OPENSSL_ALGO_SHA256) === 1;
        
        if (!$is_valid_signature) {
            add_settings_error('content_auto_manager_license', 'license_error', '授权签名验证失败！响应可能被篡改。');
            update_option(self::LICENSE_OPTION, array('status' => 'tampered', 'message' => '签名验证失败'));
            return;
        }
        
        // 保存授权数据
        $payload = json_decode($payload_json, true);
        $payload['last_validated'] = time();
        $payload['verified_by_official'] = true;
        update_option(self::LICENSE_OPTION, $payload);
        
        if ($payload['status'] === 'valid') {
            add_settings_error('content_auto_manager_license', 'license_success', '授权成功！' . $payload['message'], 'success');
        } else {
            add_settings_error('content_auto_manager_license', 'license_fail', '授权失败：' . $payload['message'], 'error');
        }
    }
    
    /**
     * 添加授权设置到发布规则页面
     */
    public static function add_license_settings() {
        // 这个方法会在发布规则页面调用
    }
    
    /**
     * 显示授权状态通知
     */
    public static function license_admin_notice() {
        if (!self::is_license_active() && current_user_can('manage_options')) {
            $screen = get_current_screen();
            if ($screen && strpos($screen->id, 'content-auto-manager') !== false) {
                $license_data = get_option(self::LICENSE_OPTION);
                $message = isset($license_data['message']) ? $license_data['message'] : '未激活或已失效';
                echo '<div class="notice notice-error"><p><strong>内容自动生成管家：</strong>授权无效或未激活，发布规则功能受限。请输入有效的授权码。</p></div>';
            }
        }
    }
    
    /**
     * 渲染授权码输入框
     */
    public static function render_license_field() {
        $license_key = get_option('content_auto_manager_license_key', '');
        $license_data = get_option(self::LICENSE_OPTION);
        
        echo '<tr>';
        echo '<th scope="row">' . __('插件授权码', 'content-auto-manager') . '</th>';
        echo '<td>';
        echo '<input type="text" name="content_auto_manager_license_key" class="regular-text" value="' . esc_attr($license_key) . '" placeholder="CMT-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" />';
        echo '<p class="description">' . __('请输入授权码以解锁发布规则配置功能。', 'content-auto-manager') . '</p>';
        
        if (self::is_license_active()) {
            echo '<p style="color: green; font-weight: bold;">授权状态：有效 (激活于域名: ' . esc_html($license_data['domain']) . ')</p>';
        } else {
            $message = isset($license_data['message']) ? $license_data['message'] : '未激活或已失效';
            echo '<p style="color: red; font-weight: bold;">授权状态：无效 (' . esc_html($message) . ')</p>';
        }
        
        echo '</td>';
        echo '</tr>';
    }
}

// 初始化授权管理器
ContentAuto_License_Manager::init();