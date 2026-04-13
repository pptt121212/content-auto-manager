<?php
if (!defined('ABSPATH')) exit;

/**
 * Adds an admin page to manually trigger the vector clustering process.
 */
class Yali_AI_Writer_ClusteringAdminPage {

    public function __construct() {
        // 菜单注册已移至 Yali_AI_Writer_AdminMenu 类统一管理
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function render_page() {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $vector_count = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");
        
        // Remove the 100 max clusters hard cap. Use an adaptable formula based on data size.
        // E.g., 1 cluster per 100 items, up to a reasonable high limit like 1000.
        $num_clusters = max(2, min(1000, floor($vector_count / 100)));

        echo '<div class="wrap yali-plugin-wrapper">'
           . '<h1 class="yali-page-title"><span class="dashicons dashicons-networking"></span> ' . __('向量聚类管理', 'yali-ai-writer') . '</h1>'
           . '<div style="margin-bottom: 20px;">'
           . '<a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary">'
           . '<span class="dashicons dashicons-arrow-left-alt"></span> ' . __('返回发布规则', 'yali-ai-writer')
           . '</a>'
           . '</div>'
           . '<div class="yali-card">'
           . '<div class="yali-card-header"><div class="yali-card-title">' . __('聚类说明', 'yali-ai-writer') . '</div></div>'
           . '<div class="yali-card-body">'
           . '<p>' . __('此工具对所有已有向量的主题执行一次完整的K-Means聚类计算。它会为整个数据集计算并保存一组新的“黄金中心点”，并为每个主题分配一个簇ID。建议在拥有大量（如数千个）向量后执行此操作。后续新增的向量将由后台任务自动分配到最近的簇中。', 'yali-ai-writer') . '</p>'
           . '<div class="yali-notice yali-notice-info">'
           . '<p><strong>' . __('用途:', 'yali-ai-writer') . '</strong><br>1. ' . __('当您积累了足够多的向量（例如超过1000个）后，进行第一次“冷启动”训练。', 'yali-ai-writer') . '<br>2. ' . __('定期（例如每隔几周或几个月）使用更新的数据重新校准中心点，以提高整体搜索精度。', 'yali-ai-writer') . '</p>'
           . '</div>'
           . '<div class="yali-notice yali-notice-warning" style="margin-top: 15px;">'
           . '<p><strong>' . __('警告：这是一个消耗大量资源的操作，会在后台异步运行。在运行期间，请勿进行大量的数据操作。您可以随时关闭此窗口，下次打开会恢复进度显示。', 'yali-ai-writer') . '</strong></p>'
           . '</div>'
           . '</div>'
           . '</div>';

        // 表单处理已移至 admin/form-handlers.php，在 admin_init 钩子中执行

        echo '<div class="yali-card">'
           . '<div class="yali-card-header"><div class="yali-card-title">' . __('聚类操作与状态', 'yali-ai-writer') . '</div></div>'
           . '<div class="yali-card-body">'
           . '<p>' . sprintf(__('当前数据库中共有 <strong>%d</strong> 个向量。根据推荐算法，本次任务将自动创建 <strong>%d</strong> 个聚类中心。', 'yali-ai-writer'), $vector_count, $num_clusters) . '</p>'
           . '<div class="yali-form-group" style="margin-top:20px;">'
           . '<button id="start-clustering-btn" class="yali-btn yali-btn-primary" data-nonce="' . wp_create_nonce('start_clustering_action') . '">' . __('开始生成/重新校准所有聚类', 'yali-ai-writer') . '</button>'
           . '<span id="clustering-status-badge" style="margin-left: 15px; font-weight: bold;"></span>'
           . '</div>'
           . '<div id="clustering-console" class="yali-textarea-code" style="max-height: 500px; overflow-y: scroll; margin-top: 20px; display: none; background: #1e1e1e; color: #d4d4d4; padding: 15px;"></div>'
           . '</div>'
           . '</div>';
        
        // Add the similarity search form
        echo '<div class="yali-card">'
           . '<div class="yali-card-header"><div class="yali-card-title">' . __('相似标题调试工具', 'yali-ai-writer') . '</div></div>'
           . '<div class="yali-card-body">'
           . '<p class="yali-desc">' . __('输入一个已有文章的ID，使用聚类筛选后计算余弦相似度，获取最相似的20个标题。这是一个重要的调试功能，可以帮助您评估算法有效性。', 'yali-ai-writer') . '</p>'
           . '<form method="post">'
           . wp_nonce_field('find_similar_titles_action', 'similarity_nonce', true, false)
           . '<div class="yali-form-group">'
           . '<label for="topic_id" class="yali-form-label">' . __('文章ID', 'yali-ai-writer') . '</label>'
           . '<input type="number" id="topic_id" name="topic_id" class="regular-text yali-input" min="1" required />'
           . '<p class="yali-desc">' . __('输入要查找相似标题的文章ID', 'yali-ai-writer') . '</p>'
           . '</div>'
           . '<div class="yali-card-footer" style="padding-left: 0; border-top: none;">'
           . '<input type="submit" name="find_similar_titles" class="yali-btn yali-btn-secondary" value="' . __('查找相似标题', 'yali-ai-writer') . '" />'
           . '</div>'
           . '</form>'
           . '</div>'
           . '</div>';
        
        echo '</div>'; // wrap

        $this->render_scripts();
    }

    private function render_scripts() {
        // 脚本已通过 wp_enqueue_scripts 加载
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'admin_page_yali-ai-writer-clustering') {
            return;
        }

        wp_enqueue_script(
            'yali-ai-writer-clustering-admin-inline-js',
            YALI_AI_WRITER_PLUGIN_URL . 'admin/assets/js/clustering-admin-inline.js',
            array('jquery', 'wp-i18n'),
            YALI_AI_WRITER_VERSION,
            true
        );
        wp_localize_script('yali-ai-writer-clustering-admin-inline-js', 'clusteringAdminData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('start_clustering_action')
        ));
        wp_set_script_translations('yali-ai-writer-clustering-admin-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
    }

