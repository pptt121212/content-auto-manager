<?php
/**
 * 增强版仪表盘页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 获取统计数据
$database = new ContentAuto_Database();
$dashboard_stats = $database->get_dashboard_stats();

// 获取队列状态
$job_queue = new ContentAuto_JobQueue();
$queue_status = $job_queue->get_queue_status();

// 获取向量生成统计
$vector_stats = $job_queue->get_vector_generation_stats();

// 处理分类缓存刷新请求
if (isset($_POST['refresh_category_cache']) && isset($_POST['content_auto_nonce'])) {
    if (wp_verify_nonce($_POST['content_auto_nonce'], 'content_auto_category_cache')) {
        $result = content_auto_refresh_category_cache();
        
        if (!empty($result)) {
            echo '<div class="yali-notice yali-notice-success"><h4><span class="dashicons dashicons-yes"></span> ' . __('刷新成功', 'yali-ai-writer') . '</h4><p>' . sprintf(__('分类向量缓存已成功刷新！共处理 %d 个最子级分类。', 'yali-ai-writer'), count($result)) . '</p></div>';
        } else {
            echo '<div class="yali-notice yali-notice-error"><h4><span class="dashicons dashicons-warning"></span> ' . __('刷新失败', 'yali-ai-writer') . '</h4><p>' . __('分类向量缓存刷新失败，请检查向量API配置。', 'yali-ai-writer') . '</p></div>';
        }
    }
}

// 获取分类缓存状态
$category_cache_status = content_auto_get_category_cache_status();

// 获取最子级分类统计
if (class_exists('ContentAuto_Category_Filter')) {
    $all_categories = ContentAuto_Category_Filter::get_filtered_categories(array('hide_empty' => false, 'number' => 0));
} else {
    $all_categories = get_categories(array('hide_empty' => false, 'number' => 0));
}

$leaf_categories_count = 0;
foreach ($all_categories as $category) {
    if (class_exists('ContentAuto_Category_Filter')) {
        $children = ContentAuto_Category_Filter::get_filtered_categories(array('parent' => $category->term_id, 'hide_empty' => false, 'number' => 1));
    } else {
        $children = get_categories(array('parent' => $category->term_id, 'hide_empty' => false, 'number' => 1));
    }
    if (empty($children)) {
        $leaf_categories_count++;
    }
}

// 获取手工添加且未匹配分类的主题数量
global $wpdb;
$topics_table = $wpdb->prefix . 'content_auto_topics';
$unmatched_manual_topics = $wpdb->get_var("
    SELECT COUNT(*) FROM {$topics_table} 
    WHERE rule_id = 0 AND (matched_category = '' OR matched_category IS NULL)
");

// 计算一些衍生数据
$vector_coverage = $dashboard_stats['topics']['total'] > 0 ?
    round(($dashboard_stats['topics']['with_vectors'] / $dashboard_stats['topics']['total']) * 100, 2) : 0;

$article_success_rate = $dashboard_stats['articles']['total'] > 0 ?
    round(($dashboard_stats['articles']['published'] / $dashboard_stats['articles']['total']) * 100, 2) : 0;

$topic_usage_rate = $dashboard_stats['topics']['total'] > 0 ?
    round(($dashboard_stats['topics']['used'] / $dashboard_stats['topics']['total']) * 100, 2) : 0;

// 格式化时间
// 格式化时间
$last_activity = $dashboard_stats['system']['last_activity'];
$current_plugin_locale = apply_filters('plugin_locale', get_locale(), 'yali-ai-writer');

if ($current_plugin_locale === 'en_US' && $last_activity) {
    $diff = current_time('timestamp') - strtotime($last_activity);
    if ($diff < 60) {
        $time_diff = $diff . ' seconds';
    } elseif ($diff < 3600) {
        $time_diff = round($diff / 60) . ' mins';
    } elseif ($diff < 86400) {
        $time_diff = round($diff / 3600) . ' hours';
    } else {
        $time_diff = round($diff / 86400) . ' days';
    }
    $last_activity_formatted = $time_diff . ' ' . __('ago', 'yali-ai-writer');
} else {
    $last_activity_formatted = $last_activity ?
        human_time_diff(strtotime($last_activity), current_time('timestamp')) . ' ' . __('前', 'yali-ai-writer') :
        __('无活动', 'yali-ai-writer');
}

// 加载增强样式
wp_enqueue_style('content-auto-enhanced-dashboard',
    plugins_url('assets/css/enhanced-dashboard.css', dirname(__FILE__)),
    array(), '1.0.0');
?>

<div class="wrap yali-plugin-wrapper">

    <!-- 仪表盘头部 -->
    <div class="dashboard-header">
        <div style="display: flex; align-items: center; gap: 20px; position: relative; z-index: 1;">
            <div style="position: relative; display: flex; align-items: center; justify-content: center;">
                <img src="<?php echo plugins_url('assets/images/yali-logo-icon.svg', dirname(__FILE__)); ?>" alt="Logo" style="height: 56px; width: auto; flex-shrink: 0; filter: drop-shadow(-1px -1px 0.5px rgba(255, 255, 255, 0.2)) drop-shadow(1px 1px 2px rgba(0, 0, 0, 0.15));">
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-start; justify-content: center; text-align: left; padding: 0; margin: 0;">
                <h1 style="margin: 0; padding: 0; line-height: 1.2; font-size: 2.3em; font-weight: 800; color: #ffffff; text-align: left; display: block;"><?php echo __('鸭梨AI', 'yali-ai-writer'); ?><span class="gradient-text"><?php echo __('文章智能写手', 'yali-ai-writer'); ?></span></h1>
                <div class="subtitle" style="margin: 10px 0 0 0; padding: 0; color: #ffffff; opacity: 1; font-size: 1.05em; font-weight: 400; text-align: left; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <span><?php printf(__('智能内容生产系统 | 最后活动: %s | 成功率: %s%% | 日均输出: %s 篇', 'yali-ai-writer'), $last_activity_formatted, $dashboard_stats['system']['success_rate'], $dashboard_stats['system']['avg_daily_output']); ?></span>
                    <span style="display: inline-flex; align-items: center; opacity: 0.85;">
                        <span style="margin: 0 5px; opacity: 0.5;">|</span>
                        <a href="https://www.yaliai.com/" target="_blank" style="color: #ffffff; text-decoration: none; border-bottom: 1px dashed rgba(255,255,255,0.6); transition: all 0.2s;" onmouseover="this.style.opacity='1';this.style.borderBottomColor='#ffffff'" onmouseout="this.style.opacity='0.85';this.style.borderBottomColor='rgba(255,255,255,0.6)'">
                            <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px; margin-right: 4px; vertical-align: middle;"></span><?php _e('学习帮助文档', 'yali-ai-writer'); ?>
                        </a>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="content-auto-dashboard">
        <!-- 核心指标概览 -->
        <div class="dashboard-overview">
            <!-- API 配置状态 -->
            <div class="overview-section yali-card">
                <h3><span class="dashicons dashicons-networking"></span> <?php _e('API 配置状态', 'yali-ai-writer'); ?></h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['api_configs']['total']; ?></span>
                        <span class="label"><?php _e('总配置', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number active"><?php echo $dashboard_stats['api_configs']['active']; ?></span>
                        <span class="label"><?php _e('活跃', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['api_configs']['with_vector']; ?></span>
                        <span class="label"><?php _e('支持向量', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
            </div>

            <!-- 内容生产统计 -->
            <div class="overview-section yali-card">
                <h3><span class="dashicons dashicons-edit"></span> <?php _e('内容生产统计', 'yali-ai-writer'); ?></h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['topics']['total']; ?></span>
                        <span class="label"><?php _e('主题总数', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['articles']['total']; ?></span>
                        <span class="label"><?php _e('文章总数', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo number_format($dashboard_stats['articles']['total_words']); ?></span>
                        <span class="label"><?php _e('总字符数', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
            </div>

            <!-- 任务执行状态 -->
            <div class="overview-section yali-card">
                <h3><span class="dashicons dashicons-performance"></span> <?php _e('任务执行状态', 'yali-ai-writer'); ?></h3>
                <div class="status-grid">
                    <div class="status-item processing">
                        <span class="number"><?php echo $queue_status['pending']; ?></span>
                        <span class="label"><?php _e('待处理', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $queue_status['processing']; ?></span>
                        <span class="label"><?php _e('处理中', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $queue_status['completed']; ?></span>
                        <span class="label"><?php _e('已完成', 'yali-ai-writer'); ?></span>
                    </div>
                </div>

                <!-- 任务类型详情 -->
                <?php if (!empty($queue_status['by_type'])): ?>
                <div class="task-type-breakdown" style="margin-top: 15px; border-top: 1px solid #f0f0f1; padding-top: 12px;">
                    <table class="wp-list-table widefat fixed striped table-view-list" style="border: none; box-shadow: none;">
                        <thead>
                            <tr>
                                <th style="padding-left: 0; color: #646970;"><?php _e('任务类型', 'yali-ai-writer'); ?></th>
                                <th style="color: #2271b1;"><?php _e('处理中', 'yali-ai-writer'); ?></th>
                                <th style="color: #d63638;"><?php _e('待处理', 'yali-ai-writer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $type_names = [
                            'topic_task' => __('主题生成', 'yali-ai-writer'),
                            'article' => __('文章生成', 'yali-ai-writer'),
                            'material_search' => __('素材搜索', 'yali-ai-writer'),
                            'vector' => __('向量处理', 'yali-ai-writer'),
                            'image_generation' => __('图片生成', 'yali-ai-writer')
                        ];
                        
                        foreach ($queue_status['by_type'] as $type => $stats): 
                            // 只有当有任务（待处理或处理中）时才显示，或者您可以删掉这行以显示所有
                            if ($stats['pending'] == 0 && $stats['processing'] == 0) continue;
                            
                            $label = isset($type_names[$type]) ? $type_names[$type] : ucfirst($type);
                        ?>
                        <tr>
                            <td style="padding-left: 0;"><strong><?php echo $label; ?></strong></td>
                            <td>
                                <?php if($stats['processing'] > 0): ?>
                                    <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; color: #2271b1; vertical-align: text-bottom;"></span> 
                                    <strong style="color: #2271b1;"><?php echo $stats['processing']; ?></strong>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($stats['pending'] > 0): ?>
                                    <span class="dashicons dashicons-clock" style="font-size: 16px; width: 16px; height: 16px; line-height: 16px; color: #d63638; vertical-align: text-bottom;"></span> 
                                    <strong><?php echo $stats['pending']; ?></strong>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 详细统计卡片 -->
        <div class="dashboard-cards">
            <!-- 主题任务 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-list-view"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['topic_tasks']['total']; ?></div>
                <div class="description"><?php _e('主题任务', 'yali-ai-writer'); ?></div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['topic_tasks']['total'] > 0 ? ($dashboard_stats['topic_tasks']['completed'] / $dashboard_stats['topic_tasks']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    <?php printf(__('完成: %d | 失败: %d', 'yali-ai-writer'), $dashboard_stats['topic_tasks']['completed'], $dashboard_stats['topic_tasks']['failed']); ?>
                </div>
            </div>

            <!-- 文章任务 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-media-text"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['article_tasks']['total']; ?></div>
                <div class="description"><?php _e('文章任务', 'yali-ai-writer'); ?></div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['article_tasks']['total'] > 0 ? ($dashboard_stats['article_tasks']['completed'] / $dashboard_stats['article_tasks']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    <?php printf(__('完成: %d | 失败: %d', 'yali-ai-writer'), $dashboard_stats['article_tasks']['completed'], $dashboard_stats['article_tasks']['failed']); ?>
                </div>
            </div>

            <!-- 向量覆盖率 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="count"><?php echo $vector_coverage; ?>%</div>
                <div class="description"><?php _e('向量覆盖率', 'yali-ai-writer'); ?></div>
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $vector_coverage < 50 ? 'warning' : ''; ?>" style="width: <?php echo $vector_coverage; ?>%"></div>
                </div>
                <div class="trend">
                    <?php printf(__('已向量化: %d / %d', 'yali-ai-writer'), $dashboard_stats['topics']['with_vectors'], $dashboard_stats['topics']['total']); ?>
                </div>
            </div>

            <!-- 自动配图文章 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-format-image"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['articles']['with_auto_images']; ?></div>
                <div class="description"><?php _e('自动配图文章', 'yali-ai-writer'); ?></div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['articles']['total'] > 0 ? ($dashboard_stats['articles']['with_auto_images'] / $dashboard_stats['articles']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    <?php printf(__('总生成图片: %d', 'yali-ai-writer'), $dashboard_stats['articles']['total_auto_images']); ?>
                </div>
            </div>

            <!-- 已发布文章 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-share-alt2"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['articles']['published']; ?></div>
                <div class="description"><?php _e('已发布文章', 'yali-ai-writer'); ?></div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $article_success_rate; ?>%"></div>
                </div>
                <div class="trend">
                    <?php printf(__('成功率: %s%%', 'yali-ai-writer'), $article_success_rate); ?>
                </div>
            </div>

            <!-- 活跃规则 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-admin-settings"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['rules']['active']; ?></div>
                <div class="description"><?php _e('活跃规则', 'yali-ai-writer'); ?></div>
                <div class="trend">
                    <?php printf(__('总规则: %d', 'yali-ai-writer'), $dashboard_stats['rules']['total']); ?>
                </div>
            </div>

            <!-- 高优先级主题 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-star-filled"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['topics']['high_priority']; ?></div>
                <div class="description"><?php _e('高优先级主题', 'yali-ai-writer'); ?></div>
                <div class="trend">
                    <?php printf(__('占比: %s%%', 'yali-ai-writer'), $dashboard_stats['topics']['total'] > 0 ? round(($dashboard_stats['topics']['high_priority'] / $dashboard_stats['topics']['total']) * 100, 1) : 0); ?>
                </div>
            </div>

            <!-- 向量聚类 -->
            <div class="card yali-card">
                <div class="icon">
                    <span class="dashicons dashicons-admin-links"></span>
                </div>
                <div class="count"><?php echo $dashboard_stats['topics']['clusters']; ?></div>
                <div class="description"><?php _e('向量聚类数', 'yali-ai-writer'); ?></div>
                <div class="trend">
                    <?php printf(__('聚类主题: %d', 'yali-ai-writer'), $dashboard_stats['topics']['with_vectors']); ?>
                </div>
            </div>
        </div>

        </div>

        <!-- 详细状态信息 -->
        <div class="status-grid-2">
            <!-- 系统设置 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-admin-generic"></span> <?php _e('系统设置', 'yali-ai-writer'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-item" style="grid-column: 1 / -1;">
                        <div class="stat-label"><?php _e('界面语言', 'yali-ai-writer'); ?></div>
                        <div class="stat-value" style="margin-top: 10px;">
                            <?php 
                            $current_plugin_locale = get_option('yali_ai_writer_locale', 'site_default');
                            ?>
                            <select id="yali-plugin-language-select" class="yali-select" style="width: 100%;">
                                <option value="site_default" <?php selected($current_plugin_locale, 'site_default'); ?>><?php _e('跟随系统 (Follow System)', 'yali-ai-writer'); ?></option>
                                <option value="zh_CN" <?php selected($current_plugin_locale, 'zh_CN'); ?>><?php _e('简体中文 (Simplified Chinese)', 'yali-ai-writer'); ?></option>
                                <option value="en_US" <?php selected($current_plugin_locale, 'en_US'); ?>><?php _e('English (United States)', 'yali-ai-writer'); ?></option>
                            </select>
                            <p class="description yali-desc" style="margin-top: 5px;">
                                <?php _e('仅针对本插件管理界面生效。', 'yali-ai-writer'); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div style="margin-top: 15px;">
                    <button type="button" id="save-language-setting" class="yali-btn yali-btn-primary">
                        <span class="dashicons dashicons-saved"></span> <?php _e('保存语言设置', 'yali-ai-writer'); ?>
                    </button>
                </div>
            </div>

            <!-- 主题状态分布 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-chart-bar"></span> <?php _e('主题状态分布', 'yali-ai-writer'); ?></h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo $dashboard_stats['topics']['unused']; ?></span>
                        <span class="label"><?php _e('未使用', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $dashboard_stats['topics']['queued']; ?></span>
                        <span class="label"><?php _e('队列中', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['topics']['used']; ?></span>
                        <span class="label"><?php _e('已使用', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
                <div class="progress-bar" style="margin-top: 15px;">
                    <div class="progress-fill" style="width: <?php echo $topic_usage_rate; ?>%"></div>
                </div>
                <p style="text-align: center; margin-top: 10px; color: #64748b;">
                    <?php printf(__('主题使用率: <strong>%s%%</strong>', 'yali-ai-writer'), $topic_usage_rate); ?>
                </p>
            </div>

            <!-- 文章状态分布 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-chart-line"></span> <?php _e('文章状态分布', 'yali-ai-writer'); ?></h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo $dashboard_stats['articles']['pending']; ?></span>
                        <span class="label"><?php _e('待处理', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $dashboard_stats['articles']['processing']; ?></span>
                        <span class="label"><?php _e('处理中', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['articles']['completed']; ?></span>
                        <span class="label"><?php _e('已完成', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item failed">
                        <span class="number"><?php echo $dashboard_stats['articles']['failed']; ?></span>
                        <span class="label"><?php _e('失败', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
            </div>

            <!-- 分类缓存管理 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-tag"></span> <?php _e('分类缓存管理', 'yali-ai-writer'); ?></h2>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label"><?php _e('缓存状态', 'yali-ai-writer'); ?></div>
                        <div class="stat-value">
                            <?php if ($category_cache_status['cache_exists']): ?>
                                <span style="color: green;">✅ <?php _e('已缓存', 'yali-ai-writer'); ?></span>
                                <br><small><?php echo date('m-d H:i', $category_cache_status['cache_time']); ?></small>
                            <?php else: ?>
                                <span style="color: orange;">⚠️ <?php _e('未缓存', 'yali-ai-writer'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label"><?php _e('最子级分类', 'yali-ai-writer'); ?></div>
                        <div class="stat-value"><?php printf(__('%d 个', 'yali-ai-writer'), $leaf_categories_count); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label"><?php _e('已缓存分类', 'yali-ai-writer'); ?></div>
                        <div class="stat-value"><?php printf(__('%d 个', 'yali-ai-writer'), $category_cache_status['category_count']); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label"><?php _e('待匹配主题', 'yali-ai-writer'); ?></div>
                        <div class="stat-value"><?php printf(__('%d 个', 'yali-ai-writer'), $unmatched_manual_topics); ?></div>
                    </div>
                </div>
                
                <form method="post" action="" style="margin-top: 15px;">
                    <?php wp_nonce_field('content_auto_category_cache', 'content_auto_nonce'); ?>
                    <button type="submit" name="refresh_category_cache" class="yali-btn yali-btn-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo $category_cache_status['cache_exists'] ? __('刷新分类缓存', 'yali-ai-writer') : __('生成分类缓存', 'yali-ai-writer'); ?>
                    </button>
                    <small style="margin-left: 10px; color: #666;">
                        <?php _e('手工添加主题时会自动匹配最相似的分类', 'yali-ai-writer'); ?>
                    </small>
                </form>
            </div>

            <!-- 向量生成状态 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-visibility"></span> <?php _e('向量生成状态', 'yali-ai-writer'); ?></h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo isset($vector_stats['pending_vector_tasks']) ? $vector_stats['pending_vector_tasks'] : 0; ?></span>
                        <span class="label"><?php _e('待处理', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo isset($vector_stats['processing_vector_tasks']) ? $vector_stats['processing_vector_tasks'] : 0; ?></span>
                        <span class="label"><?php _e('处理中', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['topics']['vector_completed']; ?></span>
                        <span class="label"><?php _e('已完成', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item failed">
                        <span class="number"><?php echo $dashboard_stats['topics']['vector_failed']; ?></span>
                        <span class="label"><?php _e('失败', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
            </div>

            <!-- 队列任务分布 -->
            <div class="content-auto-section yali-card">
                <h2><span class="dashicons dashicons-list-view"></span> <?php _e('队列任务分布', 'yali-ai-writer'); ?></h2>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['topic_jobs']; ?></span>
                        <span class="label"><?php _e('主题任务', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['article_jobs']; ?></span>
                        <span class="label"><?php _e('文章任务', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['vector_jobs']; ?></span>
                        <span class="label"><?php _e('向量任务', 'yali-ai-writer'); ?></span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['high_priority']; ?></span>
                        <span class="label"><?php _e('高优先级', 'yali-ai-writer'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 系统状态监控 -->
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-desktop"></span> <?php _e('系统状态监控', 'yali-ai-writer'); ?></h2>
            <div class="system-status">
                <div class="status-indicator">
                    <div class="indicator-light <?php echo $queue_status['processing'] > 0 ? 'green' : 'yellow'; ?>"></div>
                    <div class="info">
                        <div class="label"><?php _e('任务处理器状态', 'yali-ai-writer'); ?></div>
                        <div class="value">
                            <?php echo $queue_status['processing'] > 0 ? __('正在处理任务', 'yali-ai-writer') : __('待机中', 'yali-ai-writer'); ?> |
                            <?php printf(__('队列中有 %d 个任务待处理', 'yali-ai-writer'), $queue_status['pending']); ?>
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $dashboard_stats['api_configs']['active'] > 0 ? 'green' : 'red'; ?>"></div>
                    <div class="info">
                        <div class="label"><?php _e('API 配置状态', 'yali-ai-writer'); ?></div>
                        <div class="value">
                            <?php printf(__('%d 个活跃配置 | %d 个支持向量处理', 'yali-ai-writer'), $dashboard_stats['api_configs']['active'], $dashboard_stats['api_configs']['with_vector']); ?>
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $vector_coverage >= 80 ? 'green' : ($vector_coverage >= 50 ? 'yellow' : 'red'); ?>"></div>
                    <div class="info">
                        <div class="label"><?php _e('向量处理状态', 'yali-ai-writer'); ?></div>
                        <div class="value">
                            <?php printf(__('覆盖率 %s%% | %d 个聚类 | %d 个待处理', 'yali-ai-writer'), $vector_coverage, $dashboard_stats['topics']['clusters'], $dashboard_stats['topics']['vector_pending']); ?>
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $dashboard_stats['system']['success_rate'] >= 90 ? 'green' : ($dashboard_stats['system']['success_rate'] >= 70 ? 'yellow' : 'red'); ?>"></div>
                    <div class="info">
                        <div class="label"><?php _e('系统性能', 'yali-ai-writer'); ?></div>
                        <div class="value">
                            <?php printf(__('成功率 %s%% | 日均输出 %s 篇 | 总生成 %d 个内容', 'yali-ai-writer'), $dashboard_stats['system']['success_rate'], $dashboard_stats['system']['avg_daily_output'], $dashboard_stats['system']['total_generated_content']); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 发布配置状态 -->
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-upload"></span> <?php _e('发布配置状态', 'yali-ai-writer'); ?></h2>
            <div class="status-grid-2">
                <div class="status-card">
                    <h4><?php _e('自动发布', 'yali-ai-writer'); ?></h4>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('启用自动发布', 'yali-ai-writer'); ?></span>
                        <span class="yali-badge <?php echo $dashboard_stats['publish_rules']['auto_publish_enabled'] > 0 ? 'yali-badge-success' : 'yali-badge-neutral'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['auto_publish_enabled'] > 0 ? __('是', 'yali-ai-writer') : __('否', 'yali-ai-writer'); ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('自动配图', 'yali-ai-writer'); ?></span>
                        <span class="yali-badge <?php echo $dashboard_stats['publish_rules']['auto_images_enabled'] > 0 ? 'yali-badge-success' : 'yali-badge-neutral'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['auto_images_enabled'] > 0 ? __('是', 'yali-ai-writer') : __('否', 'yali-ai-writer'); ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('内链功能', 'yali-ai-writer'); ?></span>
                        <span class="yali-badge <?php echo $dashboard_stats['publish_rules']['internal_linking_enabled'] > 0 ? 'yali-badge-success' : 'yali-badge-neutral'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['internal_linking_enabled'] > 0 ? __('是', 'yali-ai-writer') : __('否', 'yali-ai-writer'); ?>
                        </span>
                    </div>
                </div>

                <div class="status-card">
                    <h4><?php _e('内容质量', 'yali-ai-writer'); ?></h4>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('平均处理时间', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php printf(__('%s秒', 'yali-ai-writer'), round($dashboard_stats['articles']['avg_processing_time'] ?? 0)); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('总字符数', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php echo number_format($dashboard_stats['articles']['total_words']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('已发布', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php printf(__('%s 篇', 'yali-ai-writer'), $dashboard_stats['articles']['published']); ?></span>
                    </div>
                </div>

                <div class="status-card">
                    <h4><?php _e('文章结构', 'yali-ai-writer'); ?></h4>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('结构模板', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['total']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('支持向量', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['with_vectors']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><?php _e('总使用次数', 'yali-ai-writer'); ?></span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['total_usage']; ?></span>
                    </div>
                </div>
            </div>
        </div>

  
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-admin-tools"></span> <?php _e('数据清理', 'yali-ai-writer'); ?></h2>
            <div class="card-body">
                <div class="queue-actions-grid">
                    <!-- 任务队列清理 -->
                    <div class="action-card recovery-card">
                        <div>
                            <h4>
                                <span class="dashicons dashicons-shield-alt"></span>
                                <?php _e('任务队列清理', 'yali-ai-writer'); ?>
                            </h4>
                            <div class="stats-row">
                                <div class="stat-bubble">
                                    <?php _e('待处理任务:', 'yali-ai-writer'); ?> <strong><?php echo number_format($queue_status['total']); ?></strong>
                                </div>
                                <div class="stat-bubble">
                                    <?php _e('正在运行中:', 'yali-ai-writer'); ?> <strong><?php echo number_format($queue_status['processing']); ?></strong>
                                </div>
                            </div>
                            <p class="warning-text">
                                <?php _e('立即中断所有正在运行的步进，清空执行队列。', 'yali-ai-writer'); ?>
                                <br><span><?php _e('⚠️ 适用于任务卡死或需要紧急停止发文。', 'yali-ai-writer'); ?></span>
                            </p>
                        </div>
                        <div class="card-footer">
                            <button type="button" id="clear-queue-btn" class="yali-btn yali-btn-danger">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('清空执行队列', 'yali-ai-writer'); ?>
                            </button>
                            <span class="info-text"><?php _e('操作不可逆', 'yali-ai-writer'); ?></span>
                        </div>
                    </div>

                    <!-- 完成任务清理 -->
                    <div class="action-card cleanup-card">
                        <div>
                            <h4>
                                <span class="dashicons dashicons-sweeper"></span>
                                <?php _e('完成任务清理', 'yali-ai-writer'); ?>
                            </h4>
                            <div class="stats-row">
                                <div class="stat-bubble">
                                    <?php _e('已完成主题任务:', 'yali-ai-writer'); ?> <strong><?php echo number_format($dashboard_stats['topic_tasks']['completed']); ?></strong>
                                </div>
                                <div class="stat-bubble">
                                    <?php _e('已完成文章任务:', 'yali-ai-writer'); ?> <strong><?php echo number_format($dashboard_stats['article_tasks']['completed']); ?></strong>
                                </div>
                            </div>
                            <p class="description-text">
                                <?php _e('删除已结束的任务历史记录以优化数据库性能。', 'yali-ai-writer'); ?>
                                <br><span><span class="dashicons dashicons-yes"></span> <?php _e('保留文章与知识库，不影响智能功能。', 'yali-ai-writer'); ?></span>
                            </p>
                        </div>
                        <div class="card-footer">
                            <button type="button" id="bulk-clean-btn" class="yali-btn yali-btn-primary">
                                <span class="dashicons dashicons-database-remove"></span>
                                <?php _e('批量清理历史', 'yali-ai-writer'); ?>
                            </button>
                            <span class="info-text"><?php _e('建议定期执行', 'yali-ai-writer'); ?></span>
                        </div>
                    </div>
                    <!-- 调试与诊断 -->
                    <div class="action-card" style="border-top: 4px solid #8224e3;">
                        <div>
                            <h4>
                                <span class="dashicons dashicons-admin-network" style="color: #8224e3;"></span>
                                <?php _e('调试与诊断', 'yali-ai-writer'); ?>
                            </h4>
                            <div class="stats-row" style="margin-bottom: 5px;">
                                <?php if ($queue_status['processing'] > 0): ?>
                                    <div class="stat-bubble" style="background: rgba(130, 36, 227, 0.05); color: #8224e3; border: 1px solid rgba(130, 36, 227, 0.2);">
                                        <?php _e('引擎状态:', 'yali-ai-writer'); ?> <strong><?php _e('工作中', 'yali-ai-writer'); ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="stat-bubble" style="background: rgba(100, 105, 112, 0.05); color: #646970; border: 1px solid rgba(100, 105, 112, 0.2);">
                                        <?php _e('引擎状态:', 'yali-ai-writer'); ?> <strong><?php _e('待机休眠', 'yali-ai-writer'); ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <p class="description-text">
                                <?php _e('系统级诊断工具，用于验证网络代理连通性、搜索信源提取质量、以及核心调度器状态。', 'yali-ai-writer'); ?>
                            </p>
                        </div>
                        <div class="card-footer" style="display: flex; gap: 10px;">
                            <a href="<?php echo admin_url('admin.php?page=content-auto-search-materials'); ?>" class="yali-btn yali-btn-secondary" style="flex: 1; text-align: center; justify-content: center;">
                                <span class="dashicons dashicons-search"></span>
                                <?php _e('搜索物料', 'yali-ai-writer'); ?>
                            </a>
                            <a href="<?php echo admin_url('admin.php?page=yali-ai-writer-debug-tools'); ?>" class="yali-btn yali-btn-secondary" style="flex: 1; text-align: center; justify-content: center;">
                                <span class="dashicons dashicons-hammer"></span>
                                <?php _e('调试工具', 'yali-ai-writer'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- 清除队列模态框 -->
    <div id="clear-queue-modal" class="yali-modal-overlay">
        <div class="yali-modal" style="max-width: 500px;">
            <div class="yali-modal-header">
                <h3><span class="dashicons dashicons-warning" style="color: #d63638;"></span> <?php _e('确认清除任务队列', 'yali-ai-writer'); ?></h3>
                <button type="button" class="yali-modal-close" id="clear-queue-modal-close">&times;</button>
            </div>
            <div class="yali-modal-body">
                <p><?php _e('您即将执行以下操作：', 'yali-ai-writer'); ?></p>
                <ul>
                    <li><?php _e('重置所有 <strong>处理中/失败</strong> 的主题任务为待处理状态', 'yali-ai-writer'); ?></li>
                    <li><?php _e('重置所有 <strong>处理中/失败</strong> 的文章任务为待处理状态', 'yali-ai-writer'); ?></li>
                    <li><?php _e('<strong>删除</strong>所有队列项目', 'yali-ai-writer'); ?></li>
                    <li><?php _e('重置任务的最后处理时间', 'yali-ai-writer'); ?></li>
                </ul>
                <div class="warning-box">
                    <p><?php _e('⚠️ 警告：', 'yali-ai-writer'); ?></p>
                    <p style="margin-bottom: 5px;"><?php _e('此操作<strong>不可撤销</strong>', 'yali-ai-writer'); ?></p>
                    <p style="margin-bottom: 5px;"><?php _e('所有正在执行的任务将被中断', 'yali-ai-writer'); ?></p>
                    <p><?php _e('队列进度信息将丢失', 'yali-ai-writer'); ?></p>
                </div>
            </div>
            <div class="yali-modal-footer">
                <button type="button" class="yali-btn yali-btn-secondary" id="clear-queue-modal-cancel"><?php _e('取消', 'yali-ai-writer'); ?></button>
                <button type="button" id="confirm-clear-queue" class="yali-btn yali-btn-danger"><?php _e('确认清除', 'yali-ai-writer'); ?></button>
            </div>
        </div>
    </div>

    <!-- 批量清理历史模态框 -->
    <div id="bulk-clean-modal" class="yali-modal-overlay">
        <div class="yali-modal" style="max-width: 500px;">
            <div class="yali-modal-header">
                <h3><span class="dashicons dashicons-archive" style="color: #2271b1;"></span> <?php _e('🧹 批量清理已完成任务', 'yali-ai-writer'); ?></h3>
                <button type="button" class="yali-modal-close" id="bulk-clean-modal-close">&times;</button>
            </div>
            <div class="yali-modal-body">
                <div id="bulk-clean-options">
                    <p><?php _e('请选择要清理的内容：', 'yali-ai-writer'); ?></p>
                    <div style="margin: 15px 0;">
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="clean_topic_tasks" value="true" checked> 
                            <strong><?php _e('已完成的主题任务', 'yali-ai-writer'); ?></strong>
                            <br><small style="margin-left: 25px; color: #666;"><?php _e(' (清理数据库记录，保留生成的文章/主题)', 'yali-ai-writer'); ?></small>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="clean_article_tasks" value="true" checked> 
                            <strong><?php _e('已完成的文章任务', 'yali-ai-writer'); ?></strong>
                            <br><small style="margin-left: 25px; color: #666;"><?php _e(' (清理数据库记录，保留生成的文章)', 'yali-ai-writer'); ?></small>
                        </label>
                    </div>
                </div>
            </div>
            <div class="yali-modal-footer">
                <button type="button" class="yali-btn yali-btn-secondary" id="bulk-clean-modal-cancel"><?php _e('取消', 'yali-ai-writer'); ?></button>
                <button type="button" id="confirm-bulk-clean" class="yali-btn yali-btn-primary"><?php _e('确认清理', 'yali-ai-writer'); ?></button>
            </div>
        </div>
    </div>
</div>


<!-- 添加样式 -->
<style>

/* 模态框特定样式 */
.warning-box {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    padding: 15px;
    margin: 15px 0;
}

