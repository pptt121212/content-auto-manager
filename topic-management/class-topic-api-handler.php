<?php
/**
 * 优化后的主题生成API处理器
 * 职责：业务逻辑处理（标签替换、结果解析）、API轮询控制
 * 通信：委托给 Yali_AI_Writer_UnifiedApiHandler 处理
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-config.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-json-parser.php';

class Yali_AI_Writer_TopicApiHandler {
    
    private $api_config;
    private $logger;
    private $last_api_error;
    private $current_api_config;
    
    public function __construct($logger = null) {
        $this->api_config = new Yali_AI_Writer_ApiConfig();
        $this->logger = $logger;
        $this->last_api_error = null;
    }
    
    /**
     * 【核心业务逻辑】生成主题并解析
     */
    public function generate_topics($prompt, $count, $rule_id = null, $rule_item_index = null, $task_id = null, $job_queue_id = null) {
        $context = $this->build_context($rule_id, $rule_item_index, array('期望数量' => $count, 'job_queue_id' => $job_queue_id));
        
        // 1. 【追溯业务逻辑：重试与轮询】
        $max_retries = 2;
        $last_error = null;
        
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $is_retry = ($attempt > 0);
            $api_config = $this->api_config->get_next_active_config($is_retry);
            
            if (!$api_config) {
                break;
            }
            
            $this->current_api_config = $api_config;

            // 2. 【追溯业务逻辑：标签替换】
            $full_prompt = str_replace(array('{N}', '{{N}}'), $count, $prompt);
            
            // 3. 【核心优化：接入统一通信层】
            $unified_handler = new Yali_AI_Writer_UnifiedApiHandler($this->logger);
            $result_data = $unified_handler->generate_content($full_prompt, 'topic_generation', [
                'rule_id' => $rule_id,
                'rule_item_index' => $rule_item_index,
                'config_id' => $api_config['id'],
                'task_id' => $task_id,
                'job_queue_id' => $job_queue_id,
                'return_usage' => true // 返回 usage 和 finish_reason
            ]);

            // 检查通信结果（finish_reason == 'length' 已由底层 UnifiedApiHandler 自动触发 API 轮询切换）
            if (!is_array($result_data) || !isset($result_data['error'])) {
                $content = is_array($result_data) && isset($result_data['content']) ? $result_data['content'] : $result_data;
                
                // 4. 【追溯业务逻辑：解析识别】
                $topics = $this->parse_api_content($content, $count, $rule_id, $rule_item_index);
                if (!empty($topics) && is_array($topics) && !isset($topics['error'])) {
                    $this->api_config->mark_api_success($api_config['id']);
                    return $topics;
                }
                $last_error = array(
                    'stage' => __('内容解析', 'yali-ai-writer'), 
                    'message' => (isset($topics['error']) ? $topics['error'] : __('无法从API返回的内容中提取有效主题', 'yali-ai-writer'))
                );
            } else {
                // 将新处理器的错误字符串封装为老处理器期望的数组格式
                $last_error = array(
                    'stage' => __('API通信', 'yali-ai-writer'),
                    'message' => is_string($result_data['error']) ? $result_data['error'] : json_encode($result_data['error'])
                );
            }
            
            $this->api_config->mark_api_failed($api_config['id']);
        }
        
        return array('error' => $last_error);
    }

    /**
     * 【追溯业务逻辑：复杂的解析流程】
     * 保持原有解析算法不变，确保在 SSE 开启后依然能正确拆解 AI 返回的长文本
     */
    private function parse_api_content($content, $count, $rule_id, $rule_item_index) {
        if (empty($content)) return array();
        
        // 优先使用 JSON 解析器
        $json_parser = new Yali_AI_Writer_JsonParser($this->logger);
        // ✅ 修正方法名: 从 parse 改为 parse_json_topics
        $topics = $json_parser->parse_json_topics($content, $count, $rule_id, $rule_item_index);
        
        if (!empty($topics) && is_array($topics) && !isset($topics['error'])) {
            return $topics;
        }
        
        // 【追溯逻辑：Markdown列表正则解析】
        $topics = array();
        if (preg_match_all('/^(?:\d+[\.\、\s-]+|[-*•]\s+)(.+)$/m', $content, $matches)) {
            foreach ($matches[1] as $title) {
                $topics[] = array('title' => trim($title));
            }
        }
        
        // 【追溯逻辑：换行切分兜底】
        if (empty($topics)) {
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $topics[] = array('title' => trim($line));
                }
            }
        }
        
        return array_slice($topics, 0, $count + 5); 
    }

    public function get_current_api_config() {
        return $this->current_api_config;
    }

    private function build_context($rule_id, $rule_item_index, $extra) {
        return array_merge(array('rule_id' => $rule_id, 'idx' => $rule_item_index), $extra);
    }
}