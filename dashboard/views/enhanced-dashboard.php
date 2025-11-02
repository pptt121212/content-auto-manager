<?php
/**
 * 增强版仪表盘页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
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
            echo '<div class="notice notice-success"><p>分类向量缓存已成功刷新！共处理 ' . count($result) . ' 个最子级分类。</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>分类向量缓存刷新失败，请检查向量API配置。</p></div>';
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
$last_activity = $dashboard_stats['system']['last_activity'];
$last_activity_formatted = $last_activity ?
    human_time_diff(strtotime($last_activity), current_time('timestamp')) . ' ' . __('前', 'content-auto-manager') :
    __('无活动', 'content-auto-manager');

// 加载增强样式
wp_enqueue_style('content-auto-enhanced-dashboard',
    plugins_url('assets/css/enhanced-dashboard.css', dirname(__FILE__)),
    array(), '1.0.0');
?>

<div class="wrap">
    <!-- 仪表盘头部 -->
    <div class="dashboard-header">
        <h1><?php _e('内容自动生成管家', 'content-auto-manager'); ?></h1>
        <div class="subtitle">
            <?php
            printf(__('智能内容生产系统 | 最后活动: %s | 成功率: %s%% | 日均输出: %s 篇', 'content-auto-manager'),
                $last_activity_formatted,
                $dashboard_stats['system']['success_rate'],
                $dashboard_stats['system']['avg_daily_output']
            );
            ?>
        </div>
    </div>

    <div class="content-auto-dashboard">
        <!-- 核心指标概览 -->
        <div class="dashboard-overview">
            <!-- API 配置状态 -->
            <div class="overview-section">
                <h3>🔌 API 配置状态</h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['api_configs']['total']; ?></span>
                        <span class="label">总配置</span>
                    </div>
                    <div class="status-item">
                        <span class="number active"><?php echo $dashboard_stats['api_configs']['active']; ?></span>
                        <span class="label">活跃</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['api_configs']['with_vector']; ?></span>
                        <span class="label">支持向量</span>
                    </div>
                </div>
            </div>

            <!-- 内容生产统计 -->
            <div class="overview-section">
                <h3>📝 内容生产统计</h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['topics']['total']; ?></span>
                        <span class="label">主题总数</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['articles']['total']; ?></span>
                        <span class="label">文章总数</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo number_format($dashboard_stats['articles']['total_words']); ?></span>
                        <span class="label">总字符数</span>
                    </div>
                </div>
            </div>

            <!-- 任务执行状态 -->
            <div class="overview-section">
                <h3>⚡ 任务执行状态</h3>
                <div class="status-grid">
                    <div class="status-item processing">
                        <span class="number"><?php echo $queue_status['pending']; ?></span>
                        <span class="label">待处理</span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $queue_status['processing']; ?></span>
                        <span class="label">处理中</span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $queue_status['completed']; ?></span>
                        <span class="label">已完成</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 详细统计卡片 -->
        <div class="dashboard-cards">
            <!-- 主题任务 -->
            <div class="card primary">
                <div class="icon">📋</div>
                <div class="count"><?php echo $dashboard_stats['topic_tasks']['total']; ?></div>
                <div class="description">主题任务</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['topic_tasks']['total'] > 0 ? ($dashboard_stats['topic_tasks']['completed'] / $dashboard_stats['topic_tasks']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    完成: <?php echo $dashboard_stats['topic_tasks']['completed']; ?> | 失败: <?php echo $dashboard_stats['topic_tasks']['failed']; ?>
                </div>
            </div>

            <!-- 文章任务 -->
            <div class="card success">
                <div class="icon">📄</div>
                <div class="count"><?php echo $dashboard_stats['article_tasks']['total']; ?></div>
                <div class="description">文章任务</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['article_tasks']['total'] > 0 ? ($dashboard_stats['article_tasks']['completed'] / $dashboard_stats['article_tasks']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    完成: <?php echo $dashboard_stats['article_tasks']['completed']; ?> | 失败: <?php echo $dashboard_stats['article_tasks']['failed']; ?>
                </div>
            </div>

            <!-- 向量覆盖 -->
            <div class="card info">
                <div class="icon">🎯</div>
                <div class="count"><?php echo $vector_coverage; ?>%</div>
                <div class="description">向量覆盖率</div>
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $vector_coverage < 50 ? 'warning' : ''; ?>" style="width: <?php echo $vector_coverage; ?>%"></div>
                </div>
                <div class="trend">
                    已向量化: <?php echo $dashboard_stats['topics']['with_vectors']; ?> / <?php echo $dashboard_stats['topics']['total']; ?>
                </div>
            </div>

            <!-- 自动配图 -->
            <div class="card warning">
                <div class="icon">🖼️</div>
                <div class="count"><?php echo $dashboard_stats['articles']['with_auto_images']; ?></div>
                <div class="description">自动配图文章</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $dashboard_stats['articles']['total'] > 0 ? ($dashboard_stats['articles']['with_auto_images'] / $dashboard_stats['articles']['total']) * 100 : 0; ?>%"></div>
                </div>
                <div class="trend">
                    总生成图片: <?php echo $dashboard_stats['articles']['total_auto_images']; ?>
                </div>
            </div>

            <!-- 已发布文章 -->
            <div class="card success">
                <div class="icon">🚀</div>
                <div class="count"><?php echo $dashboard_stats['articles']['published']; ?></div>
                <div class="description">已发布文章</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo $article_success_rate; ?>%"></div>
                </div>
                <div class="trend">
                    成功率: <?php echo $article_success_rate; ?>%
                </div>
            </div>

            <!-- 规则配置 -->
            <div class="card primary">
                <div class="icon">⚙️</div>
                <div class="count"><?php echo $dashboard_stats['rules']['active']; ?></div>
                <div class="description">活跃规则</div>
                <div class="trend">
                    总规则: <?php echo $dashboard_stats['rules']['total']; ?>
                </div>
            </div>

            <!-- 高优先级主题 -->
            <div class="card danger">
                <div class="icon">⭐</div>
                <div class="count"><?php echo $dashboard_stats['topics']['high_priority']; ?></div>
                <div class="description">高优先级主题</div>
                <div class="trend">
                    占比: <?php echo $dashboard_stats['topics']['total'] > 0 ? round(($dashboard_stats['topics']['high_priority'] / $dashboard_stats['topics']['total']) * 100, 1) : 0; ?>%
                </div>
            </div>

            <!-- 向量聚类 -->
            <div class="card info">
                <div class="icon">🔗</div>
                <div class="count"><?php echo $dashboard_stats['topics']['clusters']; ?></div>
                <div class="description">向量聚类数</div>
                <div class="trend">
                    聚类主题: <?php echo $dashboard_stats['topics']['with_vectors']; ?>
                </div>
            </div>
        </div>

        <!-- 详细状态信息 -->
        <div class="status-grid-2">
            <!-- 主题状态分布 -->
            <div class="content-auto-section">
                <h2>📊 主题状态分布</h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo $dashboard_stats['topics']['unused']; ?></span>
                        <span class="label">未使用</span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $dashboard_stats['topics']['queued']; ?></span>
                        <span class="label">队列中</span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['topics']['used']; ?></span>
                        <span class="label">已使用</span>
                    </div>
                </div>
                <div class="progress-bar" style="margin-top: 15px;">
                    <div class="progress-fill" style="width: <?php echo $topic_usage_rate; ?>%"></div>
                </div>
                <p style="text-align: center; margin-top: 10px; color: #64748b;">
                    主题使用率: <strong><?php echo $topic_usage_rate; ?>%</strong>
                </p>
            </div>

            <!-- 文章状态分布 -->
            <div class="content-auto-section">
                <h2>📈 文章状态分布</h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo $dashboard_stats['articles']['pending']; ?></span>
                        <span class="label">待处理</span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo $dashboard_stats['articles']['processing']; ?></span>
                        <span class="label">处理中</span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['articles']['completed']; ?></span>
                        <span class="label">已完成</span>
                    </div>
                    <div class="status-item failed">
                        <span class="number"><?php echo $dashboard_stats['articles']['failed']; ?></span>
                        <span class="label">失败</span>
                    </div>
                </div>
            </div>

            <!-- 分类缓存管理 -->
            <div class="content-auto-section">
                <h2>🏷️ 分类缓存管理</h2>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-label">缓存状态</div>
                        <div class="stat-value">
                            <?php if ($category_cache_status['cache_exists']): ?>
                                <span style="color: green;">✅ 已缓存</span>
                                <br><small><?php echo date('m-d H:i', $category_cache_status['cache_time']); ?></small>
                            <?php else: ?>
                                <span style="color: orange;">⚠️ 未缓存</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">最子级分类</div>
                        <div class="stat-value"><?php echo $leaf_categories_count; ?> 个</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">已缓存分类</div>
                        <div class="stat-value"><?php echo $category_cache_status['category_count']; ?> 个</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-label">待匹配主题</div>
                        <div class="stat-value"><?php echo $unmatched_manual_topics; ?> 个</div>
                    </div>
                </div>
                
                <form method="post" action="" style="margin-top: 15px;">
                    <?php wp_nonce_field('content_auto_category_cache', 'content_auto_nonce'); ?>
                    <button type="submit" name="refresh_category_cache" class="button button-primary">
                        <?php echo $category_cache_status['cache_exists'] ? '刷新分类缓存' : '生成分类缓存'; ?>
                    </button>
                    <small style="margin-left: 10px; color: #666;">
                        手工添加主题时会自动匹配最相似的分类
                    </small>
                </form>
            </div>

            <!-- 向量生成状态 -->
            <div class="content-auto-section">
                <h2>🧠 向量生成状态</h2>
                <div class="status-grid">
                    <div class="status-item pending">
                        <span class="number"><?php echo isset($vector_stats['pending_vector_tasks']) ? $vector_stats['pending_vector_tasks'] : 0; ?></span>
                        <span class="label">待处理</span>
                    </div>
                    <div class="status-item processing">
                        <span class="number"><?php echo isset($vector_stats['processing_vector_tasks']) ? $vector_stats['processing_vector_tasks'] : 0; ?></span>
                        <span class="label">处理中</span>
                    </div>
                    <div class="status-item completed">
                        <span class="number"><?php echo $dashboard_stats['topics']['vector_completed']; ?></span>
                        <span class="label">已完成</span>
                    </div>
                    <div class="status-item failed">
                        <span class="number"><?php echo $dashboard_stats['topics']['vector_failed']; ?></span>
                        <span class="label">失败</span>
                    </div>
                </div>
            </div>

            <!-- 队列任务分布 -->
            <div class="content-auto-section">
                <h2>⚙️ 队列任务分布</h2>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['topic_jobs']; ?></span>
                        <span class="label">主题任务</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['article_jobs']; ?></span>
                        <span class="label">文章任务</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['vector_jobs']; ?></span>
                        <span class="label">向量任务</span>
                    </div>
                    <div class="status-item">
                        <span class="number"><?php echo $dashboard_stats['queue']['high_priority']; ?></span>
                        <span class="label">高优先级</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 系统状态监控 -->
        <div class="content-auto-section">
            <h2>🖥️ 系统状态监控</h2>
            <div class="system-status">
                <div class="status-indicator">
                    <div class="indicator-light <?php echo $queue_status['processing'] > 0 ? 'green' : 'yellow'; ?>"></div>
                    <div class="info">
                        <div class="label">任务处理器状态</div>
                        <div class="value">
                            <?php echo $queue_status['processing'] > 0 ? '正在处理任务' : '待机中'; ?> |
                            队列中有 <?php echo $queue_status['pending']; ?> 个任务待处理
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $dashboard_stats['api_configs']['active'] > 0 ? 'green' : 'red'; ?>"></div>
                    <div class="info">
                        <div class="label">API 配置状态</div>
                        <div class="value">
                            <?php echo $dashboard_stats['api_configs']['active']; ?> 个活跃配置 |
                            <?php echo $dashboard_stats['api_configs']['with_vector']; ?> 个支持向量处理
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $vector_coverage >= 80 ? 'green' : ($vector_coverage >= 50 ? 'yellow' : 'red'); ?>"></div>
                    <div class="info">
                        <div class="label">向量处理状态</div>
                        <div class="value">
                            覆盖率 <?php echo $vector_coverage; ?>% |
                            <?php echo $dashboard_stats['topics']['clusters']; ?> 个聚类 |
                            <?php echo $dashboard_stats['topics']['vector_pending']; ?> 个待处理
                        </div>
                    </div>
                </div>

                <div class="status-indicator">
                    <div class="indicator-light <?php echo $dashboard_stats['system']['success_rate'] >= 90 ? 'green' : ($dashboard_stats['system']['success_rate'] >= 70 ? 'yellow' : 'red'); ?>"></div>
                    <div class="info">
                        <div class="label">系统性能</div>
                        <div class="value">
                            成功率 <?php echo $dashboard_stats['system']['success_rate']; ?>% |
                            日均输出 <?php echo $dashboard_stats['system']['avg_daily_output']; ?> 篇 |
                            总生成 <?php echo $dashboard_stats['system']['total_generated_content']; ?> 个内容
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 发布配置状态 -->
        <div class="content-auto-section">
            <h2>🚀 发布配置状态</h2>
            <div class="status-grid-2">
                <div class="status-card">
                    <h4>自动发布</h4>
                    <div class="stat-row">
                        <span class="stat-label">启用自动发布</span>
                        <span class="stat-value <?php echo $dashboard_stats['publish_rules']['auto_publish_enabled'] > 0 ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['auto_publish_enabled'] > 0 ? '是' : '否'; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">自动配图</span>
                        <span class="stat-value <?php echo $dashboard_stats['publish_rules']['auto_images_enabled'] > 0 ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['auto_images_enabled'] > 0 ? '是' : '否'; ?>
                        </span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">内链功能</span>
                        <span class="stat-value <?php echo $dashboard_stats['publish_rules']['internal_linking_enabled'] > 0 ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $dashboard_stats['publish_rules']['internal_linking_enabled'] > 0 ? '是' : '否'; ?>
                        </span>
                    </div>
                </div>

                <div class="status-card">
                    <h4>内容质量</h4>
                    <div class="stat-row">
                        <span class="stat-label">平均处理时间</span>
                        <span class="stat-value"><?php echo round($dashboard_stats['articles']['avg_processing_time']); ?>秒</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">总字符数</span>
                        <span class="stat-value"><?php echo number_format($dashboard_stats['articles']['total_words']); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">已发布</span>
                        <span class="stat-value"><?php echo $dashboard_stats['articles']['published']; ?> 篇</span>
                    </div>
                </div>

                <div class="status-card">
                    <h4>文章结构</h4>
                    <div class="stat-row">
                        <span class="stat-label">结构模板</span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['total']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">支持向量</span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['with_vectors']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">总使用次数</span>
                        <span class="stat-value"><?php echo $dashboard_stats['article_structures']['total_usage']; ?></span>
                    </div>
                </div>
            </div>
        </div>

  
        <!-- 队列管理区域 -->
        <div class="content-auto-section">
            <h2>🔧 队列管理</h2>
            <div class="queue-management">
                <div class="queue-status-info">
                    <p>当前队列状态：<span class="queue-count"><?php echo $queue_status['total']; ?></span> 个任务待处理</p>
                    <p>处理中任务：<span class="processing-count"><?php echo $queue_status['processing']; ?></span> 个</p>
                </div>
                <div class="queue-actions">
                    <button type="button" id="clear-queue-btn" class="button button-danger">
                        <span class="dashicons dashicons-trash"></span>
                        清除任务队列
                    </button>
                    <small class="description">⚠️ 将重置所有任务状态并清空队列，请谨慎操作</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 清除队列确认对话框 -->
<div id="clear-queue-modal" style="display: none;">
    <div class="modal-backdrop"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3>⚠️ 确认清除任务队列</h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <p><strong>您即将执行以下操作：</strong></p>
            <ul>
                <li>重置所有 <strong>处理中/失败</strong> 的主题任务为待处理状态</li>
                <li>重置所有 <strong>处理中/失败</strong> 的文章任务为待处理状态</li>
                <li><strong>删除</strong>所有队列项目</li>
                <li>重置任务的最后处理时间</li>
            </ul>
            <div class="warning-box">
                <p><strong>⚠️ 警告：</strong></p>
                <ul>
                    <li>此操作<strong>不可撤销</strong></li>
                    <li>所有正在执行的任务将被中断</li>
                    <li>队列进度信息将丢失</li>
                </ul>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="button button-secondary modal-cancel">取消</button>
            <button type="button" class="button button-primary modal-confirm" id="confirm-clear-queue">
                <span class="dashicons dashicons-trash"></span>
                确认清除
            </button>
        </div>
    </div>
</div>

<!-- 添加样式 -->
<style>
.queue-management {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    margin: 20px 0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.queue-status-info {
    margin-bottom: 15px;
}

.queue-status-info p {
    margin: 5px 0;
    font-size: 14px;
}

.queue-count, .processing-count {
    font-weight: bold;
    color: #23282d;
}

.queue-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.button-danger {
    background: #dc3545;
    border-color: #dc3545;
    color: white;
}

.button-danger:hover {
    background: #c82333;
    border-color: #bd2130;
    color: white;
}

.description {
    color: #666;
    font-size: 12px;
    font-style: italic;
}

/* 模态框样式 */
.modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 100000;
}

