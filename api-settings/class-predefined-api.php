<?php
/**
 * 预置API处理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PredefinedApi {
    
    private $database;
    private $channels = array();
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->init_channels();
    }
    
    /**
     * 初始化所有支持的渠道
     */
    private function init_channels() {
        // 初始化pollinations渠道
        $this->channels['pollinations'] = new ContentAuto_PollinationsChannel();
    }
    
    /**
     * 预初始化预置API配置（已简化，移除无用功能）
     */
    private function preinitialize_configs() {
        // 此方法已简化，移除无用的配置预初始化逻辑
        // 配置将在需要时通过其他方法创建
    }
    
    /**
     * 获取所有预置API渠道（已简化，直接返回固定信息）
     */
    public function get_channels() {
        // 已简化，直接返回固定渠道信息
        return array(
            'pollinations' => array(
                'name' => 'pollinations渠道',
                'api_url' => 'https://text.pollinations.ai/'
            )
        );
    }
    
    /**
     * 获取指定渠道的配置（已简化，直接返回固定信息）
     */
    public function get_channel_config($channel) {
        // 已简化，直接返回固定配置信息
        if ($channel === 'pollinations') {
            return array(
                'name' => 'pollinations渠道',
                'api_url' => 'https://text.pollinations.ai/'
            );
        }
        return false;
    }
    
    /**
     * 获取渠道对象
     */
    public function get_channel($channel) {
        return isset($this->channels[$channel]) ? $this->channels[$channel] : false;
    }
    
    /**
     * 获取预置API配置（从数据库）
     */
    public function get_config($channel = 'pollinations') {
        return $this->database->get_row('content_auto_api_configs', array('predefined_channel' => $channel));
    }
    
    /**
     * 创建预置API配置记录
     */
    public function create_config_record($channel = 'pollinations', $is_active = 0) {
        // 获取渠道配置
        $channel_obj = $this->get_channel($channel);
        if (!$channel_obj) {
            return false;
        }
        
        // 检查是否已存在相同渠道的配置
        $existing_config = $this->get_config($channel);
        if ($existing_config) {
            return false; // 渠道已存在，不允许重复创建
        }
        
        // 创建完整的配置记录，包含必需字段
        $data = array(
            'name' => $channel_obj->get_name(),
            'api_url' => $channel_obj->get_api_url(),
            'model_name' => '预置模型', // 预置API使用默认模型名称
            'api_key' => '', // 初始化为空，用户可以选择性添加TOKEN
            'is_active' => $is_active, // 使用传入的激活状态
            'predefined_channel' => $channel
        );
        
        // 尝试插入数据
        $config_id = $this->database->insert('content_auto_api_configs', $data);
        
        // 如果插入成功，返回完整的配置信息
        if ($config_id) {
            return $this->get_config($channel);
        }
        
        return false;
    }
    
    /**
     * 更新预置API配置
     */
    public function update_config($channel, $data) {
        $config = $this->get_config($channel);
        if (!$config) {
            $config_id = $this->create_config_record($channel);
            if (!$config_id) {
                return false;
            }
            $config = $this->get_config($channel);
        }
        
        // 只允许更新特定字段
        $allowed_fields = array('is_active', 'api_key');
        $update_data = array();
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        if (empty($update_data)) {
            return false;
        }
        
        return $this->database->update('content_auto_api_configs', $update_data, array('id' => $config['id']));
    }
    
    /**
     * 检查预置API是否激活
     */
    public function is_active($channel = 'pollinations') {
        $config = $this->get_config($channel);
        return !empty($config) && $config['is_active'] == 1;
    }
    
    /**
     * 验证预置API配置是否完全就绪
     * @param string $channel 渠道名称
     * @return array 验证结果
     */
    public function validate_config_readiness($channel = 'pollinations') {
        $result = array(
            'ready' => false,
            'config_exists' => false,
            'config_active' => false,
            'channel_exists' => false,
            'config_id' => null,
            'errors' => array()
        );
        
        try {
            // 检查渠道是否存在
            if (!isset($this->channels[$channel])) {
                $result['errors'][] = "渠道不存在: {$channel}";
                return $result;
            }
            $result['channel_exists'] = true;
            
            // 检查配置是否存在
            $config = $this->get_config($channel);
            if (!$config) {
                $result['errors'][] = "配置不存在: {$channel}";
                return $result;
            }
            $result['config_exists'] = true;
            $result['config_id'] = $config['id'];
            
            // 检查配置是否激活
            if ($config['is_active'] != 1) {
                $result['errors'][] = "配置未激活: {$channel}";
                return $result;
            }
            $result['config_active'] = true;
            
            // 验证必要字段
            $required_fields = array('name', 'api_url', 'model_name');
            foreach ($required_fields as $field) {
                if (empty($config[$field])) {
                    $result['errors'][] = "配置缺少必要字段 {$field}: {$channel}";
                    return $result;
                }
            }
            
            // 所有检查通过
            $result['ready'] = true;
            
        } catch (Exception $e) {
            $result['errors'][] = "配置验证异常: " . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * 发送请求到预置API
     */
    public function send_request($channel, $prompt) {
        // 获取渠道对象
        $channel_obj = $this->get_channel($channel);
        if (!$channel_obj) {
            return array('success' => false, 'message' => '无效的API渠道');
        }
        
        // 获取数据库配置
        $config = $this->get_config($channel);
        if (!$config) {
            return array('success' => false, 'message' => '未找到API配置');
        }
        
        // 通过渠道对象发送请求
        return $channel_obj->send_request($config, $prompt);
    }
    
    /**
     * 测试预置API连接
     */
    public function test_connection($channel = 'pollinations') {
        // 获取渠道对象
        $channel_obj = $this->get_channel($channel);
        if (!$channel_obj) {
            return array('success' => false, 'message' => '无效的API渠道');
        }
        
        // 获取数据库配置
        $config = $this->get_config($channel);
        if (!$config) {
            // 如果没有配置，创建一个临时配置用于测试
            $config = array(
                'name' => $channel_obj->get_name(),
                'api_url' => $channel_obj->get_api_url(),
                'api_key' => '',
                'model_name' => '预置模型',
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'temperature_enabled' => 1,
                'max_tokens_enabled' => 1,
                'is_active' => 0
            );
        }
        
        // 通过渠道对象测试连接
        return $channel_obj->test_connection($config);
    }
}