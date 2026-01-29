<?php
/**
 * 任务队列清理器
 * 
 * 统一管理 DB 队列 (job_queue) 和 Option 队列 (cam_extension_task_queue) 的清理逻辑
 * 确保两边的状态始终保持同步
 * 
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TaskQueueCleaner {
    
    const OPTION_QUEUE_KEY = 'cam_extension_task_queue';
    
    private $logger;
    
    public function __construct() {
        // 显式加载 PluginLogger 类（如果尚未加载）
        // 使用相对路径，避免依赖可能未定义的常量
        if (!class_exists('ContentAuto_PluginLogger')) {
            $logger_file = dirname(dirname(__FILE__)) . '/logging/class-plugin-logger.php';
            if (file_exists($logger_file)) {
                require_once $logger_file;
            }
        }
        
        if (class_exists('ContentAuto_PluginLogger')) {
            $this->logger = new ContentAuto_PluginLogger();
        }
    }
    
    /**
     * 根据主任务 ID 清理所有相关的队列项
     * 
     * 调用场景：用户删除主题任务时
     * 
     * @param int $task_id 主任务的数字 ID
     * @param string $task_type 任务类型 ('topic_task' 或 'article_task')
     * @return array 清理统计
     */
    public function cleanup_by_task_id($task_id, $task_type = 'topic_task') {
        global $wpdb;
        
        $stats = array(
            'db_queue_deleted' => 0,
            'option_queue_deleted' => 0
        );
        
        // 1. 清理数据库队列 (job_queue)
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $deleted_db = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$queue_table} WHERE job_type = %s AND job_id = %d AND status != 'completed'",
            $task_type,
            $task_id
        ));
        $stats['db_queue_deleted'] = ($deleted_db !== false) ? $deleted_db : 0;
        
        // 2. 清理 Option 队列 (用于 knowledge_search 等)
        $stats['option_queue_deleted'] = $this->cleanup_option_queue_by_task_id($task_id);
        
        // 3. 记录日志
        if ($stats['db_queue_deleted'] > 0 || $stats['option_queue_deleted'] > 0) {
            $this->log('QUEUE_CLEANUP_BY_TASK', '按任务ID清理队列', array(
                'task_id' => $task_id,
                'task_type' => $task_type,
                'stats' => $stats
            ));
        }
        
        return $stats;
    }
    
    /**
     * 根据任务ID清理 Option 队列
     * 
     * @param int $task_id 主任务的数字 ID
     * @return int 清理的记录数
     */
    private function cleanup_option_queue_by_task_id($task_id) {
        $queue = get_option(self::OPTION_QUEUE_KEY, array());
        $original_count = count($queue);
        
        $queue = array_filter($queue, function($queue_task) use ($task_id) {
            if (isset($queue_task['payload'])) {
                $payload = $queue_task['payload'];
                // 匹配 job_id 或 topic_id
                if ((isset($payload['job_id']) && $payload['job_id'] == $task_id) ||
                    (isset($payload['topic_id']) && $payload['topic_id'] == $task_id)) {
                    return false; // 移除
                }
            }
            return true; // 保留
        });
        
        $deleted_count = $original_count - count($queue);
        
        if ($deleted_count > 0) {
            update_option(self::OPTION_QUEUE_KEY, $queue);
        }
        
        return $deleted_count;
    }
    
    /**
     * 清理所有已完成的任务
     * 
     * 调用场景：定期维护、手动清理
     * 
     * @return array 清理统计
     */
    public function cleanup_completed_tasks() {
        $stats = array(
            'db_queue_deleted' => 0,
            'option_queue_deleted' => 0
        );
        
        // 1. 清理 Option 队列中的非 pending 任务
        $stats['option_queue_deleted'] = $this->cleanup_option_queue_non_pending();
        
        // 2. DB队列的清理由 JobQueue::cleanup_completed_jobs() 处理
        // 这里不重复，保持单一职责
        
        return $stats;
    }
    
    /**
     * 清理 Option 队列中的非 pending 任务
     * 
     * @return int 清理的记录数
     */
    public function cleanup_option_queue_non_pending() {
        $queue = get_option(self::OPTION_QUEUE_KEY, array());
        $original_count = count($queue);
        
        $queue = array_filter($queue, function($task) {
            return isset($task['status']) && $task['status'] === 'pending';
        });
        
        $deleted_count = $original_count - count($queue);
        
        if ($deleted_count > 0) {
            update_option(self::OPTION_QUEUE_KEY, $queue);
        }
        
        return $deleted_count;
    }
    
    /**
     * 清理孤儿任务（父任务已不存在或已结束）
     * 
     * 调用场景：API轮询时、定期维护
     * 
     * @return array 清理统计
     */
    public function cleanup_orphaned_tasks() {
        global $wpdb;
        
        $stats = array(
            'db_queue_marked_failed' => 0,
            'option_queue_deleted' => 0
        );
        
        // 1. 标记 DB 队列中的孤儿任务为 failed
        // 改进：允许 parent 任务为 'completed' 状态时保留其 subtasks 采集任务一段时间，仅在 parent 彻底被删除或标记失败时清理
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        $marked = $wpdb->query(
            "UPDATE {$job_queue_table} q
             LEFT JOIN {$topic_tasks_table} tt ON (q.job_type = 'topic_task' AND q.job_id = tt.id)
             LEFT JOIN {$article_tasks_table} at ON (q.job_type = 'article' AND q.job_id = at.id)
             SET q.status = 'failed', 
                 q.error_message = '自动清理: 父任务状态不再支持执行此异步操作', 
                 q.updated_at = NOW()
             WHERE q.status = 'waiting_browser'
             AND (
                -- 情景 A：父任务已被物理删除
                (q.job_type = 'topic_task' AND tt.id IS NULL)
                OR (q.job_type = 'article' AND at.id IS NULL)
                OR 
                -- 情景 B：父任务已取消或失败 (通用清理)
                (tt.status IN ('failed', 'cancelled'))
                OR (at.status IN ('failed', 'cancelled'))
                OR
                -- 情景 C：大批量采集任务 (article 类型) 的激进清理
                -- 如果是采集任务，父任务如果步入 'completed' 状态，说明采集已不再需要或已过时
                (q.job_type = 'article' AND at.status = 'completed')
             )"
        );
        $stats['db_queue_marked_failed'] = ($marked !== false) ? $marked : 0;
        
        // 2. 清理 Option 队列中对应的孤儿任务
        $stats['option_queue_deleted'] = $this->cleanup_option_queue_orphans();
        
        // 3. 记录日志
        if ($stats['db_queue_marked_failed'] > 0 || $stats['option_queue_deleted'] > 0) {
            $this->log('ORPHAN_CLEANUP', '清理孤儿任务', $stats);
        }
        
        return $stats;
    }
    
    /**
     * 清理 Option 队列中的孤儿任务
     * 匹配条件：关联的主任务已不存在或状态不活跃
     * 
     * @return int 清理的记录数
     */
    private function cleanup_option_queue_orphans() {
        global $wpdb;
        
        $queue = get_option(self::OPTION_QUEUE_KEY, array());
        if (empty($queue)) {
            return 0;
        }

        $original_count = count($queue);
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        $queue = array_filter($queue, function($task) use ($wpdb, $topics_table, $job_queue_table) {
            // 只清理 pending 状态的任务。已完成的留待定期清理
            if (!isset($task['status']) || $task['status'] !== 'pending') {
                return true; 
            }
            
            if (isset($task['payload'])) {
                $payload = $task['payload'];
                
                // 1. 验证主题 (topic_id) 是否存在且有效
                if (isset($payload['topic_id'])) {
                    $topic_id = intval($payload['topic_id']);
                    $topic_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$topics_table} WHERE id = %d",
                        $topic_id
                    ));
                    
                    if (!$topic_exists) return false; // 主题没了，任务作废

                    // 如果是知识搜索，且该主题已有资料，则此任务冗余
                    if ($task['type'] === 'knowledge_search') {
                        $has_material = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$topics_table} WHERE id = %d AND reference_material IS NOT NULL AND reference_material != ''",
                            $topic_id
                        ));
                        if ($has_material > 0) return false;
                    }
                }
                
                // 2. 验证 Job Queue 记录 (job_id) 是否还活着
                if (isset($payload['job_id'])) {
                    $job_id = intval($payload['job_id']);
                    $job_status = $wpdb->get_var($wpdb->prepare(
                        "SELECT status FROM {$job_queue_table} WHERE id = %d",
                        $job_id
                    ));
                    
                    // 如果 Job 被标记为失败、取消或已物理删除，则清理插件端任务
                    if (!$job_status || in_array($job_status, ['failed', 'cancelled'])) {
                        return false;
                    }
                }
            }
            
            return true;
        });
        
        $deleted_count = $original_count - count($queue);
        if ($deleted_count > 0) {
            update_option(self::OPTION_QUEUE_KEY, $queue);
        }
        
        return $deleted_count;
    }
    
    /**
     * 从 Option 队列中移除指定的任务（通过任务唯一 ID）
     * 
     * 调用场景：任务完成时
     * 
     * @param string $task_unique_id 任务的唯一标识符（如 UUID 或 fetch_xxx）
     * @return bool 是否成功移除
     */
    public function remove_from_option_queue($task_unique_id) {
        $queue = get_option(self::OPTION_QUEUE_KEY, array());
        
        if (isset($queue[$task_unique_id])) {
            unset($queue[$task_unique_id]);
            update_option(self::OPTION_QUEUE_KEY, $queue);
            return true;
        }
        
        return false;
    }
    
    /**
     * 标记 Option 队列中的任务为完成
     * 
     * 调用场景：任务处理完成后（但不立即删除，留待下次清理）
     * 
     * @param string $task_unique_id 任务的唯一标识符
     * @return bool 是否成功标记
     */
    public function mark_option_task_completed($task_unique_id) {
        $queue = get_option(self::OPTION_QUEUE_KEY, array());
        
        if (isset($queue[$task_unique_id])) {
            $queue[$task_unique_id]['status'] = 'completed';
            $queue[$task_unique_id]['completed_at'] = time();
            update_option(self::OPTION_QUEUE_KEY, $queue);
            return true;
        }
        
        return false;
    }
    
    /**
     * 记录日志
     */
    private function log($code, $message, $context = array()) {
        if ($this->logger) {
            // 如果是 LoggingSystem (或者拥有 log_info 方法的兼容类)
            if (method_exists($this->logger, 'log_info')) {
                $this->logger->log_info($code, $message, $context);
            } 
            // 如果是 PluginLogger (只有 info 方法)
            else {
                $full_message = "[{$code}] {$message}";
                $this->logger->info($full_message, $context);
            }
        }
    }
}
