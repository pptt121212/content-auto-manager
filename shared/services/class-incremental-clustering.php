<?php
/**
 * Handles the frequent, lightweight, incremental assignment of new vectors to existing clusters.
 */
if (!defined('ABSPATH')) exit;

class ContentAuto_IncrementalClustering {

    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action('init', [$this, 'schedule_incremental_assignment']);
        add_action('content_auto_incremental_assignment_event', [$this, 'execute_incremental_assignment']);
    }

    public function schedule_incremental_assignment() {
        if (!wp_next_scheduled('content_auto_incremental_assignment_event')) {
            wp_schedule_event(time(), 'every_five_minutes', 'content_auto_incremental_assignment_event');
        }
    }

    public function add_cron_intervals($schedules) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => esc_html__('Every 5 Minutes'),
        );
        return $schedules;
    }

    public function execute_incremental_assignment() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

        // 1. Check for Golden Centroids. If they don't exist, do nothing.
        $centroids = get_option('content_auto_vector_centroids');
        if (empty($centroids) || !is_array($centroids)) {
            return; // Cannot assign without centroids.
        }

        // 2. Find new topics that need a cluster ID (limit to a batch to avoid timeouts).
        $topics_to_assign = $wpdb->get_results($wpdb->prepare(
            "SELECT id, vector_embedding FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != '' AND vector_cluster_id IS NULL LIMIT %d",
            100 // Process up to 100 new vectors per run.
        ));

        if (empty($topics_to_assign)) {
            return;
        }

        // 确保包含必要的函数
        if (!function_exists('content_auto_decompress_vector_from_base64')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/functions.php';
        }

        // 3. Assign each new vector to the closest centroid.
        foreach ($topics_to_assign as $topic) {
            $vector = content_auto_decompress_vector_from_base64($topic->vector_embedding);
            if (!$vector) continue;

            $closest_cluster_id = $this->find_closest_centroid($vector, $centroids);

            if ($closest_cluster_id !== -1) {
                $wpdb->update(
                    $topics_table,
                    ['vector_cluster_id' => $closest_cluster_id],
                    ['id' => $topic->id]
                );
            }
        }
    }

    /**
     * Finds the index of the closest centroid to a given vector using cosine distance.
     */
    private function find_closest_centroid(array $vector, array $centroids) {
        $min_distance = INF;
        $closest_centroid_index = -1;

        // 预计算查询向量的模长
        $vector_magnitude = $this->calculate_magnitude($vector);

        foreach ($centroids as $index => $centroid) {
            $distance = $this->calculate_cosine_distance($vector, $centroid, $vector_magnitude);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $closest_centroid_index = $index;
            }
        }

        return $closest_centroid_index;
    }

    /**
     * 计算向量的模长
     */
    private function calculate_magnitude(array $vector) {
        $magnitude = 0.0;
        foreach ($vector as $component) {
            $magnitude += $component * $component;
        }
        return sqrt($magnitude);
    }

    /**
     * 计算余弦距离
     */
    private function calculate_cosine_distance(array $vec1, array $vec2, $vec1_magnitude = null) {
        $dot_product = 0.0;
        $dimensions = count($vec1);
        
        if ($dimensions !== count($vec2)) return 1.0; // 最大距离
        
        for ($i = 0; $i < $dimensions; $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
        }
        
        $vec1_mag = $vec1_magnitude ?? $this->calculate_magnitude($vec1);
        $vec2_mag = $this->calculate_magnitude($vec2);
        
        if ($vec1_mag == 0.0 || $vec2_mag == 0.0) {
            return 1.0; // 最大距离
        }
        
        $similarity = $dot_product / ($vec1_mag * $vec2_mag);
        return 1.0 - $similarity; // 转换为距离
    }
}
