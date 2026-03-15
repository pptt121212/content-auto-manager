<?php
/**
 * API配置管理类
 */

if (!defined('ABSPATH')) {
    exit;
}


class ContentAuto_ApiConfig {
    
    private $database;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
    }
    
    /**
     * 创建API配置
     */
    public function create_config($data, $is_predefined = false) {
        // 如果传入的是参数对象，转换为数组
        if ($data instanceof ContentAuto_ApiConfigParams) {
            $data = $data->toArray();
        }
        
        // 验证数据
        $validated_data = $this->validate_config_data($data, $is_predefined);
        if (!$validated_data) {
            return false;
        }
        
        // 插入数据
        return $this->database->insert('content_auto_api_configs', $validated_data);
    }
    
    /**
     * 更新API配置
     */
    public function update_config($id, $data, $is_predefined = false) {
        // 如果传入的是参数对象，转换为数组
        if ($data instanceof ContentAuto_ApiConfigParams) {
            $data = $data->toArray();
        }
        
        // 验证数据
        $validated_data = $this->validate_config_data($data, $is_predefined);
        if (!$validated_data) {
            return false;
        }
        
        // 更新数据
        return $this->database->update('content_auto_api_configs', $validated_data, array('id' => $id));
    }
    
    /**
     * 删除API配置
     */
    public function delete_config($id) {
        return $this->database->delete('content_auto_api_configs', array('id' => $id));
    }
    
    /**
     * 获取单个API配置
     */
    public function get_config($id) {
        return $this->database->get_row('content_auto_api_configs', array('id' => $id));
    }
    
    /**
     * 获取所有API配置
     */
    public function get_configs() {
        return $this->database->get_results('content_auto_api_configs');
    }
    
    /**
     * 获取所有激活的API配置
     */
    public function get_active_configs() {
        return $this->database->get_results('content_auto_api_configs', array('is_active' => 1));
    }
    
    /**
     * 获取所有激活的向量API配置
     */
    public function get_active_vector_configs() {
        // 获取所有激活的配置
        $active_configs = $this->database->get_results('content_auto_api_configs', array('is_active' => 1));
        
        // 过滤出向量API配置
        $vector_configs = array();
        foreach ($active_configs as $config) {
            if (!empty($config['vector_api_url']) && !empty($config['vector_api_key']) && !empty($config['vector_model_name'])) {
                $vector_configs[] = $config;
            }
        }
        
        return $vector_configs;
    }
    
    /**
     * 获取向量API配置（不检查激活状态，因为只有一个向量API配置）
     */
    public function get_vector_config() {
        // 获取所有配置
        $all_configs = $this->database->get_results('content_auto_api_configs');
        
        // 过滤出向量API配置
        foreach ($all_configs as $config) {
            if (!empty($config['vector_api_url']) && !empty($config['vector_api_key']) && !empty($config['vector_model_name'])) {
                return $config; // 返回第一个找到的向量API配置
            }
        }
        
        return null; // 没有找到向量API配置
    }
    
    /**
     * 获取下一个激活的API配置（实现轮询机制）
     */
    public function get_next_active_config($is_retry = false) {
        // 移除API请求间隔时间检查，因为子任务间已有30秒间隔
        // 仅在非重试的首次请求时检查API请求间隔时间
        // if (!$is_retry) {
        //     $min_interval = CONTENT_AUTO_MIN_API_INTERVAL;
        //     $last_request_time = get_option('content_auto_last_api_request', 0);
        //     $current_time = time();
        //     
        //     if ($current_time - $last_request_time < $min_interval) {
        //         $wait_time = $min_interval - ($current_time - $last_request_time);
        //         return null;
        //     }
        // }
        
        // 获取所有激活的API配置
        $configs = $this->get_active_configs();
        
        if (empty($configs)) {
            return null;
        }
        
        // 获取当前时间
        $current_time = time();
        
        // 如果只有一个配置，直接返回
        if (count($configs) == 1) {
            // 更新最后请求时间
            update_option('content_auto_last_api_request', $current_time);
            return $configs[0];
        }
        
        // 使用选项存储当前使用的API配置索引，实现简单轮询
        $current_index_option = 'content_auto_current_api_index';
        $current_index = get_option($current_index_option, 0);
        
        // 确保索引在有效范围内
        $current_index = $current_index % count($configs);
        $selected_config = $configs[$current_index];
        $selected_index = $current_index;
        
        // 更新索引为下一个配置
        $next_index = ($selected_index + 1) % count($configs);
        update_option($current_index_option, $next_index);
        
        // 更新最后请求时间
        update_option('content_auto_last_api_request', $current_time);
        
        return $selected_config;
    }
    
    /**
     * 标记API配置为失败状态
     * @param int $api_id API配置ID
     * @return bool 是否成功标记
     */
    public function mark_api_failed($api_id) {
        if (empty($api_id)) {
            return false;
        }
        
        // 获取当前失败记录
        $failed_apis = get_option('content_auto_failed_apis', array());
        
        // 添加或更新失败记录
        $failed_apis[$api_id] = time();
        
        // 更新失败记录
        update_option('content_auto_failed_apis', $failed_apis);
        
        return true;
    }
    
    /**
     * 标记API配置为成功状态（清除失败记录）
     * @param int|string $api_id API配置ID或标识符
     * @return bool 是否成功清除
     */
    public function mark_api_success($api_id) {
        if (empty($api_id)) {
            return false;
        }
        
        // 获取当前失败记录
        $failed_apis = get_option('content_auto_failed_apis', array());
        
        // 如果存在失败记录，清除它
        if (isset($failed_apis[$api_id])) {
            unset($failed_apis[$api_id]);
            update_option('content_auto_failed_apis', $failed_apis);
        }
        
        return true;
    }
    
    /**
     * 重置所有API失败记录
     * @return bool 是否成功重置
     */
    public function reset_all_failed_apis() {
        update_option('content_auto_failed_apis', array());
        return true;
    }
    
    /**
     * 获取当前失败状态
     * @return array 失败的API列表
     */
    public function get_failed_apis() {
        $failed_apis = get_option('content_auto_failed_apis', array());
        
        // 清理过期的失败记录
        $failure_timeout = 30 * 60;
        $current_time = time();
        foreach ($failed_apis as $api_id => $failure_time) {
            if ($current_time - $failure_time > $failure_timeout) {
                unset($failed_apis[$api_id]);
            }
        }
        
        if (count($failed_apis) != count(get_option('content_auto_failed_apis', array()))) {
            update_option('content_auto_failed_apis', $failed_apis);
        }
        
        return $failed_apis;
    }
    
    /**
     * 获取单个激活的API配置（向后兼容）
     */
    public function get_active_config() {
        $configs = $this->get_active_configs();
        return !empty($configs) ? $configs[0] : null;
    }
    
    /**
     * 设置激活的API配置
     */
    public function set_active_config($id) {
        // 直接将指定配置设为激活，不再禁用其他配置
        return $this->database->update('content_auto_api_configs', array('is_active' => 1), array('id' => $id));
    }

    /**
     * 更新API配置的激活状态
     */
    public function update_active_status($id, $is_active) {
        $is_active = intval($is_active);
        return $this->database->update('content_auto_api_configs', array('is_active' => $is_active), array('id' => $id));
    }
    
    /**
     * 验证配置数据
     */
    private function validate_config_data($data, $is_predefined = false) {
        $validated_data = array();

        // 验证必需字段
        if (empty($data['name'])) {
            return false;
        }
        $validated_data['name'] = sanitize_text_field($data['name']);

        // 检查是否为向量API配置
        $is_vector_config = !empty($data['vector_api_url']) || !empty($data['vector_api_key']) || !empty($data['vector_model_name']);

        if ($is_vector_config) {
            // 向量API配置 - 验证向量字段
            // 验证向量API类型 (提前验证以便用于Key的校验)
            $api_type = isset($data['vector_api_type']) ? sanitize_text_field($data['vector_api_type']) : 'openai';
            if (!in_array($api_type, array('openai', 'jina'))) {
                $api_type = 'openai'; // 默认为OpenAI
            }
            $validated_data['vector_api_type'] = $api_type;

            if (empty($data['vector_api_url'])) {
                return false;
            }
            $validated_data['vector_api_url'] = esc_url_raw($data['vector_api_url']);

            // 只有非Jina类型才强制校验Key
            if ($api_type !== 'jina' && empty($data['vector_api_key'])) {
                return false;
            }
            $validated_data['vector_api_key'] = sanitize_text_field($data['vector_api_key']);

            if (empty($data['vector_model_name'])) {
                return false;
            }
            $validated_data['vector_model_name'] = sanitize_text_field($data['vector_model_name']);

            // 向量API配置时，传统API字段设为空或默认值
            $validated_data['api_url'] = '';
            $validated_data['api_key'] = '';
            $validated_data['model_name'] = '';
            $validated_data['temperature'] = 0.70;
            $validated_data['max_tokens'] = 8000;
            $validated_data['temperature_enabled'] = 0;
            $validated_data['max_tokens_enabled'] = 0;
            
            // 新参数默认值
            $validated_data['stream'] = false;
            $validated_data['top_p'] = 1.0;
            $validated_data['stream_enabled'] = 0;
            $validated_data['top_p_enabled'] = 0;
        } else {
            // 传统API配置 - 验证传统字段
            if (empty($data['api_url'])) {
                return false;
            }
            $validated_data['api_url'] = esc_url_raw($data['api_url']);

            // 对于预置API，api_key是可选的
            if (!$is_predefined) {
                if (empty($data['api_key'])) {
                    return false;
                }
            }
            // 如果提供了api_key，进行验证和清理
            if (isset($data['api_key']) && !empty($data['api_key'])) {
                $validated_data['api_key'] = sanitize_text_field($data['api_key']);
            } else {
                $validated_data['api_key'] = '';
            }

            // 对于预置API，model_name是可选的
            if (!$is_predefined) {
                if (empty($data['model_name'])) {
                    return false;
                }
                $validated_data['model_name'] = sanitize_text_field($data['model_name']);
            } else {
                // 预置API的model_name可以留空或设置默认值
                $validated_data['model_name'] = isset($data['model_name']) ? sanitize_text_field($data['model_name']) : __('预置模型', 'yali-ai-writer');
            }

            // 验证API类型 (仅非预置API)
            if (!$is_predefined) {
                $api_type = isset($data['api_type']) ? sanitize_text_field($data['api_type']) : 'openai';
                if (!in_array($api_type, array('openai', 'gemini', 'claude'))) {
                    $api_type = 'openai'; // 默认为OpenAI
                }
                $validated_data['api_type'] = $api_type;
            } else {
                $validated_data['api_type'] = 'openai'; // 预置API默认为OpenAI格式
            }

            // 验证可选字段 - 只有在数据中存在时才设置
            if (isset($data['temperature'])) {
                $temp = floatval($data['temperature']);
                if ($temp >= 0 && $temp <= 2) {
                    $validated_data['temperature'] = $temp;
                } else {
                    $validated_data['temperature'] = 0.7; // 默认值
                }
            }
            if (isset($data['max_tokens'])) {
                $tokens = intval($data['max_tokens']);
                if ($tokens >= 1 && $tokens <= 32000) {
                    $validated_data['max_tokens'] = $tokens;
                } else {
                    $validated_data['max_tokens'] = 8000; // 默认值 (已升级适应长文)
                }
            }
            $validated_data['temperature_enabled'] = isset($data['temperature_enabled']) ? intval($data['temperature_enabled']) : 0;
            $validated_data['max_tokens_enabled'] = isset($data['max_tokens_enabled']) ? intval($data['max_tokens_enabled']) : 0;

            // 验证新增参数
            if (isset($data['stream'])) {
                $validated_data['stream'] = (bool) $data['stream'];
            } else {
                $validated_data['stream'] = false; // 默认关闭
            }

            if (isset($data['top_p'])) {
                $top_p = floatval($data['top_p']);
                if ($top_p >= 0 && $top_p <= 1) {
                    $validated_data['top_p'] = $top_p;
                } else {
                    $validated_data['top_p'] = 1.0; // 默认值
                }
            } else {
                $validated_data['top_p'] = 1.0; // 默认值
            }

            $validated_data['stream_enabled'] = isset($data['stream_enabled']) ? intval($data['stream_enabled']) : 0;
            $validated_data['top_p_enabled'] = isset($data['top_p_enabled']) ? intval($data['top_p_enabled']) : 0;

            // 传统API配置时，向量字段设为空
            $validated_data['vector_api_url'] = '';
            $validated_data['vector_api_key'] = '';
            $validated_data['vector_model_name'] = '';
        }

        $validated_data['is_active'] = isset($data['is_active']) ? intval($data['is_active']) : 0;

        return $validated_data;
    }
    
    /**
     * 检测API类型 (根据模型名称)
     */
    private function detect_api_type($model_name, $api_url) {
        $model_lower = strtolower($model_name);
        $url_lower = strtolower($api_url);
        
        // 检测 Gemini
        if (strpos($model_lower, 'gemini') !== false || strpos($url_lower, 'generativelanguage') !== false) {
            return 'gemini';
        }
        
        // 检测 Claude
        if (strpos($model_lower, 'claude') !== false || strpos($url_lower, 'anthropic') !== false) {
            return 'claude';
        }
        
        // 默认 OpenAI 格式
        return 'openai';
    }

    /**
     * 构建测试请求体 (根据不同API类型)
     */
    private function build_test_request_body($api_type, $config, $is_stream = false) {
        $model = $config['model_name'];
        $max_tokens = isset($config['max_tokens']) ? intval($config['max_tokens']) : 10;
        $temperature = isset($config['temperature']) ? floatval($config['temperature']) : 0.7;
        
        // 限制测试时的token数，避免过长响应
        $test_max_tokens = min($max_tokens, 50);
        
        switch ($api_type) {
            case 'gemini':
                // Gemini API 格式
                $body = array(
                    'contents' => array(
                        array(
                            'parts' => array(
                                array('text' => 'Hello, please respond with a short greeting.')
                            )
                        )
                    ),
                    'generationConfig' => array()
                );
                
                if (isset($config['temperature_enabled']) && $config['temperature_enabled']) {
                    $body['generationConfig']['temperature'] = $temperature;
                }
                
                if (isset($config['max_tokens_enabled']) && $config['max_tokens_enabled']) {
                    $body['generationConfig']['maxOutputTokens'] = $test_max_tokens;
                }
                
                // 测试连接不直接是某个任务类型，但由于这是系统通用的测试请求，我们如果希望保持默认也可以不加
                // 或者我们可以判断 $api_config 的用途。为了安全起见，普通测试连接不强制返回 JSON。
                // 如果需要强制返回JSON，可以添加 'responseMimeType' => 'application/json'
                // if (isset($config['action']) && $config['action'] === 'some_json_required_action') {
                //     $body['generationConfig']['responseMimeType'] = 'application/json';
                // }
                
                // 添加 top_p 如果启用
                if (isset($config['top_p_enabled']) && $config['top_p_enabled']) {
                    $body['generationConfig']['topP'] = floatval($config['top_p']);
                }
                
                return $body;
                
            case 'claude':
                // Claude API 格式
                $body = array(
                    'model' => $model,
                    'messages' => array(
                        array('role' => 'user', 'content' => 'Hello, please respond with a short greeting.')
                    )
                );
                
                if (isset($config['temperature_enabled']) && $config['temperature_enabled']) {
                    $body['temperature'] = $temperature;
                }
                
                if (isset($config['max_tokens_enabled']) && $config['max_tokens_enabled']) {
                    $body['max_tokens'] = $test_max_tokens;
                }
                
                // 添加 top_p 如果启用
                if (isset($config['top_p_enabled']) && $config['top_p_enabled']) {
                    $body['top_p'] = floatval($config['top_p']);
                }
                
                // Claude API 需要在 body 中设置 stream 参数来启用流式
                if ($is_stream) {
                    $body['stream'] = true;
                }
                
                return $body;
                
            case 'openai':
            default:
                // OpenAI 兼容格式
                $body = array(
                    'model' => $model,
                    'messages' => array(
                        array('role' => 'user', 'content' => 'Hello, please respond with a short greeting.')
                    )
                );
                
                if (isset($config['temperature_enabled']) && $config['temperature_enabled']) {
                    $body['temperature'] = $temperature;
                }
                
                if (isset($config['max_tokens_enabled']) && $config['max_tokens_enabled']) {
                    $body['max_tokens'] = $test_max_tokens;
                }
                
                // 添加 top_p 如果启用
                if (isset($config['top_p_enabled']) && $config['top_p_enabled']) {
                    $body['top_p'] = floatval($config['top_p']);
                }
                
                // 流式参数
                if ($is_stream) {
                    $body['stream'] = true;
                }
                
                return $body;
        }
    }

    /**
     * 获取测试请求Headers (根据不同API类型)
     */
    private function get_test_request_headers($api_type, $config) {
        switch ($api_type) {
            case 'gemini':
                // Gemini 通常通过 URL 参数传递 key，但也可以通过 header
                return array(
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                );
                
            case 'claude':
                return array(
                    'Content-Type' => 'application/json',
                    'x-api-key' => $config['api_key'],
                    'anthropic-version' => '2023-06-01',
                    'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                );
                
            case 'openai':
            default:
                return array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $config['api_key'],
                    'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                );
        }
    }

    /**
     * 解析测试响应 (根据不同API类型)
     */
    private function parse_test_response($api_type, $response_body) {
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('error' => 'JSON解析失败: ' . json_last_error_msg());
        }
        
        $result = array();
        
        switch ($api_type) {
            case 'gemini':
                // Gemini 响应格式
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $result['content'] = $data['candidates'][0]['content']['parts'][0]['text'];
                }
                if (isset($data['candidates'][0]['finishReason'])) {
                    $result['finish_reason'] = $data['candidates'][0]['finishReason'];
                }
                if (isset($data['usageMetadata'])) {
                    $result['prompt_tokens'] = $data['usageMetadata']['promptTokenCount'] ?? 0;
                    $result['completion_tokens'] = $data['usageMetadata']['candidatesTokenCount'] ?? 0;
                    $result['total_tokens'] = $data['usageMetadata']['totalTokenCount'] ?? 0;
                }
                return $result;
                
            case 'claude':
                // Claude 响应格式
                if (isset($data['content'][0]['text'])) {
                    $result['content'] = $data['content'][0]['text'];
                }
                if (isset($data['stop_reason'])) {
                    $result['finish_reason'] = $data['stop_reason'];
                }
                if (isset($data['usage'])) {
                    $result['prompt_tokens'] = $data['usage']['input_tokens'] ?? 0;
                    $result['completion_tokens'] = $data['usage']['output_tokens'] ?? 0;
                }
                return $result;
                
            case 'openai':
            default:
                // OpenAI 兼容格式
                if (isset($data['choices'][0]['message']['content'])) {
                    $result['content'] = $data['choices'][0]['message']['content'];
                }
                if (isset($data['choices'][0]['finish_reason'])) {
                    $result['finish_reason'] = $data['choices'][0]['finish_reason'];
                }
                if (isset($data['usage'])) {
                    $result['prompt_tokens'] = $data['usage']['prompt_tokens'] ?? 0;
                    $result['completion_tokens'] = $data['usage']['completion_tokens'] ?? 0;
                    $result['total_tokens'] = $data['usage']['total_tokens'] ?? 0;
                }
                return $result;
        }
    }

    /**
     * 测试API连接 (增强版: 支持 OpenAI/Gemini/Claude，返回详细信息)
     */
    public function test_connection($config_id) {
        $config = $this->get_config($config_id);
        if (!$config) {
            return array('success' => false, 'message' => __('配置不存在', 'yali-ai-writer'));
        }

        // 获取API类型（优先使用配置中的 api_type 字段，不存在则自动检测）
        $api_type = isset($config['api_type']) && !empty($config['api_type']) 
            ? $config['api_type'] 
            : $this->detect_api_type($config['model_name'], $config['api_url']);
        
        // 获取用户配置的流式开关 (默认为关闭)
        $use_stream = isset($config['stream']) ? (bool)$config['stream'] : false;
        
        $start_time = microtime(true);
        
        // 构建请求体
        $body_data = $this->build_test_request_body($api_type, $config, $use_stream);
        
        // 构建请求Headers
        $headers = $this->get_test_request_headers($api_type, $config);
        
        // 构建请求URL
        $api_url = $config['api_url'];
        
        // Gemini 特殊处理: 需要在URL中拼接参数
        if ($api_type === 'gemini') {
            $model_name = $config['model_name'];
            // 构建完整的Gemini URL，根据流式开关选择端点
            if (strpos($api_url, '?') === false) {
                $api_url = rtrim($api_url, '/') . '/' . $model_name;
                if ($use_stream) {
                    // 流式模式: 使用 alt=sse 让 Gemini 返回标准 SSE 格式
                    $api_url .= ':streamGenerateContent?key=' . $config['api_key'] . '&alt=sse';
                } else {
                    $api_url .= ':generateContent?key=' . $config['api_key'];
                }
            }
        }
        
        // ---------------------------------------------------------
        // 流式测试 (支持 OpenAI / Gemini / Claude 三种格式)
        // ---------------------------------------------------------
        if ($use_stream) {
            $ch = curl_init();
            $curl_headers = array();
            foreach ($headers as $k => $v) {
                $curl_headers[] = "$k: $v";
            }
            
            // Gemini 不使用 SSE Accept Header，OpenAI 和 Claude 需要
            if ($api_type !== 'gemini') {
                $curl_headers[] = 'Accept: text/event-stream';
            }
            
            $accumulated_content = '';
            $buffer = '';
            $stream_error = null;
            $stream_usage = null;
            // 将 $api_type 传入闭包以选择正确的解析逻辑
            $current_api_type = $api_type;
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $api_url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($body_data),
                CURLOPT_HTTPHEADER => $curl_headers,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => '',
            ]);
            
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$accumulated_content, &$buffer, &$stream_error, &$stream_usage, $current_api_type) {
                if ($stream_error) return 0;
                
                $buffer .= $chunk;
                
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);
                    
                    if (empty($line)) continue;
                    
                    // ========================================
                    // Gemini 流式格式: SSE (alt=sse)
                    // data: 行包含 candidates[0].content.parts[0].text
                    // ========================================
                    if ($current_api_type === 'gemini') {
                        // Gemini might include unescaped newlines in its JSON text blocks.
                        // Splitting strict by \n breaks parsing. Split by 'data: ' and decode.
                        while (($pos = strpos($buffer, 'data: ')) !== false) {
                            $next_pos = strpos($buffer, 'data: ', $pos + 6);
                            if ($next_pos !== false) {
                                $json_str = substr($buffer, $pos + 6, $next_pos - ($pos + 6));
                            } else {
                                $json_str = substr($buffer, $pos + 6);
                            }
                            
                            $json_str = trim($json_str);
                            if ($json_str === '[DONE]') {
                                $buffer = '';
                                break;
                            }

                            // Fix for Gemini: if the payload contains unescaped physical newlines inside JSON strings,
                            // PHP's json_decode will return NULL due to control character errors.
                            // We must escape these newlines inside quotes.
                            $safe_json_str = preg_replace_callback('/"([^"\\\\]|\\\\.)*"/s', function($matches) {
                                return str_replace(array("\r", "\n"), array('\r', '\n'), $matches[0]);
                            }, $json_str);

                            $json = @json_decode($safe_json_str, true);
                            if ($json !== null) {
                                // Successfully parsed a complete JSON object
                                if ($next_pos !== false) {
                                    $buffer = substr($buffer, $next_pos);
                                } else {
                                    $buffer = '';
                                }

                                if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                                    $accumulated_content .= $json['candidates'][0]['content']['parts'][0]['text'];
                                }
                                // Gemini 错误处理
                                elseif (isset($json['error'])) {
                                    $error_msg = isset($json['error']['message']) ? $json['error']['message'] : json_encode($json['error']);
                                    $stream_error = "Gemini Stream Error: " . $error_msg;
                                    return 0;
                                }
                            } else {
                                // Not complete, wait for more chunks
                                break;
                            }
                        }
                    }
                    // ========================================
                    // Claude 流式格式: SSE data: 行
                    // 事件类型 content_block_delta 含 delta.text
                    // ========================================
                    elseif ($current_api_type === 'claude') {
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
                            // Claude 错误事件
                            elseif (isset($json['type']) && $json['type'] === 'error') {
                                $stream_error = isset($json['error']['message']) 
                                    ? $json['error']['message'] 
                                    : json_encode($json['error']);
                                return 0;
                            }
                            // Usage 信息
                            elseif (isset($json['usage'])) {
                                $stream_usage = $json['usage'];
                            }
                        }
                    }
                    // ========================================
                    // OpenAI 流式格式: SSE data: 行
                    // choices[0].delta.content
                    // ========================================
                    else {
                        $content_str = null;
                        if (strpos($line, 'data: ') === 0) {
                            $content_str = substr($line, 6);
                        } elseif (strpos($line, 'data:') === 0) {
                            $content_str = substr($line, 5);
                        }
                        
                        if ($content_str !== null) {
                            if (trim($content_str) === '[DONE]') continue;
                            
                            $json = json_decode($content_str, true);
                            
                            // OpenAI delta content
                            if (isset($json['choices'][0]['delta']['content'])) {
                                $accumulated_content .= $json['choices'][0]['delta']['content'];
                            }
                            // 错误处理
                            elseif (isset($json['error'])) {
                                $error_msg = is_array($json['error']) ? ($json['error']['message'] ?? json_encode($json['error'])) : $json['error'];
                                $stream_error = "Stream API Error: " . $error_msg;
                                return 0;
                            }
                            // Usage 信息
                            elseif (isset($json['usage'])) {
                                $stream_usage = $json['usage'];
                            }
                        }
                    }
                }
                return strlen($chunk);
            });
            
            ob_start();
            $curl_success = curl_exec($ch);
            ob_end_clean();
            
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            $response_time = round(microtime(true) - $start_time, 2);
            
            if ($stream_error) {
                return array('success' => false, 'message' => $stream_error);
            }
            
            if (!$curl_success && $curl_error) {
                return array('success' => false, 'message' => "Stream Request Failed: $curl_error");
            }
            
            if ($http_code >= 400) {
                return array('success' => false, 'message' => "Stream HTTP Error: $http_code");
            }
            
            // 构建成功响应
            $message = sprintf(
                /* translators: 1: API Type, 2: Model Name, 3: Content Preview, 4: Response Time */
                __('Stream: OK！模型：%1$s，生成内容："%2$s"，响应时间：%3$s秒', 'yali-ai-writer'),
                $config['model_name'],
                mb_substr($accumulated_content, 0, 20) . (mb_strlen($accumulated_content) > 20 ? '...' : ''),
                $response_time
            );
            
            return array(
                'success' => true,
                'message' => $message,
                'data' => array(
                    'api_type' => $api_type,
                    'model' => $config['model_name'],
                    'content' => $accumulated_content,
                    'response_time' => $response_time,
                    'stream' => true,
                    'usage' => $stream_usage
                )
            );
        }
        
        // ---------------------------------------------------------
        // 标准（非流式）测试
        // ---------------------------------------------------------
        else {
            $args = array(
                'headers' => $headers,
                'body' => json_encode($body_data),
                'timeout' => 60,
                'sslverify' => false
            );
            
            $response = wp_remote_post($api_url, $args);
            
            $response_time = round(microtime(true) - $start_time, 2);
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => __('Standard Request Failed: ', 'yali-ai-writer') . $response->get_error_message()
                );
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                // 尝试解析错误信息
                $error_data = json_decode($response_body, true);
                $error_message = '';
                if (isset($error_data['error']['message'])) {
                    $error_message = $error_data['error']['message'];
                } elseif (isset($error_data['error'])) {
                    $error_message = is_string($error_data['error']) ? $error_data['error'] : json_encode($error_data['error']);
                }
                
                return array(
                    'success' => false,
                    'message' => sprintf(
                        /* translators: 1: HTTP Code, 2: Error Message */
                        __('Standard Request HTTP %1$s%2$s', 'yali-ai-writer'),
                        $response_code,
                        $error_message ? ': ' . $error_message : ''
                    )
                );
            }
            
            // 解析响应
            $parsed_response = $this->parse_test_response($api_type, $response_body);
            
            if (isset($parsed_response['error'])) {
                return array('success' => false, 'message' => $parsed_response['error']);
            }
            
            $content = $parsed_response['content'] ?? '';
            $content_preview = mb_substr($content, 0, 20) . (mb_strlen($content) > 20 ? '...' : '');
            
            // 构建详细信息
            $details = array(
                'api_type' => $api_type,
                'model' => $config['model_name'],
                'content' => $content,
                'response_time' => $response_time,
                'stream' => false
            );
            
            // 添加 token 信息（如果有）
            if (isset($parsed_response['total_tokens'])) {
                $details['total_tokens'] = $parsed_response['total_tokens'];
            }
            if (isset($parsed_response['prompt_tokens'])) {
                $details['prompt_tokens'] = $parsed_response['prompt_tokens'];
            }
            if (isset($parsed_response['completion_tokens'])) {
                $details['completion_tokens'] = $parsed_response['completion_tokens'];
            }
            
            // 构建成功消息
            $message = sprintf(
                /* translators: 1: Model Name, 2: Content Preview, 3: Response Time */
                __('Standard: OK！模型：%1$s，生成内容："%2$s"，响应时间：%3$s秒', 'yali-ai-writer'),
                $config['model_name'],
                $content_preview,
                $response_time
            );
            
            // 添加 token 信息到消息（如果有）
            if (isset($parsed_response['total_tokens']) && $parsed_response['total_tokens'] > 0) {
                $message .= sprintf(
                    /* translators: 1: Total Tokens */
                    __('
Tokens: %1$s', 'yali-ai-writer'),
                    $parsed_response['total_tokens']
                );
            }
            
            return array(
                'success' => true,
                'message' => $message,
                'data' => $details
            );
        }
    }
}