<?php
/**
 * 重构后的文章任务管理器
 * 通过使用抽象出来的功能模块，大大简化了主类的职责
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入依赖的抽象功能模块
require_once __DIR__ . '/../topic-management/class-task-status-manager.php';
require_once __DIR__ . '/../topic-management/class-task-recovery-handler.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
require_once __DIR__ . '/class-article-performance-monitor.php';

class ContentAuto_ArticleTaskManager {
    
    private $database;
    private $rule_manager;
    private $logger;
    private $performance_monitor;
    private $status_manager;
    private $recovery_handler;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->rule_manager = new ContentAuto_RuleManager();
        $this->logger = new ContentAuto_LoggingSystem();
        $this->performance_monitor = new ContentAuto_ArticlePerformanceMonitor();
        $this->status_manager = new ContentAuto_TaskStatusManager($this->database, $this->logger);
        $this->recovery_handler = new ContentAuto_TaskRecoveryHandler($this->database, $this->status_manager, $this->logger);
    }

    /**
     * 创建文章生成任务 - 重构版本，支持任务组管理
     */
    public function create_article_task($topic_ids, $name = '') {
        // 开始性能监控
        $context = array(
            'topic_count' => is_array($topic_ids) ? count($topic_ids) : 0,
            'task_name' => $name
        );
        $this->performance_monitor->start_timing('create_article_task', $context);
        
        if (empty($topic_ids) || !is_array($topic_ids)) {
            $this->logger->log_error('INVALID_INPUT', '主题ID列表为空或格式错误', $context);
            $this->performance_monitor->record_error('validation', 'INVALID_INPUT', $context);
            $this->performance_monitor->end_timing('create_article_task', false);
            return false;
        }
        
        // 验证主题是否存在
        $this->logger->log_info('TOPIC_VALIDATION', "开始验证 " . count($topic_ids) . " 个主题", $context);
        foreach ($topic_ids as $topic_id) {
            $topic = $this->database->get_row('content_auto_topics', array('id' => $topic_id));
            if (!$topic) {
                $error_context = array_merge($context, array('invalid_topic_id' => $topic_id));
                $this->logger->log_error('TOPIC_NOT_FOUND', "主题不存在: {$topic_id}", $error_context);
                $this->performance_monitor->record_error('validation', 'TOPIC_NOT_FOUND', $error_context);
                $this->performance_monitor->end_timing('create_article_task', false);
                return false;
            }
        }
        $this->logger->log_info('TOPIC_VALIDATION_SUCCESS', "所有主题验证通过", $context);
        
        // 智能任务去重检查
        $this->logger->log_info('DUPLICATE_CHECK', "开始检查重复任务", $context);
        if (!$this->should_create_new_task($topic_ids)) {
            $this->logger->log_warning('DUPLICATE_TASK', '发现相同主题的活跃任务，跳过创建', $context);
            $this->performance_monitor->record_error('business_logic', 'DUPLICATE_TASK', $context);
            $this->performance_monitor->end_timing('create_article_task', false);
            return false;
        }
        $this->logger->log_info('DUPLICATE_CHECK_PASSED', "重复任务检查通过", $context);
        
        // 计算任务统计信息
        $total_topics = count($topic_ids);
        
        // 生成全局唯一的文章任务ID
        $article_task_id = 'article_task_' . uniqid();
        
        // 准备任务时间戳（用于任务名称和数据库字段）
        $current_time = current_time('mysql');
        
        // 如果没有提供名称，生成默认名称（使用updated_at时间）
        if (empty($name)) {
            $name = "文章任务组 - " . date('Y-m-d H:i:s', strtotime($current_time)) . " ({$total_topics}个主题)";
        }
        
        // 创建任务数据，支持任务组管理
        $task_data = array(
            'article_task_id' => $article_task_id,
            'name' => $name,
            'topic_ids' => json_encode($topic_ids),
            'total_topics' => $total_topics,
            'completed_topics' => 0,
            'failed_topics' => 0,
            'current_processing_item' => 0,
            'total_rule_items' => $total_topics, // 每个主题作为一个规则项
            'generated_articles_count' => 0,
            'status' => CONTENT_AUTO_STATUS_PENDING,
            'subtask_status' => '{}', // 初始化为空的JSON对象
            'error_message' => '',
            'last_processed_at' => null,
            'created_at' => $current_time,
            'updated_at' => $current_time
        );
        
        // 插入任务记录
        $this->logger->log_info('TASK_INSERT', "开始插入任务记录", array_merge($context, array('article_task_id' => $article_task_id)));
        $task_id = $this->database->insert('content_auto_article_tasks', $task_data);
        
        if ($task_id) {
            $success_context = array_merge($context, array('task_id' => $task_id, 'article_task_id' => $article_task_id));
            $this->logger->log_success('TASK_INSERT_SUCCESS', "任务记录插入成功", $success_context);
            
            // 锁定主题状态为queued
            $lock_success = $this->lock_topics_for_task($topic_ids);
            
            if ($lock_success) {
                
                // 为每个主题创建独立的队列项，实现子任务串行执行
                $this->logger->log_info('QUEUE_CREATION', "开始创建队列项", $success_context);
                $queue_success = $this->add_to_queue($task_id, $topic_ids);
                
                if ($queue_success) {
                    $this->logger->log_success('TASK_CREATED', 
                        "文章任务组创建成功: {$name}，包含 {$total_topics} 个主题",
                        $success_context
                    );
                    $this->performance_monitor->end_timing('create_article_task', true, array(
                        'task_id' => $task_id,
                        'topics_count' => $total_topics
                    ));
                    return $task_id;
                } else {
                    // 如果队列创建失败，回滚主题状态并删除任务
                    $this->unlock_topics_for_task($topic_ids);
                    $this->database->delete('content_auto_article_tasks', array('id' => $task_id));
                    $this->logger->log_error('QUEUE_CREATION_FAILED', '队列创建失败，已回滚任务创建', $success_context);
                    $this->performance_monitor->record_error('database', 'QUEUE_CREATION_FAILED', $success_context);
                    $this->performance_monitor->end_timing('create_article_task', false);
                    return false;
                }
            } else {
                // 如果主题锁定失败，删除已创建的任务
                $this->database->delete('content_auto_article_tasks', array('id' => $task_id));
                $this->logger->log_error('TOPIC_LOCKING_FAILED', '主题状态锁定失败，已回滚任务创建', $success_context);
                $this->performance_monitor->record_error('database', 'TOPIC_LOCKING_FAILED', $success_context);
                $this->performance_monitor->end_timing('create_article_task', false);
                return false;
            }
        }
        
        $this->logger->log_error('TASK_CREATION_FAILED', '文章任务创建失败', $context);
        $this->performance_monitor->record_error('database', 'TASK_CREATION_FAILED', $context);
        $this->performance_monitor->end_timing('create_article_task', false);
        return false;
    }

    /**
     * 将任务添加到队列
     * 为每个主题创建独立的队列项，支持子任务处理模式
     */
    private function add_to_queue($task_id, $topic_ids) {
        // 获取任务信息
        $task = $this->database->get_row('content_auto_article_tasks', array('id' => $task_id));
        if (!$task) {
            return false;
        }
        
        // 获取主题总数
        $total_topics = count($topic_ids);
        if ($total_topics <= 0) {
            return false;
        }
        
        // 为每个主题创建队列项
        $queue_ids = array();
        foreach ($topic_ids as $topic_id) {
            $data = array(
                'job_type' => 'article',
                'job_id' => $task_id,
                'subtask_id' => 'subtask_' . uniqid(),  // 使用唯一ID替换索引
                'reference_id' => $topic_id, // reference_id 存储主题ID
                'priority' => 5,
                'retry_count' => 0,
                'status' => CONTENT_AUTO_STATUS_PENDING,
                'error_message' => '',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );
            
            $queue_id = $this->database->insert('content_auto_job_queue', $data);
            if ($queue_id) {
                $queue_ids[] = $queue_id;
            }
        }
        
        return !empty($queue_ids);
    }
    
    /**
     * 检查是否应该创建新任务
     */
    private function should_create_new_task($topic_ids) {
        global $wpdb;
        
        $task_timeout = 31 * 60; // 31分钟超时
        $topic_ids_json = json_encode($topic_ids);
        
        $existing_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}content_auto_article_tasks 
            WHERE topic_ids = %s AND status IN ('pending', 'processing')",
            $topic_ids_json
        ));
        
        if (!empty($existing_tasks)) {
            foreach ($existing_tasks as $existing_task) {
                $updated_time = strtotime($existing_task->updated_at) - get_option('gmt_offset') * 3600;
                $current_time = current_time('timestamp', true);
                $time_diff = $current_time - $updated_time;
                
                if ($time_diff < $task_timeout) {
                    $this->logger->log_warning('DUPLICATE_TASK', "发现相同主题的活跃任务，跳过创建新任务");
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * 更新父任务进度 - 重构版本，支持基于主题ID的进度更新
     */
    public function update_task_progress($article_task_id, $topic_id, $is_success, $error_message = '') {
        global $wpdb;
        
        // 获取父任务信息
        $task = $this->database->get_row('content_auto_article_tasks', array('id' => $article_task_id));
        if (!$task) {
            $this->logger->log_error('TASK_NOT_FOUND', "任务不存在: {$article_task_id}");
            return false;
        }

        // 使用数据库事务确保数据一致性
        $wpdb->query('START TRANSACTION');

        try {
            // 更新子任务状态记录
            $subtask_status = json_decode($task['subtask_status'], true);
            if (!is_array($subtask_status)) {
                $subtask_status = array();
            }
            
            // 检查该主题是否已经处理过，避免重复计数
            $already_processed = isset($subtask_status[$topic_id]) && 
                                in_array($subtask_status[$topic_id], ['completed', 'failed']);
            
            // 更新子任务状态
            $subtask_status[$topic_id] = $is_success ? 'completed' : 'failed';
            
            // 重新计算completed_topics和failed_topics，确保准确性
            $completed_count = 0;
            $failed_count = 0;
            
            foreach ($subtask_status as $status) {
                if ($status === 'completed') {
                    $completed_count++;
                } elseif ($status === 'failed') {
                    $failed_count++;
                }
            }
            
            // 准备更新数据
            $update_data = [
                'completed_topics' => $completed_count,
                'failed_topics' => $failed_count,
                'subtask_status' => json_encode($subtask_status),
                'last_processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            // 如果当前子任务失败，记录错误信息
            if (!$is_success && !empty($error_message)) {
                $update_data['error_message'] = $error_message;
            }

            // 更新父任务
            $result = $this->database->update('content_auto_article_tasks', $update_data, array('id' => $article_task_id));
            
            if ($result === false) {
                throw new Exception("更新任务进度失败");
            }

            // 检查是否所有子任务都已完成，自动设置任务完成状态
            $total_processed = $completed_count + $failed_count;
            if ($total_processed >= $task['total_topics']) {
                $final_status = ($failed_count > 0) ? CONTENT_AUTO_STATUS_FAILED : CONTENT_AUTO_STATUS_COMPLETED;
                
                $final_update = ['status' => $final_status];
                $this->database->update('content_auto_article_tasks', $final_update, array('id' => $article_task_id));
                
                $this->logger->log_success('TASK_COMPLETED', 
                    "文章任务完成，最终状态: {$final_status}，成功: {$completed_count}，失败: {$failed_count}",
                    ['task_id' => $article_task_id, 'topic_id' => $topic_id]
                );
            }

            $wpdb->query('COMMIT');
            
            $this->logger->log_success('PROGRESS_UPDATED', 
                "任务进度已更新，主题ID: {$topic_id}，状态: " . ($is_success ? '成功' : '失败'),
                ['task_id' => $article_task_id, 'completed' => $completed_count, 'failed' => $failed_count]
            );
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->logger->log_error('PROGRESS_UPDATE_FAILED', 
                "更新任务进度失败: " . $e->getMessage(),
                ['task_id' => $article_task_id, 'topic_id' => $topic_id]
            );
            return false;
        }
    }
    
    // ==============================================
    // 新增的核心任务处理方法
    // ==============================================
    
    /**
     * 处理文章生成任务
     */
    public function process_article_task($task_id, $subtask_id = null) {
        $context = $this->logger->build_context(null, null, array('task_id' => $task_id, 'subtask_id' => $subtask_id));
        $this->performance_monitor->start_timing('process_article_task', $context);
        $this->logger->log_success('TASK_START', '开始处理文章任务', $context);
        
        // 获取任务信息
        $this->logger->log_info('TASK_FETCH', '获取任务信息', $context);
        $task = $this->database->get_row('content_auto_article_tasks', array('id' => $task_id));
        if (!$task) {
            $error_message = '文章任务不存在: ' . $task_id;
            $this->logger->log_error('TASK_NOT_FOUND', $error_message, $context);
            $this->performance_monitor->record_error('database', 'TASK_NOT_FOUND', $context);
            $this->performance_monitor->end_timing('process_article_task', false);
            return ['success' => false, 'message' => $error_message];
        }
        $this->logger->log_info('TASK_FETCH_SUCCESS', '任务信息获取成功', array_merge($context, array('task_status' => $task['status'])));
        
        // 检查任务状态
        $this->logger->log_info('TASK_STATUS_CHECK', '检查任务状态', array_merge($context, array('current_status' => $task['status'])));
        if (!$this->is_task_processable($task)) {
            $error_message = "任务 (ID: {$task_id}) 状态不可处理或恢复失败，当前状态: {$task['status']}";
            $this->logger->log_warning('TASK_NOT_PROCESSABLE', $error_message, $context);
            $this->performance_monitor->record_error('business_logic', 'TASK_NOT_PROCESSABLE', array_merge($context, array('task_status' => $task['status'])));
            $this->performance_monitor->end_timing('process_article_task', false);
            return ['success' => false, 'message' => $error_message];
        }
        $this->logger->log_info('TASK_STATUS_CHECK_PASSED', '任务状态检查通过', $context);
        
        // 获取当前处理的子任务ID
        if ($subtask_id === null) {
            $subtask_id = $this->get_current_subtask_id($task_id, $task);
        }
        
        // 仅当任务状态不是 'processing' 时，才更新为 'processing'
        if ($task['status'] !== 'processing') {
            if (!$this->status_manager->safe_update_task_status($task_id, 'processing', '开始处理子任务', 'article')) {
                $error_message = "更新任务 (ID: {$task_id}) 状态为'处理中'失败";
                $this->logger->log_error('STATUS_UPDATE_FAILED', $error_message);
                return ['success' => false, 'message' => $error_message];
            }
            // 重新加载任务信息以确保状态更新
            $task = $this->database->get_row('content_auto_article_tasks', array('id' => $task_id));
        }
        
        global $wpdb;
        try {
            // 开始数据库事务
            $wpdb->query('START TRANSACTION');
            
            // 处理当前主题任务
            $result = $this->process_current_topic_task($task, $subtask_id);

            if ($result['success']) {
                $this->handle_successful_processing($task_id, $task, $subtask_id);
                $wpdb->query('COMMIT');
                return ['success' => true];
            } else {
                // 先回滚事务，然后处理失败状态
                $wpdb->query('ROLLBACK');
                $error_message = $this->handle_failed_processing($task_id, $task, $subtask_id, $result['error']);
                return ['success' => false, 'message' => $error_message];
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $error_message = $this->handle_exception($task_id, $task, $subtask_id, $e);
            return ['success' => false, 'message' => $error_message];
        }
    }
    
    /**
     * 处理当前主题任务
     */
    private function process_current_topic_task($task, $subtask_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        $base_context = array(
            'task_id' => $task['id'],
            'subtask_id' => $subtask_id,
            'task_name' => $task['name']
        );
        
        $this->logger->log_info('TOPIC_TASK_PROCESSING_START', '开始处理主题任务', $base_context);
        
        // 获取队列记录以获取reference_id（topic_id）
        $queue_record = $wpdb->get_row($wpdb->prepare(
            "SELECT reference_id FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND subtask_id = %s",
            $task['id'], $subtask_id
        ));
        
        if (!$queue_record || !$queue_record->reference_id) {
            $error_details = ['stage' => '获取队列信息', 'message' => '无法获取主题ID'];
            $this->logger->log_error('QUEUE_FETCH_FAILED', '无法获取队列信息中的主题ID', array_merge($base_context, array(
                'queue_record_found' => !empty($queue_record),
                'reference_id' => $queue_record ? $queue_record->reference_id : null
            )));
            return ['success' => false, 'error' => $error_details];
        }
        
        $topic_id = $queue_record->reference_id;
        $topic = $this->database->get_row('content_auto_topics', array('id' => $topic_id));

        if (!$topic) {
            $error_details = ['stage' => '主题验证', 'message' => '主题不存在'];
            $this->logger->log_error('TOPIC_NOT_FOUND', '主题不存在', array_merge($base_context, array(
                'topic_id' => $topic_id
            )));
            return ['success' => false, 'error' => $error_details];
        }
        
        $this->logger->log_info('TOPIC_FOUND', '主题信息获取成功', array_merge($base_context, array(
            'topic_id' => $topic_id,
            'topic_title' => $topic['title'],
            'topic_status' => $topic['status'],
            'rule_id' => $topic['rule_id']
        )));

        // 使用文章生成器生成文章
        require_once __DIR__ . '/class-article-generator.php';
        $article_generator = new ContentAuto_ArticleGenerator();
        
        $this->logger->log_info('ARTICLE_GENERATOR_START', '开始调用文章生成器', array_merge($base_context, array(
            'topic_id' => $topic_id,
            'topic_title' => $topic['title']
        )));
        
        $result = $article_generator->generate_article_for_topic($topic);
        
        $this->logger->log_info('ARTICLE_GENERATOR_COMPLETE', '文章生成器执行完成', array_merge($base_context, array(
            'topic_id' => $topic_id,
            'generation_success' => $result['success'],
            'generation_message' => isset($result['message']) ? $result['message'] : null
        )));

        if ($result['success']) {
            $this->logger->log_success('TOPIC_TASK_PROCESSING_SUCCESS', '主题任务处理成功', $base_context);
            return ['success' => true];
        } else {
            $error_details = ['stage' => '文章生成', 'message' => $result['message']];
            $this->logger->log_error('TOPIC_TASK_PROCESSING_FAILED', '主题任务处理失败', array_merge($base_context, array(
                'error_message' => $result['message'],
                'error_stage' => '文章生成'
            )));
            return ['success' => false, 'error' => $error_details];
        }
    }
    
    /**
     * 处理成功的任务处理
     */
    private function handle_successful_processing($task_id, $task, $subtask_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 1. 更新队列中的子任务状态为 completed
        $wpdb->update($queue_table,
            ['status' => 'completed', 'updated_at' => current_time('mysql'), 'error_message' => ''],
            ['job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务进度
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        $new_generated_count = $task['generated_articles_count'] + 1;
        
        $this->database->update('content_auto_article_tasks', array(
            'current_processing_item' => $processed_count,
            'generated_articles_count' => $new_generated_count,
            'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
            'last_processed_at' => current_time('mysql')
        ), array('id' => $task_id));
        
        $this->logger->log_success('SUBTASK_COMPLETED', "子任务完成，等待下次处理: task_id={$task_id}, subtask_id={$subtask_id}");

        // 3. 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, $task);
    }
    
    /**
     * 处理失败的任务处理
     */
    private function handle_failed_processing($task_id, $task, $subtask_id, $error_details) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 检查是否为API错误，只有API错误才允许重试
        $error_stage = $error_details['stage'] ?? '';
        $is_api_error = in_array($error_stage, ['API请求', 'API配置', 'API调用', 'API重试']);
        
        // 如果不是API错误，直接标记为最终失败，不进行重试
        if (!$is_api_error) {
            $this->mark_final_failure($task_id, $subtask_id, $error_details);
            return '子任务 ' . $subtask_id . ' 处理失败（非API错误，直接标记为最终失败）';
        }

        $error_message = '子任务 ' . $subtask_id . ' 处理失败. 阶段: ' . ($error_details['stage'] ?? '未知') . ', 详情: ' . (is_array($error_details['message']) ? json_encode($error_details['message'], JSON_UNESCAPED_UNICODE) : $error_details['message']);

        // 获取重试次数，如果API重试提供了retry_count则使用，否则保持当前值
        $retry_count = isset($error_details['retry_count']) ? $error_details['retry_count'] : 0;

        // 1. 更新队列中的子任务状态为 failed，并更新重试次数
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'retry_count' => $retry_count, 'updated_at' => current_time('mysql')],
            ['job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务的进度和错误信息，但不立即改变主任务状态
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = "子任务 {$subtask_id} 处理失败";
        
        $this->database->update('content_auto_article_tasks',
            [
                'error_message' => $main_task_error,
                'current_processing_item' => $processed_count,
                'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
                'last_processed_at' => current_time('mysql')
            ],
            ['id' => $task_id]
        );

        $this->logger->log_error('SUBTASK_FAILED', $error_message, ['task_id' => $task_id, 'subtask_id' => $subtask_id]);

        // 3. 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, $task);

        return $error_message;
    }
    
    /**
     * 标记最终失败（非API错误，不进行重试）
     */
    private function mark_final_failure($task_id, $subtask_id, $error_details) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        $error_message = '子任务 ' . $subtask_id . ' 最终失败（非API错误）. 阶段: ' . ($error_details['stage'] ?? '未知') . ', 详情: ' . (is_array($error_details['message']) ? json_encode($error_details['message'], JSON_UNESCAPED_UNICODE) : $error_details['message']);

        // 更新队列中的子任务状态为 failed，不增加重试次数
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 更新主任务的进度和错误信息
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        $main_task_error = "子任务 {$subtask_id} 最终失败（非API错误）";
        
        $this->database->update('content_auto_article_tasks',
            [
                'error_message' => $main_task_error,
                'current_processing_item' => $processed_count,
                'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
                'last_processed_at' => current_time('mysql')
            ],
            ['id' => $task_id]
        );

        $this->logger->log_error('SUBTASK_FINAL_FAILURE', $error_message, ['task_id' => $task_id, 'subtask_id' => $subtask_id]);

        // 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, []);
    }
    
    /**
     * 处理异常
     */
    private function handle_exception($task_id, $task, $subtask_id, $exception) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        $error_detail_message = sprintf(
            "在文件 %s 的第 %d 行发生异常: %s",
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage()
        );
        $error_message = '子任务 ' . $subtask_id . ' 处理失败. 阶段: 系统异常, 详情: ' . $error_detail_message;

        // 1. 更新队列子任务状态
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = "子任务 {$subtask_id} 处理失败";
        
        $this->database->update('content_auto_article_tasks',
            [
                'error_message' => $main_task_error,
                'current_processing_item' => $processed_count,
                'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending
                'last_processed_at' => current_time('mysql')
            ],
            ['id' => $task_id]
        );

        $this->logger->log_error('TASK_EXCEPTION', $error_message, ['task_id' => $task_id, 'subtask_id' => $subtask_id]);

        // 3. 检查是否完成
        $this->finalize_task_status_if_completed($task_id, $task);

        return $error_message;
    }
    
    /**
     * 检查任务是否可处理
     */
    private function is_task_processable($task) {
        $valid_statuses = array(CONTENT_AUTO_STATUS_PENDING, CONTENT_AUTO_STATUS_PROCESSING);
        
        if (!in_array($task['status'], $valid_statuses)) {
            // 尝试恢复不一致的状态
            if ($this->recovery_handler->recover_inconsistent_task_state($task['id'], 'article')) {
                $this->logger->log_success('TASK_RECOVERED', '任务状态已恢复');
                // 重新获取任务信息
                $task = $this->database->get_row('content_auto_article_tasks', array('id' => $task['id']));
                return $task && in_array($task['status'], $valid_statuses);
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取当前子任务ID
     */
    private function get_current_subtask_id($task_id, $task) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 首先检查是否有正在处理的子任务
        $processing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT subtask_id FROM {$queue_table} 
            WHERE job_type = 'article' AND job_id = %d AND status = %s 
            ORDER BY created_at ASC LIMIT 1",
            $task_id, CONTENT_AUTO_STATUS_PROCESSING
        ));
        
        if ($processing_record && $processing_record->subtask_id) {
            return $processing_record->subtask_id;
        }
        
        // 然后获取下一个待处理的子任务
        $pending_record = $wpdb->get_row($wpdb->prepare(
            "SELECT subtask_id FROM {$queue_table} 
            WHERE job_type = 'article' AND job_id = %d AND status = %s 
            ORDER BY created_at ASC LIMIT 1",
            $task_id, CONTENT_AUTO_STATUS_PENDING
        ));
        
        return $pending_record && $pending_record->subtask_id ? $pending_record->subtask_id : null;
    }
    
    /**
     * 检查任务是否完成并设置最终状态
     */
    private function finalize_task_status_if_completed($task_id, $task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 使用 >= 是为了防止在某些边缘情况下计数不精确
        $processed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')",
            $task_id
        ));

        if ($processed_count >= $task['total_rule_items']) {
            $failed_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status = 'failed'",
                $task_id
            ));

            // 如果有任何失败的子任务，则整个任务失败；否则，任务完成
            $final_status = ($failed_count > 0) ? CONTENT_AUTO_STATUS_FAILED : CONTENT_AUTO_STATUS_COMPLETED;
            
            $this->database->update('content_auto_article_tasks',
                ['status' => $final_status],
                ['id' => $task_id]
            );
            $this->logger->log_success('TASK_FINALIZED', "文章任务处理完成，最终状态: {$final_status}", ['task_id' => $task_id]);
        }
    }
    
    /**
     * 获取所有文章父任务
     */
    public function get_tasks() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_article_tasks';
        return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC", ARRAY_A);
    }
    
    // ==============================================
    // 任务管理接口方法
    // ==============================================
    
    /**
     * 获取任务
     */
    public function get_task($task_id) {
        return $this->database->get_row('content_auto_article_tasks', array('id' => $task_id));
    }
    
    /**
     * 获取任务状态
     */
    public function get_task_status($task_id) {
        return $this->status_manager->get_task_status($task_id, 'article');
    }
    
    /**
     * 获取任务进度信息 - 重构版本，支持更详细的进度跟踪
     */
    public function get_task_progress($task_id) {
        // 首先尝试按ID查询（数字）
        $task = $this->get_task($task_id);
        
        // 如果按ID查询失败，尝试按article_task_id查询（字符串）
        if (!$task) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'content_auto_article_tasks';
            $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE article_task_id = %s", $task_id), ARRAY_A);
        }
        
        if (!$task) {
            return false;
        }
        
        // 从任务队列表获取真实的子任务状态统计
        $status_counts = $this->get_subtask_status_counts_from_queue($task['id']);
        
        // 如果队列表中没有数据，使用文章任务表的subtask_status作为备选
        if (empty($status_counts)) {
            $subtask_status = json_decode($task['subtask_status'], true);
            if (!is_array($subtask_status)) {
                $subtask_status = array();
            }
            
            $status_counts = [
                'completed' => 0,
                'failed' => 0,
                'processing' => 0,
                'pending' => 0
            ];
            
            $topic_ids = json_decode($task['topic_ids'], true);
            if (is_array($topic_ids)) {
                foreach ($topic_ids as $topic_id) {
                    $status = isset($subtask_status[strval($topic_id)]) ? $subtask_status[strval($topic_id)] : 'pending';
                    if (isset($status_counts[$status])) {
                        $status_counts[$status]++;
                    } else {
                        $status_counts['pending']++;
                    }
                }
            }
        }
        
        $total_processed = $task['completed_topics'] + $task['failed_topics'];
        
        return array(
            'task_id' => $task['id'],
            'article_task_id' => $task['article_task_id'],
            'name' => $task['name'],
            'status' => $task['status'],
            'current_item' => $total_processed,
            'total_items' => $task['total_topics'],
            'completed_topics' => $task['completed_topics'],
            'failed_topics' => $task['failed_topics'],
            'generated_articles' => $task['generated_articles_count'],
            'expected_articles' => $task['total_topics'],
            'progress_percentage' => $task['total_topics'] > 0 ? 
                round(($total_processed / $task['total_topics']) * 100, 2) : 0,
            'success_rate' => $total_processed > 0 ? 
                round(($task['completed_topics'] / $total_processed) * 100, 2) : 0,
            'subtask_status_counts' => $status_counts,
            'last_processed_at' => $task['last_processed_at'],
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at'],
            'error_message' => $task['error_message']
        );
    }
    
    /**
     * 从任务队列表获取子任务状态统计
     */
    private function get_subtask_status_counts_from_queue($task_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 获取该任务的所有队列项状态统计
        $status_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count 
             FROM {$queue_table} 
             WHERE job_type = 'article' AND job_id = %d 
             GROUP BY status",
            $task_id
        ), ARRAY_A);
        
        if (empty($status_counts)) {
            return array();
        }
        
        // 转换为需要的格式
        $result = [
            'completed' => 0,
            'failed' => 0,
            'processing' => 0,
            'pending' => 0
        ];
        
        foreach ($status_counts as $row) {
            $status = $row['status'];
            $count = intval($row['count']);
            
            // 映射队列状态到子任务状态
            switch ($status) {
                case 'completed':
                case 'success':
                    $result['completed'] += $count;
                    break;
                case 'failed':
                    $result['failed'] += $count;
                    break;
                case 'processing':
                case 'running':
                    $result['processing'] += $count;
                    break;
                case 'pending':
                    $result['pending'] += $count;
                    break;
                default:
                    // 其他状态归为pending
                    $result['pending'] += $count;
            }
        }
        
        return $result;
    }
    
    /**
     * 获取任务组详细信息，支持统一监控和管理
     */
    public function get_task_group_info($task_id) {
        $progress = $this->get_task_progress($task_id);
        if (!$progress) {
            return false;
        }
        
        // 获取任务详情
        $task = $this->get_task($task_id);
        $topic_ids = json_decode($task['topic_ids'], true);
        
        // 获取主题详细信息
        $topics_info = array();
        if (is_array($topic_ids)) {
            foreach ($topic_ids as $topic_id) {
                $topic = $this->database->get_row('content_auto_topics', array('id' => $topic_id));
                if ($topic) {
                    $topics_info[] = array(
                        'id' => $topic['id'],
                        'title' => $topic['title'],
                        'status' => $topic['status'],
                        'created_at' => $topic['created_at']
                    );
                }
            }
        }
        
        // 获取队列状态信息
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT subtask_id, reference_id, status, error_message, created_at, updated_at 
             FROM {$queue_table} 
             WHERE job_type = 'article' AND job_id = %d 
             ORDER BY created_at ASC",
            $task['id']
        ), ARRAY_A);
        
        return array(
            'progress' => $progress,
            'topics' => $topics_info,
            'queue_items' => $queue_items,
            'can_retry' => in_array($progress['status'], [CONTENT_AUTO_STATUS_FAILED, CONTENT_AUTO_STATUS_PENDING]),
            'can_pause' => $progress['status'] === CONTENT_AUTO_STATUS_PROCESSING,
            'can_resume' => $progress['status'] === 'paused'
        );
    }
    
    /**
     * 暂停任务
     */
    public function pause_task($task_id) {
        return $this->status_manager->safe_update_task_status($task_id, 'paused', '用户暂停任务', 'article');
    }
    
    /**
     * 重试任务
     */
    public function retry_task($task_id, $subtask_id = null, $force_retry = false) {
        return $this->recovery_handler->retry_task($task_id, $subtask_id, 'article', $force_retry);
    }
    
    /**
     * 删除任务
     * 根据article_task_id删除父任务及其相关的非成功状态子任务
     * 注意：已生成的文章数据和成功完成的子任务会被保留
     * 
     * @param string $article_task_id 文章任务的唯一标识符
     * @return bool 删除是否成功
     */
    public function delete_task($article_task_id) {
        global $wpdb;
        
        // 1. 首先根据article_task_id找到父任务信息
        $task = $this->database->get_row('content_auto_article_tasks', array('article_task_id' => $article_task_id));
        if (!$task) {
            return false;
        }
        
        // 2. 删除非成功状态的子任务队列项
        // 只删除 pending, processing, failed 等非成功状态的子任务
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $deleted_queue_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status != 'completed'",
            $task['id']
        ));
        
        // 3. 删除任务记录本身
        $result = $this->database->delete('content_auto_article_tasks', array('article_task_id' => $article_task_id));
        
        return $result !== false;
    }
    
    /**
     * 自动恢复挂起的任务
     */
    public function auto_recover_hanging_tasks() {
        return $this->recovery_handler->auto_recover_hanging_tasks('article');
    }
    
    /**
     * 智能重试任务
     */
    public function smart_retry_task($task_id) {
        return $this->recovery_handler->smart_retry_task($task_id, 'article');
    }
    
    // ==============================================
    // 状态管理委托方法
    // ==============================================
    
    public function validate_status($status) {
        return $this->status_manager->validate_status($status);
    }
    
    public function normalize_status($status) {
        return $this->status_manager->normalize_status($status);
    }
    
    public function get_status_label($status) {
        return $this->status_manager->get_status_label($status);
    }
    
    public function get_all_valid_statuses() {
        return $this->status_manager->get_all_valid_statuses();
    }
    
    public function safe_update_task_status($task_id, $new_status, $reason = '') {
        return $this->status_manager->safe_update_task_status($task_id, $new_status, $reason, 'article');
    }
    
    // ==============================================
    // 主题状态管理方法
    // ==============================================
    
    /**
     * 锁定主题状态为queued
     * 在文章任务创建时调用，防止主题被重复选择
     */
    private function lock_topics_for_task($topic_ids) {
        $this->logger->log_info('TOPIC_LOCKING_START', "开始锁定主题状态", array('topic_ids' => $topic_ids));
        
        foreach ($topic_ids as $topic_id) {
            $result = $this->database->update('content_auto_topics', 
                array('status' => CONTENT_AUTO_TOPIC_QUEUED), 
                array('id' => $topic_id)
            );
            
            if (!$result) {
                $this->logger->log_error('TOPIC_LOCKING_FAILED', "锁定主题失败: {$topic_id}", array('topic_id' => $topic_id));
                return false;
            }
            
            $this->logger->log_info('TOPIC_LOCKED', "主题已锁定: {$topic_id}", array('topic_id' => $topic_id));
        }
        
        $this->logger->log_success('TOPIC_LOCKING_COMPLETE', "所有主题锁定成功", array('topic_ids' => $topic_ids));
        return true;
    }
    
    /**
     * 解锁主题状态为unused
     * 在任务创建失败时调用，回滚主题状态
     */
    private function unlock_topics_for_task($topic_ids) {
        $this->logger->log_info('TOPIC_UNLOCKING_START', "开始解锁主题状态", array('topic_ids' => $topic_ids));
        
        foreach ($topic_ids as $topic_id) {
            $result = $this->database->update('content_auto_topics', 
                array('status' => CONTENT_AUTO_TOPIC_UNUSED), 
                array('id' => $topic_id)
            );
            
            if (!$result) {
                $this->logger->log_error('TOPIC_UNLOCKING_FAILED', "解锁主题失败: {$topic_id}", array('topic_id' => $topic_id));
                return false;
            }
            
            $this->logger->log_info('TOPIC_UNLOCKED', "主题已解锁: {$topic_id}", array('topic_id' => $topic_id));
        }
        
        $this->logger->log_success('TOPIC_UNLOCKING_COMPLETE', "所有主题解锁成功", array('topic_ids' => $topic_ids));
        return true;
    }
}
