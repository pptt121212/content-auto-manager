<?php
if (!defined('ABSPATH')) exit;

/**
 * Adds an admin page to manually trigger the vector clustering process.
 */
class ContentAuto_ClusteringAdminPage {

    public function __construct() {
        // 菜单注册已移至 ContentAuto_AdminMenu 类统一管理
    }

    public function render_page() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $vector_count = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");
        $num_clusters = max(2, min(100, floor($vector_count / 100)));

        echo '<div class="wrap">'
           . '<h1>' . __('向量聚类管理', 'content-auto-manager') . '</h1>'
           . '<p>' . __('此工具对所有已有向量的主题执行一次完整的K-Means聚类计算。它会为整个数据集计算并保存一组新的“黄金中心点”，并为每个主题分配一个簇ID。建议在拥有大量（如数千个）向量后执行此操作。后续新增的向量将由后台任务自动分配到最近的簇中。 ', 'content-auto-manager') . '</p>'
           . '<p><strong>' . __('用途:', 'content-auto-manager') . '</strong><br>1. ' . __('当您积累了足够多的向量（例如超过1000个）后，进行第一次“冷启动”训练。', 'content-auto-manager') . '<br>2. ' . __('定期（例如每隔几周或几个月）使用更新的数据重新校准中心点，以提高整体搜索精度。', 'content-auto-manager') . '</p>'
           . '<p><strong style="color: red;">' . __('警告：这是一个消耗大量资源的操作，可能需要几分钟才能运行完毕。在操作完成前，请不要关闭此窗口。强烈建议您在运行前备份数据库。', 'content-auto-manager') . '</strong></p>';

        // Handle the form submission
        if (isset($_POST['start_clustering'])) {
            if (!isset($_POST['clustering_nonce']) || !wp_verify_nonce($_POST['clustering_nonce'], 'start_clustering_action')) {
                wp_die('安全验证失败!');
            }
            // Pass the auto-calculated number of clusters to the handler
            $this->handle_clustering_process($num_clusters);
        }

        // Handle the similarity search form submission
        if (isset($_POST['find_similar_titles'])) {
            if (!isset($_POST['similarity_nonce']) || !wp_verify_nonce($_POST['similarity_nonce'], 'find_similar_titles_action')) {
                wp_die('安全验证失败!');
            }
            $this->handle_similarity_search();
        }

        echo '<div style="border: 1px solid #ccc; padding: 15px; margin-top: 20px; background: #fff;">'
           . '<h3>' . __('聚类操作', 'content-auto-manager') . '</h3>'
           . '<p>' . sprintf(__('当前数据库中共有 <strong>%d</strong> 个向量。根据推荐算法（每100个向量约1个簇），将自动创建 <strong>%d</strong> 个聚类中心。', 'content-auto-manager'), $vector_count, $num_clusters) . '</p>'
           . '<form method="post">'
           . wp_nonce_field('start_clustering_action', 'clustering_nonce', true, false)
           . '<input type="submit" name="start_clustering" class="button button-primary" value="' . __('开始生成/重新校准所有聚类', 'content-auto-manager') . '" onclick="return confirm(\'' . __('这是一个高消耗操作，可能需要很长时间。确定要开始吗？', 'content-auto-manager') . '\');" />'
           . '</form></div>';
        
        // Add the similarity search form
        echo '<div style="border: 1px solid #ccc; padding: 15px; margin-top: 20px; background: #fff;">'
           . '<h3>' . __('相似标题调试工具', 'content-auto-manager') . '</h3>'
           . '<p>' . __('输入一个已有文章的ID，使用聚类筛选后计算余弦相似度，获取最相似的20个标题。这是一个重要的调试功能，可以帮助您评估算法有效性。', 'content-auto-manager') . '</p>'
           . '<form method="post">'
           . wp_nonce_field('find_similar_titles_action', 'similarity_nonce', true, false)
           . '<table class="form-table">'
           . '<tr>'
           . '<th scope="row"><label for="topic_id">' . __('文章ID', 'content-auto-manager') . '</label></th>'
           . '<td><input type="number" id="topic_id" name="topic_id" class="regular-text" min="1" required />'
           . '<p class="description">' . __('输入要查找相似标题的文章ID', 'content-auto-manager') . '</p></td>'
           . '</tr>'
           . '</table>'
           . '<input type="submit" name="find_similar_titles" class="button button-secondary" value="' . __('查找相似标题', 'content-auto-manager') . '" />'
           . '</form></div>';
        
