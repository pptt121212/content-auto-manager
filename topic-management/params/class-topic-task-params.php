<?php
/**
 * 主题任务参数类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TopicTaskParams {
    public $topic_task_id = '';
    public $rule_id = 0;
    public $topic_count_per_item = 0;
    public $total_rule_items = 0;
    public $total_expected_topics = 0;
    public $current_processing_item = 0;
    public $generated_topics_count = 0;
    public $status = CONTENT_AUTO_STATUS_PENDING;
    public $error_message = '';
    
    /**
     * 从数组创建参数对象
     */
    public static function fromArray($data) {
        $params = new self();
        
        $params->topic_task_id = isset($data['topic_task_id']) ? sanitize_text_field($data['topic_task_id']) : '';
        $params->rule_id = isset($data['rule_id']) ? intval($data['rule_id']) : 0;
        $params->topic_count_per_item = isset($data['topic_count_per_item']) ? intval($data['topic_count_per_item']) : 0;
        $params->total_rule_items = isset($data['total_rule_items']) ? intval($data['total_rule_items']) : 0;
        $params->total_expected_topics = isset($data['total_expected_topics']) ? intval($data['total_expected_topics']) : 0;
        $params->current_processing_item = isset($data['current_processing_item']) ? intval($data['current_processing_item']) : 0;
        $params->generated_topics_count = isset($data['generated_topics_count']) ? intval($data['generated_topics_count']) : 0;
        $params->status = isset($data['status']) ? sanitize_text_field($data['status']) : CONTENT_AUTO_STATUS_PENDING;
        $params->error_message = isset($data['error_message']) ? sanitize_text_field($data['error_message']) : '';
        
        return $params;
    }
    
    /**
     * 转换为数组
     */
    public function toArray() {
        return array(
            'topic_task_id' => $this->topic_task_id,
            'rule_id' => $this->rule_id,
            'topic_count_per_item' => $this->topic_count_per_item,
            'total_rule_items' => $this->total_rule_items,
            'total_expected_topics' => $this->total_expected_topics,
            'current_processing_item' => $this->current_processing_item,
            'generated_topics_count' => $this->generated_topics_count,
            'status' => $this->status,
            'error_message' => $this->error_message
        );
    }
    
    /**
     * 验证参数
     */
    public function validate() {
        $errors = array();
        
        // 验证规则ID
        if (empty($this->rule_id)) {
            $errors[] = __('规则ID不能为空', 'content-auto-manager');
        }
        
        // 验证主题数量
        if ($this->topic_count_per_item < 1) {
            $errors[] = __('每个规则项的主题数量必须大于0', 'content-auto-manager');
        }
        
        // 验证状态
        $valid_statuses = array(CONTENT_AUTO_STATUS_PENDING, CONTENT_AUTO_STATUS_RUNNING, CONTENT_AUTO_STATUS_COMPLETED, CONTENT_AUTO_STATUS_FAILED, CONTENT_AUTO_STATUS_PAUSED);
        if (!in_array($this->status, $valid_statuses)) {
            $errors[] = __('无效的任务状态', 'content-auto-manager');
        }
        
        return $errors;
    }
}