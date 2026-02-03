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
    private $article_task_manager;
    private $vector_generator;
    
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
        
        // 监听插件任务完成事件
        add_action('cam_extension_task_completed', array($this, 'handle_extension_task_completion'), 10, 2);
    }
    
    /**
     * 处理插件任务完成回调
     */
    public function handle_extension_task_completion($task_id, $result) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

        // 检查任务是否为测试任务
        $queue = get_option('cam_extension_task_queue', array());
        if (isset($queue[$task_id]['payload']['is_test']) && $queue[$task_id]['payload']['is_test']) {
            return; // 测试任务不更新数据库
        }
        
        // 获取 topic_id：优先从结果中获取，否则从原始任务队列获取
        $topic_id = null;
        if (is_array($result) && !empty($result['topic_id'])) {
            $topic_id = intval($result['topic_id']);
        } else {
            // 从原始任务队列中获取
            $queue = get_option('cam_extension_task_queue', array());
            if (!empty($queue[$task_id]['payload']['topic_id'])) {
                $topic_id = intval($queue[$task_id]['payload']['topic_id']);
            }
        }
        
        if (empty($topic_id)) {
            // 无法确定主题ID，记录日志并返回
            error_log('[ContentAuto] Extension task completed but no topic_id found: ' . $task_id);
            return;
        }
        
        // 检查是否有错误
        if (is_array($result) && !empty($result['error'])) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'failed',
                'material_search_error' => sanitize_text_field($result['error'])
            ], ['id' => $topic_id]);
            return;
        }
        
        // 格式化结果
        $material_content = '';
        if (is_string($result)) {
            $material_content = $result;
        } elseif (is_array($result)) {
            // 优先获取 'answer' 字段
            $material_content = isset($result['answer']) ? $result['answer'] : json_encode($result, JSON_UNESCAPED_UNICODE);
        }

        // 处理无答案标识：视为该资料搜索任务失败
        if ($material_content === 'NO_CONTEXT_ANSWER' || strpos($material_content, 'NO_CONTEXT_ANSWER') !== false) {
             $wpdb->update($topics_table, [
                'material_search_status' => 'failed',
                'material_search_error' => '没有找到相关参考资料 (No Context Found)'
            ], ['id' => $topic_id]);
            
            // 重要修正：即使没有答案，也必须继续向下执行以关闭 Job Queue 中的任务，防止队列阻塞
            $result = array('error' => '未找到相关内容 (No Context)');
        }
        
        // 检查是否有错误（合并处理）
        if (is_array($result) && !empty($result['error'])) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'failed',
                'material_search_error' => sanitize_text_field($result['error'])
            ], ['id' => $topic_id]);
            
            // 同样需要关闭 Job Queue
            $this->close_job_if_needed($task_id, 'failed', sanitize_text_field($result['error']));
            return;
        }
        
        // 格式化正常结果
        $material_content = '';
        if (is_string($result)) {
            $material_content = $result;
        } elseif (is_array($result)) {
            $material_content = isset($result['answer']) ? $result['answer'] : json_encode($result, JSON_UNESCAPED_UNICODE);
        }
        
        if (empty($material_content)) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'failed',
                'material_search_error' => '插件返回结果为空'
            ], ['id' => $topic_id]);
            $this->close_job_if_needed($task_id, 'failed', '插件返回结果为空');
            
            // 清理 Option 队列中的已完成任务
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/queue/class-task-queue-cleaner.php';
            $cleaner = new ContentAuto_TaskQueueCleaner();
            $cleaner->remove_from_option_queue($task_id);
            return;
        }

        $wpdb->update($topics_table, [
            'reference_material' => $material_content,
            'material_search_status' => 'completed',
            'material_search_error' => ''
        ], ['id' => $topic_id]);

        // 更新 Job Queue 状态
        $this->close_job_if_needed($task_id, 'completed');
        
        // 清理 Option 队列中的已完成任务
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/queue/class-task-queue-cleaner.php';
        $cleaner = new ContentAuto_TaskQueueCleaner();
        $cleaner->remove_from_option_queue($task_id);
    }

    /**
     * 辅助方法：关闭 Job Queue 中的任务
     */
    private function close_job_if_needed($task_id, $status, $error = '') {
        global $wpdb;
        $queue = get_option('cam_extension_task_queue', array());
        if (!empty($queue[$task_id]['payload']['job_id'])) {
            $job_id = intval($queue[$task_id]['payload']['job_id']);
            $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
            $wpdb->update($job_queue_table, [
                'status' => $status,
                'updated_at' => current_time('mysql'),
                'error_message' => $error
            ], ['id' => $job_id]);
        }
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
            $where_clause = "status IN (%s, %s)";
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
            $table_name = $wpdb->prefix . 'content_auto_job_queue';

            // === [核心加固] 定点超时检查与全局串行锁定 ===
            
            // 0. 检查等待浏览器采集超时的任务 (status = waiting_browser)
            $waiting_jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, updated_at FROM $table_name WHERE status = %s",
                'waiting_browser'
            ), ARRAY_A);

            if (!empty($waiting_jobs)) {
                $current_time = current_time('timestamp');
                $browser_timeout = 600; // 10分钟超时

                foreach ($waiting_jobs as $wj) {
                    $last_update = strtotime($wj['updated_at']);
                    if (($current_time - $last_update) > $browser_timeout) {
                        $wpdb->update($table_name, [
                            'status' => CONTENT_AUTO_STATUS_FAILED,
                            'error_message' => sprintf('浏览器采集超时 (超过%d秒)，请检查插件是否连接', $browser_timeout),
                            'updated_at' => current_time('mysql')
                        ], ['id' => $wj['id']]);
                    }
                }
            }

            // 1. 查找当前处理中的任务
            $processing_jobs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, job_type, job_id, reference_id, updated_at FROM $table_name WHERE status = %s",
                CONTENT_AUTO_STATUS_PROCESSING
            ), ARRAY_A);

            if (!empty($processing_jobs)) {
                $current_time = current_time('timestamp');
                
                foreach ($processing_jobs as $pij) {
                    // 【修正】仅针对知识库/素材搜索任务执行 3 分钟强行超时收割
                    // 其他任务（文章生成等）拥有独立的超时处理器，此处不做干预
                    if ($pij['job_type'] === 'material_search') {
                        $last_update = strtotime($pij['updated_at']);
                        
                        // 【语义加固】辨别搜集模式：插件异步 vs 网页同步
                        // 插件模式在 topics 表的状态为 'waiting_for_extension'
                        // 修正：优先使用 reference_id 获取 topic_id，因为对于 material_search 任务 job_id 通常为 0
                        $topic_id = !empty($pij['reference_id']) ? $pij['reference_id'] : $pij['job_id'];
                        
                        $m_status = $wpdb->get_var($wpdb->prepare(
                            "SELECT material_search_status FROM {$wpdb->prefix}content_auto_topics WHERE id = %d",
                            $topic_id
                        ));

                        // 策略：统一调整为 5 分钟 (300s) 超时。
                        // 无论是插件模式还是网页模式，超过 5 分钟未响应均视为卡死
                        $timeout_limit = 300;

                        if (($current_time - $last_update) > $timeout_limit) { 
                            $wpdb->update($table_name, [
                                'status' => CONTENT_AUTO_STATUS_FAILED,
                                'error_message' => sprintf('任务响应超时 (超过%d秒)，手动标记为失败以释放队列', $timeout_limit),
                                'updated_at' => current_time('mysql')
                            ], ['id' => $pij['id']]);
                            
                            // 更新主题表状态
                            $wpdb->update($wpdb->prefix . 'content_auto_topics', [
                                'material_search_status' => 'failed',
                                'material_search_error' => '任务响应超时'
                            ], ['id' => $topic_id]);
                        }
                    }
                }
                
                // 再次检查是否仍有活跃的任务在处理中 (包含所有类型)
                // [优化] 排除 'waiting_browser' 状态的项目，因为它们由插件异步处理，不应占用 Worker 的串行锁
                $active_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE status = %s AND status != 'waiting_browser'",
                    CONTENT_AUTO_STATUS_PROCESSING
                ));
                
                if ($active_count > 0) {
                    // 全局串行原则：只要池子里有一个工作没干完，就不拉起下一个
                    return false;
                }
            }

            $processed_count = 0;
            // 严格串行：单次 Cron 触发只处理一个 pending 任务即可，确保排队有序
            $max_jobs_per_run = 1; 

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
                    // 记录当前处理的任务信息，用于shutdown时恢复
                    $current_processing_job = array(
                        'job_id' => $job['id'],
                        'table_name' => $table_name,
                        'job_type' => $job['job_type'],
                        'task_id' => $job['job_id']
                    );
                    update_option('content_auto_current_processing_job', $current_processing_job, false);
                    
                    // 更新任务状态为处理中，同时记录开始处理时间
                    $wpdb->update(
                        $table_name,
                        array(
                            'status' => CONTENT_AUTO_STATUS_PROCESSING,
                            'updated_at' => current_time('mysql')  // 记录开始处理时间
                        ),
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
                        case 'material_search':
                            // 处理素材搜索任务
                            $result = $this->process_material_search($job);
                            break;
                    }

                    // 清除当前处理任务标记
                    delete_option('content_auto_current_processing_job');

                    // 更新任务状态
                    $is_success = is_array($result) ? $result['success'] : $result;

                    if (is_array($result) && isset($result['status']) && ($result['status'] === 'waiting' || $result['status'] === 'waiting_for_browser')) {
                        // 任务处于异步等待状态（如浏览器插件搜索），保持 processing 状态
                        // 不更新数据库状态，等待回调函数处理
                        // 防止任务被标记为完成而导致下游任务抢跑
                    } elseif ($is_success) {
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
                    // 确保清除当前处理任务标记
                    delete_option('content_auto_current_processing_job');
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
        // 按任务类型和状态分组统计
        $results = $wpdb->get_results(
            "SELECT job_type, status, COUNT(*) as count FROM $table_name GROUP BY job_type, status",
            ARRAY_A
        );

        $base_status = array(
            CONTENT_AUTO_STATUS_PENDING => 0,
            CONTENT_AUTO_STATUS_PROCESSING => 0,
            CONTENT_AUTO_STATUS_COMPLETED => 0,
            CONTENT_AUTO_STATUS_FAILED => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0
        );

        $status = $base_status;
        $status['by_type'] = array();

        foreach ($results as $row) {
            $type = $row['job_type'];
            $st = $row['status'];
            $count = intval($row['count']);

            // 初始化类型统计
            if (!isset($status['by_type'][$type])) {
                $status['by_type'][$type] = $base_status;
                // 清除by_type递归，避免无限循环（虽然这里是纯数组，不需要）
                unset($status['by_type'][$type]['by_type']);
            }

            // 更新类型统计
            // 注意：$st 通常就是 'pending', 'processing' 等字符串，与数组键名一致
            // 所以只需要更新一次，不需要再额外判断常量
            if (isset($status['by_type'][$type][$st])) {
                $status['by_type'][$type][$st] += $count;
            }
            
            $status['by_type'][$type]['total'] += $count;

            // 更新全局统计
            if (isset($status[$st])) {
                $status[$st] += $count;
            }
        }

        // 更新全局字符串键
        $status['pending'] = isset($status[CONTENT_AUTO_STATUS_PENDING]) ? $status[CONTENT_AUTO_STATUS_PENDING] : 0;
        $status['processing'] = isset($status[CONTENT_AUTO_STATUS_PROCESSING]) ? $status[CONTENT_AUTO_STATUS_PROCESSING] : 0;
        $status['completed'] = isset($status[CONTENT_AUTO_STATUS_COMPLETED]) ? $status[CONTENT_AUTO_STATUS_COMPLETED] : 0;
        $status['failed'] = isset($status[CONTENT_AUTO_STATUS_FAILED]) ? $status[CONTENT_AUTO_STATUS_FAILED] : 0;
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
     * 处理素材搜索任务
     */
    private function process_material_search($job) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 统一语义：reference_id 存储主题 ID
        $topic_id = !empty($job['reference_id']) ? $job['reference_id'] : $job['job_id'];
        
        // 1. 再次验证开关状态
        $db = new ContentAuto_Database();
        $publish_rules = $db->get_row('content_auto_publish_rules', array('id' => 1));
        
        if (empty($publish_rules['enable_reference_material'])) {
            return ['success' => false, 'message' => '素材搜索功能已关闭'];
        }
        
        // 2. 验证主题状态
        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$topics_table} WHERE id = %d",
            $topic_id
        ), ARRAY_A);
        
        if (!$topic) {
            return ['success' => false, 'message' => '主题不存在'];
        }
        
        // 2.5 检查主题来源的规则类型
        // 如果是 collect_url_rewrite 规则生成的主题，跳过素材搜索（网页采集已提供参考资料）
        if (!empty($topic['rule_id'])) {
            $rules_table = $wpdb->prefix . 'content_auto_rules';
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT rule_type FROM {$rules_table} WHERE id = %d",
                $topic['rule_id']
            ));
            
            if ($rule && $rule->rule_type === 'collect_url_rewrite') {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
                $logger = new ContentAuto_LoggingSystem();
                $logger->log_info('MATERIAL_SEARCH_SKIP', '跳过素材搜索：主题来源于采集网址仿写规则', [
                    'topic_id' => $topic_id,
                    'rule_id' => $topic['rule_id'],
                    'rule_type' => $rule->rule_type
                ]);
                
                // 直接标记为完成
                $wpdb->update($topics_table, [
                    'material_search_status' => 'completed'
                ], ['id' => $topic_id]);
                
                return ['success' => true, 'message' => '采集网址仿写规则无需素材搜索'];
            }
        }
        
        // 如果主题已有参考资料，直接完成
        if (!empty($topic['reference_material'])) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'completed'
            ], ['id' => $topic_id]);
            
            return ['success' => true, 'message' => '主题已有参考资料'];
        }
        
        // 3. 获取搜集模式（从数据库字段读取）
        $mode = !empty($publish_rules['material_collection_mode']) ? $publish_rules['material_collection_mode'] : 'none';
        // 迁移逻辑：如果字段尚未存在但旧开关开启，默认为 search_engine
        if ($mode === 'none' && !empty($publish_rules['enable_auto_material_search'])) {
             $mode = 'search_engine';
        }
        
        // 添加日志：记录实际使用的搜索模式
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
        $logger = new ContentAuto_LoggingSystem();
        $logger->log_success('MATERIAL_SEARCH_EXECUTE', '素材搜索任务执行', array(
            'topic_id' => $topic_id,
            'job_id' => $job['id'],
            'material_collection_mode_raw' => $publish_rules['material_collection_mode'] ?? 'null',
            'enable_auto_material_search' => $publish_rules['enable_auto_material_search'] ?? 'null',
            'final_mode' => $mode
        ));

        if ($mode === 'none') {
             $logger->log_warning('MATERIAL_SEARCH_SKIP', '自动收集已关闭');
             return ['success' => true, 'message' => '自动收集已关闭'];
        }

        // 4. 标记为处理中
        $wpdb->update($topics_table, [
            'material_search_status' => 'processing'
        ], ['id' => $topic_id]);
        
        $logger->log_success('MATERIAL_SEARCH_MODE', "开始使用 {$mode} 模式搜索素材", array(
            'topic_id' => $topic_id,
            'topic_title' => $topic['title']
        ));
        // 5. 分发任务
        if ($mode === 'extension_rag') {
             // --- 插件收集模式 ---
             // --- 插件收集模式 ---
             // [Fix] Removed Task_Controller require to avoid crash. Direct Database Write only.

             
             $query = $topic['title'];
             // 可选：如果主题有提取好的关键词，也可以拼接到 query
             
             $payload = array(
                 'topic_id' => $topic_id,
                 'job_id' => $job['id'], // 传入 Job ID 以便回调时闭环
                 'query' => $query,
                 'action' => 'knowledge_search' 
             );
             
             //$task_controller = new \ContentAutoManager\RestApi\Controllers\Task_Controller('content-auto-manager/v1');
             //$task_controller->add_task('knowledge_search', $payload);
             
             // [CRITICAL FIX] Bypass Controller, Write Directly to DB
             $queue_key = 'cam_extension_task_queue';
             $queue = get_option($queue_key, array());
             $tid = wp_generate_uuid4();
             
             // 清理旧任务（防止膨胀）
             if (count($queue) > 50) {
                 $queue = array_slice($queue, -20, null, true);
             }
             
             $queue[$tid] = array(
                 'id' => $tid,
                 'type' => 'knowledge_search',
                 'payload' => $payload,
                 'status' => 'pending',
                 'created_at' => time()
             );
             
             $saved = update_option($queue_key, $queue);
             
             // 强制记日志
             if ($this->logger) {
                 $this->logger->log_info('EXTENSION_QUEUE_DIRECT__V2', "已强制写入插件队列(直连模式)", array(
                     'task_id' => $tid,
                     'queue_size' => count($queue),
                     'save_success' => $saved ? 'YES' : 'NO'
                 ));
             }
             
             // 更新主题状态为等待插件
             $wpdb->update($topics_table, [
                'material_search_status' => 'waiting_for_extension'
             ], ['id' => $topic_id]);
             
             // 返回 waiting 状态，让队列保持 processing，直到回调触发
             return ['success' => true, 'status' => 'waiting', 'message' => '已分发至插件队列，等待异步回调'];
             
        } else {
            // --- 搜索引擎模式 (原逻辑) ---
            if (!class_exists('ContentAuto_SearchMaterialsService')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'search-materials/class-search-materials-service.php';
            }
            
            $service = new ContentAuto_SearchMaterialsService();
            $result = $service->execute_full_auto_search($topic_id);
            
            // 5. 更新结果状态
            if (is_wp_error($result)) {
                $wpdb->update($topics_table, [
                    'material_search_status' => 'failed',
                    'material_search_error' => $result->get_error_message()
                ], ['id' => $topic_id]);
                
                return ['success' => false, 'message' => $result->get_error_message()];
            } else {
                // 双重验证
                $verify_topic = $wpdb->get_row($wpdb->prepare("SELECT reference_material FROM {$topics_table} WHERE id = %d", $topic_id), ARRAY_A);
                
                if (empty($verify_topic['reference_material'])) {
                    $wpdb->update($topics_table, [
                        'material_search_status' => 'failed',
                        'material_search_error' => '未知错误：搜索流程返回成功，但数据库中未检测到参考资料'
                    ], ['id' => $topic_id]);
                    
                    return ['success' => false, 'message' => '资料保存验证失败'];
                }
                
                $wpdb->update($topics_table, [
                    'material_search_status' => 'completed',
                    'material_search_error' => ''
                ], ['id' => $topic_id]);
                
                return ['success' => true];
            }
        }
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