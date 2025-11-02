<?php
/**
 * 插件官方API渠道类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_OfficialChannel {
    
    /**
     * 获取渠道名称
     */
    public function get_name() {
        return '插件官方API';
    }
    
    /**
     * 获取API URL
     */
    public function get_api_url() {
        return 'https://key.kdjingpai.com/api-proxy.php';
    }
    
    /**
     * 构建请求参数（兼容基类接口）
     */
    public function build_request_params($config, $prompt) {
        // 官方API使用特殊的请求格式
        return array(
            'license_key' => $this->get_license_key(),
            'domain' => $this->get_current_domain(),
            'prompt' => $prompt,
            'action' => 'generate_content'
        );
    }
    
    /**
     * 获取渠道描述
     */
    public function get_description() {
        return '使用插件官方提供的API服务，通过授权码验证使用。如需获取授权码，请联系插件作者微信：qn006699 获取插件授权码后使用。';
    }
    
    /**
     * 检查是否需要API密钥
     */
    public function requires_api_key() {
        return false;
    }
    
    /**
     * 检查是否需要模型名称
     */
    public function requires_model_name() {
        return false;
    }
    
    /**
     * 发送请求到官方API
     */
    public function send_request($config, $prompt) {
        // 获取授权码和域名
        $license_key = $this->get_license_key();
        $domain = $this->get_current_domain();
        
        if (empty($license_key)) {
            return array(
                'success' => false, 
                'message' => '未配置授权码，请在发布规则中设置授权码'
            );
        }
        
        if (empty($domain)) {
            return array(
                'success' => false, 
                'message' => '无法获取当前域名'
            );
        }
        
        // 构建请求数据
        $request_data = array(
            'license_key' => $license_key,
            'domain' => $domain,
            'prompt' => $prompt,
            'action' => 'generate_content'
        );
        
        // 构建请求参数
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                'Accept' => 'application/json'
            ),
            'body' => http_build_query($request_data),
            'timeout' => 180,
            'sslverify' => true
        );
        
        // 发送请求
        $response = wp_remote_post($this->get_api_url(), $args);
        
        // 检查响应
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => '请求失败: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return array(
                'success' => false, 
                'message' => '服务器响应错误: HTTP ' . $response_code
            );
        }
        
        // 解析响应
        $data = json_decode($response_body, true);
        
        if (!$data) {
            return array(
                'success' => false, 
                'message' => '响应数据格式错误'
            );
        }
        
        if ($data['status'] !== 'success') {
            return array(
                'success' => false, 
                'message' => isset($data['message']) ? $data['message'] : '请求失败'
            );
        }
        
        return array(
            'success' => true,
            'data' => isset($data['content']) ? $data['content'] : '',
            'usage' => isset($data['usage']) ? $data['usage'] : array()
        );
    }
    
    /**
     * 调试授权码获取（仅用于测试）
     */
    public function debug_license_info() {
        // 从WordPress选项获取授权码信息
        $license_key = get_option('content_auto_manager_license_key', '');
        $license_data = get_option('content_auto_manager_license', array());
        
        $debug_info = array();
        $debug_info['storage_location'] = 'wp_options表';
        $debug_info['option_key'] = 'content_auto_manager_license_key';
        $debug_info['license_key'] = $license_key;
        $debug_info['license_key_length'] = strlen($license_key);
        $debug_info['license_status'] = isset($license_data['status']) ? $license_data['status'] : '未知';
        $debug_info['license_message'] = isset($license_data['message']) ? $license_data['message'] : '无消息';
        
        // 当前获取的授权码（使用修复后的方法）
        $current_license = $this->get_license_key();
        $debug_info['current_license'] = $current_license;
        
        // 当前域名
        $current_domain = $this->get_current_domain();
        $debug_info['current_domain'] = $current_domain;
        
        return $debug_info;
    }
    
    /**
     * 获取剩余配额
     */
    public function get_quota_info() {
        // 获取授权码和域名
        $license_key = $this->get_license_key();
        $domain = $this->get_current_domain();
        
        if (empty($license_key)) {
            return array(
                'success' => false, 
                'message' => '未配置授权码，请在发布规则中设置授权码'
            );
        }
        
        if (empty($domain)) {
            return array(
                'success' => false, 
                'message' => '无法获取当前域名'
            );
        }
        
        // 构建请求数据
        $request_data = array(
            'license_key' => $license_key,
            'domain' => $domain,
            'action' => 'get_quota_info'
        );
        
        // 构建请求参数
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                'Accept' => 'application/json'
            ),
            'body' => http_build_query($request_data),
            'timeout' => 30,
            'sslverify' => true
        );
        
        // 发送请求
        $response = wp_remote_post($this->get_api_url(), $args);
        
        // 检查响应
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => '请求失败: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return array(
                'success' => false, 
                'message' => '服务器响应错误: HTTP ' . $response_code
            );
        }
        
        // 解析响应
        $data = json_decode($response_body, true);
        
        if (!$data) {
            return array(
                'success' => false, 
                'message' => '响应数据格式错误'
            );
        }
        
        if ($data['status'] === 'success') {
            return array(
                'success' => true, 
                'quota_balance' => isset($data['quota_balance']) ? intval($data['quota_balance']) : 0,
                'message' => '配额信息获取成功'
            );
        } else {
            return array(
                'success' => false, 
                'message' => isset($data['message']) ? $data['message'] : '获取配额信息失败'
            );
        }
    }
    
    /**
     * 测试连接
     */
    public function test_connection($config) {
        // 获取授权码和域名
        $license_key = $this->get_license_key();
        $domain = $this->get_current_domain();
        
        if (empty($license_key)) {
            // 获取调试信息
            $debug_info = $this->debug_license_info();
            
            $debug_message = '未配置授权码，请在发布规则中设置授权码。';
            $debug_message .= ' 调试信息：';
            $debug_message .= ' 存储位置=' . $debug_info['storage_location'];
            $debug_message .= ', 选项键=' . $debug_info['option_key'];
            $debug_message .= ', 授权码=' . ($debug_info['license_key'] ? $debug_info['license_key'] : '(空)');
            $debug_message .= ', 长度=' . $debug_info['license_key_length'];
            $debug_message .= ', 状态=' . $debug_info['license_status'];
            
            return array(
                'success' => false, 
                'message' => $debug_message
            );
        }
        
        if (empty($domain)) {
            return array(
                'success' => false, 
                'message' => '无法获取当前域名'
            );
        }
        
        // 构建测试请求数据
        $request_data = array(
            'license_key' => $license_key,
            'domain' => $domain,
            'action' => 'test_connection'
        );
        
        // 构建请求参数
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                'Accept' => 'application/json'
            ),
            'body' => http_build_query($request_data),
            'timeout' => 30,
            'sslverify' => true
        );
        
        // 发送请求
        $response = wp_remote_post($this->get_api_url(), $args);
        
        // 检查响应
        if (is_wp_error($response)) {
            return array(
                'success' => false, 
                'message' => '连接失败: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return array(
                'success' => false, 
                'message' => '服务器响应错误: HTTP ' . $response_code
            );
        }
        
        // 解析响应
        $data = json_decode($response_body, true);
        
        if (!$data) {
            return array(
                'success' => false, 
                'message' => '响应数据格式错误'
            );
        }
        
        if ($data['status'] === 'success') {
            return array(
                'success' => true, 
                'message' => '连接成功'
            );
        } else {
            return array(
                'success' => false, 
                'message' => isset($data['message']) ? $data['message'] : '连接失败'
            );
        }
    }
    
    /**
     * 获取授权码
     */
    private function get_license_key() {
        // 从WordPress选项中获取授权码（正确的存储位置）
        $license_key = get_option('content_auto_manager_license_key', '');
        
        // 验证授权码格式
        if (!empty($license_key) && preg_match('/^CMT-[A-F0-9]{32}$/', $license_key)) {
            return $license_key;
        }
        
        // 如果格式不正确但不为空，仍然返回（用于调试）
        if (!empty($license_key)) {
            return $license_key;
        }
        
        return '';
    }
    
    /**
     * 获取当前域名
     */
    private function get_current_domain() {
        $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        
        if (empty($domain)) {
            // 尝试从WordPress配置获取
            $site_url = get_site_url();
            $parsed = parse_url($site_url);
            $domain = $parsed['host'] ?? '';
        }
        
        // 规范化域名
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^www\./', '', $domain);
        
        return $domain;
    }
}