<?php
/**
 * 任务状态管理器
 * 负责统一管理任务状态，包括状态验证、转换、一致性检查等
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TaskStatusManager {
    
    /**
     * 状态常量映射
     */
    private $status_constants = array(
        'pending' => 'CONTENT_AUTO_STATUS_PENDING',
        'running' => 'CONTENT_AUTO_STATUS_RUNNING',
        'processing' => 'CONTENT_AUTO_STATUS_PROCESSING',
        'completed' => 'CONTENT_AUTO_STATUS_COMPLETED',
        'failed' => 'CONTENT_AUTO_STATUS_FAILED',
        'paused' => 'CONTENT_AUTO_STATUS_PAUSED',
        'cancelled' => 'CONTENT_AUTO_STATUS_CANCELLED',
        'retry' => 'CONTENT_AUTO_STATUS_RETRY'
    );
    
    /**
     * 状态标签映射
     */
    private $status_labels = array(
        'pending' => '待处理',
        'running' => '运行中',
        'processing' => '处理中',
        'completed' => '已完成',
        'failed' => '失败',
        'paused' => '已暂停',
        'cancelled' => '已取消',
        'retry' => '重试中'
    );
    
    private $database;
    private $logger;
    
    public function __construct($database = null, $logger = null) {
        $this->database = $database ?: new ContentAuto_Database();
        $this->logger = $logger;
    }
    
    /**
     * 验证状态值的有效性
     */
    public function validate_status($status) {
        if (empty($status)) {
            return false;
        }
        
        // 检查是否为有效的状态字符串
        if (isset($this->status_constants[$status])) {
            return true;
        }
        
        // 检查是否为状态常量值
        if (in_array($status, array_values($this->status_constants))) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 标准化状态值（转换为字符串）
     */
    public function normalize_status($status) {
        if (empty($status)) {
            return 'pending';
        }
        
        // 如果已经是字符串状态，直接返回
        if (isset($this->status_constants[$status])) {
            return $status;
        }
        
        // 如果是常量值，转换为对应的字符串
        $constant_to_string = array_flip($this->status_constants);
        if (isset($constant_to_string[$status])) {
            return $constant_to_string[$status];
        }
        
        // 无效状态，默认为pending
        return 'pending';
    }
    
    /**
     * 获取状态常量值
     */
    public function get_status_constant($status_string) {
        $normalized = $this->normalize_status($status_string);
        return isset($this->status_constants[$normalized]) ? constant($this->status_constants[$normalized]) : CONTENT_AUTO_STATUS_PENDING;
    }
    
    /**
     * 获取状态标签
     */
    public function get_status_label($status) {
        $normalized = $this->normalize_status($status);
        if (isset($this->status_labels[$normalized])) {
            return sprintf(__("%s", "content-auto-manager"), $this->status_labels[$normalized]);
        }
        return $normalized;
    }
    
    /**
     * 获取所有有效状态列表
     */
    public function get_all_valid_statuses() {
        return array_keys($this->status_constants);
    }
    
    /**
     * 验证状态转换的有效性
     */
    public function is_valid_status_transition($current_status, $new_status, $task_type = 'topic_task') {
        $current = $this->normalize_status($current_status);
        $new = $this->normalize_status($new_status);
        
        // 定义允许的状态转换规则
        $valid_transitions = array(
            'pending' => array('running', 'processing', 'paused', 'cancelled'),
            'running' => array('processing', 'paused', 'cancelled', 'failed'),
            'processing' => array('pending', 'running', 'completed', 'failed', 'paused', 'cancelled'),
            'completed' => array(), // 完成状态不允许转换
            'failed' => array('pending', 'retry'), // 失败后可以重试
            'paused' => array('pending', 'running', 'processing', 'cancelled'),
            'cancelled' => array(), // 取消状态不允许转换
            'retry' => array('pending', 'running', 'processing', 'failed')
        );
        
        // 文章任务特有的状态转换规则
        if ($task_type === 'article') {
            // 文章任务允许从processing直接回到pending（子任务完成后等待下一个）
            $valid_transitions['processing'][] = 'pending';
            
            // 文章任务允许从failed状态重新开始
            $valid_transitions['failed'] = array('pending', 'retry', 'processing');
            
            // 文章任务的completed状态在某些情况下可以重新处理（如果有新的子任务）
            // 但通常情况下仍然不允许转换
        }
        
        return isset($valid_transitions[$current]) && in_array($new, $valid_transitions[$current]);
    }
    
    /**
     * 确保状态一致性
     */
    public function ensure_status_consistency($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                break;
        }
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$task_table} WHERE id = %d", $task_id), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        $inconsistencies = array();
        
        // 检查主任务状态
        $normalized_status = $this->normalize_status($task['status']);
        if ($task['status'] !== $normalized_status) {
            $inconsistencies[] = "主任务状态不规范: '{$task['status']}' → '{$normalized_status}'";
        }
        
        // 检查子任务状态 - 支持文章任务的不同结构
        if ($task_type === 'article') {
            // 文章任务的子任务状态结构可能不同
            $subtask_status = json_decode($task['subtask_status'], true);
            if (is_array($subtask_status)) {
                foreach ($subtask_status as $topic_id => $status) {
                    if (is_string($status)) {
                        // 简单状态字符串格式
                        $normalized_subtask = $this->normalize_status($status);
                        if ($status !== $normalized_subtask) {
                            $inconsistencies[] = "文章子任务[主题ID:{$topic_id}]状态不规范: '{$status}' → '{$normalized_subtask}'";
                        }
                    } elseif (is_array($status) && isset($status['status'])) {
                        // 复杂状态对象格式
                        $normalized_subtask = $this->normalize_status($status['status']);
                        if ($status['status'] !== $normalized_subtask) {
                            $inconsistencies[] = "文章子任务[主题ID:{$topic_id}]状态不规范: '{$status['status']}' → '{$normalized_subtask}'";
                        }
                    }
                }
            }
        } else {
            // 主题任务的原有逻辑
            $subtask_status = json_decode($task['subtask_status'], true);
            if (is_array($subtask_status)) {
                foreach ($subtask_status as $index => $subtask) {
                    if (isset($subtask['status'])) {
                        $normalized_subtask = $this->normalize_status($subtask['status']);
                        if ($subtask['status'] !== $normalized_subtask) {
                            $inconsistencies[] = "子任务[{$index}]状态不规范: '{$subtask['status']}' → '{$normalized_subtask}'";
                        }
                    }
                }
            }
        }
        
        // 检查队列状态一致性
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $job_type = ($task_type === 'article') ? 'article' : 'topic_task';
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE job_type = %s AND job_id = %d",
            $job_type, $task_id
        ), ARRAY_A);
        
        foreach ($queue_items as $queue_item) {
            $normalized_queue = $this->normalize_status($queue_item['status']);
            if ($queue_item['status'] !== $normalized_queue) {
                $inconsistencies[] = "队列项[{$queue_item['id']}]状态不规范: '{$queue_item['status']}' → '{$normalized_queue}'";
            }
        }
        
        // 文章任务特有的一致性检查
        if ($task_type === 'article') {
            $article_inconsistencies = $this->check_article_specific_consistency($task, $queue_items);
            $inconsistencies = array_merge($inconsistencies, $article_inconsistencies);
        }
        
        if (!empty($inconsistencies)) {
            // 记录不一致性详情
            if ($this->logger) {
                $this->logger->log_warning('STATUS_INCONSISTENCY', 
                    "发现状态不一致: " . implode('; ', $inconsistencies),
                    ['task_id' => $task_id, 'task_type' => $task_type]
                );
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * 修复不一致的状态
     */
    public function fix_inconsistent_statuses($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                break;
        }
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$task_table} WHERE id = %d", $task_id), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        $fixes_applied = array();
        
        // 修复主任务状态
        $normalized_status = $this->normalize_status($task['status']);
        if ($task['status'] !== $normalized_status) {
            $wpdb->update($task_table, 
                array('status' => $normalized_status),
                array('id' => $task_id)
            );
            $fixes_applied[] = "主任务状态: '{$task['status']}' → '{$normalized_status}'";
        }
        
        // 修复子任务状态 - 支持文章任务的不同结构
        if ($task_type === 'article') {
            $subtask_status = json_decode($task['subtask_status'], true);
            if (is_array($subtask_status)) {
                $has_changes = false;
                foreach ($subtask_status as $topic_id => $status) {
                    if (is_string($status)) {
                        // 简单状态字符串格式
                        $normalized_subtask = $this->normalize_status($status);
                        if ($status !== $normalized_subtask) {
                            $subtask_status[$topic_id] = $normalized_subtask;
                            $has_changes = true;
                            $fixes_applied[] = "文章子任务[主题ID:{$topic_id}]状态: '{$status}' → '{$normalized_subtask}'";
                        }
                    } elseif (is_array($status) && isset($status['status'])) {
                        // 复杂状态对象格式
                        $normalized_subtask = $this->normalize_status($status['status']);
                        if ($status['status'] !== $normalized_subtask) {
                            $subtask_status[$topic_id]['status'] = $normalized_subtask;
                            $has_changes = true;
                            $fixes_applied[] = "文章子任务[主题ID:{$topic_id}]状态: '{$status['status']}' → '{$normalized_subtask}'";
                        }
                    }
                }
                
                if ($has_changes) {
                    $wpdb->update($task_table,
                        array('subtask_status' => json_encode($subtask_status)),
                        array('id' => $task_id)
                    );
                }
            }
        } else {
            // 主题任务的原有逻辑
            $subtask_status = json_decode($task['subtask_status'], true);
            if (is_array($subtask_status)) {
                $has_changes = false;
                foreach ($subtask_status as $index => $subtask) {
                    if (isset($subtask['status'])) {
                        $normalized_subtask = $this->normalize_status($subtask['status']);
                        if ($subtask['status'] !== $normalized_subtask) {
                            $subtask_status[$index]['status'] = $normalized_subtask;
                            $has_changes = true;
                            $fixes_applied[] = "子任务[{$index}]状态: '{$subtask['status']}' → '{$normalized_subtask}'";
                        }
                    }
                }
                
                if ($has_changes) {
                    $wpdb->update($task_table,
                        array('subtask_status' => json_encode($subtask_status)),
                        array('id' => $task_id)
                    );
                }
            }
        }
        
        // 修复队列状态
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $job_type = ($task_type === 'article') ? 'article' : 'topic_task';
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE job_type = %s AND job_id = %d",
            $job_type, $task_id
        ), ARRAY_A);
        
        foreach ($queue_items as $queue_item) {
            $normalized_queue = $this->normalize_status($queue_item['status']);
            if ($queue_item['status'] !== $normalized_queue) {
                $wpdb->update($queue_table,
                    array('status' => $normalized_queue),
                    array('id' => $queue_item['id'])
                );
                $fixes_applied[] = "队列项[{$queue_item['id']}]状态: '{$queue_item['status']}' → '{$normalized_queue}'";
            }
        }
        
        // 应用文章任务特有的修复
        if ($task_type === 'article') {
            $article_fixes = $this->fix_article_specific_inconsistencies($task, $queue_items);
            $fixes_applied = array_merge($fixes_applied, $article_fixes);
        }
        
        if (!empty($fixes_applied)) {
            if ($this->logger) {
                $this->logger->log_success('STATUS_FIXED', 
                    "状态不一致已修复: " . implode('; ', $fixes_applied),
                    ['task_id' => $task_id, 'task_type' => $task_type]
                );
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * 记录状态变更
     */
    public function log_status_change($task_id, $old_status, $new_status, $reason = '') {
        $log_message = sprintf(
            "任务状态变更 - task_id=%d: %s → %s",
            $task_id,
            $this->normalize_status($old_status),
            $this->normalize_status($new_status)
        );
        
        if (!empty($reason)) {
            $log_message .= " (原因: {$reason})";
        }
        
        // 使用插件自定义日志类记录日志
        $logger = new ContentAuto_PluginLogger();
        $logger->info($log_message);
        
        // 可以扩展为写入专门的日志表
        return true;
    }
    
    /**
     * 标准化子任务状态
     */
    public function normalize_subtask_status($subtask_status) {
        if (!is_array($subtask_status)) {
            return array();
        }
        
        $normalized = array();
        foreach ($subtask_status as $index => $subtask) {
            if (!is_array($subtask)) {
                continue;
            }
            
            $normalized[$index] = $subtask;
            
            // 标准化状态字段
            if (isset($subtask['status'])) {
                $normalized[$index]['status'] = $this->normalize_status($subtask['status']);
            }
            
            // 确保必要字段存在
            if (!isset($normalized[$index]['processed_at'])) {
                $normalized[$index]['processed_at'] = null;
            }
            if (!isset($normalized[$index]['error'])) {
                $normalized[$index]['error'] = '';
            }
            if (!isset($normalized[$index]['retry_count'])) {
                $normalized[$index]['retry_count'] = 0;
            }
        }
        
        return $normalized;
    }
    
    /**
     * 验证子任务状态progression
     */
    public function validate_subtask_status_progression($subtask_status, $total_items) {
        if (!is_array($subtask_status)) {
            return true; // 空状态视为有效
        }
        
        $completed_count = 0;
        $failed_count = 0;
        $processing_count = 0;
        
        for ($i = 0; $i < $total_items; $i++) {
            if (!isset($subtask_status[$i])) {
                continue; // 未处理的子任务是正常的
            }
            
            $status = $this->normalize_status($subtask_status[$i]['status']);
            
            switch ($status) {
                case 'completed':
                    $completed_count++;
                    break;
                case 'failed':
                    $failed_count++;
                    break;
                case 'processing':
                    $processing_count++;
                    break;
            }
        }
        
        // 验证规则：最多只能有一个处理中的子任务
        if ($processing_count > 1) {
            return false;
        }
        
        // 验证完成和失败的总数不超过总数
        if (($completed_count + $failed_count) > $total_items) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 安全地更新任务状态
     */
    public function safe_update_task_status($task_id, $new_status, $reason = '', $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                break;
        }
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$task_table} WHERE id = %d", $task_id), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        $old_status = $task['status'];
        $normalized_old = $this->normalize_status($old_status);
        $normalized_new = $this->normalize_status($new_status);
        
        // 验证状态转换
        if (!$this->is_valid_status_transition($normalized_old, $normalized_new, $task_type)) {
            if ($this->logger) {
                $this->logger->log_warning('INVALID_TRANSITION', 
                    "无效的状态转换: {$normalized_old} → {$normalized_new}",
                    ['task_id' => $task_id, 'task_type' => $task_type]
                );
            }
            return false;
        }
        
        // 执行更新
        $result = $wpdb->update($task_table,
            array('status' => $normalized_new),
            array('id' => $task_id)
        );
        
        if ($result !== false) {
            $this->log_status_change($task_id, $normalized_old, $normalized_new, $reason);
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取任务当前状态
     */
    public function get_task_status($task_id, $task_type = 'topic_task') {
        // 根据任务类型确定表名
        switch ($task_type) {
            case 'article':
                $table_name = 'content_auto_article_tasks';
                break;
            case 'topic_task':
            default:
                $table_name = 'content_auto_topic_tasks';
                break;
        }
        
        $task = $this->database->get_row($table_name, array('id' => $task_id));
        return $task ? $this->normalize_status($task['status']) : false;
    }
    
    /**
     * 批量标准化状态
     */
    public function batch_normalize_statuses() {
        global $wpdb;
        
        $topic_task_table = $wpdb->prefix . 'content_auto_topic_tasks';
        $article_task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        $normalized_count = 0;
        
        // 标准化主题任务表状态
        $tasks = $wpdb->get_results("SELECT id, status FROM {$topic_task_table}", ARRAY_A);
        foreach ($tasks as $task) {
            $normalized_status = $this->normalize_status($task['status']);
            if ($task['status'] !== $normalized_status) {
                $wpdb->update($topic_task_table,
                    array('status' => $normalized_status),
                    array('id' => $task['id'])
                );
                $normalized_count++;
            }
        }
        
        // 标准化文章任务表状态
        $article_tasks = $wpdb->get_results("SELECT id, status FROM {$article_task_table}", ARRAY_A);
        foreach ($article_tasks as $task) {
            $normalized_status = $this->normalize_status($task['status']);
            if ($task['status'] !== $normalized_status) {
                $wpdb->update($article_task_table,
                    array('status' => $normalized_status),
                    array('id' => $task['id'])
                );
                $normalized_count++;
            }
        }
        
        // 标准化队列表状态 - 主题任务
        $queue_items = $wpdb->get_results("SELECT id, status FROM {$queue_table} WHERE job_type = 'topic_task'", ARRAY_A);
        foreach ($queue_items as $queue_item) {
            $normalized_status = $this->normalize_status($queue_item['status']);
            if ($queue_item['status'] !== $normalized_status) {
                $wpdb->update($queue_table,
                    array('status' => $normalized_status),
                    array('id' => $queue_item['id'])
                );
                $normalized_count++;
            }
        }
        
        // 标准化队列表状态 - 文章任务
        $article_queue_items = $wpdb->get_results("SELECT id, status FROM {$queue_table} WHERE job_type = 'article'", ARRAY_A);
        foreach ($article_queue_items as $queue_item) {
            $normalized_status = $this->normalize_status($queue_item['status']);
            if ($queue_item['status'] !== $normalized_status) {
                $wpdb->update($queue_table,
                    array('status' => $normalized_status),
                    array('id' => $queue_item['id'])
                );
                $normalized_count++;
            }
        }
        
        if ($normalized_count > 0 && $this->logger) {
            $this->logger->log_success('BATCH_NORMALIZE', "批量标准化完成，处理了 {$normalized_count} 个状态");
        }
        
        return $normalized_count;
    }
    
    /**
     * 检查文章任务特有的一致性问题
     */
    private function check_article_specific_consistency($task, $queue_items) {
        $inconsistencies = array();
        
        // 检查主题ID与队列项的reference_id一致性
        $topic_ids = json_decode($task['topic_ids'], true);
        if (!is_array($topic_ids)) {
            $inconsistencies[] = "文章任务的topic_ids字段格式错误";
            return $inconsistencies;
        }
        
        $queue_reference_ids = array();
        foreach ($queue_items as $queue_item) {
            if (!empty($queue_item['reference_id'])) {
                $queue_reference_ids[] = $queue_item['reference_id'];
            }
        }
        
        // 检查是否有主题ID在队列中缺失
        $missing_in_queue = array_diff($topic_ids, $queue_reference_ids);
        if (!empty($missing_in_queue)) {
            $inconsistencies[] = "队列中缺失主题ID: " . implode(', ', $missing_in_queue);
        }
        
        // 检查是否有队列项的reference_id不在主题列表中
        $extra_in_queue = array_diff($queue_reference_ids, $topic_ids);
        if (!empty($extra_in_queue)) {
            $inconsistencies[] = "队列中存在多余的主题ID: " . implode(', ', $extra_in_queue);
        }
        
        // 检查计数一致性
        $completed_count = 0;
        $failed_count = 0;
        foreach ($queue_items as $queue_item) {
            if ($queue_item['status'] === 'completed') {
                $completed_count++;
            } elseif ($queue_item['status'] === 'failed') {
                $failed_count++;
            }
        }
        
        if ($task['completed_topics'] != $completed_count) {
            $inconsistencies[] = "已完成主题计数不一致: 任务记录={$task['completed_topics']}, 队列统计={$completed_count}";
        }
        
        if ($task['failed_topics'] != $failed_count) {
            $inconsistencies[] = "失败主题计数不一致: 任务记录={$task['failed_topics']}, 队列统计={$failed_count}";
        }
        
        // 检查状态转换的合理性
        $total_processed = $completed_count + $failed_count;
        $total_topics = count($topic_ids);
        
        if ($total_processed > $total_topics) {
            $inconsistencies[] = "处理的主题数超过总主题数: 处理={$total_processed}, 总数={$total_topics}";
        }
        
        // 检查任务状态与子任务状态的一致性
        if ($task['status'] === 'completed' && $failed_count > 0) {
            $inconsistencies[] = "任务状态为已完成但存在失败的子任务";
        }
        
        if ($task['status'] === 'completed' && $total_processed < $total_topics) {
            $inconsistencies[] = "任务状态为已完成但仍有未处理的子任务";
        }
        
        return $inconsistencies;
    }
    
    /**
     * 修复文章任务特有的不一致性问题
     */
    private function fix_article_specific_inconsistencies($task, $queue_items) {
        global $wpdb;
        $fixes_applied = array();
        
        // 重新计算并修复计数
        $completed_count = 0;
        $failed_count = 0;
        foreach ($queue_items as $queue_item) {
            if ($queue_item['status'] === 'completed') {
                $completed_count++;
            } elseif ($queue_item['status'] === 'failed') {
                $failed_count++;
            }
        }
        
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $update_data = array();
        
        if ($task['completed_topics'] != $completed_count) {
            $update_data['completed_topics'] = $completed_count;
            $fixes_applied[] = "修复已完成主题计数: {$task['completed_topics']} → {$completed_count}";
        }
        
        if ($task['failed_topics'] != $failed_count) {
            $update_data['failed_topics'] = $failed_count;
            $fixes_applied[] = "修复失败主题计数: {$task['failed_topics']} → {$failed_count}";
        }
        
        // 检查并修复任务最终状态
        $topic_ids = json_decode($task['topic_ids'], true);
        $total_topics = is_array($topic_ids) ? count($topic_ids) : 0;
        $total_processed = $completed_count + $failed_count;
        
        if ($total_processed >= $total_topics && $total_topics > 0) {
            $correct_final_status = ($failed_count > 0) ? 'failed' : 'completed';
            if ($task['status'] !== $correct_final_status) {
                $update_data['status'] = $correct_final_status;
                $fixes_applied[] = "修复任务最终状态: {$task['status']} → {$correct_final_status}";
            }
        }
        
        // 应用修复
        if (!empty($update_data)) {
            $update_data['updated_at'] = current_time('mysql');
            $wpdb->update($task_table, $update_data, array('id' => $task['id']));
        }
        
        return $fixes_applied;
    }
    
    /**
     * 自动修复文章任务的状态不一致问题
     */
    public function auto_fix_article_task_inconsistencies($task_id) {
        if (!$this->ensure_status_consistency($task_id, 'article')) {
            return $this->fix_inconsistent_statuses($task_id, 'article');
        }
        return true; // 已经一致，无需修复
    }
    
    /**
     * 验证并修复文章任务与队列状态的同步
     */
    public function sync_article_task_with_queue($task_id) {
        global $wpdb;
        
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$task_table} WHERE id = %d", $task_id), ARRAY_A);
        if (!$task) {
            return false;
        }
        
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d",
            $task_id
        ), ARRAY_A);
        
        $sync_fixes = array();
        
        // 统计队列中的实际状态
        $queue_stats = array(
            'completed' => 0,
            'failed' => 0,
            'processing' => 0,
            'pending' => 0
        );
        
        foreach ($queue_items as $queue_item) {
            $status = $this->normalize_status($queue_item['status']);
            if (isset($queue_stats[$status])) {
                $queue_stats[$status]++;
            }
        }
        
        // 更新任务表中的统计信息
        $update_data = array();
        
        if ($task['completed_topics'] != $queue_stats['completed']) {
            $update_data['completed_topics'] = $queue_stats['completed'];
            $sync_fixes[] = "同步已完成主题数: {$task['completed_topics']} → {$queue_stats['completed']}";
        }
        
        if ($task['failed_topics'] != $queue_stats['failed']) {
            $update_data['failed_topics'] = $queue_stats['failed'];
            $sync_fixes[] = "同步失败主题数: {$task['failed_topics']} → {$queue_stats['failed']}";
        }
        
        // 更新当前处理项计数
        $total_processed = $queue_stats['completed'] + $queue_stats['failed'];
        if ($task['current_processing_item'] != $total_processed) {
            $update_data['current_processing_item'] = $total_processed;
            $sync_fixes[] = "同步当前处理项: {$task['current_processing_item']} → {$total_processed}";
        }
        
        // 检查并更新任务状态
        $topic_ids = json_decode($task['topic_ids'], true);
        $total_topics = is_array($topic_ids) ? count($topic_ids) : 0;
        
        if ($total_processed >= $total_topics && $total_topics > 0) {
            $correct_status = ($queue_stats['failed'] > 0) ? 'failed' : 'completed';
            if ($task['status'] !== $correct_status) {
                $update_data['status'] = $correct_status;
                $sync_fixes[] = "同步任务状态: {$task['status']} → {$correct_status}";
            }
        } elseif ($queue_stats['processing'] > 0) {
            if ($task['status'] !== 'processing') {
                $update_data['status'] = 'processing';
                $sync_fixes[] = "同步任务状态: {$task['status']} → processing";
            }
        } elseif ($queue_stats['pending'] > 0 && $task['status'] === 'processing') {
            $update_data['status'] = 'pending';
            $sync_fixes[] = "同步任务状态: {$task['status']} → pending";
        }
        
        // 应用同步修复
        if (!empty($update_data)) {
            $update_data['updated_at'] = current_time('mysql');
            $result = $wpdb->update($task_table, $update_data, array('id' => $task_id));
            
            if ($result !== false && $this->logger) {
                $this->logger->log_success('TASK_QUEUE_SYNCED', 
                    "文章任务与队列状态已同步: " . implode('; ', $sync_fixes),
                    ['task_id' => $task_id]
                );
            }
            
            return $result !== false;
        }
        
        return true; // 已经同步，无需修复
    }
    
    /**
     * 批量检查和修复所有文章任务的状态一致性
     */
    public function batch_fix_article_task_consistency() {
        global $wpdb;
        
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $tasks = $wpdb->get_results("SELECT id FROM {$task_table}", ARRAY_A);
        
        $fixed_count = 0;
        $error_count = 0;
        
        foreach ($tasks as $task) {
            try {
                if ($this->auto_fix_article_task_inconsistencies($task['id'])) {
                    $this->sync_article_task_with_queue($task['id']);
                    $fixed_count++;
                }
            } catch (Exception $e) {
                $error_count++;
                if ($this->logger) {
                    $this->logger->log_error('BATCH_FIX_ERROR', 
                        "批量修复任务 {$task['id']} 时出错: " . $e->getMessage()
                    );
                }
            }
        }
        
        if ($this->logger) {
            $this->logger->log_success('BATCH_FIX_COMPLETED', 
                "批量修复完成: 成功 {$fixed_count} 个，错误 {$error_count} 个"
            );
        }
        
        return array(
            'fixed' => $fixed_count,
            'errors' => $error_count
        );
    }
}
