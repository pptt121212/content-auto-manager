<?php
/**
 * 主题生成API处理器
 * 负责处理所有与API相关的操作，包括轮询API和预置API
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TopicApiHandler {
    
    private $api_config;
    private $current_api_config;
    private $last_api_error;
    private $logger;
    
    public function __construct($logger = null) {
        $this->api_config = new ContentAuto_ApiConfig();
        $this->current_api_config = null;
        $this->last_api_error = null;
        $this->logger = $logger;
    }
    
    /**
     * 生成主题
     */
    public function generate_topics($prompt, $count, $rule_id = null, $rule_item_index = null) {
        $method_start_time = microtime(true);
        $start_memory = memory_get_usage(true);
    
        $context = $this->build_context($rule_id, $rule_item_index, array('期望数量' => $count));
        $this->log_success('METHOD_START', 'generate_topics', $context);
    
        // 定义重试次数
        $max_retries = 2;
        $last_error = null;
    
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            try {
                // 首次尝试不标记为重试，后续尝试标记为重试
                $is_retry = ($attempt > 0);
                $api_config = $this->api_config->get_next_active_config($is_retry);
    
                if (!$api_config) {
                    $this->log_error('NO_API_AVAILABLE', '没有可用的API配置，终止重试', $context);
                    // 如果没有可用的API，则将最后一个错误设置为此消息
                    $last_error = array('stage' => '获取API配置', 'message' => '没有可用的API配置');
                    break; // 中断重试循环
                }
    
                $this->current_api_config = $api_config;
                $result = null;
    
                if ($attempt > 0) {
                    $this->log_success('API_RETRY', "开始第 {$attempt} 次重试, 使用 API: {$api_config['name']}", $context);
                }
    
                // 根据API类型处理请求
                if (!empty($api_config['predefined_channel'])) {
                    $result = $this->handle_predefined_api_request($api_config, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory);
                } else {
                    $result = $this->handle_custom_api_request($api_config, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory);
                }
    
                // 如果成功，则立即返回结果
                if (isset($result) && !isset($result['error'])) {
                    $this->api_config->mark_api_success($api_config['id']);
                    $this->log_success('API_CALL_SUCCESS', "API {$api_config['name']} 调用成功", $context);
                    return $result;
                }
    
                // 如果失败，记录错误并准备下一次重试
                $last_error = isset($result['error']) ? $result['error'] : array('stage' => '未知错误', 'message' => '未能获取API结果');
                $this->api_config->mark_api_failed($api_config['id']);
                $error_message = is_array($last_error) ? json_encode($last_error, JSON_UNESCAPED_UNICODE) : $last_error;
                $this->log_error('API_CALL_FAILED', "API {$api_config['name']} (ID: {$api_config['id']}) 调用失败 (尝试 " . ($attempt + 1) . "/".($max_retries + 1)."): " . $error_message, $context);
    
            } catch (Exception $e) {
                $last_error = array('stage' => '系统异常', 'message' => 'generate_topics方法执行异常: ' . $e->getMessage());
                $this->log_error('SYSTEM', $last_error['message'], $context);
            }
        }
    
        // 所有尝试都失败后，返回最后一个错误
        $this->log_error('API_ALL_ATTEMPTS_FAILED', '所有API重试均失败', $context);
        return array('error' => $last_error);
    }
    
    /**
     * 处理预置API请求
     */
    private function handle_predefined_api_request($api_config, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory) {
        // 使用预置API处理
        $predefined_api = new ContentAuto_PredefinedApi();
        
        // 构建提示，替换{N}为实际数量
        $full_prompt = str_replace('{N}', $count, $prompt);
        
        // 发送请求到预置API
        $response = $predefined_api->send_request($api_config['predefined_channel'], $full_prompt);
        
        if ($response['success']) {
            // 解析预置API响应
            $api_response_data = json_decode($response['data'], true);
            $actual_content = '';
            
            if (json_last_error() === JSON_ERROR_NONE && isset($api_response_data['choices'][0]['message']['content'])) {
                $actual_content = $api_response_data['choices'][0]['message']['content'];
            } else {
                $actual_content = $response['data'];
            }
            
            return $this->parse_api_content($actual_content, $count, $rule_id, $rule_item_index);
        } else {
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $response['message']));
        }
    }
    
    /**
     * 处理自定义API请求
     */
    private function handle_custom_api_request($api_config, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory) {
        // 构建提示，替换{N}为实际数量
        $full_prompt = str_replace('{N}', $count, $prompt);

        // 构建API请求数据
        $body_data = array(
            'model' => $api_config['model_name'],
            'messages' => array(
                array('role' => 'user', 'content' => $full_prompt)
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
            
            // 自定义API失败，直接返回错误
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $this->last_api_error));
        }

        return $this->process_api_response($response, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory);
    }
    
        
    /**
     * 处理API响应
     */
    private function process_api_response($response, $prompt, $count, $rule_id, $rule_item_index, $method_start_time, $start_memory) {
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
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $this->last_api_error));
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
            return array('error' => array('stage' => 'WordPress请求错误', 'message' => $this->last_api_error));
        }
        
        // 处理API响应内容
        if (isset($response_data['choices'][0]['message']['content'])) {
            $content = $response_data['choices'][0]['message']['content'];
            return $this->parse_api_content($content, $count, $rule_id, $rule_item_index);
        }
        
        // 如果响应格式不符合预期，返回错误
        return array('error' => array('stage' => 'API响应格式', 'message' => 'API响应格式不符合预期，无法解析内容'));
    }
    
    /**
     * 解析API返回的内容
     */
    private function parse_api_content($content, $count, $rule_id, $rule_item_index) {
        // 尝试解析JSON格式
        $json_parser = new ContentAuto_JsonParser($this->logger);
        $parsed_topics = $json_parser->parse_json_topics($content, $count, $rule_id, $rule_item_index);

        if (isset($parsed_topics['error'])) {
            return array('error' => array('stage' => '解析JSON', 'message' => $parsed_topics['error']));
        }
        
        if ($parsed_topics !== false) {
            return $parsed_topics;
        }
        
        // 如果不是JSON格式，按行分割主题
        $topics = explode("\n", $content);
        $clean_topics = array();
        foreach ($topics as $topic) {
            $topic = trim($topic);
            // 移除可能的代码块标记
            $topic = preg_replace('/^```[a-z]*\s*/', '', $topic);
            $topic = preg_replace('/\s*```$/', '', $topic);
            if (!empty($topic) && $topic !== '{' && $topic !== '}' && $topic !== '[' && $topic !== ']') {
                // 移除可能的编号
                $topic = preg_replace('/^\d+\.\s*/', '', $topic);
                $clean_topics[] = sanitize_text_field($topic);
            }
        }
        
        return array_slice($clean_topics, 0, $count);
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
     * 格式化性能数据
     */
    private function format_performance_data($start_time, $start_memory, $current_time = null) {
        if ($current_time === null) {
            $current_time = microtime(true);
        }
        
        $total_time = round(($current_time - $start_time) * 1000, 2);
        $total_memory = memory_get_usage(true) - $start_memory;
        $peak_memory = memory_get_peak_usage(true);
        
        return array(
            'total_time' => $total_time,
            'total_memory' => $total_memory,
            'peak_memory' => $peak_memory,
            'formatted_time' => $total_time . 'ms',
            'formatted_memory' => $this->format_memory_usage($total_memory),
            'formatted_peak_memory' => $this->format_memory_usage($peak_memory)
        );
    }
    
    /**
     * 格式化内存使用量显示
     */
    private function format_memory_usage($bytes) {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }
    
    /**
     * 记录成功信息
     */
    private function log_success($operation, $result, $context = '', $performance_data = null) {
        if ($this->logger) {
            $this->logger->log_success($operation, $result, $context, $performance_data);
        }
    }
    
    /**
     * 记录错误信息
     */
    private function log_error($error_type, $message, $context = '', $suggestions = array(), $performance_data = null) {
        if ($this->logger) {
            $this->logger->log_error($error_type, $message, $context, $suggestions, $performance_data);
        }
    }
}