<?php
/**
 * 智能结构优化管理页面
 * 
 * 处理智能结构优化功能的管理界面和 AJAX 请求
 * 
 * @package ContentAuto
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_SmartOptimizationAdminPage {
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * 冷启动管理器
     */
    private $cold_start_manager;
    
    /**
     * 多样性控制器
     */
    private $diversity_controller;
    
    /**
     * 受欢迎度计算器
     */
    private $popularity_calculator;
    
    /**
     * 构造函数
     */
    public function __construct() {
        // 注册 AJAX 处理器
        add_action('wp_ajax_get_optimization_configs', array($this, 'ajax_get_configs'));
        add_action('wp_ajax_save_optimization_config', array($this, 'ajax_save_config'));
        add_action('wp_ajax_save_optimization_configs', array($this, 'ajax_save_configs'));
        add_action('wp_ajax_reset_optimization_configs', array($this, 'ajax_reset_configs'));
        add_action('wp_ajax_get_cold_start_phases', array($this, 'ajax_get_cold_start_phases'));
        add_action('wp_ajax_get_data_driven_structures', array($this, 'ajax_get_data_driven_structures'));
        add_action('wp_ajax_get_diversity_overview', array($this, 'ajax_get_diversity_overview'));
        add_action('wp_ajax_get_performance_comparison', array($this, 'ajax_get_performance_comparison'));
        add_action('wp_ajax_run_manual_analysis', array($this, 'ajax_run_manual_analysis'));
        add_action('wp_ajax_get_pending_analysis_count', array($this, 'ajax_get_pending_analysis_count'));
        add_action('wp_ajax_process_single_article', array($this, 'ajax_process_single_article'));
        add_action('wp_ajax_update_popularity_indices', array($this, 'ajax_update_popularity_indices'));
        add_action('wp_ajax_clear_optimization_caches', array($this, 'ajax_clear_caches'));
        add_action('wp_ajax_analyze_article_structure', array($this, 'ajax_analyze_article_structure'));
    }
    
    /**
     * 延迟加载服务类
     */
    private function load_services() {
        if ($this->config === null) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-optimization-config.php';
            $this->config = new ContentAuto_OptimizationConfig();
        }
        
        if ($this->cold_start_manager === null) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-cold-start-manager.php';
            $this->cold_start_manager = new ContentAuto_ColdStartManager();
        }
        
        if ($this->diversity_controller === null) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-diversity-controller.php';
            $this->diversity_controller = new ContentAuto_DiversityController();
        }
        
        if ($this->popularity_calculator === null) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-popularity-calculator.php';
            $this->popularity_calculator = new ContentAuto_PopularityCalculator();
        }
    }
    
    /**
     * 渲染页面
     */
    public function render_page() {
        // 1. Define localized data for existing templates if needed
        $localized_data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('smart_optimization_nonce')
        );

        // 获取当前设置
        $this->load_services();
        $settings = $this->config->get_all_configs();
        
        ?>
        <div class="wrap yali-plugin-wrapper">
            <?php 
            // Use include instead of echo file_get_contents to allow PHP execution in view
            include CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/views/smart-optimization-settings.php'; 
            ?>
            
            <script type="text/javascript">
                window.smartOptimization = <?php echo json_encode($localized_data); ?>;
            </script>
        </div>
        <?php
    }
    
    /**
     * AJAX: 获取所有配置
     */
    public function ajax_get_configs() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        $configs = $this->config->get_all_configs();
        wp_send_json_success($configs);
    }
    
    /**
     * AJAX: 保存单个配置
     */
    public function ajax_save_config() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        if (!isset($_POST['key']) || !isset($_POST['value'])) {
            wp_send_json_error(array('message' => __('缺少必要参数', 'yali-ai-writer')));
            return;
        }
        
        $this->load_services();
        
        $key = sanitize_text_field($_POST['key']);
        $value = sanitize_text_field($_POST['value']);
        
        // 验证配置值
        $validation = $this->config->validate_config($key, $value);
        if (!$validation['valid']) {
            wp_send_json_error(array('message' => $validation['message']));
            return;
        }
        
        $result = $this->config->set_config($key, $value);
        
        if ($result) {
            wp_send_json_success(array('message' => __('配置已保存', 'yali-ai-writer')));
        } else {
            wp_send_json_error(array('message' => __('保存失败', 'yali-ai-writer')));
        }
    }
    
    /**
     * AJAX: 批量保存配置
     */
    public function ajax_save_configs() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        if (!isset($_POST['configs']) || !is_array($_POST['configs'])) {
            wp_send_json_error(array('message' => __('缺少配置数据', 'yali-ai-writer')));
            return;
        }
        
        $this->load_services();
        
        $configs = array();
        foreach ($_POST['configs'] as $key => $value) {
            $key = sanitize_text_field($key);
            $value = sanitize_text_field($value);
            
            // 验证配置值
            $validation = $this->config->validate_config($key, $value);
            if (!$validation['valid']) {
                wp_send_json_error(array('message' => $key . ': ' . $validation['message']));
                return;
            }
            
            $configs[$key] = $value;
        }
        
        $success_count = $this->config->set_configs($configs);
        
        wp_send_json_success(array(
            'message' => sprintf(__('已保存 %d 项配置', 'yali-ai-writer'), $success_count),
            'count' => $success_count
        ));
    }
    
    /**
     * AJAX: 重置配置为默认值
     */
    public function ajax_reset_configs() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        $result = $this->config->reset_to_default();
        
        if ($result) {
            wp_send_json_success(array('message' => __('配置已恢复为默认值', 'yali-ai-writer')));
        } else {
            wp_send_json_error(array('message' => __('重置失败', 'yali-ai-writer')));
        }
    }
    
    /**
     * AJAX: 获取冷启动阶段数据
     */
    public function ajax_get_cold_start_phases() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        $phases = $this->cold_start_manager->get_all_phases();
        wp_send_json_success($phases);
    }
    
    /**
     * AJAX: 获取数据驱动结构列表
     */
    public function ajax_get_data_driven_structures() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        // 获取数据驱动结构
        $structures = $wpdb->get_results("
            SELECT 
                s.id,
                s.content_angle,
                s.title,
                s.usage_count,
                s.source_article_id,
                s.extracted_at,
                s.source_type
            FROM {$structures_table} s
            WHERE s.source_type = 'data_driven'
            ORDER BY s.extracted_at DESC
        ", ARRAY_A);
        
        if (empty($structures)) {
            wp_send_json_success(array());
            return;
        }
        
        $this->load_services();
        
        // 补充来源文章信息和受欢迎度
        foreach ($structures as &$structure) {
            // 获取来源文章信息
            if (!empty($structure['source_article_id'])) {
                $post = get_post($structure['source_article_id']);
                if ($post) {
                    $structure['source_article_title'] = $post->post_title;
                    $structure['source_article_url'] = get_permalink($post->ID);
                } else {
                    $structure['source_article_title'] = null;
                    $structure['source_article_url'] = null;
                }
            } else {
                $structure['source_article_title'] = null;
                $structure['source_article_url'] = null;
            }
            
            // 获取受欢迎度指数
            $structure['popularity_index'] = $this->popularity_calculator->calculate_popularity_index($structure['id']);
            
            // 格式化提取时间
            if (!empty($structure['extracted_at'])) {
                $structure['extracted_at'] = mysql2date('Y-m-d H:i', $structure['extracted_at']);
            }
        }
        
        wp_send_json_success($structures);
    }
    
    /**
     * AJAX: 获取多样性概览数据
     */
    public function ajax_get_diversity_overview() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 获取所有内容角度
        $angles = $wpdb->get_col(
            "SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle != ''"
        );
        
        // 熵值概览
        $entropy_overview = array();
        foreach ($angles as $angle) {
            $entropy_alert = $this->diversity_controller->check_entropy_alert($angle);
            $entropy_overview[$angle] = array(
                'entropy' => $entropy_alert['entropy'],
                'threshold' => $entropy_alert['threshold'],
                'is_low' => $entropy_alert['is_low']
            );
        }
        
        // 使用分布（取第一个有数据的角度）
        $usage_distribution = array();
        foreach ($angles as $angle) {
            $report = $this->diversity_controller->generate_diversity_report($angle);
            if (!empty($report['usage_distribution']['structures'])) {
                $usage_distribution = $report['usage_distribution']['structures'];
                break;
            }
        }
        
        // 最近选择记录
        $recent_selections = $this->get_recent_selections(20);
        
        wp_send_json_success(array(
            'entropy_overview' => $entropy_overview,
            'usage_distribution' => $usage_distribution,
            'recent_selections' => $recent_selections
        ));
    }
    
    /**
     * 获取最近的选择记录
     */
    private function get_recent_selections($limit = 20) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.id as topic_id,
                t.source_angle as content_angle,
                t.used_structure_id,
                t.selection_method,
                t.selection_weight,
                t.updated_at as selected_at,
                s.title as structure_title,
                s.source_type
            FROM {$topics_table} t
            LEFT JOIN {$structures_table} s ON t.used_structure_id = s.id
            WHERE t.used_structure_id IS NOT NULL
            AND t.selection_method IS NOT NULL
            ORDER BY t.updated_at DESC
            LIMIT %d
        ", $limit), ARRAY_A);
        
        if (empty($results)) {
            return array();
        }
        
        $this->load_services();
        
        // 添加调整信息
        foreach ($results as &$row) {
            $structure_id = (int) $row['used_structure_id'];
            $content_angle = $row['content_angle'];
            
            $adjustment = $this->diversity_controller->get_adjustment_factor($structure_id, $content_angle);
            $row['penalty_applied'] = $adjustment['penalty_applied'];
            $row['boost_applied'] = $adjustment['boost_applied'];
            
            // 格式化时间
            $row['selected_at'] = mysql2date('Y-m-d H:i', $row['selected_at']);
        }
        
        return $results;
    }
    
    /**
     * AJAX: 获取性能对比数据
     */
    public function ajax_get_performance_comparison() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // AI 生成结构统计
        $ai_stats = $this->get_structure_type_stats('ai_generated');
        
        // 数据驱动结构统计
        $data_driven_stats = $this->get_structure_type_stats('data_driven');
        
        wp_send_json_success(array(
            'ai_generated' => $ai_stats,
            'data_driven' => $data_driven_stats
        ));
    }
    
    /**
     * 获取指定类型结构的统计数据
     */
    private function get_structure_type_stats($source_type) {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // 基础统计
        $basic_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as count,
                SUM(usage_count) as total_usage
            FROM {$structures_table}
            WHERE source_type = %s OR (source_type IS NULL AND %s = 'ai_generated')
        ", $source_type, $source_type), ARRAY_A);
        
        // 获取该类型结构关联文章的平均访问量
        $visit_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                AVG(CAST(COALESCE(pm.meta_value, '0') AS UNSIGNED)) as avg_visits
            FROM {$topics_table} t
            INNER JOIN {$structures_table} s ON t.used_structure_id = s.id
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_external_visit_count')
            WHERE (s.source_type = %s OR (s.source_type IS NULL AND %s = 'ai_generated'))
            AND p.post_status = 'publish'
        ", $source_type, $source_type), ARRAY_A);
        
        // 计算平均受欢迎度
        $this->load_services();
        
        $structure_ids = $wpdb->get_col($wpdb->prepare("
            SELECT id FROM {$structures_table}
            WHERE source_type = %s OR (source_type IS NULL AND %s = 'ai_generated')
        ", $source_type, $source_type));
        
        $total_popularity = 0;
        $popularity_count = 0;
        
        foreach ($structure_ids as $id) {
            $popularity = $this->popularity_calculator->calculate_popularity_index($id);
            if ($popularity > 0) {
                $total_popularity += $popularity;
                $popularity_count++;
            }
        }
        
        $avg_popularity = $popularity_count > 0 ? $total_popularity / $popularity_count : 0;
        
        return array(
            'count' => (int) ($basic_stats['count'] ?? 0),
            'total_usage' => (int) ($basic_stats['total_usage'] ?? 0),
            'avg_visits' => (float) ($visit_stats['avg_visits'] ?? 0),
            'avg_popularity' => $avg_popularity
        );
    }
    
    /**
     * AJAX: 获取待处理的高表现文章列表（包含具体的文章ID）
     */
    public function ajax_get_pending_analysis_count() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        try {
            $this->load_services();
            
            // 检查功能是否启用
            if (!$this->config->is_optimization_enabled()) {
                wp_send_json_success(array(
                    'total' => 0,
                    'articles' => array(),
                    'message' => '智能优化功能未启用'
                ));
                return;
            }
            
            // 加载分析器
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-article-analyzer.php';
            $analyzer = new ContentAuto_ArticleAnalyzer();
            
            // 获取所有内容角度
            global $wpdb;
            $topics_table = $wpdb->prefix . 'content_auto_topics';
            $angles = $wpdb->get_col("
                SELECT DISTINCT source_angle 
                FROM {$topics_table} 
                WHERE source_angle IS NOT NULL 
                AND source_angle != ''
            ");
            
            if (empty($angles)) {
                wp_send_json_success(array(
                    'total' => 0,
                    'articles' => array(),
                    'message' => '没有找到任何内容角度'
                ));
                return;
            }
            
            // 收集所有待处理文章的ID列表
            $all_articles = array();
            
            foreach ($angles as $angle) {
                // 获取该角度的待处理高表现文章
                $pending = $analyzer->get_unprocessed_high_performers($angle, 1000);
                
                foreach ($pending as $article) {
                    $all_articles[] = array(
                        'post_id' => $article['post_id'],
                        'content_angle' => $angle,
                        'visit_count' => $article['visit_count'],
                        'post_title' => $article['post_title'] ?? ''
                    );
                }
            }
            
            $total = count($all_articles);
            
            wp_send_json_success(array(
                'total' => $total,
                'articles' => $all_articles,
                'message' => $total > 0 ? sprintf(__('共有 %d 篇待处理文章', 'yali-ai-writer'), $total) : __('没有待处理的高表现文章', 'yali-ai-writer')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => __('获取数量失败：', 'yali-ai-writer') . $e->getMessage()));
        }
    }
    
    /**
     * AJAX: 处理指定的单篇文章
     */
    public function ajax_process_single_article() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        if (!isset($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
            wp_send_json_error(array('message' => __('缺少文章ID', 'yali-ai-writer')));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        
        try {
            // 加载结构提取器
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-structure-extractor.php';
            $extractor = new ContentAuto_StructureExtractor();
            
            // 处理这篇文章
            $result = $extractor->extract_and_create_structure($post_id);
            
            if ($result) {
                wp_send_json_success(array(
                    'message' => __('成功创建结构', 'yali-ai-writer'),
                    'structure_id' => $result['structure_id'],
                    'title' => $result['title'],
                    'post_id' => $post_id
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('结构提取失败', 'yali-ai-writer'),
                    'post_id' => $post_id
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('处理出错：', 'yali-ai-writer') . $e->getMessage(),
                'post_id' => $post_id
            ));
        }
    }
    
    /**
     * AJAX: 手动运行分析任务
     */
    public function ajax_run_manual_analysis() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        try {
            // 加载调度器
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-structure-optimization-scheduler.php';
            
            $scheduler = new ContentAuto_StructureOptimizationScheduler();
            $result = $scheduler->run_daily_analysis();
            
            if ($result['success']) {
                // 检查是否跳过了分析
                if (isset($result['skipped']) && $result['skipped']) {
                    wp_send_json_success(array(
                        'message' => __('分析已跳过：', 'yali-ai-writer') . ($result['reason'] ?? __('未知原因', 'yali-ai-writer')),
                        'structures_created' => 0
                    ));
                } else if ($result['structures_created'] > 0) {
                    wp_send_json_success(array(
                        'message' => sprintf(__('处理了 %d 篇文章，创建了 %d 个新结构。', 'yali-ai-writer'), 1, $result['structures_created']),
                        'structures_created' => $result['structures_created'],
                        'details' => $result['details'] ?? array()
                    ));
                } else if (!empty($result['reason'])) {
                    wp_send_json_success(array(
                        'message' => $result['reason'],
                        'structures_created' => 0
                    ));
                } else {
                    wp_send_json_success(array(
                        'message' => __('未创建新结构（可能提取失败）。', 'yali-ai-writer'),
                        'structures_created' => 0
                    ));
                }
            } else {
                $error_message = __('分析失败', 'yali-ai-writer');
                if (!empty($result['errors'])) {
                    $error_message .= '：' . implode('; ', $result['errors']);
                }
                wp_send_json_error(array('message' => $error_message));
            }
        } catch (Exception $e) {
            wp_send_json_error(array('message' => '执行出错：' . $e->getMessage()));
        } catch (Error $e) {
            wp_send_json_error(array('message' => '系统错误：' . $e->getMessage()));
        }
    }
    
    /**
     * AJAX: 更新受欢迎度指数
     */
    public function ajax_update_popularity_indices() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        $updated_count = $this->popularity_calculator->update_all_indices();
        
        wp_send_json_success(array(
            'message' => sprintf(__('已更新 %d 个结构的受欢迎度指数', 'yali-ai-writer'), $updated_count)
        ));
    }
    
    /**
     * AJAX: 清除缓存
     */
    public function ajax_clear_caches() {
        check_ajax_referer('smart_optimization_nonce', 'nonce');
        
        $this->load_services();
        
        // 清除各服务的缓存
        $this->popularity_calculator->clear_all_caches();
        $this->cold_start_manager->clear_all_caches();
        $this->diversity_controller->clear_cache();
        $this->config->clear_cache();
        
        wp_send_json_success(array('message' => __('缓存已清除', 'yali-ai-writer')));
    }
    
    /**
     * AJAX: 分析指定文章的结构
     */
    public function ajax_analyze_article_structure() {
        // 支持两种 nonce 验证方式
        $nonce_valid = false;
        if (isset($_POST['nonce'])) {
            if (wp_verify_nonce($_POST['nonce'], 'smart_optimization_nonce') || 
                wp_verify_nonce($_POST['nonce'], 'analyze_structure_nonce')) {
                $nonce_valid = true;
            }
        }
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => __('安全验证失败', 'yali-ai-writer')));
            return;
        }
        
        if (!isset($_POST['post_id']) || !is_numeric($_POST['post_id'])) {
            wp_send_json_error(array('message' => __('无效的文章ID', 'yali-ai-writer')));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error(array('message' => __('文章不存在', 'yali-ai-writer')));
            return;
        }
        
        // 加载结构提取器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-structure-extractor.php';
        
        $extractor = new ContentAuto_StructureExtractor();
        $result = $extractor->extract_and_save_from_article($post_id);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'structure_id' => $result['structure_id']
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}


