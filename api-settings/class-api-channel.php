<?php
/**
 * API渠道抽象类
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class ContentAuto_ApiChannel {
    protected $channel_key;
    protected $name;
    protected $api_url;
    
    public function __construct($channel_key, $name, $api_url) {
        $this->channel_key = $channel_key;
        $this->name = $name;
        $this->api_url = $api_url;
    }
    
    /**
     * 获取渠道键名
     */
    public function get_channel_key() {
        return $this->channel_key;
    }
    
    /**
     * 获取渠道名称
     */
    public function get_name() {
        return $this->name;
    }
    
    /**
     * 获取API地址
     */
    public function get_api_url() {
        return $this->api_url;
    }
    
    /**
     * 构建请求参数
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 请求参数
     */
    abstract public function build_request_params($config, $prompt);
    
    /**
     * 发送请求到API
     * @param array $config 数据库中的配置
     * @param string $prompt 请求提示
     * @return array 响应结果
     */
    abstract public function send_request($config, $prompt);
    
    /**
     * 测试API连接
     * @param array $config 数据库中的配置
     * @return array 测试结果
     */
    abstract public function test_connection($config);
}