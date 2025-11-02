<?php
/**
 * 任务队列类
 */

if (!defined('ABSPATH')) {
    exit;
}


class ContentAuto_JobQueue {
    
    private $database;
    private $article_generator;
    private $topic_task_manager;
    private $article_queue_processor;
    
    /**
     * 获取全局任务锁
     */
    private function acquire_global_task_lock() {
        $lock_key = 'content_auto_global_task_lock';
        $lock_timeout = CONTENT_AUTO_QUEUE_LOCK_TIMEOUT; // 队列锁定超时
        
        if (get_transient($lock_key)) {
            return false; // 已有任务在执行
        }
        
        set_transient($lock_key, true, $lock_timeout);
        return true;
    }
    
    /**
     * 释放全局任务锁
     */
    private function release_global_task_lock() {
        $lock_key = 'content_auto_global_task_lock';
        delete_transient($lock_key);
    }
    
    /**
     * 获取全局子任务处理锁
     * 确保任何时候只有一个子任务在处理
     */
    private function acquire_global_subtask_lock() {
        $lock_key = 'content_auto_global_subtask_lock';
        $lock_timeout = CONTENT_AUTO_QUEUE_LOCK_TIMEOUT; // 使用相同的超时时间
        
        if (get_transient($lock_key)) {
            return false; // 已有子任务在执行
        }
        
        set_transient($lock_key, true, $lock_timeout);
        return true;
    }
    
    /**
     * 释放全局子任务锁
     */
    private function release_global_subtask_lock() {
        $lock_key = 'content_auto_global_subtask_lock';
        delete_transient($lock_key);
    }
    
    /**
     * 检测任务类型是否涉及API请求
     */
    private function was_api_request_made($job_type) {
        // 涉及API请求的任务类型
        $api_job_types = array(
            'topic_task',       // 主题任务需要调用API生成主题
            'article',           // 文章任务需要调用API生成文章
            'vector_generation', // 向量生成任务需要调用向量API
        );
        
        return in_array($job_type, $api_job_types);
    }
    
    // API延迟逻辑已移除，避免与子任务间间隔重复
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->article_generator = new ContentAuto_ArticleGenerator();
        $this->article_task_manager = new ContentAuto_ArticleTaskManager();
        $this->topic_task_manager = new ContentAuto_TopicTaskManager();
        
        // 引入文章队列处理器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-article-queue-processor.php';
        $this->article_queue_processor = new ContentAuto_ArticleQueueProcessor();
        
