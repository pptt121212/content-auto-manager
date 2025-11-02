<?php
/**
 * 向量API处理器
 * 负责处理所有与向量API相关的操作，包括文本嵌入生成、向量存储等
 */

if (!defined('ABSPATH')) {
    exit;
}

// 确保日志类已加载
if (!class_exists('ContentAuto_PluginLogger')) {
    require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
}

class ContentAuto_VectorApiHandler {
    
    private $api_config;
    private $logger;
    private $rate_limiter;
    private $last_error;
    private $retry_attempts;
    private $max_retries;
    private $retry_delay;
    
    // 默认速率限制设置 (基于硅基流动的限制)
    const DEFAULT_RPM = 2000;  // 每分钟请求数
    const DEFAULT_TPM = 500000; // 每分钟Token数
    
    public function __construct($logger = null) {
        $this->api_config = new ContentAuto_ApiConfig();
        
        // 处理日志依赖 - 如果类不存在则创建虚拟日志器
        if ($logger !== null) {
            $this->logger = $logger;
        } elseif (class_exists('ContentAuto_PluginLogger')) {
            $this->logger = new ContentAuto_PluginLogger();
        } else {
            // 创建虚拟日志器，避免类未找到错误
            $this->logger = new class {
                public function log($message, $level = 'INFO', $context = array()) {
                    // 使用PHP错误日志作为后备
                    error_log('VectorAPI: [' . $level . '] ' . $message . ' ' . json_encode($context));
                }
            };
        }
        
        $this->rate_limiter = new ContentAuto_RateLimiter();
        $this->last_error = null;
        $this->retry_attempts = 0;
        $this->max_retries = 3;
        $this->retry_delay = 1000; // 1秒
    }
    
    /**
     * 生成文本嵌入向量
     * 
     * @param string|array $text 输入文本（单个字符串或字符串数组）
     * @param int|null $config_id 指定的API配置ID，如果为null则使用激活的配置
     * @return array|false 成功返回向量数据，失败返回false
     * @deprecated a new method generate_embeddings_batch is created, use that instead
     */
    public function generate_embedding($text, $config_id = null) {
        $texts = is_array($text) ? $text : [$text];
        return $this->generate_embeddings_batch($texts, $config_id);
    }

