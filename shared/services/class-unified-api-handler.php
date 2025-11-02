<?php
// Cache buster: 2025-09-27 20:45:00
/**
 * 统一API处理器
 * 负责处理所有与API相关的操作，包括轮询API和预置API
 * 与主题生成任务的API处理逻辑保持完全一致
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_UnifiedApiHandler {
    
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
     * 生成内容（通用方法）
     * 与主题生成任务的generate_topics方法保持一致的逻辑
     */
    public function generate_content($prompt, $task_type = 'article', $additional_params = array()) {
        $method_start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        $context = $this->build_context(
            isset($additional_params['rule_id']) ? $additional_params['rule_id'] : null,
            isset($additional_params['rule_item_index']) ? $additional_params['rule_item_index'] : null,
            array('任务类型' => $task_type)
        );
        
        $this->log_success('METHOD_START', 'generate_content', $context);
        
        try {
            // 首先尝试轮询机制
            $api_config = $this->api_config->get_next_active_config();
            
            if ($api_config) {
                $this->current_api_config = $api_config;
                $this->last_api_error = null;
                
                // 检查API类型
                if (!empty($api_config['predefined_channel'])) {
                    return $this->handle_predefined_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                }
                
                // 自定义API处理
                return $this->handle_custom_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            } else {
                // 没有可用的API配置，尝试预置API作为备选
                return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            }
            
        } catch (Exception $e) {
            $performance_data = $this->format_performance_data($method_start_time, $start_memory);
            $this->log_error('SYSTEM', 'generate_content方法发生异常: ' . $e->getMessage(), $context, 
                array('请检查系统资源是否充足'), $performance_data);
            
            return array('error' => 'generate_content方法执行异常: ' . $e->getMessage());
        }
    }
    
    /**
     * 处理自定义API请求
     * 与主题生成任务的handle_custom_api_request方法保持一致
     */
    private function handle_custom_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory) {
        $context = $this->build_context(
            isset($additional_params['rule_id']) ? $additional_params['rule_id'] : null,
            isset($additional_params['rule_item_index']) ? $additional_params['rule_item_index'] : null,
            array_merge($additional_params, array(
                '任务类型' => $task_type,
                'API类型' => 'custom',
                'API_URL' => $api_config['api_url'],
                '模型名称' => $api_config['model_name']
            ))
        );
        
        $this->log_success('API_REQUEST_START', '开始自定义API请求', $context);
        
        // 记录API请求前的提示词（仅在调试模式下）
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_debug('API_PROMPT', 'API请求提示词', array_merge($context, array(
                'prompt_length' => strlen($prompt),
                'prompt_preview' => substr($prompt, 0, 500) . (strlen($prompt) > 500 ? '...' : '')
            )));
        }
        
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

        // 构建API请求
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_config['api_key']
            ),
            'body' => json_encode($body_data),
            'timeout' => 120
        );
        
        // 记录完整的API请求参数（仅在调试模式下）
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_debug('API_REQUEST_DETAILS', 'API请求详情', array_merge($context, array(
                'request_url' => $api_config['api_url'],
                'request_body' => json_encode($body_data, JSON_UNESCAPED_UNICODE)
            )));
        }
        
        // 发送请求
        $response = wp_remote_post($api_config['api_url'], $args);
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->last_api_error = "WordPress请求错误: " . $error_message;
            $this->log_error('API_REQUEST_ERROR', '自定义API请求失败: ' . $error_message, $context);

            // 如果自定义API失败，尝试预置API作为备选
            return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
        }
        
        return $this->process_api_response($response, $prompt, $task_type, $additional_params, $method_start_time, $start_memory);
    }
    
    /**
     * 处理预置API请求
     * 与主题生成任务的handle_predefined_api_request方法保持一致
     */
    private function handle_predefined_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory) {
        $this->current_api_config = $api_config;
        
        $context = $this->build_context(
            isset($additional_params['rule_id']) ? $additional_params['rule_id'] : null,
            isset($additional_params['rule_item_index']) ? $additional_params['rule_item_index'] : null,
            array_merge($additional_params, array(
                '任务类型' => $task_type,
                'API类型' => 'predefined',
                '预置渠道' => $api_config['predefined_channel']
            ))
        );
        
        $this->log_success('API_REQUEST_START', '开始预置API请求', $context);
        
        // 记录API请求前的提示词（仅在调试模式下）
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_debug('API_PROMPT', 'API请求提示词', array_merge($context, array(
                'prompt_length' => strlen($prompt),
                'prompt_preview' => substr($prompt, 0, 500) . (strlen($prompt) > 500 ? '...' : '')
            )));
        }
        
        $predefined_api = new ContentAuto_PredefinedApi();
        
        // 检查预置API配置是否存在，如果不存在则自动创建
        $config = $predefined_api->get_config($api_config['predefined_channel']);
        if (!$config) {
            $config = $predefined_api->create_config_record($api_config['predefined_channel'], 1);
            if (!$config) {
                $error_msg = '预置API配置创建失败，无法使用预置API服务';
                $this->log_error('API_CONFIG_FAILED', $error_msg, $context);
                return array('error' => $error_msg);
            }
        }
        
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
            
            // 记录API返回的原始内容（仅在调试模式下）
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->log_debug('API_RAW_RESPONSE', 'API原始返回内容', array_merge($context, array(
                    'response_length' => strlen($actual_content),
                    'raw_response' => $actual_content
                )));
            }
            
            $this->log_success('API_REQUEST_SUCCESS', '预置API请求成功', array_merge($context, array(
                'response_length' => strlen($actual_content)
            )));
            
            return $actual_content;
        } else {
            $error_msg = $response['message'];
            $this->log_error('API_REQUEST_FAILED', '预置API请求失败: ' . $error_msg, $context);
            return array('error' => $error_msg);
        }
    }
    
    /**
     * 处理API响应
     * 与主题生成任务的process_api_response方法保持一致
     */
    private function process_api_response($response, $prompt, $task_type, $additional_params, $method_start_time, $start_memory) {
        $context = $this->build_context(
            isset($additional_params['rule_id']) ? $additional_params['rule_id'] : null,
            isset($additional_params['rule_item_index']) ? $additional_params['rule_item_index'] : null,
            array_merge($additional_params, array('任务类型' => $task_type))
        );
        
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        // 记录原始API响应（仅在调试模式下）
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_debug('API_RAW_RESPONSE', 'API原始响应', array_merge($context, array(
                'response_code' => $response_code,
                'response_body' => $response_body,
                'response_length' => strlen($response_body)
            )));
        }

        // Special handling for structure generation task
        if ($task_type === 'structure_generation') {
            $extracted_content = null;

            // 1. 尝试从标准聊天完成格式中提取 (e.g., OpenAI, DeepSeek)
            if (isset($response_data['choices'][0]['message']['content'])) {
                $extracted_content = $response_data['choices'][0]['message']['content'];
            } 
            
            // 2. 如果标准格式中未找到，尝试直接从顶层解析 (e.g., some custom APIs that return raw JSON)
            // 检查是否是有效的JSON且包含title和structure键
            if ($extracted_content === null && isset($response_data['title']) && isset($response_data['structure'])) {
                // 如果是，则整个响应体就是我们需要的JSON
                $extracted_content = $response_body;
            }

            if ($extracted_content !== null) {
                // 记录提取的结构化内容（仅在调试模式下）
                if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                    $this->log_debug('STRUCTURE_CONTENT_EXTRACTED', '结构化内容提取成功', array_merge($context, array(
                        'content_length' => strlen($extracted_content),
                        'extracted_content' => $extracted_content
                    )));
                }
                return $extracted_content; // Return the successfully extracted content string
            }
            // If content not found after all attempts, fall through to standard error handling.
        }
        
        // 检查HTTP状态码
        if ($response_code >= 400) {
            $error_message = "API调用返回错误状态码: " . $response_code;
            if (isset($response_data['error'])) {
                $error_message .= " - " . (isset($response_data['error']['message']) ? $response_data['error']['message'] : (is_string($response_data['error']) ? $response_data['error'] : json_encode($response_data['error'])));
            }
            
            $this->last_api_error = $error_message;
            $this->log_error('API_HTTP_ERROR', $error_message, $context);
            return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
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
            $this->log_error('API_RESPONSE_ERROR', $error_message, $context);
            return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
        }
        
        // 处理API响应内容
        if (isset($response_data['choices'][0]['message']['content'])) {
            $final_content = $response_data['choices'][0]['message']['content'];
            
            // 记录最终提取的内容（仅在调试模式下）
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->log_debug('API_FINAL_CONTENT', 'API最终提取内容', array_merge($context, array(
                    'content_length' => strlen($final_content),
                    'final_content' => $final_content
                )));
            }
            
            $this->log_success('API_RESPONSE_SUCCESS', 'API响应处理成功', array_merge($context, array(
                'content_length' => strlen($final_content)
            )));
            
            return $final_content;
        }
        
        $this->log_error('API_NO_CONTENT', 'API响应中未找到有效内容', $context);
        return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
    }
    
    /**
     * 使用API轮询机制进行重试
     * 替代原来的预置API备选方案
     */
    private function try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory) {
        if ($method_start_time === null) {
            $method_start_time = microtime(true);
        }
        if ($start_memory === null) {
            $start_memory = memory_get_usage(true);
        }
        
        // 使用API轮询机制进行重试
        $max_retries = get_option('content_auto_max_retries', 2);
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // 使用API轮询机制获取下一个API配置
            $api_config = $this->api_config->get_next_active_config(true); // 标记为重试
            
            if ($api_config) {
                // 根据API类型选择正确的处理方法
                if (!empty($api_config['predefined_channel'])) {
                    // 预置API处理
                    $result = $this->handle_predefined_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                } else {
                    // 自定义API处理
                    $result = $this->handle_custom_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                }
                
                if (!isset($result['error'])) {
                    // 标记API成功
                    $this->api_config->mark_api_success($api_config['id']);
                    return $result;
                } else {
                    // 标记API失败
                    $this->api_config->mark_api_failed($api_config['id']);
                    
                    // 如果不是最后一次尝试，继续重试
                    if ($attempt < $max_retries) {
                        continue;
                    }
                }
            }
        }
        
        return array('error' => '所有重试都失败了');
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
            $context['规则ID'] = $rule_id;
        }
        
        if ($rule_item_index !== null) {
            $context['规则项目索引'] = $rule_item_index;
        }
        
        return array_merge($context, $additional_info);
    }
    
    /**
     * 格式化性能数据
     */
    private function format_performance_data($start_time, $start_memory) {
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        
        return array(
            '执行时间' => round(($end_time - $start_time) * 1000, 2) . 'ms',
            '内存使用' => round(($end_memory - $start_memory) / 1024 / 1024, 2) . 'MB'
        );
    }
    
    /**
     * 记录成功日志
     */
    private function log_success($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->log_success($code, $message, $context);
        }
    }

/**
     * 记录调试日志
     */
    private function log_debug($code, $message, $context = array()) {
        // 仅在调试模式下记录调试日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if ($this->logger) {
                $this->logger->debug("[{$code}] {$message}", $context);
            }
        }
    }
  
    /**
     * 记录错误日志
     */
    private function log_error($code, $message, $context = array(), $suggestions = array(), $performance_data = array()) {
        if ($this->logger) {
            $this->logger->log_error($code, $message, $context, $suggestions, $performance_data);
        }
    }
}