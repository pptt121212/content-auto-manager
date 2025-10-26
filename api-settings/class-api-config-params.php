<?php
/**
 * API配置参数类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ApiConfigParams {
    public $name;
    public $api_url;
    public $api_key;
    public $model_name;
    public $temperature = 0.7;
    public $max_tokens = 2000;
    public $temperature_enabled = 1;
    public $max_tokens_enabled = 1;
    public $is_active = 0;
    public $predefined_channel = '';

    // 新增参数
    public $stream = false;           // 流式输出，默认关闭
    public $top_p = 1.0;             // 核采样参数，默认1.0
    public $stream_enabled = 0;      // 是否启用stream参数控制
    public $top_p_enabled = 0;       // 是否启用top_p参数控制

    /**
     * 从数组创建参数对象
     */
    public static function fromArray($data) {
        $params = new self();

        // 特殊处理需要类型转换的参数
        $params->name = isset($data['name']) ? sanitize_text_field($data['name']) : $params->name;
        $params->api_url = isset($data['api_url']) ? esc_url_raw($data['api_url']) : $params->api_url;
        $params->api_key = isset($data['api_key']) ? sanitize_text_field($data['api_key']) : $params->api_key;
        $params->model_name = isset($data['model_name']) ? sanitize_text_field($data['model_name']) : $params->model_name;
        $params->temperature = isset($data['temperature']) ? floatval($data['temperature']) : $params->temperature;
        $params->max_tokens = isset($data['max_tokens']) ? intval($data['max_tokens']) : $params->max_tokens;
        $params->temperature_enabled = isset($data['temperature_enabled']) ? intval($data['temperature_enabled']) : $params->temperature_enabled;
        $params->max_tokens_enabled = isset($data['max_tokens_enabled']) ? intval($data['max_tokens_enabled']) : $params->max_tokens_enabled;
        $params->is_active = isset($data['is_active']) ? intval($data['is_active']) : $params->is_active;
        $params->predefined_channel = isset($data['predefined_channel']) ? sanitize_text_field($data['predefined_channel']) : $params->predefined_channel;

        // 处理新参数
        $params->stream = isset($data['stream']) ? (bool) $data['stream'] : $params->stream;
        $params->top_p = isset($data['top_p']) ? floatval($data['top_p']) : $params->top_p;
        $params->stream_enabled = isset($data['stream_enabled']) ? intval($data['stream_enabled']) : $params->stream_enabled;
        $params->top_p_enabled = isset($data['top_p_enabled']) ? intval($data['top_p_enabled']) : $params->top_p_enabled;

        // 处理CLI参数名称
        if (isset($data['api-url'])) {
            $params->api_url = esc_url_raw($data['api-url']);
        }
        if (isset($data['api-key'])) {
            $params->api_key = sanitize_text_field($data['api-key']);
        }
        if (isset($data['model-name'])) {
            $params->model_name = sanitize_text_field($data['model-name']);
        }
        if (isset($data['max-tokens'])) {
            $params->max_tokens = intval($data['max-tokens']);
        }
        if (isset($data['is-active'])) {
            $params->is_active = intval($data['is-active']);
        }
        if (isset($data['temperature_enabled'])) {
            $params->temperature_enabled = intval($data['temperature_enabled']);
        }
        if (isset($data['max_tokens_enabled'])) {
            $params->max_tokens_enabled = intval($data['max_tokens_enabled']);
        }

        return $params;
    }

    /**
     * 转换为数组
     */
    public function toArray() {
        return array(
            'name' => $this->name,
            'api_url' => $this->api_url,
            'api_key' => $this->api_key,
            'model_name' => $this->model_name,
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
            'temperature_enabled' => $this->temperature_enabled,
            'max_tokens_enabled' => $this->max_tokens_enabled,
            'is_active' => $this->is_active,
            'predefined_channel' => $this->predefined_channel,
            // 新增参数
            'stream' => $this->stream,
            'top_p' => $this->top_p,
            'stream_enabled' => $this->stream_enabled,
            'top_p_enabled' => $this->top_p_enabled
        );
    }

    /**
     * 验证参数
     */
    public function validate() {
        $errors = array();

        // 验证必需字段
        if (empty($this->name)) {
            $errors[] = __('配置名称不能为空', 'content-auto-manager');
        }

        // 如果不是预置API，验证API地址和模型名称
        if (empty($this->predefined_channel)) {
            if (empty($this->api_url)) {
                $errors[] = __('API地址不能为空', 'content-auto-manager');
            }

            if (empty($this->model_name)) {
                $errors[] = __('模型名称不能为空', 'content-auto-manager');
            }
        }

        // 验证数值范围
        if ($this->temperature < 0 || $this->temperature > 2) {
            $errors[] = __('温度参数必须在0-2之间', 'content-auto-manager');
        }

        if ($this->max_tokens < 1 || $this->max_tokens > 32000) {
            $errors[] = __('最大Token数必须在1-32000之间', 'content-auto-manager');
        }

        // 验证新增参数范围
        if ($this->top_p < 0 || $this->top_p > 1) {
            $errors[] = __('top_p参数必须在0-1之间', 'content-auto-manager');
        }

        return $errors;
    }
}