    /**
     * 批量生成文本嵌入向量, 并以base64格式返回
     *
     * @param array $texts 输入的文本字符串数组
     * @param int|null $config_id 指定的API配置ID, null则自动获取
     * @return array|false 成功时返回包含向量和元数据的数组, 失败返回false
     */
    public function generate_embeddings_batch(array $texts, $config_id = null) {
        $method_start_time = microtime(true);
        $start_memory = memory_get_usage(true);

        $context = [
            'text_count' => count($texts),
            'config_id' => $config_id,
        ];

        $this->log_info('EMBEDDING_BATCH_START', '开始批量生成文本嵌入向量 (base64)', $context);

        try {
            $api_config = $this->get_vector_api_config($config_id);
            if (!$api_config) {
                $this->last_error = '没有可用的向量API配置';
                $this->log_error('NO_VECTOR_CONFIG', $this->last_error, $context);
                return false;
            }

            if (!$this->check_rate_limit($api_config)) {
                return false;
            }

            $request_data = $this->prepare_request_data($texts, $api_config, 'base64');
            $result = $this->send_request_with_retry($api_config, $request_data);

            if ($result === false) {
                return false;
            }

            $embedding_data = $this->parse_response($result, $api_config);

            if ($embedding_data === false) {
                return false;
            }

            $this->update_rate_limit($api_config, $result);

            $execution_time = round((microtime(true) - $method_start_time) * 1000, 2);
            $memory_used = round((memory_get_usage(true) - $start_memory) / 1024 / 1024, 2);

            $success_context = array_merge($context, [
                'execution_time_ms' => $execution_time,
                'memory_used_mb' => $memory_used,
                'tokens_used' => $embedding_data['tokens_used'],
            ]);

            $this->log_success('EMBEDDING_BATCH_SUCCESS', '批量文本嵌入向量生成成功', $success_context);

            return $embedding_data;

        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->log_error('EMBEDDING_BATCH_EXCEPTION', '批量生成文本嵌入向量时发生异常: ' . $e->getMessage(), array_merge($context, [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
            return false;
        }
    }
    
    /**
     * 获取向量API配置
     */
    private function get_vector_api_config($config_id = null) {
        if ($config_id) {
            return $this->api_config->get_config($config_id);
        }
        
        // 获取向量API配置（不检查激活状态，因为只有一个向量API配置）
        $vector_config = $this->api_config->get_vector_config();
        if (empty($vector_config)) {
            return null;
        }
        
        return $vector_config;
    }
    
    /**
     * 检查速率限制
     */
    private function check_rate_limit($api_config) {
        $rpm = isset($api_config['rpm']) ? intval($api_config['rpm']) : self::DEFAULT_RPM;
        $tpm = isset($api_config['tpm']) ? intval($api_config['tpm']) : self::DEFAULT_TPM;
        
        $key = 'vector_api_' . $api_config['id'];
        
        if (!$this->rate_limiter->can_proceed($key, $rpm, 'requests')) {
            $this->last_error = '超过RPM限制，请稍后再试';
            $this->log_warning('RATE_LIMIT_RPM', $this->last_error, array('config_id' => $api_config['id'], 'rpm' => $rpm));
            return false;
        }
        
        if (!$this->rate_limiter->can_proceed($key . '_tokens', $tpm, 'tokens')) {
            $this->last_error = '超过TPM限制，请稍后再试';
            $this->log_warning('RATE_LIMIT_TPM', $this->last_error, array('config_id' => $api_config['id'], 'tpm' => $tpm));
            return false;
        }
        
        return true;
    }
    
    /**
     * 更新速率限制计数器
     */
    private function update_rate_limit($api_config, $response) {
        $key = 'vector_api_' . $api_config['id'];
        
        // 更新请求计数
        $this->rate_limiter->record_request($key, 'requests');
        
        // 更新Token计数（如果响应中包含usage信息）
        if (isset($response['usage']) && isset($response['usage']['total_tokens'])) {
            $this->rate_limiter->record_request($key . '_tokens', 'tokens', $response['usage']['total_tokens']);
        }
    }
    
    /**
     * 准备请求数据
     */
    private function prepare_request_data($texts, $api_config, $encoding_format = 'float') {
        $model = $api_config['vector_model_name'];
        $api_type = isset($api_config['vector_api_type']) ? $api_config['vector_api_type'] : 'openai';

        if ($api_type === 'jina') {
            // Jina Embeddings v4 格式
            $input_texts = [];
            foreach ($texts as $text) {
                $input_texts[] = ['text' => $text];
            }

            return [
                'model' => $model,
                'input' => $input_texts,
                'task' => 'text-matching',
                'dimensions' => 1024,
                'embedding_type' => 'base64',
            ];
        } else {
            // OpenAI Embeddings 格式（默认）
            return [
                'model' => $model,
                'input' => $texts,
                'encoding_format' => $encoding_format,
            ];
        }
    }
    
    /**
     * 发送请求（包含重试机制）
     */
    private function send_request_with_retry($api_config, $request_data) {
        $this->retry_attempts = 0;
        
        while ($this->retry_attempts <= $this->max_retries) {
            try {
                $result = $this->send_http_request($api_config, $request_data);
                if ($result !== false) {
                    return $result;
                }
                
                // 如果是最后一次重试，直接返回false
                if ($this->retry_attempts >= $this->max_retries) {
                    break;
                }
                
                // 等待后重试
                $this->retry_attempts++;
                $delay = $this->retry_delay * pow(2, $this->retry_attempts - 1); // 指数退避
                usleep($delay * 1000);
                
            } catch (Exception $e) {
                $this->last_error = $e->getMessage();
                $this->log_error('REQUEST_EXCEPTION', '请求异常: ' . $e->getMessage(), array(
                    'attempt' => $this->retry_attempts,
                    'config_id' => $api_config['id']
                ));
                
                if ($this->retry_attempts >= $this->max_retries) {
                    break;
                }
                
                $this->retry_attempts++;
                usleep($this->retry_delay * 1000);
            }
        }
        
        $this->log_error('MAX_RETRIES_EXCEEDED', '达到最大重试次数: ' . $this->max_retries, array(
            'config_id' => $api_config['id'],
            'last_error' => $this->last_error
        ));
        
        return false;
    }
    
    /**
     * 发送HTTP请求
     */
    private function send_http_request($api_config, $request_data) {
        $url = $api_config['vector_api_url'];
        
        $headers = array(
            'Authorization' => 'Bearer ' . $api_config['vector_api_key'],
            'Content-Type' => 'application/json'
        );
        
        // 根据API类型设置不同的超时时间
        $api_type = isset($api_config['vector_api_type']) ? $api_config['vector_api_type'] : 'openai';
        $timeout = ($api_type === 'jina') ? 60 : 45; // Jina使用更长超时时间
        
        $args = array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => json_encode($request_data),
            'timeout' => $timeout,
            'sslverify' => true
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            // 为超时错误提供更友好的提示
            if (strpos($error_message, 'Operation timed out') !== false || strpos($error_message, 'timeout') !== false) {
                $api_type = isset($api_config['vector_api_type']) ? $api_config['vector_api_type'] : 'openai';
                $this->last_error = sprintf(
                    'API请求超时（%d秒）。%s API服务器可能响应较慢，这通常是正常现象。建议稍后重试，或检查网络连接。',
                    $timeout,
                    $api_type === 'jina' ? 'Jina' : 'OpenAI'
                );
            } else {
                $this->last_error = $error_message;
            }
            
            $this->log_error('HTTP_REQUEST_ERROR', 'HTTP请求失败: ' . $error_message, array(
                'url' => $url,
                'response_code' => $error_code,
                'api_type' => $api_type ?? 'unknown',
                'timeout_used' => $timeout ?? 30
            ));
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $this->last_error = 'HTTP响应错误: ' . $response_code;
            $this->log_error('HTTP_RESPONSE_ERROR', $this->last_error, array(
                'url' => $url,
                'response_code' => $response_code,
                'response_body' => $response_body
            ));
            return array(
                'error' => true,
                'code' => $response_code,
                'body' => $response_body
            );
        }
        
        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->last_error = 'JSON解析错误: ' . json_last_error_msg();
            $this->log_error('JSON_PARSE_ERROR', $this->last_error, array(
                'response_body' => $response_body
            ));
            return false;
        }
        
        return $data;
    }
    
    /**
     * 解析API响应
     */
    private function parse_response($response, $api_config) {
        $api_type = isset($api_config['vector_api_type']) ? $api_config['vector_api_type'] : 'openai';
        
        if ($api_type === 'jina') {
            // Jina API响应格式: {embeddings: [{...}], model: "...", usage: {...}}
            return $this->parse_jina_response($response, $api_config);
        } else {
            // OpenAI API响应格式: {data: [{...}], model: "...", usage: {...}}
            return $this->parse_openai_response($response, $api_config);
        }
    }
    
    /**
     * 解析OpenAI格式的API响应
     */
    private function parse_openai_response($response, $api_config) {
        if (!isset($response['data']) || !is_array($response['data'])) {
            $this->last_error = '响应格式错误：缺少data字段';
            $this->log_error('RESPONSE_FORMAT_ERROR', $this->last_error, ['response' => $response]);
            return false;
        }
    
        // 提取向量数据
        $embeddings = [];
        foreach ($response['data'] as $item) {
            // 同时检查 embedding 字段是字符串(base64)还是数组(float)
            if (isset($item['embedding']) && (is_string($item['embedding']) || is_array($item['embedding']))) {
                $embeddings[] = [
                    'embedding' => $item['embedding'],
                    'index' => $item['index'] ?? 0,
                ];
            }
        }
    
        if (empty($embeddings)) {
            $this->last_error = '响应中未找到有效的向量数据';
            $this->log_error('NO_EMBEDDING_DATA', $this->last_error, ['response' => $response]);
            return false;
        }
    
        // 返回包含所有结果的数组
        return [
            'embeddings' => $embeddings,
            'model' => $response['model'] ?? $api_config['vector_model_name'],
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            'config_id' => $api_config['id'],
        ];
    }
    
    /**
     * 解析Jina格式的API响应
     */
    private function parse_jina_response($response, $api_config) {
        if (!isset($response['embeddings']) || !is_array($response['embeddings'])) {
            $this->last_error = '响应格式错误：缺少embeddings字段';
            $this->log_error('RESPONSE_FORMAT_ERROR', $this->last_error, ['response' => $response]);
            return false;
        }
    
        // 提取向量数据
        $embeddings = [];
        foreach ($response['embeddings'] as $index => $item) {
            // Jina格式中，embedding字段通常是base64字符串
            if (isset($item['embedding']) && (is_string($item['embedding']) || is_array($item['embedding']))) {
                $embeddings[] = [
                    'embedding' => $item['embedding'],
                    'index' => $item['index'] ?? $index,
                ];
            }
        }
    
        if (empty($embeddings)) {
            $this->last_error = '响应中未找到有效的向量数据';
            $this->log_error('NO_EMBEDDING_DATA', $this->last_error, ['response' => $response]);
            return false;
        }
    
        // 返回包含所有结果的数组
        return [
            'embeddings' => $embeddings,
            'model' => $response['model'] ?? $api_config['vector_model_name'],
            'tokens_used' => $response['usage']['total_tokens'] ?? 0,
            'config_id' => $api_config['id'],
        ];
    }
    
    /**
     * 获取最后的错误信息
     */
    public function get_last_error() {
        return $this->last_error;
    }
    
    /**
     * 获取重试次数
     */
    public function get_retry_attempts() {
        return $this->retry_attempts;
    }
    
    /**
     * 设置最大重试次数
     */
    public function set_max_retries($max_retries) {
        $this->max_retries = intval($max_retries);
    }
    
    /**
     * 设置重试延迟
     */
    public function set_retry_delay($delay_ms) {
        $this->retry_delay = intval($delay_ms);
    }
    
    /**
     * 记录信息日志
     */
    private function log_info($code, $message, $context = array()) {
        $this->logger->log($message, 'INFO', $context);
    }
    
    /**
     * 记录成功日志
     */
    private function log_success($code, $message, $context = array()) {
        $this->logger->log($message, 'SUCCESS', $context);
    }
    
    /**
     * 记录警告日志
     */
    private function log_warning($code, $message, $context = array()) {
        $this->logger->log($message, 'WARNING', $context);
    }
    
    /**
     * 记录错误日志
     */
    private function log_error($code, $message, $context = array()) {
        $this->logger->log($message, 'ERROR', $context);
    }
    
    /**
     * 测试向量API连接
     */
    public function test_connection($config_id) {
        $start_time = microtime(true);
        
        try {
            // 验证配置ID
            if (empty($config_id) || !is_numeric($config_id)) {
                return array(
                    'success' => false,
                    'message' => '无效的配置ID'
                );
            }
            
            // 获取向量API配置
            $api_config = new ContentAuto_ApiConfig();
            $config = $api_config->get_config($config_id);
            
            if (!$config) {
                return array(
                    'success' => false,
                    'message' => '未找到向量API配置 (ID: ' . $config_id . ')'
                );
            }
            
            // 验证是否为向量API配置
            if (empty($config['vector_api_url']) || empty($config['vector_api_key']) || empty($config['vector_model_name'])) {
                return array(
                    'success' => false,
                    'message' => '配置不是有效的向量API配置 (ID: ' . $config_id . ')'
                );
            }
            
            // 验证API URL格式
            if (!filter_var($config['vector_api_url'], FILTER_VALIDATE_URL)) {
                return array(
                    'success' => false,
                    'message' => '无效的向量API URL: ' . $config['vector_api_url']
                );
            }
            
            // 检查速率限制
            if (!$this->check_rate_limit($config)) {
                return array(
                    'success' => false,
                    'message' => '超过速率限制，请稍后再试'
                );
            }
            
            // 准备测试数据
            $test_texts = ['这是一个向量API连接测试文本。'];
            $request_data = $this->prepare_request_data($test_texts, $config);
            
            // 发送测试请求
            $result = $this->send_http_request($config, $request_data);
            
            if ($result === false) {
                return array(
                    'success' => false,
                    'message' => '请求失败：' . $this->get_last_error()
                );
            }
            
            // 解析响应
            $embedding_data = $this->parse_response($result, $config);
            
            if ($embedding_data === false) {
                return array(
                    'success' => false,
                    'message' => '响应解析失败：' . $this->get_last_error()
                );
            }
            
            // 更新速率限制计数器
            $this->update_rate_limit($config, $result);
            
            // 计算响应时间
            $response_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // 构建成功响应
            $first_embedding = $embedding_data['embeddings'][0]['embedding'] ?? null;
            if ($first_embedding === null) {
                return array(
                    'success' => false,
                    'message' => '响应中未找到有效的向量数据'
                );
            }

            // 计算向量维度 - 处理base64字符串和数组两种格式
            $dimensions = 0;
            if (is_string($first_embedding)) {
                // base64编码的向量 - 解码后计算维度
                $binary_data = base64_decode($first_embedding, true);
                if ($binary_data !== false) {
                    $float_array = unpack('f*', $binary_data);
                    $dimensions = is_array($float_array) ? count($float_array) : 0;
                }
            } elseif (is_array($first_embedding)) {
                // 直接的浮点数数组
                $dimensions = count($first_embedding);
            }
            $success_message = sprintf(
                '向量API连接成功！向量维度：%d，模型：%s，响应时间：%.2f秒',
                $dimensions,
                $embedding_data['model'],
                $response_time
            );
            
            return array(
                'success' => true,
                'message' => $success_message,
                'data' => array(
                    'dimensions' => $dimensions,
                    'model' => $embedding_data['model'],
                    'tokens_used' => $embedding_data['tokens_used'],
                    'response_time' => $response_time,
                    'config_id' => $config_id
                )
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => '测试异常：' . $e->getMessage()
            );
        }
    }
}

/**
 * 简单的速率限制器
 */
class ContentAuto_RateLimiter {
    
    private $limits = array();
    
    /**
     * 检查是否可以继续请求
     */
    public function can_proceed($key, $limit, $type = 'requests') {
        $now = time();
        $minute_start = floor($now / 60) * 60;
        
        $cache_key = "rate_limit_{$key}_{$type}_{$minute_start}";
        $current_count = intval(get_transient($cache_key));
        
        return $current_count < $limit;
    }
    
    /**
     * 记录请求
     */
    public function record_request($key, $type = 'requests', $count = 1) {
        $now = time();
        $minute_start = floor($now / 60) * 60;
        
        $cache_key = "rate_limit_{$key}_{$type}_{$minute_start}";
        $current_count = intval(get_transient($cache_key));
        
        $new_count = $current_count + $count;
        set_transient($cache_key, $new_count, 60); // 缓存1分钟
    }
}

/**
 * 便捷函数：生成文本嵌入向量
 */
function content_auto_generate_embedding($text, $config_id = null) {
    static $handler = null;
    
    if ($handler === null) {
        $handler = new ContentAuto_VectorApiHandler();
    }
    
    return $handler->generate_embedding($text, $config_id);
}