<?php
/**
 * 优化后的文章API处理器 (适配器模式)
 * 职责：保持向后兼容性，实现API轮询和重试逻辑
 * 通信：完全重定向到 ContentAuto_UnifiedApiHandler
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-config.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';

class ContentAuto_ArticleApiHandler {
    
    private $api_config;
    private $current_api_config;
    private $last_api_error;
    private $logger;
    
    public function __construct($logger = null) {
        $this->api_config = new ContentAuto_ApiConfig();
        $this->current_api_config = null;
        $this->last_api_error = null;
        $this->logger = $logger ?: new ContentAuto_LoggingSystem();
    }
    
    /**
     * 【主业务逻辑】生成文章内容
     * 保持原有接口签名，内部实现重试与通信代理
     */
    public function generate_article_content($prompt, $topic_id, $rule_id, $job_queue_id = null) {
        $context = array('topic_id' => $topic_id, 'rule_id' => $rule_id, 'job_queue_id' => $job_queue_id);
        
        // 1. 【追溯业务逻辑：重试轮询】
        $max_retries = 3;
        $last_error = null;
        
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $is_retry = ($attempt > 1);
            $api_config = $this->api_config->get_next_active_config($is_retry);
            
            if (!$api_config) {
                break;
            }
            
            $this->current_api_config = $api_config;
            
            // 2. 【核心优化：接入统一通信层】
            $unified_handler = new ContentAuto_UnifiedApiHandler($this->logger);
            $result = $unified_handler->generate_content($prompt, 'article', [
                'rule_id' => $rule_id,
                'topic_id' => $topic_id,
                'config_id' => $api_config['id'],
                'job_queue_id' => $job_queue_id
            ]);

            // 检查通信结果
            if (!is_array($result) || !isset($result['error'])) {
                $this->mark_api_result($api_config['id'], true);
                return $result;
            }
            
            // 将新处理器的错误格式对齐为老系统期望的格式
            $last_error = array(
                'stage' => 'API请求',
                'message' => is_string($result['error']) ? $result['error'] : json_encode($result['error'])
            );
            $this->mark_api_result($api_config['id'], false);
            
            // 指数退避
            if ($attempt < $max_retries) {
                sleep(pow(2, $attempt - 1));
            }
        }
        
        return array('error' => $last_error);
    }
    
    private function mark_api_result($api_id, $success) {
        if ($success) {
            $this->api_config->mark_api_success($api_id);
        } else {
            $this->api_config->mark_api_failed($api_id);
        }
        update_option('content_auto_last_api_request', time());
    }

    // --- 兼容性保留方法 ---
    public function get_current_api_config() { return $this->current_api_config; }
    public function get_last_api_error() { return $this->last_api_error; }
}