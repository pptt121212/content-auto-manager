<?php
/**
 * 文章API处理器
 * 专门处理文章生成API请求，实现API轮询机制和30秒强制间隔控制
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入依赖的组件
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-config.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-predefined-api.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';

class ContentAuto_ArticleApiHandler {
    
    private $api_config;
    private $current_api_config;
    private $last_api_error;
    private $logger;
    
    public function __construct($logger = null) {
        $this->api_config = new ContentAuto_ApiConfig();
        $this->current_api_config = null;
        $this->last_api_error = null;
        $this->logger = $logger ?: new ContentAuto_LoggingSystem();
    }
    
    /**
     * 生成文章内容（使用API轮询）
     * 实现API轮询机制，确保在只有一个API时也保持轮询逻辑
     */
    public function generate_article_content($prompt, $topic_id, $rule_id) {
        $method_start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        $context = $this->build_context($rule_id, null, array(
            'topic_id' => $topic_id,
            'task_type' => 'article'
        ));
        
        $this->logger->log_success('METHOD_START', 'generate_article_content', $context);
        
        // 定义重试次数
        $max_retries = 3;
        $last_error = null;
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            try {
                // 标记是否为重试
                $is_retry = ($attempt > 1);
                
                // 轮询API配置
                $api_config = $this->get_next_api_config($is_retry);
                
                if (!$api_config) {
                    $error_message = '没有可用的API配置';
                    $this->logger->log_error('NO_API_AVAILABLE', $error_message, $context);
                    $last_error = array('stage' => '获取API配置', 'message' => $error_message);
                    break; // 中断重试循环
                }
                
                $this->current_api_config = $api_config;
                
                if ($attempt > 1) {
                    $this->logger->log_success('API_RETRY', "开始第 {$attempt} 次重试, 使用 API: {$api_config['name']}", $context);
                }
                
                // 处理API请求
                $result = $this->handle_api_request($api_config, $prompt);
                
                // 如果成功，则立即返回结果
                if (isset($result) && !isset($result['error'])) {
                    $this->mark_api_result($api_config['id'], true);
                    $this->logger->log_success('API_CALL_SUCCESS', "API {$api_config['name']} 调用成功", $context);
                    return $result;
                }
                
                // 如果失败，记录错误并准备下一次重试
                $last_error = isset($result['error']) ? $result['error'] : array('stage' => '未知错误', 'message' => '未能获取API结果');
                $this->mark_api_result($api_config['id'], false);
                
                $error_message = is_array($last_error) ? json_encode($last_error, JSON_UNESCAPED_UNICODE) : $last_error;
                $this->logger->log_error('API_CALL_FAILED', "API {$api_config['name']} (ID: {$api_config['id']}) 调用失败 (尝试 {$attempt}/{$max_retries}): " . $error_message, $context);
                
                // 如果不是最后一次尝试，进行指数退避延迟
                if ($attempt < $max_retries) {
                    $retry_delay = pow(2, $attempt - 1); // 指数退避: 1, 2, 4 秒
                    $this->logger->log_success('RETRY_DELAY', "等待 {$retry_delay} 秒后重试", $context);
                    sleep($retry_delay);
                }
                
            } catch (Exception $e) {
                $last_error = array('stage' => '系统异常', 'message' => 'generate_article_content方法执行异常: ' . $e->getMessage());
                $this->logger->log_error('SYSTEM', $last_error['message'], $context);
            }
        }
        
        // 所有尝试都失败后，返回最后一个错误
        $this->logger->log_error('API_ALL_ATTEMPTS_FAILED', '所有API重试均失败', $context);
        return array('error' => $last_error);
    }
    
    /**
     * 轮询API配置
     * 复用ContentAuto_ApiConfig的轮询机制，确保失败重试时也使用轮询逻辑
     */
    private function get_next_api_config($is_retry = false) {
        // 使用API配置管理器的轮询机制
        return $this->api_config->get_next_active_config($is_retry);
    }
    
    /**
     * 处理API请求
     * 实现API响应的解析和验证，添加API错误处理和失败标记机制
     */
    private function handle_api_request($api_config, $prompt) {
        try {
            // 根据API类型处理请求
            if (!empty($api_config['predefined_channel'])) {
                return $this->handle_predefined_api_request($api_config, $prompt);
            } else {
                return $this->handle_custom_api_request($api_config, $prompt);
            }
        } catch (Exception $e) {
            return array('error' => array('stage' => 'API请求异常', 'message' => $e->getMessage()));
        }
    }
    
    /**
     * 处理预置API请求
     */
    private function handle_predefined_api_request($api_config, $prompt) {
        // 使用预置API处理
        $predefined_api = new ContentAuto_PredefinedApi();
        
        // 发送请求到预置API
        $response = $predefined_api->send_request($api_config['predefined_channel'], $prompt);
        
        if ($response['success']) {
            // 解析预置API响应
            $api_response_data = json_decode($response['data'], true);
            $actual_content = '';
            
            if (json_last_error() === JSON_ERROR_NONE && isset($api_response_data['choices'][0]['message']['content'])) {
                $actual_content = $api_response_data['choices'][0]['message']['content'];
            } else {
                $actual_content = $response['data'];
            }
            
            return $actual_content;
        } else {
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $response['message']));
        }
    }
    
    /**
     * 处理自定义API请求
     */
    private function handle_custom_api_request($api_config, $prompt) {
        // 构建API请求数据
        $body_data = array(
            'model' => $api_config['model_name'],
            'messages' => array(
                array('role' => 'user', 'content' => $prompt)
            ),
        );

        // 仅在启用时添加温度参数
        if (!isset($api_config['temperature_enabled']) || $api_config['temperature_enabled']) {
            $body_data['temperature'] = (float) $api_config['temperature'];
        }

        // 仅在启用时添加最大Token数参数
        if (!isset($api_config['max_tokens_enabled']) || $api_config['max_tokens_enabled']) {
            $body_data['max_tokens'] = (int) $api_config['max_tokens'];
        }

        // 将stream写死设为false，暂时不支持流式输出
        $body_data['stream'] = false;

        // 添加top_p参数支持
        if (isset($api_config['top_p_enabled']) && $api_config['top_p_enabled']) {
            $body_data['top_p'] = (float) $api_config['top_p'];
        } else {
            // 默认添加top_p: 1.0以确保兼容性
            $body_data['top_p'] = 1.0;
        }

        // 构建API请求 - 增加超时时间以支持思考模型
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_config['api_key'],
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                'Accept' => 'application/json',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache'
            ),
            'body' => json_encode($body_data),
            'timeout' => 300, // 增加到5分钟，支持思考模型的处理时间
            'user-agent' => 'ContentAutoManager/1.0 (WordPress Plugin)'
        );

        // 发送请求
        $response = wp_remote_post($api_config['api_url'], $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->last_api_error = "WordPress请求错误: " . $error_message;
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $this->last_api_error));
        }

        return $this->process_api_response($response);
    }
    
    /**
     * 处理API响应
     */
    private function process_api_response($response) {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        // 检查HTTP状态码
        if ($response_code >= 400) {
            $error_message = "API调用返回错误状态码: " . $response_code;
            if (isset($response_data['error'])) {
                $error_message .= " - " . (isset($response_data['error']['message']) ? $response_data['error']['message'] : (is_string($response_data['error']) ? $response_data['error'] : json_encode($response_data['error'])));
            }
            
            $this->last_api_error = $error_message;
            return array('error' => array('stage' => 'API响应错误', 'message' => $this->last_api_error));
        }
        
        // 检查是否有错误信息
        if (isset($response_data['error'])) {
            $error_message = "API返回错误: ";
            if (is_string($response_data['error'])) {
                $error_message .= $response_data['error'];
            } elseif (is_array($response_data['error'])) {
                $error_message .= isset($response_data['error']['message']) ? $response_data['error']['message'] : json_encode($response_data['error']);
            }
            
            $this->last_api_error = $error_message;
            return array('error' => array('stage' => 'API响应错误', 'message' => $this->last_api_error));
        }
        
        // 处理API响应内容
        if (isset($response_data['choices'][0]['message']['content'])) {
            $content = $response_data['choices'][0]['message']['content'];
            
            // 验证内容不为空
            if (empty(trim($content))) {
                return array('error' => array('stage' => 'API响应验证', 'message' => 'API返回空内容'));
            }
            
            return $content;
        }
        
        // 如果响应格式不符合预期，返回错误
        return array('error' => array('stage' => 'API响应格式', 'message' => 'API响应格式不符合预期，无法解析内容'));
    }
    
    /**
     * 标记API成功/失败
     * 添加API成功/失败标记和30秒强制间隔控制
     */
    private function mark_api_result($api_id, $success) {
        if ($success) {
            // 标记API成功（清除失败记录）
            $this->api_config->mark_api_success($api_id);
        } else {
            // 标记API失败
            $this->api_config->mark_api_failed($api_id);
        }
        
        // 更新最后请求时间（30秒间隔控制在队列调度器中实现）
        update_option('content_auto_last_api_request', time());
        
        return true;
    }
    
    /**
     * 获取当前API配置
     */
    public function get_current_api_config() {
        return $this->current_api_config;
    }
    
    /**
     * 获取最后的API错误
     */
    public function get_last_api_error() {
        return $this->last_api_error;
    }
    
    /**
     * 构建上下文信息
     */
    private function build_context($rule_id = null, $rule_item_index = null, $additional_info = array()) {
        $context = array();
        
        if ($rule_id !== null) {
            $context['rule_id'] = $rule_id;
        }
        
        if ($rule_item_index !== null) {
            $context['rule_item_index'] = $rule_item_index;
        }
        
        $context = array_merge($context, $additional_info);
        
        // 格式化为字符串
        $context_parts = array();
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $context_parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
        }
        
        return implode(', ', $context_parts);
    }
    
    /**
     * 验证API处理器功能
     * 用于测试API轮询和配置管理功能
     */
    public function verify_api_handler() {
        $verification_results = array();
        
        // 1. 验证API配置管理器
        try {
            if (method_exists($this->api_config, 'get_next_active_config')) {
                $verification_results['api_config_polling'] = 'OK - API轮询机制可用';
            } else {
                $verification_results['api_config_polling'] = 'ERROR - get_next_active_config方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['api_config_polling'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 2. 验证API成功/失败标记功能
        try {
            if (method_exists($this->api_config, 'mark_api_success') && method_exists($this->api_config, 'mark_api_failed')) {
                $verification_results['api_result_marking'] = 'OK - API结果标记功能可用';
            } else {
                $verification_results['api_result_marking'] = 'ERROR - API结果标记方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['api_result_marking'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 3. 验证预置API处理
        try {
            $predefined_api = new ContentAuto_PredefinedApi();
            if (method_exists($predefined_api, 'send_request')) {
                $verification_results['predefined_api'] = 'OK - 预置API处理可用';
            } else {
                $verification_results['predefined_api'] = 'ERROR - 预置API send_request方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['predefined_api'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 4. 验证日志记录功能
        try {
            if (method_exists($this->logger, 'log_success') && method_exists($this->logger, 'log_error')) {
                $verification_results['logging'] = 'OK - 日志记录功能可用';
            } else {
                $verification_results['logging'] = 'ERROR - 日志记录方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['logging'] = 'ERROR - ' . $e->getMessage();
        }
        
        return $verification_results;
    }
    
    /**
     * 获取API处理器状态摘要
     */
    public function get_handler_status() {
        $results = $this->verify_api_handler();
        $total_components = count($results);
        $successful_components = 0;
        
        foreach ($results as $component => $status) {
            if (strpos($status, 'OK') === 0) {
                $successful_components++;
            }
        }
        
        return array(
            'total_components' => $total_components,
            'successful_components' => $successful_components,
            'success_rate' => round(($successful_components / $total_components) * 100, 2) . '%',
            'current_api_config' => $this->current_api_config ? $this->current_api_config['name'] : 'None',
            'last_api_error' => $this->last_api_error,
            'details' => $results
        );
    }
}