<?php
/**
 * Pollinations API渠道实现
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PollinationsChannel extends ContentAuto_ApiChannel {
    
    public function __construct() {
        parent::__construct('pollinations', 'pollinations渠道', 'https://text.pollinations.ai/');
    }
    
    /**
     * 构建请求参数
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 请求参数
     */
    public function build_request_params($config, $prompt) {
        return array(
            'model' => 'openai',
            'private' => 'true',
            'referrer' => $this->get_site_domain(),
            'json' => 'false',
            'seed' => $this->generate_seed()
        );
    }
    
    /**
     * 发送请求到API
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 响应结果
     */
    public function send_request($config, $prompt) {
        // 使用POST请求的API端点
        $url = 'https://text.pollinations.ai/openai';
        
        // 构建请求参数
        $params = $this->build_request_params($config, $prompt);
        
        // 构建POST请求体，确保使用Pollinations支持的模型
        $request_body = array(
            'model' => 'openai',  // 强制使用Pollinations支持的模型
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'seed' => $params['seed'],
            'private' => filter_var($params['private'], FILTER_VALIDATE_BOOLEAN),
            'referrer' => $params['referrer']
        );
        
        // 构建请求头
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress-ContentAutoManager/1.0'
        );
        
        // 如果配置了YOUR_TOKEN，添加Authorization头
        if (!empty($config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $config['api_key'];
        }
        
        // 发送POST请求
        $args = array(
            'timeout' => 120,
            'headers' => $headers,
            'body' => json_encode($request_body)
        );
        
        $response = wp_remote_post($url, $args);
        
        // 检查响应
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            return array('success' => false, 'message' => '请求失败: ' . $error_message);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code === 200) {
            return array('success' => true, 'data' => $response_body);
        } else {
            return array('success' => false, 'message' => '请求失败: HTTP ' . $response_code . ' - ' . substr($response_body, 0, 500));
        }
    }
    
    /**
     * 测试API连接
     * @param array $config 数据库中的配置
     * @return array 测试结果
     */
    public function test_connection($config) {
        $test_prompt = 'Hello, this is a test message. Please respond with a simple greeting.';
        
        $result = $this->send_request($config, $test_prompt);
        
        if ($result['success']) {
            // 检查响应内容
            $response_data = $result['data'];
            if (!empty($response_data)) {
                // 成功获取响应
            } else {
                // 响应为空
            }
        } else {
            // 测试失败
        }
        
        return $result;
    }
    
    /**
     * 获取主域名
     */
    private function get_site_domain() {
        $site_url = get_site_url();
        $parsed_url = parse_url($site_url);
        return isset($parsed_url['host']) ? $parsed_url['host'] : '';
    }
    
    /**
     * 生成随机seed参数
     */
    private function generate_seed() {
        return rand(100, 99999999); // 3-8位随机数字
    }
}