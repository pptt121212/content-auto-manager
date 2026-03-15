<?php
/**
 * 任务安全哨兵 (Safety Sentinel)
 * 职责：检测后台任务的生命体征，防止孤儿进程（幽灵任务）继续运行
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_SafetySentinel {

    /**
     * 检查当前执行上下文是否仍然有效
     * 
     * @param array $params 环境参数，包含 job_queue_id, task_id, task_type 等
     * @return bool 有效返回 true，已停用/删除返回 false
     */
    public static function is_execution_valid($params = array()) {
        global $wpdb;

        // 1. 如果有 Job Queue ID，最优先级检查队列记录
        if (!empty($params['job_queue_id'])) {
            $queue_table = $wpdb->prefix . 'content_auto_job_queue';
            $status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $queue_table WHERE id = %d",
                $params['job_queue_id']
            ));
            
            // 如果队列项被物理删除，或者状态不再是 processing，说明任务已在外部被终止
            if (!$status || !in_array($status, array('processing', 'waiting_browser', 'waiting'))) {
                return false;
            }
        }

        // 2. 检查具体的业务任务状态
        if (!empty($params['task_id']) && !empty($params['task_type'])) {
            return self::is_task_active($params['task_type'], $params['task_id']);
        }

        return true; // 默认允许继续
    }

    /**
     * 针对特定任务类型的活跃状态检查
     */
    private static function is_task_active($type, $id) {
        global $wpdb;
        $table = '';
        
        switch ($type) {
            case 'topic_task':
            case 'topic_generation':
                $table = $wpdb->prefix . 'content_auto_topic_tasks';
                break;
            case 'article':
            case 'article_task':
            case 'article_generation':
                $table = $wpdb->prefix . 'content_auto_article_tasks';
                break;
            case 'topic':
            case 'topic_item':
            case 'material_search':
                // 单个主题记录或素材搜索
                $table = $wpdb->prefix . 'content_auto_topics';
                break;
        }

        if (!$table) return true;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM $table WHERE id = %d",
            $id
        ));

        // 任务记录不存在（被物理删除）或状态为已暂停/失败，应视为不再活跃
        if (!$status || in_array($status, array('paused', 'failed', 'stopped'))) {
            return false;
        }

        return true;
    }
}