.warning-box p {
    margin: 0 0 10px 0;
    color: #856404;
    font-weight: bold;
}

/* 加载动画 */
.clearing-queue {
    opacity: 0.6;
    pointer-events: none;
}

.clearing-queue .button-danger {
    position: relative;
}

.clearing-queue .button-danger::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 16px;
    height: 16px;
    border: 2px solid #fff;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: translate(-50%, -50%) rotate(0deg); }
    100% { transform: translate(-50%, -50%) rotate(360deg); }
}
</style>

<!-- JavaScript -->
<script>
jQuery(document).ready(function($) {
    // 清除队列按钮点击
    $('#clear-queue-btn').on('click', function(e) {
        e.preventDefault();
        $('#clear-queue-modal').addClass('active');
        $('body').css('overflow', 'hidden');
    });

    // 模态框关闭
    $('#clear-queue-modal-close, #clear-queue-modal-cancel').on('click', function() {
        $('#clear-queue-modal').removeClass('active');
        $('body').css('overflow', '');
    });

    // 点击背景关闭
    $('#clear-queue-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).removeClass('active');
            $('body').css('overflow', '');
        }
    });

    // 确认清除队列
    $('#confirm-clear-queue').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $btnText = $button.html();

        // 显示加载状态
        $button.addClass('clearing-queue');
        $button.prop('disabled', true);
        $button.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('清除中...', 'yali-ai-writer')); ?>');

        // 发送AJAX请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_clear_task_queue',
                nonce: '<?php echo wp_create_nonce('content_auto_clear_queue'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // 显示成功消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message, 'success');
                    } else {
                        alert(response.data.message);
                    }

                    // 关闭模态框
                    $('#clear-queue-modal').removeClass('active');

                    // 刷新页面数据（可选）
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    // 显示错误消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message || '<?php echo esc_js(__('清除队列失败', 'yali-ai-writer')); ?>', 'error');
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('清除队列失败', 'yali-ai-writer')); ?>');
                    }
                }

                // 恢复按钮状态
                $button.removeClass('clearing-queue');
                $button.prop('disabled', false);
                $button.html($btnText);
            },
            error: function() {
                var errorMsg = '<?php echo esc_js(__('网络请求失败，请稍后重试', 'yali-ai-writer')); ?>';
                if (typeof window.yaliToast === 'function') {
                    window.yaliToast(errorMsg, 'error');
                } else {
                    alert(errorMsg);
                }
                // 恢复按钮状态
                $button.removeClass('clearing-queue');
                $button.prop('disabled', false);
                $button.html($btnText);
            }
        });
    });

    // 批量清理 - 打开模态框
    $('#bulk-clean-btn').on('click', function(e) {
        e.preventDefault();
        $('#bulk-clean-modal').addClass('active');
        $('body').css('overflow', 'hidden');
    });

    // 批量清理 - 关闭模态框
    $('#bulk-clean-modal-close, #bulk-clean-modal-cancel').on('click', function() {
        $('#bulk-clean-modal').removeClass('active');
        $('body').css('overflow', '');
    });

    // 批量清理 - 点击背景关闭
    $('#bulk-clean-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).removeClass('active');
            $('body').css('overflow', '');
        }
    });

    // 批量清理 - 确认
    $('#confirm-bulk-clean').on('click', function(e) {
        e.preventDefault();

        var data = {
            action: 'content_auto_bulk_clean_tasks',
            nonce: '<?php echo wp_create_nonce('content_auto_bulk_clean'); ?>'
        };

        // 获取选中的清理选项
        var hasSelection = false;
        $('#bulk-clean-options input[type="checkbox"]:checked').each(function() {
            data[this.name] = $(this).val();
            hasSelection = true;
        });

        if (!hasSelection) {
            alert('<?php echo esc_js(__('请至少选择一项进行清理', 'yali-ai-writer')); ?>');
            return;
        }

        var $button = $(this);
        var $btnText = $button.html();

        // 显示加载状态
        $button.addClass('clearing-queue');
        $button.prop('disabled', true);
        $button.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('清理中...', 'yali-ai-writer')); ?>');

        // 发送AJAX请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    // 显示成功消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message, 'success');
                    } else {
                        alert(response.data.message);
                    }

                    // 关闭模态框
                    $('#bulk-clean-modal').removeClass('active');

                    // 刷新页面数据
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    // 显示错误消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message || '<?php echo esc_js(__('清理失败', 'yali-ai-writer')); ?>', 'error');
                    } else {
                        alert(response.data.message || '<?php echo esc_js(__('清理失败', 'yali-ai-writer')); ?>');
                    }
                }

                // 恢复按钮状态
                $button.removeClass('clearing-queue');
                $button.prop('disabled', false);
                $button.html($btnText);
            },
            error: function() {
                var errorMsg = '<?php echo esc_js(__('网络请求失败，请稍后重试', 'yali-ai-writer')); ?>';
                if (typeof window.yaliToast === 'function') {
                    window.yaliToast(errorMsg, 'error');
                } else {
                    alert(errorMsg);
                }
                // 恢复按钮状态
                $button.removeClass('clearing-queue');
                $button.prop('disabled', false);
                $button.html($btnText);
            }
        });
    });


    // 保存语言设置
    $('#save-language-setting').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var originalHtml = $button.html();
        var locale = $('#yali-plugin-language-select').val();

        $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + <?php echo json_encode(__('保存中...', 'yali-ai-writer')); ?>);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_save_language_setting',
                nonce: '<?php echo wp_create_nonce('content_auto_manager_nonce'); ?>',
                plugin_locale: locale
            },
            success: function(response) {
                if (response.success) {
                    $button.html('<span class="dashicons dashicons-yes"></span> ' + <?php echo json_encode(__('已保存', 'yali-ai-writer')); ?>);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert(response.data.message || 'Error saving setting');
                    $button.prop('disabled', false).html(originalHtml);
                }
            },
            error: function() {
                alert(<?php echo json_encode(__('网络请求失败，请稍后重试', 'yali-ai-writer')); ?>);
                $button.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // ESC键关闭模态框
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) { // ESC键
            if ($('#clear-queue-modal').hasClass('active')) {
                $('#clear-queue-modal').removeClass('active');
                $('body').css('overflow', '');
            }
            if ($('#bulk-clean-modal').hasClass('active')) {
                $('#bulk-clean-modal').removeClass('active');
                $('body').css('overflow', '');
            }
        }
    });
});
</script>