    private function update_status($append_msg, $status_state = 'running', $has_error = false) {
        $status = get_option('yali_ai_writer_clustering_status', array());
        if (!is_array($status)) {
            $status = array(
                'status' => 'running',
                'progress_message' => '',
                'start_time' => current_time('mysql'),
                'completed_time' => null,
                'has_error' => false
            );
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $status['progress_message'] .= "[{$timestamp}] " . $append_msg . "\n";
        $status['status'] = $status_state;
        if ($status_state === 'completed' || $has_error) {
            $status['completed_time'] = current_time('mysql');
        }
        if ($has_error) {
            $status['has_error'] = true;
        }

        update_option('yali_ai_writer_clustering_status', $status);
    }

    public function do_clustering_background() {
        // 设置资源限制，作为流式批处理的一层兜底保护
        set_time_limit(300); // 5分钟
        ini_set('memory_limit', '1024M'); 
        
        try {
            require_once(YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-clustering-manager.php');
            if (!function_exists('yali_ai_writer_decompress_vector_from_base64')) {
                require_once(YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/functions.php');
            }

            global $wpdb;
            $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
            
            $state = get_option('yali_ai_writer_clustering_state');
        
        // --- 阶段 1: 初始化 (INIT) ---
        if (!$state) {
            $this->update_status(__('步骤 1：清理并初始化流式分析状态...', 'yali-ai-writer'));
            
            $vector_count = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");
            
            if ($vector_count == 0 || !$vector_count) {
                $this->update_status(__('错误：未找到可供聚类的向量。', 'yali-ai-writer'), 'error', true);
                delete_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT);
                return;
            }
            
            // 动态计算簇数量
            $num_clusters = max(2, min(1000, ceil($vector_count / 100)));
            $this->update_status(sprintf(__('共检测到 %d 个有效文章向量，即将构建 %d 个聚类中心。', 'yali-ai-writer'), $vector_count, $num_clusters));
            
            // 随机采样初始化黄金中心点
            $this->update_status(__('为应对海量数据，系统正采用随机采样算法初始化聚类中心（确保低内存占用）...', 'yali-ai-writer'));
            $sampled_topics = $wpdb->get_results($wpdb->prepare("SELECT vector_embedding FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != '' ORDER BY RAND() LIMIT %d", $num_clusters));
            
            $centroids = [];
            foreach ($sampled_topics as $topic) {
                $decoded = yali_ai_writer_decompress_vector_from_base64($topic->vector_embedding);
                if ($decoded) {
                    $centroids[] = $decoded;
                }
            }
            
            // 补充不足的中心点 (如果极端情况下合法向量少于 K)
            while (count($centroids) < $num_clusters && count($centroids) > 0) {
                 $centroids[] = $centroids[array_rand($centroids)];
            }
            
            if (empty($centroids)) {
                $this->update_status(__('错误：提取聚类中心参考点失败，未找到有效向量，任务中止。', 'yali-ai-writer'), 'error', true);
                delete_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT);
                return;
            }
            
            update_option('yali_ai_writer_vector_centroids', $centroids);
            
            // 初始化累加器
            $accumulators = [];
            for ($i = 0; $i < $num_clusters; $i++) {
                $accumulators[$i] = ['sum' => [], 'count' => 0];
            }
            
            $state = [
                'phase' => 'batch_process',
                'total' => $vector_count,
                'processed' => 0,
                'epoch' => 1,
                'max_epochs' => 2, // 数据量大时，2个世代足矣收敛大半
                'batch_size' => 500,
                'accumulators' => $accumulators
            ];
            update_option('yali_ai_writer_clustering_state', $state);
            
            $this->update_status(__('初始化完成，即将启动轻量级流式处理引擎。', 'yali-ai-writer'));
            $this->trigger_next_batch();
            return;
        }
        
        $centroids = get_option('yali_ai_writer_vector_centroids');
        
        // --- 阶段 2: 批处理计算归类 (BATCH_PROCESS) ---
        if ($state['phase'] === 'batch_process') {
            $offset = $state['processed'];
            $batch_size = $state['batch_size'];
            
            if ($offset >= $state['total']) {
                $state['phase'] = 'epoch_update';
                update_option('yali_ai_writer_clustering_state', $state);
                $this->trigger_next_batch();
                return;
            }
            
            $percent = $state['total'] > 0 ? min(100, round(($offset / $state['total']) * 100, 2)) : 100;
            $end_record = min($offset + $batch_size, $state['total']);
            // 控制台输出
            $this->update_status(sprintf(__('[迭代 %d/%d] 正在处理第 %d 至 %d 条记录... (进度: %s%%)', 'yali-ai-writer'), $state['epoch'], $state['max_epochs'], $offset, $end_record, $percent));
            
            // 分批拉取数据
            $topics = $wpdb->get_results($wpdb->prepare("SELECT id, vector_embedding FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != '' LIMIT %d OFFSET %d", $batch_size, $offset));
            
            if (!empty($topics)) {
                $updated_count = 0;
                foreach ($topics as $topic) {
                    $vector = yali_ai_writer_decompress_vector_from_base64($topic->vector_embedding);
                    if (!$vector) continue;
                    
                    // 找最近中心点
                    $best_cluster = 0;
                    $max_similarity = -2.0;

                    for ($k = 0; $k < count($centroids); $k++) {
                        $similarity = yali_ai_writer_calculate_cosine_similarity($vector, $centroids[$k]);
                        if ($similarity > $max_similarity) {
                            $max_similarity = $similarity;
                            $best_cluster = $k;
                        }
                    }
                    
                    // 立即更新数据库归属
                    $wpdb->update($topics_table, ['vector_cluster_id' => $best_cluster], ['id' => $topic->id]);
                    $updated_count++;
                    
                    // 累加器累积 (用于之后重新生成中心点)
                    $state['accumulators'][$best_cluster]['count']++;
                    $dim = count($vector);
                    
                    if (empty($state['accumulators'][$best_cluster]['sum'])) {
                        $state['accumulators'][$best_cluster]['sum'] = array_fill(0, $dim, 0.0);
                    }
                    
                    for ($d = 0; $d < $dim; $d++) {
                        $state['accumulators'][$best_cluster]['sum'][$d] += $vector[$d];
                    }
                }
                
                $state['processed'] += count($topics);
                update_option('yali_ai_writer_clustering_state', $state);
                $this->trigger_next_batch();
                return;
            } else {
                // 本世代全表跑完
                $state['phase'] = 'epoch_update';
                update_option('yali_ai_writer_clustering_state', $state);
                $this->trigger_next_batch();
                return;
            }
        }
        
        // --- 阶段 3: 世代交替与中心点更新 (EPOCH_UPDATE) ---
        if ($state['phase'] === 'epoch_update') {
            $this->update_status(sprintf(__('第 %d 轮迭代完成。正在根据聚合结果更新聚类中心...', 'yali-ai-writer'), $state['epoch']));
            
            $new_centroids = [];
            $num_clusters = count($centroids);
            for ($k = 0; $k < $num_clusters; $k++) {
                $count = $state['accumulators'][$k]['count'];
                if ($count > 0 && !empty($state['accumulators'][$k]['sum'])) {
                    $new_centroid = [];
                    $dim = count($state['accumulators'][$k]['sum']);
                    for ($d = 0; $d < $dim; $d++) {
                        $new_centroid[$d] = $state['accumulators'][$k]['sum'][$d] / $count;
                    }
                    $new_centroids[$k] = $new_centroid;
                } else {
                    $new_centroids[$k] = $centroids[$k];
                }
            }
            
            update_option('yali_ai_writer_vector_centroids', $new_centroids);
            $this->update_status(__('聚类中心已成功更新并保存。', 'yali-ai-writer'));
            
            if ($state['epoch'] >= $state['max_epochs']) {
                 $state['phase'] = 'finalize';
                 update_option('yali_ai_writer_clustering_state', $state);
                 $this->trigger_next_batch();
                 return;
            } else {
                 $state['epoch']++;
                 $state['processed'] = 0;
                 $state['phase'] = 'batch_process';
                 for ($k = 0; $k < $num_clusters; $k++) {
                     $state['accumulators'][$k] = ['sum' => [], 'count' => 0];
                 }
                 update_option('yali_ai_writer_clustering_state', $state);
                 $this->update_status(sprintf(__('准备开始下一轮迭代微调：第 %d 轮...', 'yali-ai-writer'), $state['epoch']));
                 $this->trigger_next_batch();
                 return;
            }
        }
        
            // --- 阶段 4: 结束清理 (FINALIZE) ---
            if ($state['phase'] === 'finalize') {
                $this->update_status(__('所有迭代均已完成！正在清理临时运行状态...', 'yali-ai-writer'));
                delete_option('yali_ai_writer_clustering_state');
                delete_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT);
                $this->update_status(__('向量聚类任务成功结束！结果已持久化保存。', 'yali-ai-writer'), 'completed');
                return;
            }
            
        } catch (\Throwable $e) {
            // 全局终极兜底捕获，防止任何 Fatal Error 或未处理异常导致死锁
            error_log('Content Auto Clustering Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $this->update_status(sprintf(__('系统发生严重致命错误，任务被迫强制中止: %s (在 %s 第 %d 行)', 'yali-ai-writer'), $e->getMessage(), basename($e->getFile()), $e->getLine()), 'error', true);
            delete_option('yali_ai_writer_clustering_state');
            
            if (class_exists('Yali_AI_Writer_VectorClusteringManager')) {
                delete_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT);
            }
        }
    }

