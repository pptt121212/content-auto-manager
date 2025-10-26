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
            if (empty($data['vector_api_url'])) {
                return false;
            }
            $validated_data['vector_api_url'] = esc_url_raw($data['vector_api_url']);

            if (empty($data['vector_api_key'])) {
                return false;
            }
            $validated_data['vector_api_key'] = sanitize_text_field($data['vector_api_key']);

            if (empty($data['vector_model_name'])) {
                return false;
            }
            $validated_data['vector_model_name'] = sanitize_text_field($data['vector_model_name']);

            // 验证向量API类型
            $api_type = isset($data['vector_api_type']) ? sanitize_text_field($data['vector_api_type']) : 'openai';
            if (!in_array($api_type, array('openai', 'jina'))) {
                $api_type = 'openai'; // 默认为OpenAI
            }
            $validated_data['vector_api_type'] = $api_type;

            // 向量API配置时，传统API字段设为空或默认值
            $validated_data['api_url'] = '';
            $validated_data['api_key'] = '';
            $validated_data['model_name'] = '';
            $validated_data['temperature'] = 0.70;
            $validated_data['max_tokens'] = 2000;
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
                $validated_data['model_name'] = isset($data['model_name']) ? sanitize_text_field($data['model_name']) : '预置模型';
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
                    $validated_data['max_tokens'] = 2000; // 默认值
                }
            }
            $validated_data['temperature_enabled'] = isset($data['temperature_enabled']) ? intval($data['temperature_enabled']) : 1;
            $validated_data['max_tokens_enabled'] = isset($data['max_tokens_enabled']) ? intval($data['max_tokens_enabled']) : 1;

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
     * 测试API连接
     */
    public function test_connection($config_id) {
        $config = $this->get_config($config_id);
        if (!$config) {
            return false;
        }

        // 构建测试请求
        $body_data = array(
            'model' => $config['model_name'],
            'messages' => array(
                array('role' => 'user', 'content' => 'Hello, this is a test message.')
            ),
            'max_tokens' => 10,
            'temperature' => 0.7
        );

        // 将stream写死设为false，确保测试稳定性
        $body_data['stream'] = false;

        // 添加top_p参数支持
        if (isset($config['top_p_enabled']) && $config['top_p_enabled']) {
            $body_data['top_p'] = (float) $config['top_p'];
        } else {
            $body_data['top_p'] = 1.0;
        }

        // 构建请求参数 - 增加超时时间以支持思考模型测试
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $config['api_key'],
                'User-Agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
                'Accept' => 'application/json',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache'
            ),
            'body' => json_encode($body_data),
            'timeout' => 180, // 增加到3分钟，测试连接时需要考虑思考模型响应时间
            'user-agent' => 'ContentAutoManager/1.0 (WordPress Plugin)',
            'sslverify' => false // 如果遇到SSL问题可以临时禁用，但生产环境建议启用
        );

        // 发送请求
        $response = wp_remote_post($config['api_url'], $args);

        // 检查响应
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code === 200) {
            return array('success' => true, 'message' => '连接成功');
        } else {
            $error_message = '连接失败';

            // 检查是否是HTML响应（错误页面）
            if (strpos($response_body, '<!DOCTYPE html') === 0 || strpos($response_body, '<html') === 0) {
                // 尝试提取HTML标题中的错误信息
                if (preg_match('/<title>(.*?)<\/title>/i', $response_body, $matches)) {
                    $error_message .= ': ' . strip_tags($matches[1]);
                } else {
                    $error_message .= ': API返回HTML错误页面，请检查API地址和配置';
                }
            } else {
                // 尝试解析JSON错误
                $json_data = json_decode($response_body, true);
                if ($json_data && isset($json_data['error']['message'])) {
                    $error_message .= ': ' . $json_data['error']['message'];
                } elseif ($json_data && isset($json_data['error'])) {
                    $error_message .= ': ' . (is_string($json_data['error']) ? $json_data['error'] : json_encode($json_data['error']));
                } else {
                    $error_message .= ': HTTP ' . $response_code . ' - ' . substr($response_body, 0, 200);
                }
            }

            return array('success' => false, 'message' => $error_message);
        }
    }
}