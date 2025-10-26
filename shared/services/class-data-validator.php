<?php
/**
 * 简化的数据验证器
 * 保留基本的字段验证功能，移除未使用的复杂逻辑
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_DataValidator {
    
    /**
     * 基本验证规则
     */
    private $validation_rules = array(
        'task_id' => array(
            'type' => 'integer',
            'min' => 1,
            'required' => true
        ),
        'status' => array(
            'type' => 'string',
            'enum' => array('pending', 'running', 'processing', 'completed', 'failed', 'paused', 'cancelled', 'retry'),
            'required' => true
        ),
        'progress' => array(
            'type' => 'array',
            'required' => false,
            'fields' => array(
                'current_item' => array('type' => 'integer', 'min' => 0),
                'total_items' => array('type' => 'integer', 'min' => 0),
                'generated_topics' => array('type' => 'integer', 'min' => 0),
                'expected_topics' => array('type' => 'integer', 'min' => 0),
                'progress_percentage' => array('type' => 'integer', 'min' => 0, 'max' => 100)
            )
        ),
        'error_message' => array(
            'type' => 'string',
            'required' => false,
            'max_length' => 1000
        )
    );
    
    /**
     * 构造函数 - 简化版本，不注册钩子
     */
    public function __construct() {
        // 简化版本，不初始化复杂的钩子注册
    }
    
    /**
     * 验证单个数据字段
     */
    public function validate_field($field_name, $field_value, $rules) {
        $errors = array();
        
        // 检查必填字段
        if (isset($rules['required']) && $rules['required'] && empty($field_value)) {
            $errors[] = sprintf(__('字段 %s 是必填的', 'content-auto-manager'), $field_name);
            return $errors;
        }
        
        // 如果字段为空且不是必填，跳过验证
        if (empty($field_value) && !isset($rules['required'])) {
            return $errors;
        }
        
        // 类型验证
        if (isset($rules['type'])) {
            switch ($rules['type']) {
                case 'integer':
                    if (!is_int($field_value) && !is_numeric($field_value)) {
                        $errors[] = sprintf(__('字段 %s 必须是整数', 'content-auto-manager'), $field_name);
                    } else {
                        $field_value = intval($field_value);
                        
                        // 最小值验证
                        if (isset($rules['min']) && $field_value < $rules['min']) {
                            $errors[] = sprintf(__('字段 %s 不能小于 %d', 'content-auto-manager'), $field_name, $rules['min']);
                        }
                        
                        // 最大值验证
                        if (isset($rules['max']) && $field_value > $rules['max']) {
                            $errors[] = sprintf(__('字段 %s 不能大于 %d', 'content-auto-manager'), $field_name, $rules['max']);
                        }
                    }
                    break;
                    
                case 'string':
                    if (!is_string($field_value)) {
                        $errors[] = sprintf(__('字段 %s 必须是字符串', 'content-auto-manager'), $field_name);
                    } else {
                        // 枚举值验证
                        if (isset($rules['enum']) && !in_array($field_value, $rules['enum'])) {
                            $errors[] = sprintf(__('字段 %s 必须是以下值之一: %s', 'content-auto-manager'), $field_name, implode(', ', $rules['enum']));
                        }
                        
                        // 最大长度验证
                        if (isset($rules['max_length']) && strlen($field_value) > $rules['max_length']) {
                            $errors[] = sprintf(__('字段 %s 长度不能超过 %d 个字符', 'content-auto-manager'), $field_name, $rules['max_length']);
                        }
                    }
                    break;
                    
                case 'array':
                    if (!is_array($field_value)) {
                        $errors[] = sprintf(__('字段 %s 必须是数组', 'content-auto-manager'), $field_name);
                    } elseif (isset($rules['fields'])) {
                        // 验证数组字段
                        foreach ($rules['fields'] as $sub_field => $sub_rules) {
                            if (isset($field_value[$sub_field])) {
                                $sub_errors = $this->validate_field($sub_field, $field_value[$sub_field], $sub_rules);
                                $errors = array_merge($errors, $sub_errors);
                            }
                        }
                    }
                    break;
            }
        }
        
        return $errors;
    }
    
    /**
     * 验证任务数据完整性 - 简化版本
     */
    public function validate_task_data($task_data) {
        $errors = array();
        
        // 验证基本字段
        foreach ($this->validation_rules as $field => $rules) {
            if (isset($task_data[$field])) {
                $field_errors = $this->validate_field($field, $task_data[$field], $rules);
                $errors = array_merge($errors, $field_errors);
            }
        }
        
        return $errors;
    }
    
    /**
     * 验证规则数据 - 简化版本
     */
    public function validate_rule_data($rule_data) {
        $errors = array();
        
        // 基本规则验证
        if (empty($rule_data['rule_name'])) {
            $errors[] = __('规则名称不能为空', 'content-auto-manager');
        }
        
        if (empty($rule_data['rule_type'])) {
            $errors[] = __('规则类型不能为空', 'content-auto-manager');
        }
        
        return $errors;
    }
    
    /**
     * 验证API配置数据 - 简化版本
     */
    public function validate_api_config_data($config_data) {
        $errors = array();
        
        // 基本配置验证
        if (empty($config_data['name'])) {
            $errors[] = __('API配置名称不能为空', 'content-auto-manager');
        }
        
        if (empty($config_data['api_url'])) {
            $errors[] = __('API地址不能为空', 'content-auto-manager');
        }
        
        if (empty($config_data['api_key'])) {
            $errors[] = __('API密钥不能为空', 'content-auto-manager');
        }
        
        return $errors;
    }
}
?>