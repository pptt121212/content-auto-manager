<?php
/**
 * 任务恢复处理器
 * 负责处理任务恢复、重试、状态修复等功能
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TaskRecoveryHandler {
    
    private $database;
    private $status_manager;
    private $logger;
    
    public function __construct($database = null, $status_manager = null, $logger = null) {
        $this->database = $database ?: new ContentAuto_Database();
        $this->status_manager = $status_manager ?: new ContentAuto_TaskStatusManager($this->database, $logger);
        $this->logger = $logger;
    }
    
    /**
     * 恢复不一致的任务状态
     */
    public function recover_inconsistent_task_state($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 获取任务信息
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$task_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        // 获取所有相关的队列项
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE job_type = %s AND job_id = %d ORDER BY created_at ASC",
            $job_type, $task_id
        ), ARRAY_A);
        
        $inconsistencies_found = false;
        $recovery_actions = array();
        
        // 文章任务特有的状态不一致检测
        if ($task_type === 'article') {
            $inconsistencies_found = $this->detect_article_specific_inconsistencies($task, $queue_items, $recovery_actions) || $inconsistencies_found;
        }
        
        // 检查1: 任务状态为processing但没有处理中的队列项
        if ($task['status'] === 'processing') {
            $processing_queue = array_filter($queue_items, function($item) {
                return $item['status'] === 'processing';
            });
            
            if (empty($processing_queue)) {
                $recovery_actions[] = "将任务状态从processing改为pending";
                $wpdb->update($task_table, array(
                    'status' => 'pending',
                    'updated_at' => current_time('mysql')
                ), array('id' => $task_id));
                $inconsistencies_found = true;
            }
        }
        
        // 检查2: 有completed的队列项但任务状态未完成
        $completed_queue = array_filter($queue_items, function($item) {
            return $item['status'] === 'completed';
        });
        
        if (!empty($completed_queue)) {
            // 通过查找最大创建时间来确定处理进度
            $max_completed_time = '';
            foreach ($completed_queue as $item) {
                if ($item['updated_at'] > $max_completed_time) {
                    $max_completed_time = $item['updated_at'];
                }
            }
            
            // 重新计算当前处理项（基于已完成队列项的数量）
            $completed_count = count($completed_queue);
            
            // 文章任务和主题任务都使用 current_processing_item 字段
            if ($task['current_processing_item'] < $completed_count) {
                $new_processing_item = $completed_count;
                $recovery_actions[] = "更新current_processing_item从{$task['current_processing_item']}到{$new_processing_item}";
                $wpdb->update($task_table, array(
                    'current_processing_item' => $new_processing_item,
                    'updated_at' => current_time('mysql')
                ), array('id' => $task_id));
                $inconsistencies_found = true;
            }
            
            // 文章任务特有：同步更新subtask_status和completed_topics
            if ($task_type === 'article') {
                $inconsistencies_found = $this->sync_article_task_progress($task, $completed_queue, $recovery_actions) || $inconsistencies_found;
            }
        }
        
        // 检查3: 有failed的队列项但任务状态不是failed
        $failed_queue = array_filter($queue_items, function($item) {
            return $item['status'] === 'failed';
        });
        
        if (!empty($failed_queue) && $task['status'] !== 'failed') {
            // 对于文章任务，只有当所有子任务都完成且有失败时才设置为failed
            if ($task_type === 'article') {
                $total_processed = count($completed_queue) + count($failed_queue);
                if ($total_processed >= $task['total_topics']) {
                    $recovery_actions[] = "所有文章子任务已完成，设置任务状态为failed";
                    $failed_error = "文章任务包含失败的子项目";
                    $wpdb->update($task_table, array(
                        'status' => 'failed',
                        'error_message' => $failed_error,
                        'updated_at' => current_time('mysql')
                    ), array('id' => $task_id));
                    $inconsistencies_found = true;
                }
            } else {
                $recovery_actions[] = "将任务状态设置为failed，因为有失败的队列项";
                $failed_error = "任务包含失败的子项目";
                $wpdb->update($task_table, array(
                    'status' => 'failed',
                    'error_message' => $failed_error,
                    'updated_at' => current_time('mysql')
                ), array('id' => $task_id));
                $inconsistencies_found = true;
            }
        }
        
        // 检查4: 没有待处理的队列项但任务状态为pending
        $pending_queue = array_filter($queue_items, function($item) {
            return in_array($item['status'], array('pending', 'processing'));
        });
        
        // 两种任务类型都使用相同的字段判断逻辑
        if (empty($pending_queue) && $task['status'] === 'pending' && $task['current_processing_item'] < $task['total_rule_items']) {
            // 文章任务需要基于reference_id恢复队列项
            if ($task_type === 'article') {
                $inconsistencies_found = $this->recover_article_queue_items($task, $queue_items, $recovery_actions) || $inconsistencies_found;
            } else {
                // 重新创建下一个子任务队列 - 使用数字索引，与规则项目表对应
                // 获取当前应该创建的子任务索引
                global $wpdb;
                $queue_table = $wpdb->prefix . 'content_auto_job_queue';
                $existing_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d",
                    $job_type, $task_id
                ));
                $next_subtask_id = intval($existing_count); // 使用数字索引

                $recovery_actions[] = "重新创建队列项: subtask_id={$next_subtask_id}, task_type={$task_type}";
                $this->add_task_to_queue($task_id, $next_subtask_id, $next_subtask_id, $job_type);
                $inconsistencies_found = true;
            }
        }
        
        if ($inconsistencies_found && $this->logger && !empty($recovery_actions)) {
            $this->logger->log_success('TASK_RECOVERY', '任务状态恢复完成: ' . implode('; ', $recovery_actions), ['task_id' => $task_id]);
        }
        
        return $inconsistencies_found;
    }
    
    /**
     * 自动检测和恢复挂起的任务
     */
    public function auto_recover_hanging_tasks($task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 获取挂起的任务（处理状态超过阈值时间）
        $threshold_time = date('Y-m-d H:i:s', strtotime('-30 minutes')); // 30分钟阈值
        
        $hanging_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$task_table} 
            WHERE status = 'processing' 
            AND updated_at < %s",
            $threshold_time
        ), ARRAY_A);
        
        $recovered_count = 0;
        $failed_count = 0;
        
        foreach ($hanging_tasks as $task) {
            
            // 尝试恢复挂起的任务
            if ($this->recover_hanging_task($task['id'], $task_type)) {
                $recovered_count++;
            } else {
                $failed_count++;
            }
        }
        
        // 检查 orphaned 队列项（没有对应任务的队列项）
        $orphaned_queues = $wpdb->get_results($wpdb->prepare(
            "SELECT q.* FROM {$queue_table} q
            LEFT JOIN {$task_table} t ON q.job_id = t.id
            WHERE q.job_type = %s 
            AND t.id IS NULL
            AND q.status IN ('pending', 'processing')",
            $job_type, $threshold_time
        ), ARRAY_A);
        
        foreach ($orphaned_queues as $queue) {
            $wpdb->delete($queue_table, array('subtask_id' => $queue['subtask_id']));
        }
        
        return array(
            'recovered' => $recovered_count,
            'failed' => $failed_count,
            'orphaned_cleaned' => count($orphaned_queues)
        );
    }
    
    /**
     * 恢复单个挂起的任务
     */
    private function recover_hanging_task($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 获取任务信息
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$task_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task) {
            // 使用插件自定义日志类记录日志
            $logger = new ContentAuto_PluginLogger();
            $logger->error("恢复失败: 任务不存在", array('task_id' => $task_id, 'task_type' => $task_type));
            return false;
        }
        
        // 获取相关的队列项
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$queue_table} WHERE job_type = %s AND job_id = %d ORDER BY created_at ASC",
            $job_type, $task_id
        ), ARRAY_A);
        
        $recovery_actions = array();
        
        // 1. 将任务状态重置为pending
        $wpdb->update($task_table, array(
            'status' => 'pending',
            'updated_at' => current_time('mysql')
        ), array('id' => $task_id));
        $recovery_actions[] = "重置任务状态为pending";
        
        // 2. 处理队列项
        $processing_queue = array_filter($queue_items, function($item) {
            return $item['status'] === 'processing';
        });
        
        foreach ($processing_queue as $queue_item) {
            // 将处理中的队列项重置为pending
            $wpdb->update($queue_table, array(
                'status' => 'pending',
                'updated_at' => current_time('mysql'),
                'error_message' => '自动恢复: 重置挂起的处理状态'
            ), array('subtask_id' => $queue_item['subtask_id']));
            $recovery_actions[] = "重置队列项{$queue_item['subtask_id']}为pending";
        }
        
        // 记录恢复操作（简化版本，不写入历史表）
        if ($this->logger && !empty($recovery_actions)) {
            $this->logger->log_success('HANGING_TASK_RECOVERY', '挂起任务自动恢复完成: ' . implode('; ', $recovery_actions), ['task_id' => $task_id]);
        }
        
        return true;
    }
    
    /**
     * 智能重试策略
     */
    public function smart_retry_task($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$task_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        // 分析错误类型以确定重试策略
        $error_message = $task['error_message'] ?: '';
        $retry_strategy = $this->determine_retry_strategy($error_message);
        
        switch ($retry_strategy) {
            case 'immediate':
                // 立即重试 - 适用于临时性错误
                return $this->retry_task($task_id, null, $task_type);
                
            case 'delayed':
                // 延迟重试 - 适用于API限流等问题
                wp_schedule_single_event(time() + CONTENT_AUTO_RATE_LIMIT_DELAY, 'content_auto_delayed_retry', array($task_id));
                return true;
                
            case 'manual':
                // 需要手动干预 - 严重错误
                if ($this->logger) {
                    $this->logger->log_warning('MANUAL_RETRY_REQUIRED', '任务需要手动干预，错误类型: ' . $error_message, ['task_id' => $task_id]);
                }
                return false;
                
            default:
                return $this->retry_task($task_id, null, $task_type);
        }
    }
    
    /**
     * 根据错误信息确定重试策略
     */
    private function determine_retry_strategy($error_message) {
        if (empty($error_message)) {
            return 'immediate';
        }
        
        // API限流或临时网络问题
        if (strpos($error_message, 'timeout') !== false ||
            strpos($error_message, 'rate limit') !== false ||
            strpos($error_message, 'network') !== false ||
            strpos($error_message, 'connection') !== false) {
            return 'delayed';
        }
        
        // 数据库问题
        if (strpos($error_message, 'database') !== false ||
            strpos($error_message, 'SQL') !== false) {
            return 'immediate';
        }
        
        // 验证错误或配置问题
        if (strpos($error_message, 'invalid') !== false ||
            strpos($error_message, 'configuration') !== false ||
            strpos($error_message, 'permission') !== false) {
            return 'manual';
        }
        
        // 默认立即重试
        return 'immediate';
    }
    
    /**
     * 重试任务
     */
    public function retry_task($task_id, $subtask_id = null, $task_type = 'topic_task', $force_retry = false) {
        if ($subtask_id !== null) {
            // 重试特定子任务
            return $this->retry_specific_item($task_id, $subtask_id, $task_type);
        } else {
            // 重试整个任务
            return $this->retry_entire_task($task_id, $task_type, $force_retry);
        }
    }
    
    /**
     * 重试整个任务
     */
    private function retry_entire_task($task_id, $task_type = 'topic_task', $force_retry = false) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table_name = 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table_name = 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }

        if ($this->logger) {
            $this->logger->log_success('RETRY_ENTIRE_TASK_START', "开始重试整个任务。", ['task_id' => $task_id, 'force_retry' => $force_retry]);
        }

        // 1. 查找所有失败的子任务
        $failed_subtasks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, subtask_id, retry_count, reference_id FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status = 'failed'",
            $job_type, $task_id
        ));

        if (empty($failed_subtasks)) {
            if ($this->logger) {
                $this->logger->log_warning('RETRY_NO_FAILED_SUBTASKS', "任务重试请求，但未找到失败的子任务。将仅重置主任务状态。", ['task_id' => $task_id]);
            }
            // 没有失败的子任务，无需重试
            // 但仍然将父任务状态设为pending，以防父任务是failed但子任务不是failed的边缘情况
            $this->database->update($task_table_name, 
                array(
                    'status' => 'pending', 
                    'error_message' => '' // 清空父任务的错误信息
                ), 
                array('id' => $task_id)
            );
            return true;
        }

        if ($this->logger) {
            $this->logger->log_success('RETRY_FOUND_FAILED_SUBTASKS', "找到 " . count($failed_subtasks) . " 个失败的子任务准备重试。", ['task_id' => $task_id, 'subtask_ids' => wp_list_pluck($failed_subtasks, 'subtask_id')]);
        }

        // 2. 检查重试次数限制并重置失败的子任务
        $updated_count = 0;
        $max_retries = defined('CONTENT_AUTO_MAX_RETRIES') ? CONTENT_AUTO_MAX_RETRIES : 3;
        
        foreach ($failed_subtasks as $subtask) {
            // 如果不是强制重试，才检查重试次数限制
            if (!$force_retry && $subtask->retry_count >= $max_retries) {
                if ($this->logger) {
                    $this->logger->log_warning('RETRY_LIMIT_EXCEEDED', 
                        "子任务{$subtask->subtask_id}已达到最大重试次数({$max_retries})，跳过重试", 
                        ['task_id' => $task_id, 'subtask_id' => $subtask->subtask_id]
                    );
                }
                continue;
            }
            
            // 根据是否强制重试，决定重试次数是重置还是增加
            $new_retry_count = $force_retry ? 0 : $subtask->retry_count + 1;

            // 计算重试间隔（指数退避）
            $retry_delay = $this->calculate_retry_delay($new_retry_count);
            
            $update_data = array(
                'status' => 'pending', 
                'error_message' => '', 
                'retry_count' => $new_retry_count,
                'updated_at' => current_time('mysql')
            );
            
            // 如果有重试间隔，设置延迟执行时间
            if ($retry_delay > 0) {
                $update_data['scheduled_at'] = date('Y-m-d H:i:s', current_time('timestamp') + $retry_delay);
            }

            $result = $wpdb->update($queue_table, $update_data, array('id' => $subtask->id));
            
            if ($result !== false) {
                $updated_count++;
                
                // 文章任务特有：更新subtask_status
                if ($task_type === 'article' && !empty($subtask->reference_id)) {
                    $this->update_article_subtask_status($task_id, $subtask->reference_id, 'pending');
                }
            }
            
            if ($this->logger) {
                $this->logger->log_success('SUBTASK_UPDATE_RESULT', 
                    "子任务更新结果: subtask_id={$subtask->subtask_id}, new_retry_count={$new_retry_count}, delay={$retry_delay}s", 
                    ['task_id' => $task_id]
                );
            }
        }

        if ($this->logger) {
            $this->logger->log_success('RETRY_SUBTASKS_UPDATED', "成功更新了 " . $updated_count . " 个子任务的状态为 'pending'。", ['task_id' => $task_id]);
        }

        if ($updated_count > 0) {
            // 3. 只要有子任务被重置，就将父任务的状态也重置为 'pending'
            // 注意：我们不重置进度统计字段（current_processing_item, generated_topics_count），
            // 因为成功的子任务已经生成了主题，这些统计是有效的。
            // 系统会在重新处理失败子任务时正确更新进度。
            $update_data = array(
                'status' => 'pending', 
                'error_message' => '' // 清空父任务的错误信息
            );
            
            // 文章任务特有：重新计算failed_topics
            if ($task_type === 'article') {
                $remaining_failed_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status = 'failed'",
                    $job_type, $task_id
                ));
                $update_data['failed_topics'] = $remaining_failed_count;
            }
            
            $this->database->update($task_table_name, $update_data, array('id' => $task_id));
            
            if ($this->logger) {
                $this->logger->log_success('TASK_RETRY_INITIATED', "任务重试已启动，{$updated_count}个失败的子任务被重新排队。", ['task_id' => $task_id]);
            }
            return true;
        }

        if ($this->logger) {
            $this->logger->log_error('RETRY_UPDATE_FAILED', "尝试重置子任务状态，但数据库未报告任何行被更新。", ['task_id' => $task_id]);
        }
        return false;
    }
    
    /**
     * 重试特定子任务
     */
    private function retry_specific_item($task_id, $subtask_id, $task_type = 'topic_task') {
        // 根据任务类型确定表名和job类型
        switch ($task_type) {
            case 'article':
                $task_table_name = 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table_name = 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        
        // 获取任务信息
        $task = $this->database->get_row($task_table_name, array('id' => $task_id));
        if (!$task) {
            return false;
        }
        
        // 更新任务状态为待处理，但不重置整体进度
        $this->database->update($task_table_name, 
            array('status' => 'pending', 'error_message' => ''), 
            array('id' => $task_id)
        );
        
        // 查找特定子任务的队列记录
        $existing = $this->database->get_row('content_auto_job_queue', 
            array('job_type' => $job_type, 'job_id' => $task_id, 'subtask_id' => $subtask_id)
        );
        
        if ($existing) {
            // 更新现有记录，增加重试次数
            $retry_count = isset($existing['retry_count']) ? $existing['retry_count'] + 1 : 1;
            $update_data = array(
                'status' => 'pending',
                'error_message' => '',
                'retry_count' => $retry_count
            );
            
            return $this->database->update('content_auto_job_queue', $update_data, 
                array('id' => $existing['id'])
            );
        } else {
            // 添加新的队列记录
            return $this->add_task_to_queue($task_id, null, $subtask_id, $job_type);
        }
    }
    
    /**
     * 将任务添加到队列
     */
    private function add_task_to_queue($task_id, $subtask_index = null, $subtask_id = null, $job_type = 'topic_task') {
        // 根据任务类型决定subtask_id生成策略
        if ($subtask_id === null) {
            if ($job_type === 'article') {
                // 文章任务使用唯一ID
                $subtask_id = 'subtask_' . uniqid();
            } else {
                // 主题任务也使用唯一ID
                $subtask_id = 'subtask_' . uniqid();
            }
        }

        $queue_data = array(
            'job_type' => $job_type,
            'job_id' => $task_id,
            'subtask_id' => $subtask_id,
            'priority' => 100,
            'retry_count' => 0,
            'status' => 'pending',
            'error_message' => '',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        // 文章任务需要设置reference_id字段
        if ($job_type === 'article') {
            $reference_id = $this->get_next_topic_id_for_article_task($task_id);
            if ($reference_id) {
                $queue_data['reference_id'] = $reference_id;
            } else {
                // 如果无法获取下一个主题ID，则恢复失败
                return false;
            }
        } elseif ($job_type === 'topic_task' && $subtask_index !== null) {
            // 主题任务：根据subtask_index设置reference_id为规则项目ID
            global $wpdb;
            $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
            if ($task && isset($task['rule_id'])) {
                $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
                $rule_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$rule_items_table} WHERE rule_id = %d ORDER BY id LIMIT %d, 1",
                    $task['rule_id'], $subtask_index
                ));
                if ($rule_item) {
                    $queue_data['reference_id'] = $rule_item->id;
                }
            }
        }
        
        // 检查是否已存在相同的队列项
        $existing = $this->database->get_row('content_auto_job_queue', 
            array('job_type' => $job_type, 'job_id' => $task_id, 'subtask_id' => $queue_data['subtask_id'])
        );
        
        if ($existing) {
            return true;
        }
        
        return $this->database->insert('content_auto_job_queue', $queue_data);
    }
    
    /**
     * 检测文章任务特有的状态不一致
     */
    private function detect_article_specific_inconsistencies($task, $queue_items, &$recovery_actions) {
        global $wpdb;
        $inconsistencies_found = false;
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 解析主题ID列表
        $topic_ids = json_decode($task['topic_ids'], true);
        if (!is_array($topic_ids)) {
            return false;
        }
        
        // 检查1: reference_id缺失的队列项
        $missing_reference_items = array_filter($queue_items, function($item) {
            return empty($item['reference_id']);
        });
        
        if (!empty($missing_reference_items)) {
            foreach ($missing_reference_items as $item) {
                // 尝试从主题列表中分配一个未使用的reference_id
                $used_reference_ids = array_column($queue_items, 'reference_id');
                $available_topic_id = null;
                
                foreach ($topic_ids as $topic_id) {
                    if (!in_array($topic_id, $used_reference_ids)) {
                        $available_topic_id = $topic_id;
                        break;
                    }
                }
                
                if ($available_topic_id) {
                    $wpdb->update($wpdb->prefix . 'content_auto_job_queue', 
                        array('reference_id' => $available_topic_id),
                        array('id' => $item['id'])
                    );
                    $recovery_actions[] = "为队列项{$item['subtask_id']}分配reference_id: {$available_topic_id}";
                    $inconsistencies_found = true;
                }
            }
        }
        
        // 检查2: subtask_status与队列状态不一致
        $subtask_status = json_decode($task['subtask_status'], true);
        if (!is_array($subtask_status)) {
            $subtask_status = array();
        }
        
        $status_inconsistencies = false;
        foreach ($queue_items as $item) {
            if (!empty($item['reference_id'])) {
                $topic_id = $item['reference_id'];
                $queue_status = $item['status'];
                $subtask_recorded_status = isset($subtask_status[$topic_id]) ? $subtask_status[$topic_id] : null;
                
                // 将队列状态映射到subtask状态
                $expected_subtask_status = $this->map_queue_status_to_subtask_status($queue_status);
                
                if ($subtask_recorded_status !== $expected_subtask_status) {
                    $subtask_status[$topic_id] = $expected_subtask_status;
                    $status_inconsistencies = true;
                    $recovery_actions[] = "同步主题{$topic_id}的subtask状态: {$subtask_recorded_status} -> {$expected_subtask_status}";
                }
            }
        }
        
        if ($status_inconsistencies) {
            $wpdb->update($task_table, 
                array('subtask_status' => json_encode($subtask_status)),
                array('id' => $task['id'])
            );
            $inconsistencies_found = true;
        }
        
        // 检查3: completed_topics和failed_topics计数不准确
        $completed_count = count(array_filter($queue_items, function($item) {
            return $item['status'] === 'completed';
        }));
        $failed_count = count(array_filter($queue_items, function($item) {
            return $item['status'] === 'failed';
        }));
        
        if ($task['completed_topics'] !== $completed_count || $task['failed_topics'] !== $failed_count) {
            $wpdb->update($task_table, array(
                'completed_topics' => $completed_count,
                'failed_topics' => $failed_count,
                'updated_at' => current_time('mysql')
            ), array('id' => $task['id']));
            $recovery_actions[] = "修正计数: completed_topics={$completed_count}, failed_topics={$failed_count}";
            $inconsistencies_found = true;
        }
        
        return $inconsistencies_found;
    }
    
    /**
     * 同步文章任务进度
     */
    private function sync_article_task_progress($task, $completed_queue, &$recovery_actions) {
        global $wpdb;
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $inconsistencies_found = false;
        
        // 解析并更新subtask_status
        $subtask_status = json_decode($task['subtask_status'], true);
        if (!is_array($subtask_status)) {
            $subtask_status = array();
        }
        
        $status_updated = false;
        foreach ($completed_queue as $item) {
            if (!empty($item['reference_id'])) {
                $topic_id = $item['reference_id'];
                if (!isset($subtask_status[$topic_id]) || $subtask_status[$topic_id] !== 'completed') {
                    $subtask_status[$topic_id] = 'completed';
                    $status_updated = true;
                }
            }
        }
        
        if ($status_updated) {
            $wpdb->update($task_table, 
                array('subtask_status' => json_encode($subtask_status)),
                array('id' => $task['id'])
            );
            $recovery_actions[] = "同步文章任务的subtask_status";
            $inconsistencies_found = true;
        }
        
        return $inconsistencies_found;
    }
    
    /**
     * 基于reference_id恢复文章队列项
     */
    private function recover_article_queue_items($task, $existing_queue_items, &$recovery_actions) {
        global $wpdb;
        $inconsistencies_found = false;
        
        // 解析主题ID列表
        $topic_ids = json_decode($task['topic_ids'], true);
        if (!is_array($topic_ids)) {
            return false;
        }
        
        // 获取已存在的reference_id
        $existing_reference_ids = array();
        foreach ($existing_queue_items as $item) {
            if (!empty($item['reference_id'])) {
                $existing_reference_ids[] = $item['reference_id'];
            }
        }
        
        // 找到缺失的主题ID并创建队列项
        foreach ($topic_ids as $topic_id) {
            if (!in_array($topic_id, $existing_reference_ids)) {
                $queue_data = array(
                    'job_type' => 'article',
                    'job_id' => $task['id'],
                    'subtask_id' => 'subtask_' . uniqid(),
                    'reference_id' => $topic_id,
                    'priority' => 100,
                    'retry_count' => 0,
                    'status' => 'pending',
                    'error_message' => '',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                );
                
                $result = $wpdb->insert($wpdb->prefix . 'content_auto_job_queue', $queue_data);
                if ($result) {
                    $recovery_actions[] = "为主题{$topic_id}重新创建队列项";
                    $inconsistencies_found = true;
                }
            }
        }
        
        return $inconsistencies_found;
    }
    
    /**
     * 将队列状态映射到subtask状态
     */
    private function map_queue_status_to_subtask_status($queue_status) {
        switch ($queue_status) {
            case 'completed':
                return 'completed';
            case 'failed':
                return 'failed';
            case 'processing':
                return 'processing';
            case 'pending':
            default:
                return 'pending';
        }
    }
    
    /**
     * 获取文章任务的下一个待处理主题ID
     */
    private function get_next_topic_id_for_article_task($task_id) {
        global $wpdb;
        
        // 获取文章任务信息
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT topic_ids FROM {$task_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task || !$task['topic_ids']) {
            return null;
        }
        
        // 解析主题ID列表
        $topic_ids = json_decode($task['topic_ids'], true);
        if (!is_array($topic_ids) || empty($topic_ids)) {
            return null;
        }
        
        // 获取已处理的主题ID列表
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $processed_topic_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT reference_id FROM {$queue_table} 
             WHERE job_type = 'article' AND job_id = %d AND reference_id IS NOT NULL",
            $task_id
        ));
        
        // 找到第一个未处理的主题ID
        foreach ($topic_ids as $topic_id) {
            if (!in_array($topic_id, $processed_topic_ids)) {
                return $topic_id;
            }
        }
        
        return null; // 所有主题都已处理
    }
    
    
    
    /**
     * 计算重试间隔（指数退避策略）
     */
    private function calculate_retry_delay($retry_count) {
        // 基础延迟时间（秒）- 与自动重试保持一致
        $base_delay = 1;
        
        // 指数退避：1s, 2s, 4s, 8s...
        $delay = $base_delay * pow(2, $retry_count);
        
        // 最大延迟不超过10分钟
        return min($delay, 600);
    }
    
    /**
     * 更新文章任务的subtask状态
     */
    private function update_article_subtask_status($task_id, $topic_id, $status) {
        global $wpdb;
        $task_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 获取当前任务信息
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT subtask_status FROM {$task_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task) {
            return false;
        }
        
        // 解析并更新subtask_status
        $subtask_status = json_decode($task['subtask_status'], true);
        if (!is_array($subtask_status)) {
            $subtask_status = array();
        }
        
        $subtask_status[$topic_id] = $status;
        
        // 更新数据库
        return $wpdb->update($task_table, 
            array('subtask_status' => json_encode($subtask_status)),
            array('id' => $task_id)
        );
    }
    
    /**
     * 重新排队失败的子任务
     */
    public function requeue_failed_subtasks($task_id, $task_type = 'topic_task', $specific_subtask_ids = null) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 根据任务类型确定job类型
        $job_type = ($task_type === 'article') ? 'article' : 'topic_task';
        
        // 构建查询条件
        $where_conditions = array(
            'job_type' => $job_type,
            'job_id' => $task_id,
            'status' => 'failed'
        );
        
        // 如果指定了特定的subtask_ids，添加到条件中
        if (!empty($specific_subtask_ids) && is_array($specific_subtask_ids)) {
            $placeholders = implode(',', array_fill(0, count($specific_subtask_ids), '%s'));
            $where_sql = $wpdb->prepare(
                "job_type = %s AND job_id = %d AND status = 'failed' AND subtask_id IN ({$placeholders})",
                array_merge(array($job_type, $task_id), $specific_subtask_ids)
            );
        } else {
            $where_sql = $wpdb->prepare(
                "job_type = %s AND job_id = %d AND status = 'failed'",
                $job_type, $task_id
            );
        }
        
        // 获取失败的子任务
        $failed_subtasks = $wpdb->get_results(
            "SELECT * FROM {$queue_table} WHERE {$where_sql}",
            ARRAY_A
        );
        
        if (empty($failed_subtasks)) {
            return array('success' => true, 'requeued_count' => 0, 'message' => '没有找到需要重新排队的失败子任务');
        }
        
        $requeued_count = 0;
        $max_retries = defined('CONTENT_AUTO_MAX_RETRIES') ? CONTENT_AUTO_MAX_RETRIES : 3;
        
        foreach ($failed_subtasks as $subtask) {
            // 检查重试次数限制
            if ($subtask['retry_count'] >= $max_retries) {
                continue;
            }
            
            // 计算重试间隔
            $retry_delay = $this->calculate_retry_delay($subtask['retry_count']);
            
            // 更新子任务状态
            $update_data = array(
                'status' => 'pending',
                'error_message' => '',
                'retry_count' => $subtask['retry_count'] + 1,
                'updated_at' => current_time('mysql')
            );
            
            // 如果有重试间隔，设置延迟执行时间
            if ($retry_delay > 0) {
                $update_data['scheduled_at'] = date('Y-m-d H:i:s', time() + $retry_delay);
            }
            
            $result = $wpdb->update($queue_table, $update_data, array('id' => $subtask['id']));
            
            if ($result !== false) {
                $requeued_count++;
                
                // 文章任务特有：更新subtask_status
                if ($task_type === 'article' && !empty($subtask['reference_id'])) {
                    $this->update_article_subtask_status($task_id, $subtask['reference_id'], 'pending');
                }
                
                if ($this->logger) {
                    $this->logger->log_success('SUBTASK_REQUEUED', 
                        "子任务重新排队: {$subtask['subtask_id']}, 重试次数: " . ($subtask['retry_count'] + 1),
                        ['task_id' => $task_id, 'delay' => $retry_delay]
                    );
                }
            }
        }
        
        // 如果有子任务被重新排队，更新父任务状态
        if ($requeued_count > 0) {
            $this->update_parent_task_after_requeue($task_id, $task_type);
        }
        
        return array(
            'success' => true,
            'requeued_count' => $requeued_count,
            'total_failed' => count($failed_subtasks),
            'message' => "成功重新排队 {$requeued_count} 个失败的子任务"
        );
    }
    
    /**
     * 重新排队后更新父任务状态
     */
    private function update_parent_task_after_requeue($task_id, $task_type) {
        global $wpdb;
        
        // 根据任务类型确定表名
        switch ($task_type) {
            case 'article':
                $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                $job_type = 'article';
                break;
            case 'topic_task':
            default:
                $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                $job_type = 'topic_task';
                break;
        }
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 重新计算失败任务数量
        $failed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status = 'failed'",
            $job_type, $task_id
        ));
        
        $update_data = array(
            'status' => 'pending',
            'error_message' => '',
            'updated_at' => current_time('mysql')
        );
        
        // 文章任务特有：更新failed_topics计数
        if ($task_type === 'article') {
            $update_data['failed_topics'] = $failed_count;
        }
        
        $wpdb->update($task_table, $update_data, array('id' => $task_id));
        
        if ($this->logger) {
            $this->logger->log_success('PARENT_TASK_UPDATED_AFTER_REQUEUE', 
                "父任务状态已更新为pending，剩余失败子任务: {$failed_count}",
                ['task_id' => $task_id, 'task_type' => $task_type]
            );
        }
    }
    
    
}