.modal-content {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
    z-index: 100001;
    box-shadow: 0 5px 15px rgba(0,0,0,0.3);
}

.modal-header {
    padding: 20px 20px 10px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    font-size: 18px;
    color: #333;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    padding: 0;
    width: 30px;
    height: 30px;
    line-height: 1;
}

.modal-body {
    padding: 20px;
}

.modal-body ul {
    margin: 10px 0;
    padding-left: 20px;
}

.modal-body li {
    margin: 5px 0;
}

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

.modal-footer {
    padding: 10px 20px 20px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
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
        $('#clear-queue-modal').show();
    });

    // 模态框关闭
    $('.modal-close, .modal-cancel').on('click', function() {
        $('#clear-queue-modal').hide();
    });

    // 点击背景关闭
    $('.modal-backdrop').on('click', function() {
        $('#clear-queue-modal').hide();
    });

    // 确认清除队列
    $('#confirm-clear-queue').on('click', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $btnText = $button.html();

        // 显示加载状态
        $button.addClass('clearing-queue');
        $button.prop('disabled', true);
        $button.html('<span class="dashicons dashicons-update spin"></span> 清除中...');

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
                    var $successDiv = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                    $('.wrap').prepend($successDiv);

                    // 3秒后自动隐藏
                    setTimeout(function() {
                        $successDiv.fadeOut(function() {
                            $successDiv.remove();
                        });
                    }, 5000);

                    // 关闭模态框
                    $('#clear-queue-modal').hide();

                    // 刷新页面数据（可选）
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    // 显示错误消息
                    var $errorDiv = $('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                    $('.wrap').prepend($errorDiv);

                    setTimeout(function() {
                        $errorDiv.fadeOut(function() {
                            $errorDiv.remove();
                        });
                    }, 5000);
                }
            },
            error: function(xhr, status, error) {
                // 显示网络错误消息
                var $errorDiv = $('<div class="notice notice-error is-dismissible"><p>网络错误，请稍后重试。</p></div>');
                $('.wrap').prepend($errorDiv);

                setTimeout(function() {
                    $errorDiv.fadeOut(function() {
                        $errorDiv.remove();
                    });
                }, 5000);
            },
            complete: function() {
                // 恢复按钮状态
                $button.removeClass('clearing-queue');
                $button.prop('disabled', false);
                $button.html($btnText);
            }
        });
    });

    // ESC键关闭模态框
    $(document).on('keydown', function(e) {
        if (e.keyCode === 27) { // ESC键
            $('#clear-queue-modal').hide();
        }
    });
});
</script>