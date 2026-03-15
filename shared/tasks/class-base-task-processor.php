<?php
/**
 * 内容自动生成管家 - 基础任务处理器抽象类
 * 
 * 提供所有任务类型的共享处理逻辑
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入日志系统
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';

abstract class ContentAuto_BaseTaskProcessor {
    
    protected $database;
    protected $logger;
    protected $rule_manager;
    protected $api_config;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->logger = new ContentAuto_LoggingSystem();
        $this->rule_manager = new ContentAuto_RuleManager();
        $this->api_config = new ContentAuto_ApiConfig();
    }
    
    /**
     * 处理任务的抽象方法
     */
    abstract protected function process_single_task($job_id, $subtask_id);
    
    /**
     * 获取任务类型的抽象方法
     */
    abstract protected function get_task_type();
    
    /**
     * 获取任务表的抽象方法
     */
    abstract protected function get_task_table();
    
    /**
     * 处理任务的主入口
     */
    public function process($job_id, $subtask_id) {
        $context = $this->logger->build_context(null, null, array(
            'job_type' => $this->get_task_type(),
            'job_id' => $job_id,
            'subtask_id' => $subtask_id
        ));
        
        $this->logger->log_success('TASK_START', '开始处理任务', $context);
        
        // 获取任务信息
        $task = $this->get_task_info($job_id);
        if (!$task) {
            $error_message = '任务不存在: ' . $job_id;
            $this->logger->log_error('TASK_NOT_FOUND', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 检查任务是否可处理
        if (!$this->is_task_processable($task)) {
            $error_message = "任务状态不可处理或恢复失败，当前状态: {$task['status']}";
            $this->logger->log_warning('TASK_NOT_PROCESSABLE', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 并发控制检查
        if (!$this->check_concurrency_control($task)) {
            $error_message = "发现并发任务，跳过处理";
            $this->logger->log_warning('CONCURRENT_TASK_SKIPPED', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 检查API限流
        if (!$this->check_api_rate_limit()) {
            $error_message = "API限流，跳过处理";
            $this->logger->log_warning('API_RATE_LIMITED', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 更新任务状态为处理中
        if (!$this->update_task_status($job_id, 'processing')) {
            $error_message = "更新任务状态失败";
            $this->logger->log_error('STATUS_UPDATE_FAILED', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
        
        global $wpdb;
        try {
            // 开始数据库事务
            $wpdb->query('START TRANSACTION');
            
            // 处理单个任务
            $result = $this->process_single_task($job_id, $subtask_id);
            
            if ($result['success']) {
                $this->handle_successful_processing($job_id, $subtask_id, $task);
                $wpdb->query('COMMIT');
                return ['success' => true];
            } else {
                // 处理失败
                $wpdb->query('ROLLBACK');
                $error_message = $this->handle_failed_processing($job_id, $subtask_id, $task, $result);
                return ['success' => false, 'message' => $error_message];
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $error_message = "处理异常: " . $e->getMessage();
            $this->logger->log_error('TASK_PROCESSING_EXCEPTION', $error_message, $context);
            return ['success' => false, 'message' => $error_message];
        }
    }
    
    /**
     * 获取任务信息
     */
    protected function get_task_info($job_id) {
        return $this->database->get_row($this->get_task_table(), array('id' => $job_id));
    }
    
    /**
     * 检查任务是否可处理
     */
    protected function is_task_processable($task) {
        // 基础检查：任务状态
        if (!in_array($task['status'], [CONTENT_AUTO_STATUS_PENDING, 'processing'])) {
            return false;
        }
        
        // 检查任务超时
        if (isset($task['last_processed_at']) && $task['last_processed_at']) {
            $time_diff = current_time('timestamp') - strtotime($task['last_processed_at']);
            $task_timeout = 31 * 60; // 31分钟超时
            if ($time_diff < $task_timeout && $task['status'] === 'processing') {
                return false; // 正在处理且未超时
            }
        }
        
        return true;
    }
    
    /**
     * 并发控制检查
     */
    protected function check_concurrency_control($task) {
        // 基础实现：可以被子类重写
        return true;
    }
    
    /**
     * 检查API限流
     */
    protected function check_api_rate_limit($is_retry = false) {
        // 移除API请求间隔时间检查，因为子任务间已有30秒间隔
        // 仅在非重试的首次请求时检查API请求间隔时间
        // if (!$is_retry) {
        //     $min_interval = CONTENT_AUTO_MIN_API_INTERVAL;
        //     $last_request_time = get_option('content_auto_last_api_request', 0);
        //     $current_time = time();
        //     
        //     if ($current_time - $last_request_time < $min_interval) {
        //         $wait_time = $min_interval - ($current_time - $last_request_time);
        //         $this->logger->log_warning('API_RATE_LIMIT', "API请求间隔控制：需要等待 {$wait_time} 秒");
        //         return false;
        //     }
        // }
        
        // 更新最后请求时间
        update_option('content_auto_last_api_request', current_time('timestamp'));
        return true;
    }
    
    /**
     * 更新任务状态
     */
    protected function update_task_status($job_id, $status) {
        return $this->database->update($this->get_task_table(), 
            ['status' => $status, 'updated_at' => current_time('mysql')], 
            ['id' => $job_id]
        );
    }
    
    /**
     * 处理成功完成
     */
    protected function handle_successful_processing($job_id, $subtask_id, $task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 1. 更新队列中的子任务状态为 completed
        $wpdb->update($queue_table,
            ['status' => CONTENT_AUTO_STATUS_COMPLETED, 'updated_at' => current_time('mysql'), 'error_message' => ''],
            ['job_type' => $this->get_task_type(), 'job_id' => $job_id, 'subtask_id' => $subtask_id]
        );
        
        // 2. 更新主任务进度（由子类实现）
        $this->update_task_progress($job_id, $subtask_id, true);
        
        // 3. 记录成功日志
        $this->logger->log_success('SUBTASK_COMPLETED', "子任务处理成功", [
            'job_type' => $this->get_task_type(),
            'job_id' => $job_id,
            'subtask_id' => $subtask_id
        ]);
        
        // 4. 检查是否需要完成整个任务
        $this->finalize_task_if_completed($job_id, $task);
    }
    
    /**
     * 处理失败情况
     */
    protected function handle_failed_processing($job_id, $subtask_id, $task, $result) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 1. 更新队列中的子任务状态为 failed
        $error_message = is_array($result) && isset($result['message']) ? $result['message'] : '未知错误';
        $wpdb->update($queue_table,
            ['status' => CONTENT_AUTO_STATUS_FAILED, 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => $this->get_task_type(), 'job_id' => $job_id, 'subtask_id' => $subtask_id]
        );
        
        // 2. 更新主任务进度（由子类实现）
        $this->update_task_progress($job_id, $subtask_id, false, $error_message);
        
        // 3. 记录错误日志
        $this->logger->log_error('SUBTASK_FAILED', "子任务处理失败", [
            'job_type' => $this->get_task_type(),
            'job_id' => $job_id,
            'subtask_id' => $subtask_id,
            'error' => $error_message
        ]);
        
        // 4. 检查是否需要完成整个任务
        $this->finalize_task_if_completed($job_id, $task);
        
        return $error_message;
    }
    
    /**
     * 更新任务进度（由子类实现）
     */
    abstract protected function update_task_progress($job_id, $subtask_id, $is_success, $error_message = '');
    
    /**
     * 完成任务（如果所有子任务都已完成）
     */
    protected function finalize_task_if_completed($job_id, $task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 检查所有子任务的状态
        $total_subtasks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d",
            $this->get_task_type(), $job_id
        ));
        
        $completed_subtasks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status = 'completed'",
            $this->get_task_type(), $job_id
        ));
        
        $failed_subtasks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status = 'failed'",
            $this->get_task_type(), $job_id
        ));
        
        // 如果所有子任务都已完成或有失败，更新任务状态
        if ($completed_subtasks + $failed_subtasks >= $total_subtasks) {
            $final_status = ($failed_subtasks > 0) ? CONTENT_AUTO_STATUS_FAILED : CONTENT_AUTO_STATUS_COMPLETED;
            
            $this->database->update($this->get_task_table(), [
                'status' => $final_status,
                'updated_at' => current_time('mysql')
            ], ['id' => $job_id]);
            
            $this->logger->log_success('TASK_COMPLETED', "任务已完成，状态: {$final_status}", [
                'job_type' => $this->get_task_type(),
                'job_id' => $job_id,
                'total_subtasks' => $total_subtasks,
                'completed_subtasks' => $completed_subtasks,
                'failed_subtasks' => $failed_subtasks
            ]);
        }
    }
}
?>