/**
 * 添加文章编辑页面的结构分析元框
 */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'smart_structure_analysis',
        __('智能结构分析', 'yali-ai-writer'),
        'render_structure_analysis_metabox',
        'post',
        'side',
        'default'
    );
});

/**
 * 渲染结构分析元框
 */
function render_structure_analysis_metabox($post) {
    // 检查文章是否已发布
    if ($post->post_status !== 'publish') {
        echo '<p>' . __('文章发布后才能进行结构分析。', 'yali-ai-writer') . '</p>';
        return;
    }
    
    // 检查是否已有关联的结构
    $structure_id = get_post_meta($post->ID, '_article_structure_id', true);
    
    wp_nonce_field('analyze_structure_nonce', 'analyze_structure_nonce_field');
    
    ?>
    <div id="structure-analysis-container">
        <?php if ($structure_id): ?>
            <p><strong><?php _e('当前关联结构ID:', 'yali-ai-writer'); ?></strong> <?php echo esc_html($structure_id); ?></p>
        <?php endif; ?>
        
        <p><?php _e('从此文章提取结构特征，生成可复用的文章结构模板。', 'yali-ai-writer'); ?></p>
        
        <button type="button" id="analyze-structure-btn" class="button button-primary" data-post-id="<?php echo esc_attr($post->ID); ?>">
            <?php _e('分析此文章', 'yali-ai-writer'); ?>
        </button>
        
        <span class="spinner" id="analyze-spinner" style="float: none; margin-left: 5px;"></span>
        
        <div id="analyze-result" style="margin-top: 10px;"></div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#analyze-structure-btn').on('click', function() {
            var $btn = $(this);
            var $spinner = $('#analyze-spinner');
            var $result = $('#analyze-result');
            var postId = $btn.data('post-id');
            
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $result.html('');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'analyze_article_structure',
                    nonce: $('#analyze_structure_nonce_field').val(),
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error inline"><p>' + wp.i18n.__('请求失败，请重试', 'yali-ai-writer') + '</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
    });
    </script>
    <?php
}
