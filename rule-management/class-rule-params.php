<?php
/**
 * 规则参数类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_RuleParams {
    public $name = '';
    public $rule_type = '';
    public $conditions = array();
    public $item_count = 0;
    public $rule_task_id = '';
    public $status = 1;
    
    /**
     * 从数组创建参数对象
     */
    public static function fromArray($data) {
        $params = new self();
        
        $params->name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
        $params->rule_type = isset($data['rule_type']) ? sanitize_text_field($data['rule_type']) : '';
        $params->conditions = isset($data['conditions']) ? maybe_unserialize($data['conditions']) : array();
        $params->item_count = isset($data['item_count']) ? intval($data['item_count']) : 0;
        $params->rule_task_id = isset($data['rule_task_id']) ? sanitize_text_field($data['rule_task_id']) : '';
        $params->status = isset($data['status']) ? intval($data['status']) : 1;
        
        // 确保conditions是数组
        if (!is_array($params->conditions)) {
            $params->conditions = array();
        }
        
        return $params;
    }
    
    /**
     * 转换为数组
     */
    public function toArray() {
        return array(
            'name' => $this->name,
            'rule_type' => $this->rule_type,
            'conditions' => maybe_serialize($this->conditions),
            'item_count' => $this->item_count,
            'rule_task_id' => $this->rule_task_id,
            'status' => $this->status
        );
    }
    
    /**
     * 验证参数
     */
    public function validate() {
        $errors = array();
        
        // 验证名称
        if (empty($this->name)) {
            $errors[] = __('规则名称不能为空', 'content-auto-manager');
        }
        
        // 验证规则类型
        $valid_types = array('random_selection', 'fixed_articles', 'upload_text');
        if (empty($this->rule_type) || !in_array($this->rule_type, $valid_types)) {
            $errors[] = __('无效的规则类型', 'content-auto-manager');
        }
        
        // 验证状态
        if (!in_array($this->status, array(0, 1))) {
            $errors[] = __('无效的规则状态', 'content-auto-manager');
        }
        
        return $errors;
    }
}