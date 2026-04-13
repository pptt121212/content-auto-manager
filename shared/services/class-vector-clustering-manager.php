<?php
/**
 * Manages the automatic, scheduled clustering of vectors.
 */
if (!defined('ABSPATH')) exit;

class Yali_AI_Writer_VectorClusteringManager {

    const CLUSTERING_THRESHOLD = 100; // Minimum number of un-clustered vectors to trigger a new clustering process.
    const CLUSTERING_LOCK_TRANSIENT = 'yali_ai_writer_clustering_lock';

    public function __construct() {
        // Add the custom cron schedule and schedule the event.
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action('init', [$this, 'schedule_clustering_check']);
        add_action('yali_ai_writer_clustering_check_event', [$this, 'check_and_trigger_clustering']);
    }

    public function schedule_clustering_check() {
        if (!wp_next_scheduled('yali_ai_writer_clustering_check_event')) {
            wp_schedule_event(time(), 'hourly', 'yali_ai_writer_clustering_check_event');
        }
    }

    public function add_cron_intervals($schedules) {
        $schedules['hourly'] = array(
            'interval' => 3600,
            'display'  => 'Once Hourly',
        );
        return $schedules;
    }

    public function check_and_trigger_clustering() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';

        // 1. Check if a clustering process is already running.
        if (get_transient(self::CLUSTERING_LOCK_TRANSIENT)) {
            return; // Process is locked.
        }

        // 2. Check for the number of un-clustered vectors.
        $unclustered_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != '' AND vector_cluster_id IS NULL"
        );

        if ($unclustered_count >= self::CLUSTERING_THRESHOLD) {
            $this->execute_clustering();
        }
    }

    private function execute_clustering() {
        $logger = new Yali_AI_Writer_PluginLogger();
        $logger->info('Starting automatic vector clustering process via async state machine...');

        // 初始化状态
        update_option('yali_ai_writer_clustering_status', array(
            'status' => 'running',
            'has_error' => false,
            'progress_message' => __('初始化增量聚类任务(定时触发)...', 'yali-ai-writer') . "\n",
            'start_time' => current_time('mysql'),
            'completed_time' => null
        ));

        // 清理老旧转轮机器状态
        delete_option('yali_ai_writer_clustering_state');

        // 发射内部起步令牌
        $internal_token = wp_generate_password(32, false);
        set_transient('yali_ai_writer_clustering_internal_token', $internal_token, 120);
        
        // 大锁护体
        set_transient(self::CLUSTERING_LOCK_TRANSIENT, true, HOUR_IN_SECONDS * 2);
        
        $url = admin_url('admin-ajax.php');
        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => array(
                'action' => 'execute_vector_clustering',
                'internal_token' => $internal_token
            )
        );
        wp_remote_post($url, $args);
    }
}
