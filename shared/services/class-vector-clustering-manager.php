<?php
/**
 * Manages the automatic, scheduled clustering of vectors.
 */
if (!defined('ABSPATH')) exit;

class ContentAuto_VectorClusteringManager {

    const CLUSTERING_THRESHOLD = 100; // Minimum number of un-clustered vectors to trigger a new clustering process.
    const CLUSTERING_LOCK_TRANSIENT = 'content_auto_clustering_lock';

    public function __construct() {
        // Add the custom cron schedule and schedule the event.
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
        add_action('init', [$this, 'schedule_clustering_check']);
        add_action('content_auto_clustering_check_event', [$this, 'check_and_trigger_clustering']);
    }

    public function schedule_clustering_check() {
        if (!wp_next_scheduled('content_auto_clustering_check_event')) {
            wp_schedule_event(time(), 'hourly', 'content_auto_clustering_check_event');
        }
    }

    public function add_cron_intervals($schedules) {
        $schedules['hourly'] = array(
            'interval' => 3600,
            'display'  => esc_html__('Once Hourly'),
        );
        return $schedules;
    }

    public function check_and_trigger_clustering() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

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
        // 1. Set a lock to prevent concurrent runs.
        set_transient(self::CLUSTERING_LOCK_TRANSIENT, true, HOUR_IN_SECONDS * 2);
        set_time_limit(3600); // Set to 1 hour

        require_once(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/content-processing/class-vector-clustering.php');
        $logger = new ContentAuto_PluginLogger();

        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

        $logger->info('Starting automatic vector clustering process...');

        // 2. Fetch all vectors (we need the full dataset for accurate clustering).
        $topics = $wpdb->get_results("SELECT id, vector_embedding FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");
        
        if (count($topics) < self::CLUSTERING_THRESHOLD) {
            $logger->warning('Clustering aborted: Not enough vectors to process.');
            delete_transient(self::CLUSTERING_LOCK_TRANSIENT);
            return;
        }

        // 3. Decode vectors.
        $vectors = [];
        $topic_map = []; // Map vector index back to topic ID
        foreach ($topics as $index => $topic) {
            $decoded_vector = content_auto_decompress_vector_from_base64($topic->vector_embedding);
            if ($decoded_vector) {
                $vectors[] = $decoded_vector;
                $topic_map[$index] = $topic->id;
            }
        }

        if (empty($vectors)) {
            $logger->error('Clustering failed: No vectors could be decoded.');
            delete_transient(self::CLUSTERING_LOCK_TRANSIENT);
            return;
        }

        // 4. Run K-Means Clustering.
        // Dynamically determine a reasonable number of clusters.
        $num_clusters = max(2, min(100, floor(count($vectors) / 100)));
        $logger->info("Executing K-Means with {$num_clusters} clusters for " . count($vectors) . " vectors.");

        $clustering = new ContentAuto_VectorClustering($num_clusters);
        $result = $clustering->cluster($vectors);

        if ($result === false) {
            $logger->error('Clustering algorithm failed to execute.');
            delete_transient(self::CLUSTERING_LOCK_TRANSIENT);
            return;
        }

        // 5. Update database with cluster IDs.
        $updated_count = 0;
        foreach ($result['assignments'] as $vector_index => $cluster_id) {
            if (isset($topic_map[$vector_index])) {
                $topic_id = $topic_map[$vector_index];
                $wpdb->update(
                    $topics_table,
                    ['vector_cluster_id' => $cluster_id],
                    ['id' => $topic_id]
                );
                $updated_count++;
            }
        }
        $logger->info("Updated cluster IDs for {$updated_count} topics.");

        // 6. Save centroids to wp_options.
        update_option('content_auto_vector_centroids', $result['centroids']);
        $logger->info('New centroids have been saved.');

        // 7. Release the lock.
        delete_transient(self::CLUSTERING_LOCK_TRANSIENT);
        $logger->info('Automatic vector clustering process finished successfully!');
    }
}
