<?php
/**
 * Pollinations API渠道实现
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PollinationsChannel extends ContentAuto_ApiChannel {
    
    public function __construct() {
        parent::__construct('pollinations', 'pollinations渠道', 'https://gen.pollinations.ai/');
    }
    
    /**
     * 构建请求参数
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 请求参数
     */
    public function build_request_params($config, $prompt) {
        $model = !empty($config['model_name']) ? $config['model_name'] : 'openai-large';
        return array(
            'model' => $model, // 使用配置的模型
            'stream' => false
        );
    }
    
    /**
     * 发送请求到API
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 响应结果
     */
    public function send_request($config, $prompt) {
        $url = 'https://gen.pollinations.ai/v1/chat/completions';
        $model = !empty($config['model_name']) ? $config['model_name'] : 'openai-large';
        
        // 构建请求体 - 保持极简，不发送限制性参数如 max_tokens, temperature (保留 seed 用于破除缓存)
        $request_body = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'stream' => true,
            'seed' => $this->generate_seed()
        );
        
        $headers = array(
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'User-Agent: WordPress-ContentAutoManager/1.1'
        );
        
        if (!empty($config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . trim($config['api_key']);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request_body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false, 
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 300,          // 5分钟超时，防止长文生成断流
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_ENCODING => '',          // 自动处理压缩
        ));

        $accumulated_content = '';
        $buffer = '';
        $stream_error = null;
        $saw_done = false;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$accumulated_content, &$buffer, &$stream_error, &$saw_done) {
            if ($stream_error) return 0;
            
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);
                
                if (empty($line)) continue;
                
                if (strpos($line, 'data: ') === 0) {
                    $json_str = substr($line, 6);
                    if ($json_str === '[DONE]') {
                        $saw_done = true;
                        continue;
                    }
                    
                    $decoded = json_decode($json_str, true);
                    if (isset($decoded['choices'][0]['delta']['content'])) {
                        $accumulated_content .= $decoded['choices'][0]['delta']['content'];
                    } elseif (isset($decoded['error'])) {
                        $stream_error = "API Error: " . (is_array($decoded['error']) ? json_encode($decoded['error']) : $decoded['error']);
                        return 0;
                    }
                }
            }
            return strlen($chunk);
        });

        ob_start();
        $success = curl_exec($ch);
        ob_end_clean();

        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($stream_error) {
            return array('success' => false, 'message' => __('流数据错误: ', 'yali-ai-writer') . $stream_error);
        }
        
        if ($curl_error) {
            return array('success' => false, 'message' => __('网络请求失败 (cURL): ', 'yali-ai-writer') . $curl_error);
        }

        if ($http_code >= 400) {
            return array('success' => false, 'message' => 'API 响应错误 (HTTP ' . $http_code . ')');
        }

        // [关键防御] 检查流是否完整结束
        if (!$saw_done && !empty($accumulated_content)) {
            return array(
                'success' => false, 
                'message' => __('内容生成被意外截断 (未检测到结束标记)。已收到的部分内容：', 'yali-ai-writer') . mb_substr($accumulated_content, -100) . '...'
            );
        }

        if (empty($accumulated_content)) {
            return array('success' => false, 'message' => __('未获取到内容响应', 'yali-ai-writer'));
        }

        return array('success' => true, 'data' => $accumulated_content);
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
     * 获取账户余额
     */
    public function get_account_balance($api_key) {
        return $this->fetch_pollinations_account_data('account/balance', $api_key);
    }

    /**
     * 获取历史总用量
     */
    public function get_account_usage($api_key) {
        return $this->fetch_pollinations_account_data('account/usage', $api_key);
    }

    /**
     * 获取今日用量 (Daily)
     */
    public function get_account_daily_usage($api_key) {
        return $this->fetch_pollinations_account_data('account/usage/daily', $api_key);
    }

    /**
     * 获取 API Key 信息 (权限、预算等)
     */
    public function get_account_key($api_key) {
        return $this->fetch_pollinations_account_data('account/key', $api_key);
    }

    /**
     * 获取账户 Profile (Tier, Email 等)
     */
    public function get_account_profile($api_key) {
        return $this->fetch_pollinations_account_data('account/profile', $api_key);
    }

    /**
     * 辅助方法：从 enter.pollinations.ai 获取账户相关数据
     */
    private function fetch_pollinations_account_data($endpoint, $api_key) {
        if (empty($api_key)) {
            return array('success' => false, 'message' => __('未提供 API Key', 'yali-ai-writer'));
        }

        $url = 'https://gen.pollinations.ai/' . ltrim($endpoint, '/');
        
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($api_key),
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress-ContentAutoManager/1.0'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            $data = json_decode($response_body, true);
            return array('success' => true, 'data' => $data);
        } else {
            return array('success' => false, 'message' => 'HTTP ' . $response_code . ': ' . substr($response_body, 0, 200));
        }
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