        echo '</div>';
    }

    private function handle_clustering_process($num_clusters) {
        // 设置更长的超时时间和更多内存
        set_time_limit(7200); // 2小时
        ini_set('memory_limit', '1024M'); // 1GB内存
        
        echo '<div style="font-family: monospace; background: #f1f1f1; padding: 15px; border: 1px solid #ccc; max-height: 500px; overflow-y: scroll; margin-top: 20px;">';
        echo __('开始完整聚类流程...', 'content-auto-manager') . '<br>';
        echo sprintf(__('资源限制：内存 %s，执行时间 %d 分钟', 'content-auto-manager'), ini_get('memory_limit'), 120) . '<br>';
        flush();

        require_once(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/content-processing/class-vector-clustering.php');

        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

        // 1. Fetch all vectors
        echo __('步骤1：从数据库获取所有向量...', 'content-auto-manager') . '<br>';
        flush();
        $topics = $wpdb->get_results("SELECT id, vector_embedding FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");
        
        if (empty($topics)) {
            echo __('错误：未找到可供聚类的向量。', 'content-auto-manager') . '<br></div>';
            return;
        }
        echo sprintf(__('找到了 %d 个向量。', 'content-auto-manager'), count($topics)) . '<br>';
        flush();

        // 2. Decode all vectors
        echo __('步骤2：解码所有Base64向量...', 'content-auto-manager') . '<br>';
        flush();
        $vectors = [];
        $topic_map = []; // Map vector index back to topic ID
        foreach ($topics as $index => $topic) {
            $decoded_vector = content_auto_decompress_vector_from_base64($topic->vector_embedding);
            if ($decoded_vector) {
                $vectors[] = $decoded_vector;
                $topic_map[$index] = $topic->id;
            }
        }
        echo sprintf(__('成功解码 %d 个向量。', 'content-auto-manager'), count($vectors)) . '<br>';
        flush();

        // 3. Run K-Means Clustering
        if ($num_clusters <= 1) $num_clusters = 2;
        echo sprintf(__('步骤3：为 %d 个聚类中心开始K-Means聚类（使用余弦距离）...', 'content-auto-manager'), $num_clusters) . '<br>';
        echo __('注意：现在使用余弦距离进行聚类，这将提高相似度搜索的准确性。', 'content-auto-manager') . '<br>';
        
        $start_memory = memory_get_usage(true);
        $start_time = time();
        echo sprintf(__('开始时内存使用：%s MB', 'content-auto-manager'), round($start_memory / 1024 / 1024, 2)) . '<br>';
        flush();

        // 使用更长的超时时间（30分钟）
        $clustering = new ContentAuto_VectorClustering($num_clusters, 50, 1800);
        $result = $clustering->cluster($vectors);

        if ($result === false) {
            echo __('错误：聚类失败。向量数量不足以满足请求的聚类中心数量。', 'content-auto-manager') . '<br></div>';
            return;
        }
        
        $end_memory = memory_get_usage(true);
        $end_time = time();
        echo sprintf(__('聚类计算完成。用时：%d 秒，内存峰值：%s MB', 'content-auto-manager'), 
                    ($end_time - $start_time), 
                    round($end_memory / 1024 / 1024, 2)) . '<br>';
        flush();

        // 4. Update database with cluster IDs
        echo __('步骤4：使用新的簇ID更新主题...', 'content-auto-manager') . '<br>';
        flush();
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
        echo sprintf(__('更新了 %d 个主题。' , 'content-auto-manager'), $updated_count) . '<br>';
        flush();

        // 5. Save centroids to wp_options
        echo __('步骤5：保存新的“黄金中心点”...', 'content-auto-manager') . '<br>';
        flush();
        update_option('content_auto_vector_centroids', $result['centroids']);
        echo __('中心点已保存。', 'content-auto-manager') . '<br>';
        flush();

        echo '<strong style="color: green;">' . __('流程成功结束！', 'content-auto-manager') . '</strong><br>';
        echo '</div>';
    }

    /**
     * Handle similarity search form submission
     */
    private function handle_similarity_search() {
        // Check if required data is provided
        if (!isset($_POST['topic_id']) || empty($_POST['topic_id'])) {
            echo '<div class="notice notice-error"><p>' . __('请提供有效的文章ID。', 'content-auto-manager') . '</p></div>';
            return;
        }

        $topic_id = intval($_POST['topic_id']);
        
        // Validate that the topic exists
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $topic = $wpdb->get_row($wpdb->prepare("SELECT id, title FROM {$topics_table} WHERE id = %d", $topic_id));
        
        if (!$topic) {
            echo '<div class="notice notice-error"><p>' . sprintf(__('未找到ID为 %d 的文章。', 'content-auto-manager'), $topic_id) . '</p></div>';
            return;
        }

        echo '<div style="font-family: monospace; background: #f1f1f1; padding: 15px; border: 1px solid #ccc; max-height: 500px; overflow-y: scroll; margin-top: 20px;">';
        echo sprintf(__('开始查找与 "%s" 相似的标题...', 'content-auto-manager'), esc_html($topic->title)) . '<br>';
        flush();

        // Call the similarity function to find similar titles
        $similar_titles = content_auto_find_similar_titles($topic_id, 20); // Get top 20 similar titles

        if (empty($similar_titles)) {
            echo __('未找到相似的标题。请确保已执行聚类操作。', 'content-auto-manager') . '<br>';
        } else {
            echo sprintf(__('找到 %d 个相似标题：', 'content-auto-manager'), count($similar_titles)) . '<br><br>';
            echo '<table style="width: 100%; border-collapse: collapse;">';
            echo '<thead><tr><th style="text-align: left; border-bottom: 1px solid #000;">排名</th><th style="text-align: left; border-bottom: 1px solid #000;">相似度</th><th style="text-align: left; border-bottom: 1px solid #000;">文章ID</th><th style="text-align: left; border-bottom: 1px solid #000;">标题</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($similar_titles as $index => $similar_title) {
                echo '<tr>';
                echo '<td>' . ($index + 1) . '</td>';
                echo '<td>' . number_format($similar_title['similarity'], 4) . '</td>';
                echo '<td>' . $similar_title['id'] . '</td>';
                echo '<td>' . esc_html($similar_title['title']) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
}