        // 引入向量生成器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-generator.php';
        $this->vector_generator = new ContentAuto_VectorGenerator();
    }
    
    /**
     * 简化调度器 - 直接处理主题任务
     */
    public function process_simple_topic_task() {
        // 获取全局子任务锁
        if (!$this->acquire_global_subtask_lock()) {
            return false;
        }
        
        try {
            global $wpdb;
            
            // 并发控制：检查是否有相同规则的正在处理任务
            $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
            $processing_tasks = $wpdb->get_results(
                "SELECT rule_id FROM {$tasks_table} 
                WHERE status = 'processing' 
                GROUP BY rule_id"
            );
            
            $processing_rule_ids = array();
            foreach ($processing_tasks as $ptask) {
                $processing_rule_ids[] = $ptask->rule_id;
            }
            
            // 构建查询条件，避免相同规则的任务并发执行
            $where_clause = "status IN ('pending', 'processing')";
            if (!empty($processing_rule_ids)) {
                $rule_ids_placeholders = implode(',', array_fill(0, count($processing_rule_ids), '%d'));
                $where_clause .= " AND rule_id NOT IN ({$rule_ids_placeholders})";
            }
            
            // 获取下一个待处理的主题任务
            $query = $wpdb->prepare(
                "SELECT * FROM {$tasks_table} 
                WHERE {$where_clause}
                ORDER BY last_processed_at ASC, created_at ASC 
                LIMIT 1",
                array_merge(array(CONTENT_AUTO_STATUS_PENDING, CONTENT_AUTO_STATUS_PROCESSING), $processing_rule_ids)
            );
            
            $task = $wpdb->get_row($query, ARRAY_A);
            
            if (!$task) {
                // 如果没有找到非冲突的任务，检查是否有被阻塞的任务
                if (!empty($processing_rule_ids)) {
                    $blocked_task = $wpdb->get_row(
                        "SELECT * FROM {$tasks_table} 
                        WHERE status IN ('pending', 'processing') 
                        AND rule_id IN (" . implode(',', $processing_rule_ids) . ")
                        ORDER BY last_processed_at ASC, created_at ASC 
                        LIMIT 1",
                        ARRAY_A
                    );
                    
                    if ($blocked_task) {
                        return false;
                    }
                }
                
                return false;
            }
            
            // 处理主题任务
            $result = $this->topic_task_manager->process_topic_task($task['id']);
            
            if ($result) {
            } else {
            }
            
            return $result;
        } finally {
            // 释放全局子任务锁
            $this->release_global_subtask_lock();
        }
    }
    
    /**
     * 处理下一个任务
     */
    public function process_next_job() {
        global $wpdb;

        // 获取全局任务锁
        if (!$this->acquire_global_task_lock()) {
            return false;
        }

        try {
            $processed_count = 0;
            $max_jobs_per_run = defined('CONTENT_AUTO_MAX_JOBS_PER_RUN') ? CONTENT_AUTO_MAX_JOBS_PER_RUN : 5;

            while ($processed_count < $max_jobs_per_run) {
                // 获取下一个待处理的任务
                $table_name = $wpdb->prefix . 'content_auto_job_queue';
                $job = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $table_name WHERE status = %s ORDER BY priority DESC, created_at ASC LIMIT 1",
                        CONTENT_AUTO_STATUS_PENDING
                    ),
                    ARRAY_A
                );

                if (!$job) {
                    // 没有更多待处理的任务，退出循环
                    break;
                }

                // 获取全局子任务锁
                if (!$this->acquire_global_subtask_lock()) {
                    // 如果无法获取子任务锁，则中断本次执行，等待下一次 Cron
                    break;
                }

                try {
                    // 更新任务状态为处理中
                    $wpdb->update(
                        $table_name,
                        array('status' => CONTENT_AUTO_STATUS_PROCESSING),
                        array('id' => $job['id'])
                    );

                    // 根据任务类型处理任务
                    $result = false;
                    switch ($job['job_type']) {
                        case 'topic_task':
                            $result = $this->topic_task_manager->process_topic_task($job['job_id'], $job['subtask_id']);
                            break;
                        case 'article':
                            // 使用专门的文章队列处理器处理文章生成子任务
                            $result = $this->article_queue_processor->process_article_subtask($job['job_id'], $job['subtask_id']);
                            break;
                        case 'vector_generation':
                            // 使用向量生成器处理向量生成任务
                            $result = $this->vector_generator->process_vector_generation($job);
                            break;
                    }

                    // 更新任务状态
                    $is_success = is_array($result) ? $result['success'] : $result;

                    if ($is_success) {
                        $wpdb->update(
                            $table_name,
                            array(
                                'status' => CONTENT_AUTO_STATUS_COMPLETED,
                                'updated_at' => current_time('mysql')
                            ),
                            array('id' => $job['id'])
                        );
                    } else {
                        $error_message = (is_array($result) && !empty($result['message'])) ? $result['message'] : '任务处理失败，且未返回明确错误信息';
                        $wpdb->update(
                            $table_name,
                            array(
                                'status' => CONTENT_AUTO_STATUS_FAILED,
                                'error_message' => $error_message,
                                'updated_at' => current_time('mysql')
                            ),
                            array('id' => $job['id'])
                        );
                    }
                    
                    // 更新父任务状态（文章任务）
                    if ($job['job_type'] === 'article') {
                        $this->update_article_parent_task_status($job['job_id']);
                    }
                } finally {
                    // 释放全局子任务锁
                    $this->release_global_subtask_lock();
                }

                $processed_count++;

                // 检查是否还有更多任务，以决定是否需要休眠
                $next_job_exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE status = %s LIMIT 1", CONTENT_AUTO_STATUS_PENDING));
                if (!$next_job_exists) {
                    // 如果没有更多任务，则无需等待，直接退出
                    break;
                }
                
                // 如果处理了任务且仍在限制内，则进行休眠
                if ($processed_count < $max_jobs_per_run) {
                    $interval = defined('CONTENT_AUTO_SUBTASK_INTERVAL') ? CONTENT_AUTO_SUBTASK_INTERVAL : 0;
                    if ($interval > 0) {
                        sleep($interval);
                    }
                }
            } // 结束 while 循环

            return $processed_count > 0;

        } catch (Exception $e) {
            $error_message = "任务处理异常: " . $e->getMessage();
            if (isset($job['id'])) {
                $enhanced_error_message = sprintf("[%s] %s", current_time('mysql'), $error_message);
                $wpdb->update(
                    $table_name,
                    array(
                        'status' => CONTENT_AUTO_STATUS_FAILED,
                        'error_message' => $enhanced_error_message,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $job['id'])
                );
                if ($job['job_type'] === 'topic_task' && isset($this->topic_task_manager)) {
                    $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
                    $wpdb->update(
                        $task_table,
                        array(
                            'error_message' => $enhanced_error_message,
                            'status' => 'failed',
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $job['job_id'])
                    );
                } elseif ($job['job_type'] === 'article' && isset($this->article_task_manager)) {
                    $task_table = $wpdb->prefix . 'content_auto_article_tasks';
                    $wpdb->update(
                        $task_table,
                        array(
                            'error_message' => $enhanced_error_message,
                            'status' => 'failed',
                            'updated_at' => current_time('mysql')
                        ),
                        array('id' => $job['job_id'])
                    );
                }
            }
            return false;
        } finally {
            // 释放全局任务锁
            $this->release_global_task_lock();
        }
    }
    
    /**
     * 获取队列状态
     */
    public function get_queue_status() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status",
            ARRAY_A
        );

        $status = array(
            CONTENT_AUTO_STATUS_PENDING => 0,
            CONTENT_AUTO_STATUS_PROCESSING => 0,
            CONTENT_AUTO_STATUS_COMPLETED => 0,
            CONTENT_AUTO_STATUS_FAILED => 0
        );

        foreach ($results as $row) {
            $status[$row['status']] = $row['count'];
        }

        // 为了兼容性，同时返回字符串键和总数
        $status['pending'] = $status[CONTENT_AUTO_STATUS_PENDING];
        $status['processing'] = $status[CONTENT_AUTO_STATUS_PROCESSING];
        $status['completed'] = $status[CONTENT_AUTO_STATUS_COMPLETED];
        $status['failed'] = $status[CONTENT_AUTO_STATUS_FAILED];
        $status['total'] = $status['pending'] + $status['processing'] + $status['completed'] + $status['failed'];

        return $status;
    }
    
    /**
     * 获取队列中的所有任务
     */
    public function get_queue_jobs() {
        return $this->database->get_results('content_auto_job_queue');
    }
    
    /**
     * 更新文章父任务状态
     */
    private function update_article_parent_task_status($task_id) {
        global $wpdb;
        
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 获取该任务的所有子任务状态统计
        $subtask_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM $job_queue_table 
            WHERE job_type = 'article' AND job_id = %d
        ", $task_id));
        
        if (!$subtask_stats || $subtask_stats->total == 0) {
            return false;
        }
        
        // 确定父任务状态
        $new_status = 'pending';
        $status_message = '';
        
        if ($subtask_stats->failed == $subtask_stats->total) {
            // 所有子任务都失败
            $new_status = 'failed';
            $status_message = "所有 {$subtask_stats->total} 个子任务都失败";
        } elseif ($subtask_stats->completed == $subtask_stats->total) {
            // 所有子任务都完成
            $new_status = 'completed';
            $status_message = "所有 {$subtask_stats->total} 个子任务都完成";
        } elseif ($subtask_stats->completed + $subtask_stats->failed == $subtask_stats->total) {
            // 所有子任务都已处理完（部分成功，部分失败）
            if ($subtask_stats->failed > 0) {
                // 只要有任何子任务失败，父任务就标记为失败
                $new_status = 'failed';
                if ($subtask_stats->completed > 0) {
                    $status_message = "{$subtask_stats->completed} 个子任务完成，{$subtask_stats->failed} 个子任务失败";
                } else {
                    $status_message = "所有 {$subtask_stats->failed} 个子任务都失败";
                }
            } else {
                // 这种情况不应该发生，但为了安全起见
                $new_status = 'completed';
                $status_message = "所有 {$subtask_stats->completed} 个子任务都完成";
            }
        } elseif ($subtask_stats->processing > 0) {
            // 有子任务正在处理中
            $new_status = 'processing';
            $status_message = "有 {$subtask_stats->processing} 个子任务正在处理";
        } elseif ($subtask_stats->pending > 0) {
            // 有子任务待处理
            $new_status = 'pending';
            $status_message = "有 {$subtask_stats->pending} 个子任务待处理";
        }
        
        // 更新父任务状态
        $update_result = $wpdb->update(
            $article_tasks_table,
            array(
                'status' => $new_status,
                'error_message' => $status_message,
                'completed_topics' => $subtask_stats->completed,
                'failed_topics' => $subtask_stats->failed,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $task_id),
            array('%s', '%s', '%d', '%d', '%s'),
            array('%d')
        );
        
        return $update_result !== false;
    }
    
    /**
     * 重新排队失败的任务
     */
    public function requeue_failed_jobs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        return $wpdb->update(
            $table_name,
            array('status' => CONTENT_AUTO_STATUS_PENDING),
            array('status' => CONTENT_AUTO_STATUS_FAILED)
        );
    }
    
    /**
     * 清理已完成的任务
     */
    public function cleanup_completed_jobs() {
        return $this->database->delete('content_auto_job_queue', array('status' => CONTENT_AUTO_STATUS_COMPLETED));
    }
    
    /**
     * 验证队列字段和数据完整性
     * 确保队列表的reference_id字段正确设置，验证job_type='article'的队列项创建正确
     */
    public function verify_queue_data_integrity() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        $results = array();
        
        // 1. 检查article类型的队列项是否有正确的reference_id
        $article_jobs_without_reference = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE job_type = 'article' AND (reference_id IS NULL OR reference_id = '')"
        );
        
        $results['article_jobs_without_reference'] = $article_jobs_without_reference;
        
        // 2. 检查article类型队列项的reference_id是否对应有效的主题
        $invalid_topic_references = $wpdb->get_results(
            "SELECT q.id, q.reference_id FROM $table_name q 
             LEFT JOIN {$wpdb->prefix}content_auto_topics t ON q.reference_id = t.id 
             WHERE q.job_type = 'article' AND q.reference_id IS NOT NULL AND t.id IS NULL",
            ARRAY_A
        );
        
        $results['invalid_topic_references'] = $invalid_topic_references;
        
        // 3. 检查article类型队列项是否有对应的任务记录
        $orphaned_queue_items = $wpdb->get_results(
            "SELECT q.id, q.job_id FROM $table_name q 
             LEFT JOIN {$wpdb->prefix}content_auto_article_tasks t ON q.job_id = t.id 
             WHERE q.job_type = 'article' AND t.id IS NULL",
            ARRAY_A
        );
        
        $results['orphaned_queue_items'] = $orphaned_queue_items;
        
        // 4. 检查必需字段的完整性
        $incomplete_article_jobs = $wpdb->get_results(
            "SELECT id, job_id, subtask_id, reference_id, status FROM $table_name 
             WHERE job_type = 'article' AND (
                 job_id IS NULL OR job_id = '' OR 
                 subtask_id IS NULL OR subtask_id = '' OR 
                 status IS NULL OR status = ''
             )",
            ARRAY_A
        );
        
        $results['incomplete_article_jobs'] = $incomplete_article_jobs;
        
        // 5. 统计article类型队列项的状态分布
        $article_status_distribution = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name WHERE job_type = 'article' GROUP BY status",
            ARRAY_A
        );
        
        $results['article_status_distribution'] = $article_status_distribution;
        
        // 6. 检查重复的队列项（相同的job_id, subtask_id, reference_id组合）
        $duplicate_queue_items = $wpdb->get_results(
            "SELECT job_id, subtask_id, reference_id, COUNT(*) as count 
             FROM $table_name 
             WHERE job_type = 'article' 
             GROUP BY job_id, subtask_id, reference_id 
             HAVING count > 1",
            ARRAY_A
        );
        
        $results['duplicate_queue_items'] = $duplicate_queue_items;
        
        return $results;
    }
    
    /**
     * 修复队列数据完整性问题
     */
    public function fix_queue_data_integrity() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_job_queue';
        $fixed_issues = array();
        
        // 1. 删除没有reference_id的article队列项
        $deleted_count = $wpdb->query(
            "DELETE FROM $table_name WHERE job_type = 'article' AND (reference_id IS NULL OR reference_id = '')"
        );
        if ($deleted_count > 0) {
            $fixed_issues[] = "删除了 {$deleted_count} 个缺少reference_id的article队列项";
        }
        
        // 2. 删除reference_id对应无效主题的队列项
        $deleted_invalid_refs = $wpdb->query(
            "DELETE q FROM $table_name q 
             LEFT JOIN {$wpdb->prefix}content_auto_topics t ON q.reference_id = t.id 
             WHERE q.job_type = 'article' AND q.reference_id IS NOT NULL AND t.id IS NULL"
        );
        if ($deleted_invalid_refs > 0) {
            $fixed_issues[] = "删除了 {$deleted_invalid_refs} 个引用无效主题的队列项";
        }
        
        // 3. 删除孤立的队列项（没有对应任务记录）
        $deleted_orphaned = $wpdb->query(
            "DELETE q FROM $table_name q 
             LEFT JOIN {$wpdb->prefix}content_auto_article_tasks t ON q.job_id = t.id 
             WHERE q.job_type = 'article' AND t.id IS NULL"
        );
        if ($deleted_orphaned > 0) {
            $fixed_issues[] = "删除了 {$deleted_orphaned} 个孤立的队列项";
        }
        
        // 4. 删除重复的队列项，保留最早创建的
        $duplicate_items = $wpdb->get_results(
            "SELECT job_id, subtask_id, reference_id, MIN(id) as keep_id
             FROM $table_name 
             WHERE job_type = 'article' 
             GROUP BY job_id, subtask_id, reference_id 
             HAVING COUNT(*) > 1",
            ARRAY_A
        );
        
        $deleted_duplicates = 0;
        foreach ($duplicate_items as $item) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name 
                 WHERE job_type = 'article' 
                 AND job_id = %d AND subtask_id = %s AND reference_id = %d 
                 AND id != %d",
                $item['job_id'], $item['subtask_id'], $item['reference_id'], $item['keep_id']
            ));
            $deleted_duplicates += $deleted;
        }
        
        if ($deleted_duplicates > 0) {
            $fixed_issues[] = "删除了 {$deleted_duplicates} 个重复的队列项";
        }
        
        return $fixed_issues;
    }
    
    /**
     * 启动向量生成调度器
     * 仅在系统空闲时（无活跃主题任务）启动向量生成
     */
    public function start_vector_generation_scheduler() {
        return $this->vector_generator->start_vector_generation_scheduler();
    }
    
    /**
     * 获取向量生成统计信息
     */
    public function get_vector_generation_stats() {
        return $this->vector_generator->get_vector_generation_stats();
    }
}