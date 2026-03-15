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

require_once __DIR__ . '/class-safety-sentinel.php';

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
            // 检查是否指定了具体的 API ID
            if (isset($additional_params['config_id'])) {
                $api_config = $this->api_config->get_config($additional_params['config_id']);
            } else {
                // 否则使用标准的 轮询/备选 机制获取下一个可用的 API
                $api_config = $this->api_config->get_next_active_config();
            }
            
            if ($api_config) {
                $this->current_api_config = $api_config;
                $this->last_api_error = null;
                
                // ✅ 关键加固：不仅使用API，还需同步更新主题表的API关联快照
                // 这确保了如果请求失败，后续的超时处理器或重试逻辑能看到真实使用的API，而不是过时的记录
                if (isset($additional_params['topic_id'])) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->prefix . 'content_auto_topics',
                        array(
                            'api_config_id' => $api_config['id'],
                            'api_config_name' => $api_config['name']
                        ),
                        array('id' => $additional_params['topic_id'])
                    );
                }
                
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
    private function handle_custom_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $is_fallback = false) {
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
        
        // 如果提供了任务ID或队列ID，执行安全哨兵检测（防止已删除的“幽灵任务”继续执行）
        if (!$this->is_execution_valid($additional_params)) {
             $this->log_warning('GHOST_TASK_ABORT', '检测到幽灵任务或已停止的任务，正在终止执行', $context);
             return array('error' => 'Task has been deleted or stopped');
        }
        
        $this->log_success('API_REQUEST_START', '开始自定义API请求', $context);
        
        // 记录API请求前的提示词
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $prompt_preview = $prompt;
            if (strlen($prompt_preview) > 5000) {
                $prompt_preview = substr($prompt_preview, 0, 5000) . "... [Content truncated for log stability. Full length: " . strlen($prompt) . "]";
            }
            $this->log_debug('API_PROMPT', '发送给大模型的完整提示词', array_merge($context, array(
                'prompt' => $prompt_preview
            )));
        } else {
            $this->log_debug('API_PROMPT', '开始向大模型发送请求 (完整提示词请开启调试模式查看)', $context);
        }
        
        // 检测API类型
        $api_type = isset($api_config['api_type']) ? $api_config['api_type'] : 'openai';
        
        // 根据API类型构建请求
        $api_url = $api_config['api_url'];
        $use_streaming = isset($api_config['stream']) ? (bool)$api_config['stream'] : true;
        
        switch ($api_type) {
            case 'gemini':
                // Gemini API 特殊处理
                $result = $this->handle_gemini_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $context);
                if (isset($result['error']) && !$is_fallback) {
                    return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                }
                return $result;
                
            case 'claude':
                // Claude API 特殊处理
                $result = $this->handle_claude_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $context);
                if (isset($result['error']) && !$is_fallback) {
                    return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                }
                return $result;
                
            case 'openai':
            default:
                // OpenAI 兼容格式 (默认)
                break;
        }
        
        // 构建API请求数据 (OpenAI 格式)
        $body_data = array(
            'model' => $api_config['model_name'],
            'messages' => array(
                array('role' => 'user', 'content' => $prompt)
            ),
        );

        // 仅在启用时添加温度参数
        if (!empty($api_config['temperature_enabled'])) {
            $body_data['temperature'] = (float) $api_config['temperature'];
        }

        // 仅在启用时添加最大Token数参数
        if (!empty($api_config['max_tokens_enabled'])) {
            $body_data['max_tokens'] = (int) $api_config['max_tokens'];
        }

        // 添加top_p参数支持 (Restored Business Logic)
        if (isset($api_config['top_p_enabled']) && $api_config['top_p_enabled']) {
            $body_data['top_p'] = (float) $api_config['top_p'];
        } else {
            // 默认添加top_p: 1.0以确保兼容性
            $body_data['top_p'] = 1.0;
        }

        // 构建API请求
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
            // 允许通过 additional_params 覆盖默认超时时间
            // 用户设定：3分钟 (180s) 足够
            'timeout' => isset($additional_params['timeout']) ? (int)$additional_params['timeout'] : 300,
            'httpversion' => '1.1', // 强制使用 HTTP 1.1 分块传输
            'sslverify' => true, 
        );

        // 如果 additional_params 中通过，则显式传递 stream 参数给 API Provider
        // 如果用户在后台显式禁用了流式，则尊重用户选择；否则默认开启流式以避免超时
        // 我们只认 'stream' 这个字段，它对应 UI 里的下拉框
        $user_stream_setting = isset($api_config['stream']) ? (bool)$api_config['stream'] : true; 
        
        $use_streaming = $user_stream_setting; 
        
        if ($use_streaming) {
            $body_data['stream'] = true;
            // [修复] 使用 JSON_INVALID_UTF8_SUBSTITUTE 防止非 UTF8 字符导致 json_encode 返回 false
            $args['body'] = json_encode($body_data, JSON_INVALID_UTF8_SUBSTITUTE);
            // [关键修复] 流式请求需要使用 SSE 的 Accept Header
            $args['headers']['Accept'] = 'text/event-stream';
        } else {
             // [修复] 非流式也同样加固
             $args['body'] = json_encode($body_data, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        
        // 传递 return_usage 标志给 send_streaming_request
        if (!empty($additional_params['return_usage'])) {
            $args['return_usage'] = true;
        }
        
        // 记录完整的API请求参数
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $body_preview = $body_data;
            if (isset($body_preview['messages'][0]['content']) && strlen($body_preview['messages'][0]['content']) > 5000) {
                $body_preview['messages'][0]['content'] = substr($body_preview['messages'][0]['content'], 0, 5000) . "... [Content truncated for log stability]";
            }
            $this->log_debug('API_RAW_REQUEST', '完整的 API 请求参数', array_merge($context, array(
                'api_url' => $api_config['api_url'],
                'headers' => $args['headers'],
                'body' => $body_preview
            )));
        } else {
            $this->log_debug('API_RAW_REQUEST', '发送API请求 (完整参数请开启调试模式查看)', array_merge($context, array(
                'api_url' => $api_config['api_url'],
                'model' => $api_config['model_name']
            )));
        }
        
        // 发送请求: 根据是否启用流式选择不同的发送方式
        if ($use_streaming) {
            $response = $this->send_streaming_request($api_config['api_url'], $args, $additional_params);
        } else {
            $response = wp_remote_post($api_config['api_url'], $args);
        }
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message(); // 这里捕获的通常就是 cURL error 56
            
            // 针对 SSL Connection Reset 的特殊重试提示逻辑
            if (strpos($error_message, 'cURL error 56') !== false) {
                 $error_message .= " (建议：检查请求内容是否过长或尝试切换代理/VPN)";
            }

            $this->last_api_error = "WordPress请求错误: " . $error_message;
            $this->log_error('API_REQUEST_ERROR', '自定义API请求失败: ' . $error_message, $context);

            // 如果自定义API失败，且不是在重试链路上，尝试预置API作为备选
            if (!$is_fallback) {
                return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            } else {
                return array('error' => $error_message);
            }
        }
        
        // 对于流式请求，send_streaming_request 返回的是标准格式的 array 或者 WP_Error
        if ($use_streaming) {
             if (is_wp_error($response)) {
                 // 处理流式请求返回的错误
                 $this->last_api_error = $response->get_error_message();
                 $this->log_error('API_STREAM_ERROR', '流式请求失败: ' . $this->last_api_error, $context);
                 if (!$is_fallback) {
                     return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                 } else {
                     return array('error' => current($response->get_error_messages()));
                 }
             }
             // 成功获取内容，因为流式返回了标准的 array('body' => json, 'response' => array('code' => 200))，
             // 所以我们可以直接复用 process_api_response
             return $this->process_api_response($response, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $is_fallback);
        }
        
        return $this->process_api_response($response, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $is_fallback);
    }
    
    /**
     * 处理预置API请求
     * 与主题生成任务的handle_predefined_api_request方法保持一致
     */
    private function handle_predefined_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $is_fallback = false) {
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
        
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $response_preview = $response['data'];
            if (strlen($response_preview) > 5000) {
                $response_preview = substr($response_preview, 0, 5000) . "... [Content truncated for log stability. Full length: " . strlen($response['data']) . "]";
            }
            $this->log_debug('API_RAW_RESPONSE', '预置API返回的完整原始响应', array_merge($context, array(
                'raw_response' => $response_preview
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
            // 预置API内容提取成功
            
            $this->log_success('API_REQUEST_SUCCESS', '预置API请求成功', array_merge($context, array(
                'response_length' => strlen($actual_content)
            )));
            
            // ✅ 移除 AI 思考标签
            $actual_content = $this->strip_think_tags($actual_content);
            
            return $actual_content;
        } else {
            $error_msg = $response['message'];
            $this->log_error('API_REQUEST_FAILED', '预置API请求失败: ' . $error_msg, $context);
            
            if (!$is_fallback) {
                return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            }
            
            return array('error' => $error_msg);
        }
    }
    
    /**
     * 处理API响应
     * 与主题生成任务的process_api_response方法保持一致
     */
    private function process_api_response($response, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $is_fallback = false) {
        $context = $this->build_context(
            isset($additional_params['rule_id']) ? $additional_params['rule_id'] : null,
            isset($additional_params['rule_item_index']) ? $additional_params['rule_item_index'] : null,
            array_merge($additional_params, array('任务类型' => $task_type))
        );
        
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        $response_code = wp_remote_retrieve_response_code($response);
        
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $body_preview = $response_body;
            if (strlen($body_preview) > 5000) {
                $body_preview = substr($body_preview, 0, 5000) . "... [Content truncated for log stability. Full length: " . strlen($response_body) . "]";
            }
            $this->log_debug('API_RAW_RESPONSE', 'API返回的完整原始响应', array_merge($context, array(
                'response_code' => $response_code,
                'raw_response' => $body_preview
            )));
        } else {
            $this->log_debug('API_RAW_RESPONSE', '接收到API响应 (完整响应请开启调试模式查看)', array_merge($context, array(
                'response_code' => $response_code,
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
                // ✅ 移除 AI 思考标签
                $extracted_content = $this->strip_think_tags($extracted_content);
                
                // 记录提取的结构化内容（仅在调试模式下）
                if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                    $content_preview = $extracted_content;
                    if (strlen($content_preview) > 5000) {
                        $content_preview = substr($content_preview, 0, 5000) . "... [Content truncated for log stability. Full length: " . strlen($extracted_content) . "]";
                    }
                    $this->log_debug('STRUCTURE_CONTENT_EXTRACTED', '结构化内容提取成功', array_merge($context, array(
                        'content_length' => strlen($extracted_content),
                        'extracted_content' => $content_preview
                    )));
                }

                if (!empty($additional_params['return_usage'])) {
                    $usage = isset($response_data['usage']) ? $response_data['usage'] : array();
                    $finish_reason = isset($response_data['choices'][0]['finish_reason']) ? $response_data['choices'][0]['finish_reason'] : null;
                    
                    // ✅ length 视为 API 失败，触发轮询切换
                    if ($finish_reason === 'length') {
                        $this->log_error('API_LENGTH_LIMIT', '大模型单次输出长度已达上限 (finish_reason: length)，尝试切换API', $context);
                        if (!$is_fallback) {
                            return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                        } else {
                            return array('error' => 'API生成失败：大模型单次输出长度已达上限 (finish_reason: length)，建议更换模型或服务商', 'finish_reason' => 'length');
                        }
                    }
                    
                    return array(
                        'content' => $extracted_content,
                        'usage' => $usage,
                        'finish_reason' => $finish_reason
                    );
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
            if (!$is_fallback) {
                return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            } else {
                return array('error' => $error_message);
            }
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
            if (!$is_fallback) {
                return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
            } else {
                return array('error' => $error_message);
            }
        }
        
        // 处理API响应内容
        if (isset($response_data['choices'][0]['message']['content'])) {
            $final_content = $response_data['choices'][0]['message']['content'];
            
            // ✅ 移除 AI 思考标签
            $final_content = $this->strip_think_tags($final_content);
            
            // 记录最终提取的内容（仅在调试模式下）
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $content_preview = $final_content;
                if (strlen($content_preview) > 5000) {
                    $content_preview = substr($content_preview, 0, 5000) . "... [Content truncated for log stability. Full length: " . strlen($final_content) . "]";
                }
                $this->log_debug('API_FINAL_CONTENT', 'API最终提取内容', array_merge($context, array(
                    'content_length' => strlen($final_content),
                    'final_content' => $content_preview
                )));
            }
            
            $this->log_success('API_RESPONSE_SUCCESS', 'API响应处理成功', array_merge($context, array(
                'content_length' => strlen($final_content)
            )));
            
            // 如果请求需要返回 usage 数据
            if (!empty($additional_params['return_usage'])) {
                $usage = isset($response_data['usage']) ? $response_data['usage'] : array();
                $finish_reason = isset($response_data['choices'][0]['finish_reason']) ? $response_data['choices'][0]['finish_reason'] : null;
                
                // ✅ length 视为 API 失败，触发轮询切换
                if ($finish_reason === 'length') {
                    $this->log_error('API_LENGTH_LIMIT', '大模型单次输出长度已达上限 (finish_reason: length)，尝试切换API', $context);
                    if (!$is_fallback) {
                        return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
                    } else {
                        return array('error' => 'API生成失败：大模型单次输出长度已达上限 (finish_reason: length)，建议更换模型或服务商', 'finish_reason' => 'length');
                    }
                }
                
                return array(
                    'content' => $final_content,
                    'usage' => $usage,
                    'finish_reason' => $finish_reason
                );
            }
            
            return $final_content;
        }
        
        // 如果请求需要返回 usage 数据
        if (!empty($additional_params['return_usage'])) {
            $usage = isset($response_data['usage']) ? $response_data['usage'] : array();
            return array(
                'content' => isset($final_content) ? $final_content : '',
                'usage' => $usage
            );
        }
        
        // 更新最后请求时间（恢复频率限制）
        update_option('content_auto_last_api_request', time());

        
        $this->log_error('API_NO_CONTENT', 'API响应中未找到有效内容', $context);
        if (!$is_fallback) {
            return $this->try_predefined_api_fallback($prompt, $task_type, $additional_params, $method_start_time, $start_memory);
        } else {
            return array('error' => 'API响应中未找到有效内容');
        }
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
        
        // 检查任务是否有效
        if (!$this->is_execution_valid($additional_params)) {
            return array('error' => 'Task aborted: deleted or inactive');
        }

        // 使用API轮询机制进行重试
        $max_retries = get_option('content_auto_max_retries', 2);
        $last_error_data = array('error' => '所有重试都失败了'); // 默认错误
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            // 使用API轮询机制获取下一个API配置
            $api_config = $this->api_config->get_next_active_config(true); // 标记为重试
            
            if ($api_config) {
                // 根据API类型选择正确的处理方法
                if (!empty($api_config['predefined_channel'])) {
                    // 预置API处理
                    $result = $this->handle_predefined_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, true);
                } else {
                    // 自定义API处理
                    $result = $this->handle_custom_api_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, true);
                }
                
                if (!isset($result['error'])) {
                    // ✅ fallback 循环内也要拦截 length，不能让截断内容当作成功返回
                    if (is_array($result) && isset($result['finish_reason']) && $result['finish_reason'] === 'length') {
                        $last_error_data = array(
                            'error' => 'API生成失败：大模型单次输出长度已达上限 (finish_reason: length)',
                            'finish_reason' => 'length'
                        );
                        $this->api_config->mark_api_failed($api_config['id']);
                        $this->log_error('API_LENGTH_LIMIT_FALLBACK', "Fallback尝试 #{$attempt} (API ID: {$api_config['id']}) 也触发长度截断，继续尝试下一个", array('api_id' => $api_config['id']));
                        continue;
                    }
                    
                    // 标记API成功
                    $this->api_config->mark_api_success($api_config['id']);
                    
                    // 更新最后请求时间（恢复频率限制）
                    update_option('content_auto_last_api_request', time());
                    
                    return $result;
                } else {
                    // 记录最后一次错误详情
                    $last_error_data = $result;
                    
                    // 标记API失败
                    $this->api_config->mark_api_failed($api_config['id']);
                    
                    // 如果不是最后一次尝试，继续重试
                    if ($attempt < $max_retries) {
                        continue;
                    }
                }
            }
        }
        
        return $last_error_data;
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
     * 移除 AI 思考标签
     * 某些 AI 模型会在响应中输出 <think>...</think> 标签包裹的思考过程
     * 这些内容应该被过滤掉，不应该返回给业务逻辑
     * 
     * @param string $content 原始内容
     * @return string 移除思考标签后的内容
     */
    private function strip_think_tags($content) {
        if (empty($content) || !is_string($content)) {
            return $content;
        }
        
        // 移除 <think>...</think> 标签及其内容
        // 使用 s 修饰符使 . 能匹配换行符
        // 使用 i 修饰符忽略大小写
        $cleaned = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content);
        
        // 清理可能留下的多余空白行
        $cleaned = preg_replace('/^\s*\n/m', '', $cleaned);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        
        return trim($cleaned);
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
    /* ==========================================================================
     * SSE 流式客户端实现
     * ========================================================================== */

    /**
     * 发送流式请求 (客户端)
     * 替代 wp_remote_post，使用底层 cURL 实现实时接收
     */
    private function send_streaming_request($url, $args, $additional_params = array()) {
        $ch = curl_init();
        
        $headers = [];
        if (isset($args['headers'])) {
            // [FIX] Ensure we don't duplicate headers if they are key-value array vs indexed array
            foreach ($args['headers'] as $k => $v) {
                if (is_int($k)) {
                    $headers[] = $v;
                } else {
                    $headers[] = "$k: $v";
                }
            }
        }
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // 关键：禁用自动返回，改用 WriteFunction
        curl_setopt($ch, CURLOPT_TIMEOUT, $args['timeout']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $args['sslverify']);
        if (isset($args['httpversion']) && $args['httpversion'] === '1.1') {
             curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        }
        
        // [关键修复] 自动处理 gzip/deflate/br 响应压缩
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        $accumulated_content = '';
        $buffer = '';
        
        // 用于在闭包中捕获流式错误
        $stream_error = null;
        $stream_usage = null;
        $stream_finish_reason = null;
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$accumulated_content, &$buffer, &$stream_error, &$stream_usage, &$stream_finish_reason, $additional_params) {
            // 如果已经发生了错误，直接返回0中断传输
            if ($stream_error) return 0;
            
            // 【安全哨兵】检测任务是否存活。如果任务已删除，立即中断传输。
            if (!ContentAuto_SafetySentinel::is_execution_valid($additional_params)) {
                $stream_error = "Execution Aborted: Ghost task detected during stream.";
                return 0; // 中断 cURL
            }
            
            $buffer .= $chunk;
            
            // 规范化换行符
            $buffer = str_replace("\r\n", "\n", $buffer);
            
            // 按照标准的 MDN Server-Sent Events (SSE) 规范，事件通过连续两个换行符(\n\n)分隔
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                // 截取一个事件块
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                
                $lines = explode("\n", $block);
                $data_lines = [];
                $event_type = 'message';
                
                // 解析每一行的字段 (event, data, id, retry)
                foreach ($lines as $line) {
                    if (strpos($line, 'event: ') === 0) {
                        $event_type = substr($line, 7);
                    } elseif (strpos($line, 'event:') === 0) {
                        $event_type = substr($line, 6);
                    } elseif (strpos($line, 'data: ') === 0) {
                        $data_lines[] = substr($line, 6);
                    } elseif (strpos($line, 'data:') === 0) {
                        $data_lines[] = substr($line, 5);
                    }
                }
                
                // SSE 规范允许空的 data，但如果我们没提取到 data 字段，就跳过这个事件块
                if (empty($data_lines)) {
                    continue;
                }
                
                // 将多行 data 合并为完整的负荷
                $json_str = implode("\n", $data_lines);
                
                if ($json_str === '[DONE]') {
                    break;
                }

                // 容错：修复某些不规范模型在 JSON 值中返回了未转义物理换行符的问题
                $safe_json_str = preg_replace_callback('/"([^"\\\\]|\\\\.)*"/s', function($matches) {
                    return str_replace(array("\r", "\n"), array('\r', '\n'), $matches[0]);
                }, $json_str);

                $json = @json_decode($safe_json_str, true);
                
                if ($json !== null) {
                    // 已成功解析出完整的 JSON 对象
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $accumulated_content .= $json['choices'][0]['delta']['content'];
                    }
                    // 或者 OpenAI 兼容格式的其他可能位置
                    elseif (isset($json['choices'][0]['text'])) {
                        $accumulated_content .= $json['choices'][0]['text'];
                    }
                    // 流内嵌错误 (OpenAI/Proxy 格式)
                    elseif (isset($json['error'])) {
                        $error_msg = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'])) : $json['error'];
                        $stream_error = "Stream API Error: " . $error_msg;
                        return 0; // 中断 cURL
                    }
                    // 兼容 DeepSeek/Other 的用法字段 (可选处理)
                    elseif (isset($json['usage'])) {
                        $stream_usage = $json['usage'];
                    }
                    
                    if (isset($json['choices'][0]['finish_reason']) && $json['choices'][0]['finish_reason'] !== null) {
                        $stream_finish_reason = $json['choices'][0]['finish_reason'];
                    }
                }
            }
            return strlen($chunk);
        });
        
        ob_start();
        $success = curl_exec($ch);
        $output = ob_get_clean(); // 捕获可能泄漏的输出
        
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // 处理最后可能残留在 buffer 中的不带 \n\n 的未完全块
        if (!empty($buffer) && !$stream_error && strpos($buffer, 'data:') !== false) {
            $lines = explode("\n", $buffer);
            $data_lines = [];
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $data_lines[] = substr($line, 6);
                } elseif (strpos($line, 'data:') === 0) {
                    $data_lines[] = substr($line, 5);
                }
            }
            if (!empty($data_lines)) {
                $json_str = implode("\n", $data_lines);
                if ($json_str !== '[DONE]') {
                    $safe_json_str = preg_replace_callback('/"([^"\\\\]|\\\\.)*"/s', function($matches) {
                        return str_replace(array("\r", "\n"), array('\r', '\n'), $matches[0]);
                    }, $json_str);
                    $json = @json_decode($safe_json_str, true);
                    if ($json !== null) {
                        if (isset($json['choices'][0]['delta']['content'])) {
                            $accumulated_content .= $json['choices'][0]['delta']['content'];
                        } elseif (isset($json['choices'][0]['text'])) {
                            $accumulated_content .= $json['choices'][0]['text'];
                        }
                        if (isset($json['choices'][0]['finish_reason']) && $json['choices'][0]['finish_reason'] !== null) {
                            $stream_finish_reason = $json['choices'][0]['finish_reason'];
                        }
                    }
                }
            }
        }
        curl_close($ch);

        // 优先处理流内部捕获的业务错误
        if ($stream_error) {
            return new WP_Error('api_error', $stream_error, ['http_code' => $http_code]);
        }

        if ($error) {
            return new WP_Error('curl_error', "cURL Error: $error", ['http_code' => $http_code]);
        }

        if ($http_code < 200 || $http_code >= 300) {
            // 在流式请求中，如果 HTTP 状态码错误，往往内容并不是真正的流，而是错误信息 JSON
            $error_info = json_decode($accumulated_content, true);
            $error_msg = isset($error_info['error']['message']) ? $error_info['error']['message'] : (empty($accumulated_content) ? 'HTTP Request Failed' : $accumulated_content);
            return new WP_Error('api_http_error', "HTTP Error $http_code: " . $error_msg, ['http_code' => $http_code, 'body' => $accumulated_content]);
        }
        
        if (empty(trim($accumulated_content))) {
             return new WP_Error('empty_response', "Streaming API returned empty content but HTTP status was 200 OK. Output Buffer: $output.", ['http_code' => $http_code]);
        }

        // 把累积拼接的内容打包成标准数组返回，与非流式保持结构一致
        $response_data = [
            'choices' => [
                [
                    'message' => [
                        'content' => $accumulated_content
                    ]
                ]
            ]
        ];
        
        if ($stream_usage) {
            $response_data['usage'] = $stream_usage;
        }
        
        if ($stream_finish_reason) {
            $response_data['choices'][0]['finish_reason'] = $stream_finish_reason;
        }

        return [
            'body' => wp_json_encode($response_data),
            'response' => ['code' => $http_code, 'message' => 'OK']
        ];
    }
    
    /**
     * 处理 Gemini API 请求
     */
    private function handle_gemini_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $context) {
        $model = $api_config['model_name'];
        $api_key = $api_config['api_key'];
        $base_url = rtrim($api_config['api_url'], '/');
        
        // 构建完整的Gemini URL
        $endpoint = isset($api_config['stream']) && $api_config['stream'] ? ':streamGenerateContent' : ':generateContent';
        $api_url = $base_url . '/' . $model . $endpoint . '?key=' . $api_key;
        // 流式模式添加 alt=sse 参数，让 Gemini 返回标准 SSE 格式
        if (isset($api_config['stream']) && $api_config['stream']) {
            $api_url .= '&alt=sse';
        }
        
        // 构建请求体
        $body_data = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array()
        );
        
        // 仅在启用时添加 temperature
        if (!empty($api_config['temperature_enabled'])) {
            $body_data['generationConfig']['temperature'] = isset($api_config['temperature']) ? (float)$api_config['temperature'] : 0.7;
        }
        
        // 仅在启用时添加 maxOutputTokens
        if (!empty($api_config['max_tokens_enabled'])) {
            $body_data['generationConfig']['maxOutputTokens'] = isset($api_config['max_tokens']) ? (int)$api_config['max_tokens'] : 8000;
        }
        
        // 添加 top_p 如果启用
        if (isset($api_config['top_p_enabled']) && $api_config['top_p_enabled']) {
            $body_data['generationConfig']['topP'] = (float) $api_config['top_p'];
        }
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
            ),
            'body' => json_encode($body_data, JSON_INVALID_UTF8_SUBSTITUTE),
            'timeout' => isset($additional_params['timeout']) ? (int)$additional_params['timeout'] : 300,
            'sslverify' => true,
        );
        
        $use_streaming = isset($api_config['stream']) ? (bool)$api_config['stream'] : true;
        
        if ($use_streaming) {
            return $this->handle_gemini_streaming_request($api_url, $args, $context, $additional_params);
        } else {
            $response = wp_remote_post($api_url, $args);
            return $this->process_gemini_response($response, $context, $additional_params);
        }
    }
    
    /**
     * 处理 Gemini 流式请求
     */
    private function handle_gemini_streaming_request($api_url, $args, $context, $additional_params) {
        $ch = curl_init();
        
        $headers = array('Content-Type: application/json');
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $args['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $args['timeout'],
            CURLOPT_SSL_VERIFYPEER => $args['sslverify'],
            CURLOPT_ENCODING => '',
        ));
        
        $accumulated_content = '';
        $buffer = '';
        $stream_error = null;
        $stream_usage = null;
        $stream_finish_reason = null;
        
        // Gemini SSE 流式响应格式 (alt=sse)
        // 每行以 "data: " 前缀开头，包含完整的 JSON 对象
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$accumulated_content, &$buffer, &$stream_error, &$stream_usage, &$stream_finish_reason, $additional_params) {
                if ($stream_error) return 0;
                
                // 【安全哨兵】检测任务是否存活
                if (!ContentAuto_SafetySentinel::is_execution_valid($additional_params)) {
                    $stream_error = "Execution Aborted: Ghost task detected during Gemini stream.";
                    return 0; // 中断 cURL
                }
                
                $buffer .= $chunk;
                
                // 规范化换行符
                $buffer = str_replace("\r\n", "\n", $buffer);
                
                // 按照标准的 MDN Server-Sent Events (SSE) 规范进行处理
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    
                    $lines = explode("\n", $block);
                    $data_lines = [];
                    
                    foreach ($lines as $line) {
                        if (strpos($line, 'data: ') === 0) {
                            $data_lines[] = substr($line, 6);
                        } elseif (strpos($line, 'data:') === 0) {
                            $data_lines[] = substr($line, 5);
                        }
                    }
                    
                    if (empty($data_lines)) {
                        continue;
                    }
                    
                    $json_str = implode("\n", $data_lines);
                    
                    if ($json_str === '[DONE]') {
                        break;
                    }
                    
                    // 修复 for Gemini: 如果负载内有未转义的物理换行
                    $safe_json_str = preg_replace_callback('/"([^"\\\\]|\\\\.)*"/s', function($matches) {
                        return str_replace(array("\r", "\n"), array('\r', '\n'), $matches[0]);
                    }, $json_str);

                    $json = @json_decode($safe_json_str, true);
                    if ($json !== null) {
                        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                            $accumulated_content .= $json['candidates'][0]['content']['parts'][0]['text'];
                        }
                        
                        // 提取 usageMetadata
                        if (isset($json['usageMetadata'])) {
                            $stream_usage = $json['usageMetadata'];
                        }
                        
                        // 提取 finishReason
                        if (isset($json['candidates'][0]['finishReason']) && $json['candidates'][0]['finishReason'] !== null) {
                            $stream_finish_reason = $json['candidates'][0]['finishReason'];
                        }
                        
                        // 错误处理
                        if (isset($json['error'])) {
                            $error_msg = isset($json['error']['message']) ? $json['error']['message'] : json_encode($json['error']);
                            $stream_error = "Gemini Stream Error: " . $error_msg;
                            return 0;
                        }
                    }
                }
                
                return strlen($chunk);
            });
        
        ob_start();
        $success = curl_exec($ch);
        ob_end_clean();
        
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($stream_error) {
            return array('error' => $stream_error);
        }
        
        if ($error) {
            return array('error' => 'Gemini Stream Error: ' . $error);
        }
        
        if ($http_code >= 400) {
            $err_msg = 'Gemini HTTP Error: ' . $http_code;
            if (!empty($buffer)) {
                $err_msg .= ' - Response: ' . trim(mb_substr($buffer, 0, 1000));
            }
            return array('error' => $err_msg);
        }
        
        $final_content = $this->strip_think_tags($accumulated_content);
        
        // 追加: Gemini 经常会返回带 ```json 和 ``` 包裹的内容（非预期），这里进行剥离
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', trim($final_content), $matches)) {
            $final_content = $matches[1];
        }
        
        if (empty($final_content)) {
            return array('error' => 'Gemini response was empty');
        }
        
        // Gemini 格式转换：MAX_TOKENS -> length
        if ($stream_finish_reason === 'MAX_TOKENS') {
            $stream_finish_reason = 'length';
        }
        
        if (!empty($additional_params['return_usage'])) {
            // ✅ length 视为 API 失败，返回 error 让上层 fallback 机制自动切换
            if ($stream_finish_reason === 'length') {
                return array('error' => 'Gemini流式API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
            }
            
            $response_data = array(
                'content' => $final_content,
                'usage' => $stream_usage ? $stream_usage : array()
            );
            if ($stream_finish_reason) {
                $response_data['finish_reason'] = $stream_finish_reason;
            }
            return $response_data;
        }
        
        return $final_content;
    }
    
    /**
     * 处理 Gemini 响应
     */
    private function process_gemini_response($response, $context, $additional_params) {
        if (is_wp_error($response)) {
            return array('error' => 'Gemini Request Error: ' . $response->get_error_message());
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code >= 400) {
            $error_message = isset($response_data['error']['message']) 
                ? $response_data['error']['message'] 
                : 'HTTP ' . $response_code;
            return array('error' => 'Gemini API Error: ' . $error_message);
        }
        
        if (isset($response_data['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $response_data['candidates'][0]['content']['parts'][0]['text'];
            
            // Special handling for structure generation task
            if ($task_type === 'structure_generation') {
                $final_content = $this->strip_think_tags($content);
                // Strip markdown wrappers if any
                if (preg_match('/^```(?:xml|json)?\s*([\s\S]*?)\s*```$/i', trim($final_content), $matches)) {
                    $final_content = $matches[1];
                }
                
                if (!empty($additional_params['return_usage'])) {
                    $finish_reason = isset($response_data['candidates'][0]['finishReason']) ? $response_data['candidates'][0]['finishReason'] : null;
                    if ($finish_reason === 'MAX_TOKENS') $finish_reason = 'length';
                    
                    if ($finish_reason === 'length') {
                        return array('error' => 'Gemini API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
                    }
                    
                    return array(
                        'content' => $final_content,
                        'usage' => isset($response_data['usageMetadata']) ? $response_data['usageMetadata'] : array(),
                        'finish_reason' => $finish_reason
                    );
                }
                return $final_content;
            }

            $final_content = $this->strip_think_tags($content);
            
            // 追加: Gemini 经常会返回带 ```json 和 ``` 包裹的内容（非预期），这里进行剥离
            if (preg_match('/^```(?:json)?\s*([\s\S]*?)\s*```$/i', trim($final_content), $matches)) {
                $final_content = $matches[1];
            }
            
            if (!empty($additional_params['return_usage'])) {
                $finish_reason = isset($response_data['candidates'][0]['finishReason']) ? $response_data['candidates'][0]['finishReason'] : null;
                // Gemini 长度截断标记为 MAX_TOKENS，映射为统一的 length
                if ($finish_reason === 'MAX_TOKENS') {
                    $finish_reason = 'length';
                }
                
                // ✅ length 视为 API 失败，返回 error 让上层 fallback 机制自动切换
                if ($finish_reason === 'length') {
                    return array('error' => 'Gemini API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
                }
                
                return array(
                    'content' => $final_content,
                    'usage' => isset($response_data['usageMetadata']) ? $response_data['usageMetadata'] : array(),
                    'finish_reason' => $finish_reason
                );
            }
            
            return $final_content;
        }
        
        return array('error' => 'No content in Gemini response');
    }
    
    /**
     * 处理 Claude API 请求
     */
    private function handle_claude_request($api_config, $prompt, $task_type, $additional_params, $method_start_time, $start_memory, $context) {
        $api_url = $api_config['api_url'];
        $api_key = $api_config['api_key'];
        $model = $api_config['model_name'];
        
        // 构建请求体 (Claude Messages API 格式)
        $body_data = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'user', 'content' => $prompt)
            ),
            // Claude API 要求必须传递 max_tokens 参数
            // 若用户关闭 max_tokens_enabled，则使用较大的安全默认值，让模型自由生成不被截断
            'max_tokens' => (!empty($api_config['max_tokens_enabled']))
                ? (isset($api_config['max_tokens']) ? (int)$api_config['max_tokens'] : 8000)
                : 16000,
        );
        
        // 添加温度参数 (Claude 支持)
        if (!empty($api_config['temperature_enabled'])) {
            $body_data['temperature'] = (float) $api_config['temperature'];
        }
        
        // 添加 top_p 参数 (Claude 支持)
        if (isset($api_config['top_p_enabled']) && $api_config['top_p_enabled']) {
            $body_data['top_p'] = (float) $api_config['top_p'];
        }
        
        // Claude 支持流式输出
        $use_streaming = isset($api_config['stream']) ? (bool)$api_config['stream'] : true;
        if ($use_streaming) {
            $body_data['stream'] = true;
        }
        
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
            ),
            'body' => json_encode($body_data, JSON_INVALID_UTF8_SUBSTITUTE),
            'timeout' => isset($additional_params['timeout']) ? (int)$additional_params['timeout'] : 300,
            'sslverify' => true,
        );
        
        if ($use_streaming) {
            $args['headers']['Accept'] = 'text/event-stream';
            return $this->handle_claude_streaming_request($api_url, $args, $context, $additional_params);
        } else {
            $response = wp_remote_post($api_url, $args);
            return $this->process_claude_response($response, $context, $additional_params);
        }
    }
    
    /**
     * 处理 Claude 流式请求
     */
    private function handle_claude_streaming_request($api_url, $args, $context, $additional_params) {
        $ch = curl_init();
        
        $headers = array();
        foreach ($args['headers'] as $k => $v) {
            $headers[] = "$k: $v";
        }
        $headers[] = 'Accept: text/event-stream';
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $args['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $args['timeout'],
            CURLOPT_SSL_VERIFYPEER => $args['sslverify'],
            CURLOPT_ENCODING => '',
        ));
        
        $accumulated_content = '';
        $buffer = '';
        $stream_error = null;
        $stream_finish_reason = null;
        
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$accumulated_content, &$buffer, &$stream_error, &$stream_finish_reason, $additional_params) {
            if ($stream_error) return 0;
            
            // 【安全哨兵】检测任务是否存活
            if (!ContentAuto_SafetySentinel::is_execution_valid($additional_params)) {
                $stream_error = "Execution Aborted: Ghost task detected during Claude stream.";
                return 0; // 中断 cURL
            }
            
            $buffer .= $chunk;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                
                if (empty($line)) continue;
                
                // Claude 流式响应格式
                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') continue;
                    
                    $json = json_decode($json_str, true);
                    
                    // Claude content_block_delta 事件
                    if (isset($json['type']) && $json['type'] === 'content_block_delta') {
                        if (isset($json['delta']['text'])) {
                            $accumulated_content .= $json['delta']['text'];
                        }
                    }
                    // Claude content 直接格式
                    elseif (isset($json['delta']['text'])) {
                        $accumulated_content .= $json['delta']['text'];
                    }
                    // 错误处理
                    elseif (isset($json['type']) && $json['type'] === 'error') {
                        $stream_error = isset($json['error']['message']) 
                            ? $json['error']['message'] 
                            : json_encode($json['error']);
                        return 0;
                    }
                    // Claude 消息结束事件 / Delta (获取 stop_reason)
                    elseif (isset($json['type']) && ($json['type'] === 'message_delta' || $json['type'] === 'message_stop')) {
                        if (isset($json['delta']['stop_reason'])) {
                            $stream_finish_reason = $json['delta']['stop_reason'];
                        } elseif (isset($json['message']['stop_reason'])) {
                            $stream_finish_reason = $json['message']['stop_reason'];
                        }
                    }
                }
            }
            
            return strlen($chunk);
        });
        
        ob_start();
        $success = curl_exec($ch);
        ob_end_clean();
        
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($stream_error) {
            return array('error' => 'Claude Stream Error: ' . $stream_error);
        }
        
        if ($error) {
            return array('error' => 'Claude cURL Error: ' . $error);
        }
        
        if ($http_code >= 400) {
            $err_msg = 'Claude HTTP Error: ' . $http_code;
            if (!empty($buffer)) {
                $err_msg .= ' - Response: ' . trim(mb_substr($buffer, 0, 1000));
            }
            return array('error' => $err_msg);
        }
        
        $final_content = $this->strip_think_tags($accumulated_content);
        
        if (empty($final_content)) {
            return array('error' => 'Claude response was empty');
        }
        
        // 尝试解析可能遗留在 buffer 中的非标准或截断的 finish_reason
        if (empty($stream_finish_reason) && !empty($buffer) && strpos($buffer, 'stop_reason') !== false) {
             preg_match('/"stop_reason"\s*:\s*"([^"]+)"/', $buffer, $reason_match);
             if (!empty($reason_match[1])) {
                 $stream_finish_reason = $reason_match[1];
             }
        }

        // Claude 格式转换：max_tokens -> length
        if ($stream_finish_reason === 'max_tokens') {
            $stream_finish_reason = 'length';
        }
        
        if (!empty($additional_params['return_usage'])) {
            // ✅ length 视为 API 失败，返回 error 让上层 fallback 机制自动切换
            if ($stream_finish_reason === 'length') {
                return array('error' => 'Claude流式API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
            }
            
            $response_data = array(
                'content' => $final_content,
                'usage' => array()
            );
            if ($stream_finish_reason) {
                $response_data['finish_reason'] = $stream_finish_reason;
            }
            return $response_data;
        }
        
        return $final_content;
    }
    
    /**
     * 处理 Claude 响应
     */
    private function process_claude_response($response, $context, $additional_params) {
        if (is_wp_error($response)) {
            return array('error' => 'Claude Request Error: ' . $response->get_error_message());
        }
        
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code >= 400) {
            $error_message = isset($response_data['error']['message']) 
                ? $response_data['error']['message'] 
                : (isset($response_data['error']) ? json_encode($response_data['error']) : 'HTTP ' . $response_code);
            return array('error' => 'Claude API Error: ' . $error_message);
        }
        
        // Claude 响应格式
        if (isset($response_data['content'][0]['text'])) {
            $content = $response_data['content'][0]['text'];
            
            // Special handling for structure generation task
            if ($task_type === 'structure_generation') {
                $final_content = $this->strip_think_tags($content);
                // Strip markdown wrappers if any
                if (preg_match('/^```(?:xml|json)?\s*([\s\S]*?)\s*```$/i', trim($final_content), $matches)) {
                    $final_content = $matches[1];
                }
                
                if (!empty($additional_params['return_usage'])) {
                    $finish_reason = isset($response_data['stop_reason']) ? $response_data['stop_reason'] : null;
                    if ($finish_reason === 'max_tokens') $finish_reason = 'length';
                    
                    if ($finish_reason === 'length') {
                        return array('error' => 'Claude API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
                    }
                    
                    return array(
                        'content' => $final_content,
                        'usage' => isset($response_data['usage']) ? $response_data['usage'] : array(),
                        'finish_reason' => $finish_reason
                    );
                }
                return $final_content;
            }

            $final_content = $this->strip_think_tags($content);
            
            if (!empty($additional_params['return_usage'])) {
                $finish_reason = isset($response_data['stop_reason']) ? $response_data['stop_reason'] : null;
                // Claude 长度截断的标记通常是 max_tokens，为了统一处理，映射为 length
                if ($finish_reason === 'max_tokens') {
                    $finish_reason = 'length';
                }
                
                // ✅ length 视为 API 失败，返回 error 让上层 fallback 机制自动切换
                if ($finish_reason === 'length') {
                    return array('error' => 'Claude API生成失败：单次输出长度已达上限 (finish_reason: length)', 'finish_reason' => 'length');
                }
                
                return array(
                    'content' => $final_content,
                    'usage' => isset($response_data['usage']) ? $response_data['usage'] : array(),
                    'finish_reason' => $finish_reason
                );
            }
            
            return $final_content;
        }
        
        return array('error' => 'No content in Claude response');
    }
    
    /**
     * 检查当前执行上下文是否仍然有效
     * 整合了 Job Queue ID 和业务 Task ID 的双重检测
     */
    private function is_execution_valid($additional_params) {
        return ContentAuto_SafetySentinel::is_execution_valid($additional_params);
    }
}