    /**
     * 流式架构：向后台继续发射令牌请求，链式唤醒自己处理下一批任务
     */
    private function trigger_next_batch() {
        // 创建接力棒令牌
        $internal_token = wp_generate_password(32, false);
        set_transient('yali_ai_writer_clustering_internal_token', $internal_token, 120);
        
        // 刷新大锁过期时间，确保上百万级任务在跑几天几夜也不会意外过期防并发
        set_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT, true, HOUR_IN_SECONDS * 2);
        
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

    /**
     * Handle similarity search form submission
     */
    private function handle_similarity_search() {
        // Check if required data is provided
        if (!isset($_POST['topic_id']) || empty($_POST['topic_id'])) {
            echo '<div class="notice notice-error"><p>' . __('请提供有效的文章ID。', 'yali-ai-writer') . '</p></div>';
            return;
        }

        $topic_id = intval($_POST['topic_id']);
        
        // Validate that the topic exists
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $topic = $wpdb->get_row($wpdb->prepare("SELECT id, title FROM {$topics_table} WHERE id = %d", $topic_id));
        
        if (!$topic) {
            echo '<div class="notice notice-error"><p>' . sprintf(__('未找到ID为 %d 的文章。', 'yali-ai-writer'), $topic_id) . '</p></div>';
            return;
        }

        echo '<div class="yali-textarea-code" style="max-height: 500px; overflow-y: scroll; margin-top: 20px;">';
        echo sprintf(__('开始查找与 "%s" 相似的标题...', 'yali-ai-writer'), esc_html($topic->title)) . '<br>';
        flush();

        // Call the similarity function to find similar titles
        $similar_titles = yali_ai_writer_find_similar_titles($topic_id, 20); // Get top 20 similar titles

        if (empty($similar_titles)) {
            echo __('未找到相似的标题。请确保已执行聚类操作。', 'yali-ai-writer') . '<br>';
        } else {
            echo sprintf(__('找到 %d 个相似标题：', 'yali-ai-writer'), count($similar_titles)) . '<br><br>';
            echo '<table class="yali-table">';
            echo '<thead><tr><th>' . __('排名', 'yali-ai-writer') . '</th><th>' . __('相似度', 'yali-ai-writer') . '</th><th>' . __('文章ID', 'yali-ai-writer') . '</th><th>' . __('标题', 'yali-ai-writer') . '</th></tr></thead>';
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
