<?php
/**
 * 文章任务超时处理WP-CLI命令
 */

if (!defined('ABSPATH')) {
    exit;
}

// 确保必要的类被加载
if (!class_exists('ContentAuto_ArticleTaskTimeoutHandler')) {
    require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-article-task-timeout-handler.php';
}

if (defined('WP_CLI') && WP_CLI) {
    
    class ContentAuto_ArticleTimeout_Command extends WP_CLI_Command {
        
        /**
         * 处理超时的文章任务
         *
         * ## OPTIONS
         *
         * [--cleanup]
         * : 同时清理孤立的队列项
         *
         * ## EXAMPLES
         *
         *     wp content-auto handle-timeouts
         *     wp content-auto handle-timeouts --cleanup
         */
        public function handle_timeouts($args, $assoc_args) {
            // 确保文章任务超时处理器类已加载
            if (!class_exists('ContentAuto_ArticleTaskTimeoutHandler')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-article-task-timeout-handler.php';
            }
            
            if (!class_exists('ContentAuto_ArticleTaskTimeoutHandler')) {
                WP_CLI::error('无法加载文章任务超时处理器类');
                return;
            }
            
            $timeout_handler = new ContentAuto_ArticleTaskTimeoutHandler();
            
            // 处理超时任务
            WP_CLI::line('正在处理超时的文章任务子项...');
            $result = $timeout_handler->handle_timeout_tasks();
            
            WP_CLI::success(sprintf(
                '处理完成: 发现%d个超时子项，成功处理%d个，失败%d个',
                $result['total_found'],
                $result['processed'],
                $result['failed']
            ));
            
            // 如果指定了--cleanup参数，清理孤立队列项
            if (isset($assoc_args['cleanup'])) {
                WP_CLI::line('正在清理孤立的队列项...');
                $cleaned_count = $timeout_handler->cleanup_orphaned_queues();
                WP_CLI::success(sprintf('清理完成: 清理了%d个孤立队列项', $cleaned_count));
            }
        }
        
        /**
         * 显示当前处理中的文章任务
         *
         * ## EXAMPLES
         *
         *     wp content-auto show-processing
         */
        public function show_processing($args, $assoc_args) {
            global $wpdb;
            
            $queue_table = $wpdb->prefix . 'content_auto_job_queue';
            $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
            
            // 获取所有处理中的文章任务子项
            $processing_subtasks = $wpdb->get_results(
                "SELECT q.*, at.article_task_id, at.name as task_name, TIMESTAMPDIFF(SECOND, q.updated_at, NOW()) as processing_seconds
                 FROM {$queue_table} q
                 LEFT JOIN {$article_tasks_table} at ON q.job_id = at.id
                 WHERE q.job_type = 'article' 
                 AND q.status = 'processing'
                 ORDER BY q.updated_at ASC",
                ARRAY_A
            );
            
            if (empty($processing_subtasks)) {
                WP_CLI::success('当前没有处理中的文章任务子项');
                return;
            }
            
            WP_CLI::line(sprintf('发现%d个处理中的文章任务子项:', count($processing_subtasks)));
            WP_CLI::line('');
            
            foreach ($processing_subtasks as $subtask) {
                WP_CLI::line(sprintf(
                    "子任务ID: %s
任务名称: %s
处理时间: %d秒
开始时间: %s
",
                    $subtask['subtask_id'],
                    $subtask['task_name'] ?: '未命名任务',
                    $subtask['processing_seconds'],
                    $subtask['updated_at']
                ));
            }
        }
    }
    
    WP_CLI::add_command('content-auto', 'ContentAuto_ArticleTimeout_Command');
}