<?php
/**
 * 主题管理页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 处理删除操作


// 删除操作已改为AJAX处理


// 处理生成文章操作
if (isset($_POST['generate_articles']) && isset($_POST['topic_ids'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_generate_articles')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }
    
    $topic_ids = $_POST['topic_ids'];
    
    if (!empty($topic_ids) && is_array($topic_ids)) {
        // 创建文章生成父任务
        $article_task_manager = new ContentAuto_ArticleTaskManager();
        $task_id = $article_task_manager->create_article_task($topic_ids);
        
        if ($task_id) {
            echo '<script>window.addEventListener("load", function() { window.yaliToast("' . esc_js(__('文章生成父任务已创建。', 'yali-ai-writer')) . '", "success"); });</script>';
        } else {
            echo '<script>window.addEventListener("load", function() { window.yaliToast("' . esc_js(__('文章生成父任务创建失败。', 'yali-ai-writer')) . '", "error"); });</script>';
        }
    }
}

// 获取筛选参数
$task_id = isset($_GET['task_id']) ? sanitize_text_field($_GET['task_id']) : '';
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : CONTENT_AUTO_TOPIC_UNUSED;
$title_keyword = isset($_GET['title_keyword']) ? sanitize_text_field($_GET['title_keyword']) : '';
$matched_category = isset($_GET['matched_category']) ? sanitize_text_field($_GET['matched_category']) : '';
$priority_score = isset($_GET['priority_score']) ? sanitize_text_field($_GET['priority_score']) : '';
$has_vector = isset($_GET['has_vector']) ? sanitize_text_field($_GET['has_vector']) : '';
$has_reference = isset($_GET['has_reference']) ? sanitize_text_field($_GET['has_reference']) : '';

// 获取分页参数
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($current_page - 1) * $per_page;

// 获取主题，按最后更新时间排序，支持分页
global $wpdb;
$topics_table = $wpdb->prefix . 'content_auto_topics';
$where_clauses = array();
$where_values = array();

// 状态筛选
if (!empty($status)) {
    $where_clauses[] = 'status = %s';
    $where_values[] = $status;
}

// 任务ID筛选
if (!empty($task_id)) {
    $where_clauses[] = 'task_id = %s';
    $where_values[] = $task_id;
}

// 标题关键字搜索
if (!empty($title_keyword)) {
    $where_clauses[] = 'title LIKE %s';
    $where_values[] = '%' . $wpdb->esc_like($title_keyword) . '%';
}

// 推荐分类筛选
if (!empty($matched_category)) {
    if ($matched_category === '__empty__') {
        $where_clauses[] = "(matched_category IS NULL OR matched_category = '')";
    } else {
        $where_clauses[] = 'matched_category = %s';
        $where_values[] = $matched_category;
    }
}

// 优先级筛选
if (!empty($priority_score)) {
    $where_clauses[] = 'priority_score = %d';
    $where_values[] = intval($priority_score);
}

// 生成向量状态筛选
if ($has_vector !== '') {
    if ($has_vector === '1') {
        $where_clauses[] = "(vector_embedding IS NOT NULL AND vector_embedding != '')";
    } elseif ($has_vector === '0') {
        $where_clauses[] = "(vector_embedding IS NULL OR vector_embedding = '')";
    }
}

// 参考资料筛选
if ($has_reference !== '') {
    if ($has_reference === '1') {
        $where_clauses[] = "(reference_material IS NOT NULL AND reference_material != '')";
    } elseif ($has_reference === '0') {
        $where_clauses[] = "(reference_material IS NULL OR reference_material = '')";
    }
}

// 构建WHERE子句
$where_clause = '';
if (!empty($where_clauses)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_clauses);
}

// 获取总记录数
$count_query = "SELECT COUNT(*) FROM $topics_table $where_clause";
if (!empty($where_values)) {
    $count_query = $wpdb->prepare($count_query, $where_values);
}
$total_items = $wpdb->get_var($count_query);

// 计算总页数
$total_pages = ceil($total_items / $per_page);

// 获取分页数据
$query = "SELECT * FROM $topics_table $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
$pagination_values = array_merge($where_values, array($per_page, $offset));
if (!empty($pagination_values)) {
    $query = $wpdb->prepare($query, $pagination_values);
}

$topics = $wpdb->get_results($query, ARRAY_A);

// 获取规则管理器
$rule_manager = new ContentAuto_RuleManager();
?>

<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title">
        <span class="dashicons dashicons-category"></span> <?php _e('主题管理', 'yali-ai-writer'); ?>
    </h1>
    
    <!-- 高级筛选器 (Collapsible) -->
    <details class="yali-accordion">
        <summary><?php _e('高级筛选', 'yali-ai-writer'); ?></summary>
        <div class="yali-accordion-content">
            <form method="get" action="" id="advanced-filter-form">
                <input type="hidden" name="page" value="yali-ai-writer-topics">
                <input type="hidden" name="paged" value="1">
                
                <div class="filter-grid">
                    <!-- 第一行筛选条件 -->
                    <div class="filter-row">
                        <div class="filter-item">
                            <label for="filter-title"><?php _e('标题关键字', 'yali-ai-writer'); ?></label>
                            <input type="text" id="filter-title" name="title_keyword" 
                                   value="<?php echo esc_attr(isset($_GET['title_keyword']) ? $_GET['title_keyword'] : ''); ?>" 
                                   class="regular-text yali-input" placeholder="<?php esc_attr_e('输入关键字搜索标题...', 'yali-ai-writer'); ?>">
                        </div>
                        
                        <div class="filter-item">
                            <label for="filter-status"><?php _e('状态', 'yali-ai-writer'); ?></label>
                            <select id="filter-status" name="status" class="yali-select">
                                <option value="unused" <?php selected($status, 'unused'); ?>><?php _e('未使用', 'yali-ai-writer'); ?></option>
                                <option value="queued" <?php selected($status, 'queued'); ?>><?php _e('队列中', 'yali-ai-writer'); ?></option>
                                <option value="used" <?php selected($status, 'used'); ?>><?php _e('已使用', 'yali-ai-writer'); ?></option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label for="filter-category"><?php _e('推荐分类', 'yali-ai-writer'); ?></label>
                            <select id="filter-category" name="matched_category" class="yali-select">
                                <option value=""><?php _e('全部分类', 'yali-ai-writer'); ?></option>
                                <option value="__empty__" <?php selected(isset($_GET['matched_category']) ? $_GET['matched_category'] : '', '__empty__'); ?>><?php _e('无分类', 'yali-ai-writer'); ?></option>
                                <?php
                                // 获取现有分类
                                global $wpdb;
                                $topics_table = $wpdb->prefix . 'content_auto_topics';
                                $existing_categories = $wpdb->get_col("
                                    SELECT DISTINCT matched_category 
                                    FROM {$topics_table} 
                                    WHERE matched_category IS NOT NULL AND matched_category != ''
                                    ORDER BY matched_category ASC
                                ");
                                foreach ($existing_categories as $cat) {
                                    $selected = (isset($_GET['matched_category']) && $_GET['matched_category'] === $cat) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($cat) . '" ' . $selected . '>' . esc_html($cat) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label for="filter-priority"><?php _e('优先级', 'yali-ai-writer'); ?></label>
                            <select id="filter-priority" name="priority_score" class="yali-select">
                                <option value=""><?php _e('全部', 'yali-ai-writer'); ?></option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>" <?php selected(isset($_GET['priority_score']) ? $_GET['priority_score'] : '', $i); ?>>
                                        <?php echo str_repeat('★', $i); ?> (<?php echo $i; ?>/5)
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- 第二行筛选条件 -->
                    <div class="filter-row">
                        <div class="filter-item">
                            <label for="filter-vector"><?php _e('生成向量', 'yali-ai-writer'); ?></label>
                            <select id="filter-vector" name="has_vector" class="yali-select">
                                <option value=""><?php _e('全部', 'yali-ai-writer'); ?></option>
                                <option value="1" <?php selected(isset($_GET['has_vector']) ? $_GET['has_vector'] : '', '1'); ?>><?php _e('已生成', 'yali-ai-writer'); ?></option>
                                <option value="0" <?php selected(isset($_GET['has_vector']) ? $_GET['has_vector'] : '', '0'); ?>><?php _e('未生成', 'yali-ai-writer'); ?></option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label for="filter-reference"><?php _e('参考资料', 'yali-ai-writer'); ?></label>
                            <select id="filter-reference" name="has_reference" class="yali-select">
                                <option value=""><?php _e('全部', 'yali-ai-writer'); ?></option>
                                <option value="1" <?php selected(isset($_GET['has_reference']) ? $_GET['has_reference'] : '', '1'); ?>><?php _e('有参考资料', 'yali-ai-writer'); ?></option>
                                <option value="0" <?php selected(isset($_GET['has_reference']) ? $_GET['has_reference'] : '', '0'); ?>><?php _e('无参考资料', 'yali-ai-writer'); ?></option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label for="filter-task-id"><?php _e('任务ID', 'yali-ai-writer'); ?></label>
                            <input type="text" id="filter-task-id" name="task_id" 
                                   value="<?php echo esc_attr($task_id); ?>" 
                                   class="regular-text yali-input" placeholder="<?php esc_attr_e('留空显示全部', 'yali-ai-writer'); ?>">
                        </div>
                        
                        <div class="filter-item filter-buttons" style="display: block; width: 100%; margin-top: 10px;">
                            <label>&nbsp;</label>
                            <?php submit_button(__('筛选', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit', false); ?>
                            <a href="<?php echo admin_url('admin.php?page=yali-ai-writer-topics'); ?>" class="button yali-btn yali-btn-secondary" id="reset-filters" style="margin-left: 5px;"><?php _e('重置', 'yali-ai-writer'); ?></a>
                            <!-- 保留JS处理逻辑以防万一 -->
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </details>
        
    <!-- 重复标题检测区域 (Collapsible) -->
    <details class="yali-accordion">
        <summary><?php _e('重复标题检测', 'yali-ai-writer'); ?></summary>
        <div class="yali-accordion-content">
            <div class="duplicate-detection-section">
                <!-- <h3>重复标题检测</h3> Remove inner h3 as summary serves as title -->
                <p class="description yali-desc"><?php _e('检测完全相同的标题或向量相似度高于设定阈值的主题，支持一键删除重复项（保留最早创建的）', 'yali-ai-writer'); ?></p>
                
                <div class="duplicate-actions">
                    <div class="threshold-input-group" style="display:inline-block; margin-right:10px;">
                        <label for="duplicate-threshold"><?php _e('相似度阈值:', 'yali-ai-writer'); ?></label>
                        <input type="number" id="duplicate-threshold" value="90" min="10" max="100" step="1" style="width: 60px;" class="yali-input"> %
                    </div>
                    <button type="button" class="button yali-btn yali-btn-secondary" id="detect-duplicates">
                        <span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
                        <?php _e('检测重复标题', 'yali-ai-writer'); ?>
                    </button>
                    <button type="button" class="button button-link-delete yali-btn yali-btn-danger" id="delete-all-duplicates" style="display:none;">
                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                        <?php _e('一键删除所有重复', 'yali-ai-writer'); ?>
                    </button>
                    <span id="duplicate-status" class="duplicate-status"></span>
                </div>
                
                <div id="duplicate-results" style="display:none;"></div>
            </div>
        </div>
    </details>
    
    <!-- 批量操作区域 -->
    <div class="yali-card bulk-actions-section" id="bulk-actions-section" style="display:none;">
        <h3><?php _e('批量操作', 'yali-ai-writer'); ?></h3>
        <div class="bulk-action-buttons">
            <span id="selected-count" class="yali-badge yali-badge-neutral">0</span> <?php _e('个主题已选中', 'yali-ai-writer'); ?>
            <button type="button" class="button button-link-delete yali-btn yali-btn-danger" id="bulk-delete-selected">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php _e('删除选中', 'yali-ai-writer'); ?>
            </button>
            <span class="bulk-separator">|</span>
            <button type="button" class="button button-link-delete yali-btn yali-btn-danger" id="bulk-delete-all-filtered">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php _e('删除所有符合条件的主题', 'yali-ai-writer'); ?> 
                (<span id="filtered-total-count"><?php echo intval($total_items); ?></span> <?php _e('条', 'yali-ai-writer'); ?>)
            </button>
            
            <span class="bulk-separator" style="margin-left: 10px;">|</span>
            <button type="button" class="button button-primary yali-btn yali-btn-primary" id="bulk-generate-reference" style="margin-left: 10px;">
                <span class="dashicons dashicons-book" style="vertical-align: middle;"></span>
                <?php _e('生成参考资料', 'yali-ai-writer'); ?>
            </button>
        </div>
        <p class="bulk-action-warning" style="margin-top: 10px; color: #856404; font-size: 12px;">
            <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
            <?php _e('提示：只能删除"未使用"状态的主题，队列中和已使用的主题不会被删除。', 'yali-ai-writer'); ?>
        </p>
    </div>
    
    <!-- 手工添加主题弹窗触发器 -->

    <!-- 生成文章表单 -->
    <form method="post" action="">
        <?php wp_nonce_field('content_auto_manager_generate_articles', 'content_auto_manager_nonce'); ?>
        
        <!-- 主题列表 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <div class="yali-card-title" style="font-size: 1.3em; font-weight: 600;"><?php _e('主题列表', 'yali-ai-writer'); ?></div>
                <div class="yali-card-actions" style="display: flex; gap: 10px; align-items: center;">
                    <a href="#" id="open-manual-add-modal" class="button yali-btn yali-btn-secondary">
                        <span class="dashicons dashicons-plus-alt2"></span> <?php _e('手工添加主题', 'yali-ai-writer'); ?>
                    </a>
                    <?php if (!empty($topics)): ?>
                        <input type="submit" name="generate_articles" class="button button-primary yali-btn yali-btn-primary" value="<?php esc_attr_e('生成文章', 'yali-ai-writer'); ?>">
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($total_items > 0): ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <span class="displaying-num">
                            <?php
                            printf(
                                __('共 %s 条记录，当前显示第 %s-%s 条', 'yali-ai-writer'),
                                '<strong>' . number_format_i18n($total_items) . '</strong>',
                                '<strong>' . number_format_i18n(($current_page - 1) * $per_page + 1) . '</strong>',
                                '<strong>' . number_format_i18n(min($current_page * $per_page, $total_items)) . '</strong>'
                            );
                            ?>
                        </span>
                    </div>
                    <div class="tablenav-pages">
                        <span class="yali-pagination">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'yali-ai-writer-topics',
                                'task_id' => $task_id,
                                'status' => $status
                            ), remove_query_arg('paged'));

                            // 上一页
                            if ($current_page > 1):
                                $prev_url = add_query_arg('paged', $current_page - 1, $base_url);
                                echo '<a class="prev-page page-numbers" href="' . esc_url($prev_url) . '"><span class="screen-reader-text">' . __('上一页', 'yali-ai-writer') . '</span><span aria-hidden="true">‹</span></a>';
                            else:
                                echo '<span class="page-numbers disabled" aria-hidden="true">‹</span>';
                            endif;

                            // 页码显示
                            $page_links = array();
                            $dots = false;

                            for ($i = 1; $i <= $total_pages; $i++) {
                                if ($i == 1 || $i == $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)) {
                                    if ($i == $current_page) {
                                        $page_links[] = '<span class="page-numbers current" aria-current="page">' . number_format_i18n($i) . '</span>';
                                    } else {
                                        $page_url = add_query_arg('paged', $i, $base_url);
                                        $page_links[] = '<a class="page-numbers" href="' . esc_url($page_url) . '">' . number_format_i18n($i) . '</a>';
                                    }
                                    $dots = false;
                                } elseif (!$dots) {
                                    $page_links[] = '<span class="page-numbers dots" aria-hidden="true">...</span>';
                                    $dots = true;
                                }
                            }

                            echo implode("\n", $page_links);

                            // 下一页
                            if ($current_page < $total_pages):
                                $next_url = add_query_arg('paged', $current_page + 1, $base_url);
                                echo '<a class="next-page page-numbers" href="' . esc_url($next_url) . '"><span class="screen-reader-text">' . __('下一页', 'yali-ai-writer') . '</span><span aria-hidden="true">›</span></a>';
                            else:
                                echo '<span class="page-numbers disabled" aria-hidden="true">›</span>';
                            endif;
                            ?>
                        </span>
                    </div>
                    <br class="clear">
                </div>
            <?php endif; ?>

            <?php if (empty($topics)): ?>
                <p><?php _e('暂无主题。', 'yali-ai-writer'); ?></p>
            <?php else: ?>
                <div class="yali-table-responsive">
                    <table class="yali-table">
                    <colgroup>
                        <col class="manage-column check-column">
                        <col class="col-id">
                        <col class="col-task-id">
                        <col class="col-title">
                        <col class="col-angle">
                        <col class="col-value">
                        <col class="col-seo">
                        <col class="col-category">
                        <col class="col-priority">
                        <col class="col-api">
                        <col class="col-status">
                        <col class="col-vector">
                        <col class="col-reference">
                        <col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="check-column"><input type="checkbox" id="select-all-topics"></th>
                            <th><?php _e('ID', 'yali-ai-writer'); ?></th>
                            <th><?php _e('任务ID', 'yali-ai-writer'); ?></th>
                            <th><?php _e('标题', 'yali-ai-writer'); ?></th>
                            <th><?php _e('内容角度', 'yali-ai-writer'); ?></th>
                            <th><?php _e('用户价值', 'yali-ai-writer'); ?></th>
                            <th><?php _e('SEO关键词', 'yali-ai-writer'); ?></th>
                            <th><?php _e('推荐分类', 'yali-ai-writer'); ?></th>
                            <th><?php _e('优先级', 'yali-ai-writer'); ?></th>
                            <th><?php _e('API配置', 'yali-ai-writer'); ?></th>
                            <th><?php _e('状态', 'yali-ai-writer'); ?></th>
                            <th><?php _e('向量', 'yali-ai-writer'); ?></th>
                            <th><?php _e('参考资料', 'yali-ai-writer'); ?></th>
                            <th><?php _e('操作', 'yali-ai-writer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topics as $topic): ?>
                            <?php
                            $rule = null;
                            if ($topic['rule_id'] > 0) {
                                $rule = $rule_manager->get_rule($topic['rule_id']);
                            }
                            ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="topic_ids[]" value="<?php echo esc_attr($topic['id']); ?>" 
                                        class="topic-checkbox" 
                                        <?php echo ($topic['status'] === CONTENT_AUTO_TOPIC_QUEUED || $topic['status'] === CONTENT_AUTO_TOPIC_USED) ? 'disabled' : ''; ?>>
                                </th>
                                <td><?php echo esc_html($topic['id']); ?></td>
                                <td>
                                    <?php echo esc_html($topic['task_id']); ?>
                                </td>
                                <td>
                                    <div class="topic-title-wrapper">
                                        <strong><?php echo esc_html($topic['title']); ?></strong>
                                    </div>
                                    <?php if (!empty($topic['task_id']) && $topic['task_id'] !== '0'): ?>
                                        <small class="topic-meta" style="color: #666; margin-top: 4px; display: block;">
                                            <?php _e('项目:', 'yali-ai-writer'); ?> <?php echo $topic['rule_item_index'] + 1; ?> | 
                                            <?php _e('规则:', 'yali-ai-writer'); ?> <?php echo $rule ? esc_html($rule['rule_name']) : __('无', 'yali-ai-writer'); ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="topic-meta" style="color: #999; margin-top: 4px; display:block;"><?php _e('手工添加', 'yali-ai-writer'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['source_angle'])): ?>
                                        <span class="source-angle"><?php echo esc_html(__($topic['source_angle'], 'yali-ai-writer')); ?></span>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['user_value'])): ?>
                                        <div class="user-value" title="<?php echo esc_attr($topic['user_value']); ?>">
                                            <?php echo esc_html(content_auto_manager_truncate_string($topic['user_value'], 10)); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['seo_keywords'])): ?>
                                        <?php 
                                        $keywords = json_decode($topic['seo_keywords'], true);
                                        if (is_array($keywords) && !empty($keywords)) {
                                            echo '<div class="seo-wrapper">';
                                            foreach ($keywords as $keyword) {
                                                echo '<span class="keyword-tag">' . esc_html($keyword) . '</span> ';
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '<span class="no-data">-</span>';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['matched_category'])): ?>
                                        <span class="matched-category"><?php echo esc_html($topic['matched_category']); ?></span>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($topic['priority_score'] > 0): ?>
                                        <div class="priority-score priority-<?php echo $topic['priority_score']; ?>" style="white-space: nowrap;">
                                            <?php echo str_repeat('★', $topic['priority_score']); ?>
                                            <small>(<?php echo $topic['priority_score']; ?>)</small>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['api_config_name'])): ?>
                                        <span class="api-config-name"><?php echo esc_html(__($topic['api_config_name'], 'yali-ai-writer')); ?></span>
                                    <?php elseif (!empty($topic['task_id']) && $topic['task_id'] !== '0'): ?>
                                        <span class="no-data">-</span>
                                    <?php else: ?>
                                        <span class="no-data"><?php _e('未使用API', 'yali-ai-writer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $status_class = 'yali-badge yali-badge-neutral';
                                    if ($topic['status'] === 'queued' || $topic['status'] === 'running') {
                                        $status_class = 'yali-badge yali-badge-warning';
                                    } elseif ($topic['status'] === 'completed' || $topic['status'] === 'used') {
                                        $status_class = 'yali-badge yali-badge-success';
                                    } elseif ($topic['status'] === 'failed') {
                                        $status_class = 'yali-badge yali-badge-error';
                                    }
                                    ?>
                                    <span class="<?php echo $status_class; ?>">
                                        <?php echo content_auto_manager_get_topic_status_label($topic['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $vector_status = isset($topic['vector_status']) ? $topic['vector_status'] : 'pending';
                                    $status_text = __('否', 'yali-ai-writer');
                                    $status_class = 'yali-badge yali-badge-neutral';
                                    $error_title = '';

                                    if ($vector_status === 'completed') {
                                        $status_text = __('是', 'yali-ai-writer');
                                        $status_class = 'yali-badge yali-badge-success';
                                    } elseif ($vector_status === 'failed') {
                                        $status_text = __('失败', 'yali-ai-writer');
                                        $status_class = 'yali-badge yali-badge-error';
                                        $error_title = isset($topic['vector_error']) ? esc_attr($topic['vector_error']) : '';
                                    }
                                    ?>
                                    <span class="<?php echo $status_class; ?>" title="<?php echo $error_title; ?>">
                                        <?php echo esc_html($status_text); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    // 显示主题级参考资料
                                    $topic_reference = isset($topic['reference_material']) ? trim($topic['reference_material']) : '';
                                    $rule_reference = '';

                                    // 如果没有主题级参考资料，显示规则级参考资料
                                    if (empty($topic_reference) && !empty($topic['rule_id']) && $rule) {
                                        $rule_reference = isset($rule['reference_material']) ? trim($rule['reference_material']) : '';
                                    }

                                    $reference_text = !empty($topic_reference) ? $topic_reference : $rule_reference;

                                    if (!empty($reference_text)) {
                                        // 截断逻辑：15个字
                                        $display_text = mb_substr($reference_text, 0, 15);
                                        if (mb_strlen($reference_text) > 15) {
                                            $display_text .= '...';
                                            echo '<div class="topic-reference">';
                                            echo esc_html($display_text);
                                            echo ' <a href="#" class="view-reference-btn" data-content="' . esc_attr($reference_text) . '" data-title="' . esc_attr__('参考资料', 'yali-ai-writer') . '" style="margin-left: 5px;">' . __('查看', 'yali-ai-writer') . '</a>';
                                            echo '</div>';
                                        } else {
                                            echo '<div class="topic-reference">' . esc_html($reference_text) . '</div>';
                                        }
                                    } else {
                                        echo '<span class="no-data">-</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div class="row-actions-wrapper">
                                        <button type="button" class="button button-small yali-btn yali-btn-danger yali-btn-small" 
                                                data-yali-action="delete"
                                                data-yali-ajax-action="content_auto_delete_topic"
                                                data-yali-id="<?php echo esc_attr($topic['id']); ?>"
                                                data-yali-id-param="topic_id"
                                                data-yali-confirm="<?php echo esc_attr(__('确定要删除此主题吗？此操作不可撤销。', 'yali-ai-writer')); ?>">
                                            <?php _e('删除', 'yali-ai-writer'); ?>
                                        </button>
                                        <button type="button" class="button button-small btn-recall-test yali-btn yali-btn-secondary yali-btn-small" 
                                                data-topic-id="<?php echo esc_attr($topic['id']); ?>"
                                                data-topic-title="<?php echo esc_attr($topic['title']); ?>"
                                                <?php echo empty($topic['vector_embedding']) ? 'disabled title="' . esc_attr__('主题没有向量，无法测试召回', 'yali-ai-writer') . '"' : ''; ?>>
                                            <?php _e('召回测试', 'yali-ai-writer'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <!-- 底部分页导航 -->
                <?php if ($total_items > 0 && $total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="alignleft actions bulkactions">
                            <span class="displaying-num">
                                <?php
                                printf(
                                    __('共 %s 条记录', 'yali-ai-writer'),
                                    '<strong>' . number_format_i18n($total_items) . '</strong>'
                                );
                                ?>
                            </span>
                        </div>
                        <div class="tablenav-pages">
                            <span class="yali-pagination">
                                <?php
                                // 上一页
                                if ($current_page > 1):
                                    $prev_url = add_query_arg('paged', $current_page - 1, $base_url);
                                    echo '<a class="prev-page page-numbers" href="' . esc_url($prev_url) . '"><span class="screen-reader-text">' . __('上一页', 'yali-ai-writer') . '</span><span aria-hidden="true">‹</span></a>';
                                else:
                                    echo '<span class="page-numbers disabled" aria-hidden="true">‹</span>';
                                endif;

                                // 页码显示
                                echo implode("\n", $page_links);

                                // 下一页
                                if ($current_page < $total_pages):
                                    $next_url = add_query_arg('paged', $current_page + 1, $base_url);
                                    echo '<a class="next-page page-numbers" href="' . esc_url($next_url) . '"><span class="screen-reader-text">' . __('下一页', 'yali-ai-writer') . '</span><span aria-hidden="true">›</span></a>';
                                else:
                                    echo '<span class="page-numbers disabled" aria-hidden="true">›</span>';
                                endif;
                                ?>
                            </span>
                        </div>
                        <br class="clear">
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
</div>



<style>
.content-auto-section {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.status-unused {
    color: #0073aa;
    font-weight: bold;
}

.status-used {
    color: #666;
}

.status-queued {
    color: #f59e0b;
    font-weight: bold;
}

.button-small {
    padding: 4px 8px;
    font-size: 12px;
}

.reference-material {
    display: inline-block;
    background: #f0f6fc;
    border: 1px solid #c3d4e7;
    border-radius: 3px;
    padding: 2px 6px;
    font-size: 11px;
    color: #2a547e;
    max-width: 120px;
    white-space: normal;
    word-wrap: break-word;
    word-break: break-all;
    cursor: help;
    line-height: 1.3;
    vertical-align: top;
}

.reference-material:hover {
    background: #e9f2f9;
    border-color: #a8c4e0;
}

.topic-meta {
    color: #666;
    font-size: 11px;
}

.source-angle {
    background: #e3f2fd;
    color: #1976d2;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}

.user-value {
    font-size: 12px;
    color: #555;
    line-height: 1.4;
}

.keyword-tag {
    background: #f5f5f5;
    color: #333;
    padding: 1px 4px;
    border-radius: 2px;
    font-size: 11px;
    margin-right: 2px;
    display: inline-block;
    margin-bottom: 2px;
}

.matched-category {
    background: #e8f5e8;
    color: #2e7d32;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
}

.priority-score {
    text-align: center;
}

.priority-1 { color: #f44336; }
.priority-2 { color: #ff9800; }
.priority-3 { color: #ffc107; }
.priority-4 { color: #4caf50; }
.priority-5 { color: #2196f3; }

.priority-score small {
    display: block;
    font-size: 10px;
    color: #666;
}

.no-data {
    color: #999;
    font-style: italic;
}

.api-config-name {
    background: #f3e5f5;
    color: #7b1fa2;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

/* 表格单元格垂直对齐优化 */
.wp-list-table td {
    vertical-align: top;
}

.prev-page span,
.next-page span {
    font-size: 16px;
    font-weight: bold;
}

/* 响应式分页样式 */
@media screen and (max-width: 782px) {
    .tablenav {
        height: auto;
    }

    .tablenav-pages {
        float: none;
        margin: 10px 0;
        text-align: center;
    }

    .pagination-links a,
    .pagination-links span {
        margin: 3px;
        padding: 8px 12px;
        font-size: 14px;
    }
}

.btn-recall-test {
    margin-left: 5px !important;
}

.btn-recall-test:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* 召回测试弹窗样式 - 使用通用 yali-modal 样式 */
/* 额外的自定义样式 */
.yali-bg-success {
    background-color: rgba(34, 197, 94, 0.1) !important;
}

.yali-bg-error {
    background-color: rgba(239, 68, 68, 0.1) !important;
}
</style>

<!-- 召回测试弹窗 -->
<div id="recall-test-modal" class="yali-modal-overlay">
    <div class="yali-modal large">
        <div class="yali-modal-header">
            <h3><?php _e('参考资料召回测试', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close" aria-label="<?php esc_attr_e('关闭', 'yali-ai-writer'); ?>">&times;</button>
        </div>
        <div class="yali-modal-body">
            <div id="recall-test-result"></div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var recallTestNonce = '<?php echo wp_create_nonce('cam_reference_recall_test'); ?>';
    
    // 召回测试按钮点击
    $('.btn-recall-test').on('click', function() {
        var topicId = $(this).data('topic-id');
        var topicTitle = $(this).data('topic-title');
        
        // 显示弹窗
        $('#recall-test-modal').addClass('active');
        
        // 显示加载状态
        $('#recall-test-result').html(
            '<div class="yali-panel" style="text-align: center; padding: 40px;">' +
            '<span class="spinner is-active" style="float: none; margin: 0 auto 15px; display: block;"></span>' +
            '<p><?php _e('正在执行召回测试，请稍候...', 'yali-ai-writer'); ?></p>' +
            '<p style="color: var(--yali-text-muted); font-size: 12px; margin-top: 8px;"><?php _e('（大模型精选可能需要10-30秒）', 'yali-ai-writer'); ?></p>' +
            '</div>'
        );
        
        // 发送AJAX请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_test_reference_recall',
                nonce: recallTestNonce,
                topic_id: topicId
            },
            success: function(response) {
                if (response.success) {
                    renderRecallTestResult(response.data, topicTitle);
                } else {
                    $('#recall-test-result').html(
                        '<div class="yali-notice yali-notice-error">' +
                        '<h4 style="margin: 0 0 8px 0;"><?php _e('测试失败', 'yali-ai-writer'); ?></h4>' +
                        '<p>' + (response.data.message || '<?php _e('未知错误', 'yali-ai-writer'); ?>') + '</p>' +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('#recall-test-result').html(
                    '<div class="yali-notice yali-notice-error">' +
                    '<h4 style="margin: 0 0 8px 0;"><?php _e('请求失败', 'yali-ai-writer'); ?></h4>' +
                    '<p>' + error + '</p>' +
                    '</div>'
                );
            }
        });
    });
    
    // 关闭弹窗
    $('.yali-modal-close, .yali-modal-overlay').on('click', function(e) {
        if (e.target === this || $(e.target).closest('.yali-modal-close').length) {
            $('#recall-test-modal').removeClass('active');
        }
    });
    
    // 渲染测试结果
    function renderRecallTestResult(data, topicTitle) {
        var html = '';
        
        // 主题信息
        html += '<div class="yali-panel" style="margin-bottom: 16px;">';
        html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-primary);"><?php _e('测试主题', 'yali-ai-writer'); ?></h4>';
        html += '<p style="margin: 4px 0;"><strong>ID:</strong> ' + data.topic_id + '</p>';
        html += '<p style="margin: 4px 0;"><strong><?php _e('标题:', 'yali-ai-writer'); ?></strong> ' + escapeHtml(data.topic_title) + '</p>';
        html += '<p style="margin: 4px 0;"><strong><?php _e('向量状态:', 'yali-ai-writer'); ?></strong> ' + (data.has_vector ? '<span style="color: var(--yali-success);"><?php _e('已生成', 'yali-ai-writer'); ?></span>' : '<span style="color: var(--yali-warning);"><?php _e('未生成', 'yali-ai-writer'); ?></span>') + '</p>';
        html += '</div>';
        
        // 调试信息
        if (data.debug_info) {
            html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid #9b59b6;">';
            html += '<h4 style="margin: 0 0 12px 0; color: #9b59b6;"><?php _e('调试信息', 'yali-ai-writer'); ?></h4>';
            if (data.debug_info.topic_vector_dimensions) {
                html += '<p style="margin: 4px 0;"><strong><?php _e('主题向量维度:', 'yali-ai-writer'); ?></strong> ' + data.debug_info.topic_vector_dimensions + '</p>';
            }
            if (data.debug_info.total_reference_profiles !== undefined) {
                html += '<p style="margin: 4px 0;"><strong><?php _e('参考资料总数:', 'yali-ai-writer'); ?></strong> ' + data.debug_info.total_reference_profiles + ' <?php _e('条', 'yali-ai-writer'); ?></p>';
            }
            if (data.debug_info.suggestion) {
                html += '<p style="margin: 4px 0; color: var(--yali-warning);"><strong><?php _e('建议:', 'yali-ai-writer'); ?></strong> ' + escapeHtml(data.debug_info.suggestion) + '</p>';
            }
            html += '</div>';
        }
        
        // 所有参考资料相似度（调试用）
        if (data.all_profiles_similarity && data.all_profiles_similarity.length > 0) {
            html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid var(--yali-info);">';
            html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-info);"><?php _e('所有参考资料相似度', 'yali-ai-writer'); ?> (' + data.all_profiles_similarity.length + ' <?php _e('条', 'yali-ai-writer'); ?>)</h4>';
            html += '<p style="color: var(--yali-text-muted); font-size: 12px; margin-bottom: 12px;"><?php _e('此表显示所有参考资料与主题的相似度，用于调试。阈值为 0.5（50%）。', 'yali-ai-writer'); ?></p>';
            html += '<table class="yali-table">';
            html += '<thead><tr>';
            html += '<th>ID</th>';
            html += '<th><?php _e('标题', 'yali-ai-writer'); ?></th>';
            html += '<th><?php _e('相似度', 'yali-ai-writer'); ?></th>';
            html += '<th><?php _e('达到阈值', 'yali-ai-writer'); ?></th>';
            html += '</tr></thead><tbody>';
            
            data.all_profiles_similarity.forEach(function(profile) {
                var similarity = profile.similarity;
                var similarityText = similarity !== null ? (similarity * 100).toFixed(2) + '%' : '<?php _e('解码失败', 'yali-ai-writer'); ?>';
                var meetsThreshold = profile.meets_threshold;
                var rowClass = meetsThreshold ? 'yali-bg-success' : (similarity !== null && similarity < 0.3 ? 'yali-bg-error' : '');
                
                html += '<tr class="' + rowClass + '">';
                html += '<td>' + profile.id + '</td>';
                html += '<td>' + escapeHtml(profile.title) + '</td>';
                html += '<td>' + similarityText + '</td>';
                html += '<td>' + (profile.error ? '<span class="yali-text-danger">' + escapeHtml(profile.error) + '</span>' : (meetsThreshold ? '<span class="yali-text-success">✓ <?php _e('是', 'yali-ai-writer'); ?></span>' : '<span class="yali-text-muted">✗ <?php _e('否', 'yali-ai-writer'); ?></span>')) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
        }
        
        if (data.error) {
            html += '<div class="yali-notice yali-notice-error" style="margin-bottom: 16px;">';
            html += '<h4 style="margin: 0 0 8px 0;"><?php _e('召回失败', 'yali-ai-writer'); ?></h4>';
            html += '<p>' + escapeHtml(data.error) + '</p>';
            html += '</div>';
            $('#recall-test-result').html(html);
            return;
        }
        
        // 候选列表
        if (data.candidates && data.candidates.length > 0) {
            html += '<div class="yali-panel" style="margin-bottom: 16px;">';
            html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-primary);"><?php _e('召回候选列表', 'yali-ai-writer'); ?> (' + data.candidates.length + ' <?php _e('条', 'yali-ai-writer'); ?>)</h4>';
            html += '<table class="yali-table">';
            html += '<thead><tr>';
            html += '<th>ID</th>';
            html += '<th><?php _e('标题', 'yali-ai-writer'); ?></th>';
            html += '<th><?php _e('相似度', 'yali-ai-writer'); ?></th>';
            html += '<th><?php _e('描述预览', 'yali-ai-writer'); ?></th>';
            html += '</tr></thead><tbody>';
            
            data.candidates.forEach(function(candidate) {
                var isSelected = data.ai_selected && data.ai_selected.id == candidate.id;
                html += '<tr class="' + (isSelected ? 'yali-bg-success' : '') + '">';
                html += '<td>' + candidate.id + (isSelected ? ' <span class="yali-text-success">✓</span>' : '') + '</td>';
                html += '<td>' + escapeHtml(candidate.title) + '</td>';
                html += '<td>' + (candidate.similarity * 100).toFixed(2) + '%</td>';
                html += '<td>' + escapeHtml(candidate.description_preview) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
        }
        
        // AI选择结果
        if (data.ai_selected) {
            html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid var(--yali-success);">';
            html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-success);"><?php _e('大模型精选结果', 'yali-ai-writer'); ?></h4>';
            html += '<p style="margin: 4px 0;"><strong><?php _e('选中ID:', 'yali-ai-writer'); ?></strong> ' + data.ai_selected.id + '</p>';
            html += '<p style="margin: 4px 0;"><strong><?php _e('选中标题:', 'yali-ai-writer'); ?></strong> ' + escapeHtml(data.ai_selected.title) + '</p>';
            html += '<p style="margin: 4px 0;"><strong><?php _e('选择原因:', 'yali-ai-writer'); ?></strong> ' + escapeHtml(data.ai_selected.reason) + '</p>';
            html += '</div>';
        }
        
        // 最终结果
        if (data.final_result) {
            html += '<div class="yali-notice yali-notice-success" style="margin-top: 16px;">';
            html += '<h4 style="margin: 0 0 12px 0;"><?php _e('最终召回结果', 'yali-ai-writer'); ?></h4>';
            html += '<p style="margin: 4px 0;"><strong>ID:</strong> ' + data.final_result.id + '</p>';
            html += '<p style="margin: 4px 0;"><strong><?php _e('标题:', 'yali-ai-writer'); ?></strong> ' + escapeHtml(data.final_result.title) + '</p>';
            html += '<p style="margin: 4px 0;"><strong><?php _e('参考资料内容:', 'yali-ai-writer'); ?></strong></p>';
            html += '<div style="background: var(--yali-card); border: 1px solid var(--yali-border); border-radius: 4px; padding: 12px; margin-top: 8px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; font-size: 13px; line-height: 1.5;">' + escapeHtml(data.final_result.description) + '</div>';
            html += '</div>';
        }
        
        $('#recall-test-result').html(html);
    }
    
    // HTML转义函数
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
</script>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var modal = $('#view-reference-modal-overlay');
    var contentDiv = $('#view-reference-modal-content');
    
    // 关键修正：将弹窗移动到 body 直接子级，防止被其他容器样式干扰
    if (modal.parent().not('body').length) {
        modal.appendTo('body');
    }
    
    // 打开弹窗 - 使用 view-reference-btn 类 (来自列表视图)
    $(document).on('click', '.view-reference-btn', function(e) {
        e.preventDefault();
        var content = $(this).data('content');
        var title = $(this).data('title') || '参考资料';
        
        $('#view-reference-modal-title').text(title);
        contentDiv.html(escapeHtml(content)); // 使用之前定义的escapeHtml或者jQuery .text()防止XSS
        
        modal.addClass('active');
        $('body').css('overflow', 'hidden'); // 防止背景滚动
    });
    
    // 关闭弹窗函数
    function closeModal() {
        modal.removeClass('active');
        $('body').css('overflow', '');
    }
    
    // 关闭按钮点击事件
    $(document).on('click', '#view-reference-modal-close, #view-reference-close-btn', function(e) {
        e.preventDefault();
        closeModal();
    });
    
    // 点击背景关闭
    modal.on('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // ESC键关闭
    $(document).on('keyup', function(e) {
        if (e.key === "Escape" && modal.hasClass('active')) {
            closeModal();
        }
    });

    function escapeHtml(text) {
        if (!text) return '';
        return $('<div>').text(text).html();
    }
});
</script>

<!-- 加载高级筛选样式和脚本 -->
<?php
// 加载高级筛选CSS
$filter_css_url = plugins_url('assets/css/topic-filter.css', dirname(__FILE__));
echo '<link rel="stylesheet" href="' . esc_url($filter_css_url) . '?ver=' . CONTENT_AUTO_MANAGER_VERSION . '">';

// 加载高级筛选JS
$filter_js_url = plugins_url('assets/js/topic-filter.js', dirname(__FILE__));
?>

<!-- 高级筛选配置 -->
<input type="hidden" id="cam-filter-nonce" value="<?php echo wp_create_nonce('cam_topic_filter'); ?>">
<script type="text/javascript">
window.camTopicFilterI18n = {
    detecting: '<?php _e('正在检测重复标题...', 'yali-ai-writer'); ?>',
    detected: '<?php _e('检测完成，发现', 'yali-ai-writer'); ?>',
    duplicateTopics: '<?php _e('个重复主题', 'yali-ai-writer'); ?>',
    noDuplicates: '<?php _e('未发现重复标题', 'yali-ai-writer'); ?>',
    detectFailed: '<?php _e('检测失败', 'yali-ai-writer'); ?>',
    requestFailed: '<?php _e('请求失败', 'yali-ai-writer'); ?>',
    summary: '<?php _e('检测结果汇总', 'yali-ai-writer'); ?>',
    exactGroups: '<?php _e('完全相同标题组', 'yali-ai-writer'); ?>',
    exactTopics: '<?php _e('完全重复主题数', 'yali-ai-writer'); ?>',
    similarGroups: '<?php _e('向量相似组', 'yali-ai-writer'); ?>',
    similarTopics: '<?php _e('相似重复主题数', 'yali-ai-writer'); ?>',
    exactLabel: '<?php _e('完全相同', 'yali-ai-writer'); ?>',
    exactTitle: '<?php _e('完全相同的标题', 'yali-ai-writer'); ?>',
    similarLabel: '<?php _e('向量相似', 'yali-ai-writer'); ?>',
    similarTitle: '<?php _e('向量相似的标题', 'yali-ai-writer'); ?>',
    groups: '<?php _e('组', 'yali-ai-writer'); ?>',
    topics: '<?php _e('个主题', 'yali-ai-writer'); ?>',
    createdAt: '<?php _e('创建时间', 'yali-ai-writer'); ?>',
    category: '<?php _e('分类', 'yali-ai-writer'); ?>',
    keep: '<?php _e('保留', 'yali-ai-writer'); ?>',
    willDelete: '<?php _e('将删除', 'yali-ai-writer'); ?>',
    similarity: '<?php _e('相似度', 'yali-ai-writer'); ?>',
    noResults: '<?php _e('未发现重复标题', 'yali-ai-writer'); ?>',
    detectFirst: '<?php _e('请先检测重复标题', 'yali-ai-writer'); ?>',
    noDuplicatesToDelete: '<?php _e('没有需要删除的重复主题', 'yali-ai-writer'); ?>',
    confirmDeleteAll: '<?php _e('确定要删除所有重复主题吗？将保留每组中最早创建的主题。', 'yali-ai-writer'); ?>',
    deleting: '<?php _e('正在删除重复主题...', 'yali-ai-writer'); ?>',
    deleteFailed: '<?php _e('删除失败', 'yali-ai-writer'); ?>',
    selectTopics: '<?php _e('请选择要删除的主题', 'yali-ai-writer'); ?>',
    confirmDeleteSelected: '<?php _e('确定要删除选中的', 'yali-ai-writer'); ?>',
    topicsConfirm: '<?php _e('个主题吗？此操作不可撤销。', 'yali-ai-writer'); ?>',
    deletingText: '<?php _e('删除中...', 'yali-ai-writer'); ?>',
    deleteSelected: '<?php _e('删除选中', 'yali-ai-writer'); ?>',
    confirmGenerateReference: '<?php _e('确定要为选中的', 'yali-ai-writer'); ?>',
    success: '<?php _e('操作成功', 'yali-ai-writer'); ?>',
    noTopicsToDelete: '<?php _e('没有符合条件的主题可删除', 'yali-ai-writer'); ?>',
    onlyUnusedCanDelete: '<?php _e('只能批量删除"未使用"状态的主题，请先在状态筛选中选择"未使用"', 'yali-ai-writer'); ?>',
    confirmDeleteAllFiltered: '<?php _e('确定要删除所有符合筛选条件的', 'yali-ai-writer'); ?>',
    topicsQuestion: '<?php _e('个主题吗？此操作不可撤销。', 'yali-ai-writer'); ?>',
    deleteSuccess: '<?php _e('删除成功', 'yali-ai-writer'); ?>'
};
</script>
<script src="<?php echo esc_url($filter_js_url); ?>?ver=<?php echo CONTENT_AUTO_MANAGER_VERSION; ?>"></script>

<!-- 手工添加主题弹窗 (Unified UI) -->
<div class="yali-modal-overlay" id="manual-add-modal-overlay">
    <div class="yali-modal">
        <div class="yali-modal-header">
            <h3><?php _e('手工添加主题', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close" id="manual-add-modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="yali-modal-body">
            <div class="yali-form-group">
                <label for="manual-titles"><?php _e('主题标题', 'yali-ai-writer'); ?> <span style="color:var(--yali-error, #ef4444);">*</span></label>
                <textarea id="manual-titles" class="yali-textarea" rows="5" placeholder="<?php esc_attr_e('每行输入一个主题标题，可批量添加多个主题', 'yali-ai-writer'); ?>"></textarea>
                <p class="description"><?php _e('支持批量添加，每行一个标题', 'yali-ai-writer'); ?></p>
            </div>
            
            <div class="yali-form-group">
                <label for="manual-reference"><?php _e('参考资料', 'yali-ai-writer'); ?> <span style="color:var(--yali-text-muted, #64748b);">(<?php _e('可选', 'yali-ai-writer'); ?>)</span></label>
                <textarea id="manual-reference" class="yali-textarea" rows="4" placeholder="<?php esc_attr_e('输入参考资料，用于指导AI生成文章内容', 'yali-ai-writer'); ?>"></textarea>
                <div class="char-counter" id="reference-char-counter">0 / 800</div>
                <p class="description"><?php _e('参考资料将帮助AI生成更准确、有深度的内容。所有主题共享同一参考资料。', 'yali-ai-writer'); ?></p>
            </div>
            
            <div class="yali-form-group">
                <label for="manual-category"><?php _e('目标分类', 'yali-ai-writer'); ?></label>
                <select id="manual-category" class="yali-select">
                    <option value=""><?php _e('AI 智能自动匹配', 'yali-ai-writer'); ?></option>
                    <?php
                    $all_categories = get_categories(array('hide_empty' => false));
                    foreach ($all_categories as $cat) {
                        echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
                    }
                    ?>
                </select>
                <p class="description"><?php _e('指定分类或让AI根据内容自动匹配', 'yali-ai-writer'); ?></p>
            </div>
        </div>
        <div class="yali-modal-footer">
            <button type="button" class="yali-btn yali-btn-secondary" id="manual-add-cancel"><?php _e('取消', 'yali-ai-writer'); ?></button>
            <button type="button" class="yali-btn yali-btn-primary" id="manual-add-submit"><?php _e('添加主题', 'yali-ai-writer'); ?></button>
        </div>
    </div>
</div>

<!-- 加载手工添加主题弹窗资源 -->
<?php
// 注意：当前文件在 views 目录，资源在 assets 目录，需要向上退一级
$modal_css_url = plugins_url('../assets/css/manual-add-modal.css', __FILE__);
$modal_js_url = plugins_url('../assets/js/manual-add-modal.js', __FILE__);
echo '<link rel="stylesheet" href="' . esc_url($modal_css_url) . '?ver=' . CONTENT_AUTO_MANAGER_VERSION . '">';
?>
<input type="hidden" id="cam-manual-add-nonce" value="<?php echo wp_create_nonce('cam_manual_add_topics'); ?>">
<script type="text/javascript">
window.camManualAddI18n = {
    adding: '<?php _e('正在添加...', 'yali-ai-writer'); ?>',
    addSuccess: '<?php _e('添加成功', 'yali-ai-writer'); ?>',
    addFailed: '<?php _e('添加失败', 'yali-ai-writer'); ?>',
    requestFailed: '<?php _e('请求失败', 'yali-ai-writer'); ?>',
    pleaseEnterTitle: '<?php _e('请至少输入一个主题标题', 'yali-ai-writer'); ?>',
    success: '<?php _e('添加成功', 'yali-ai-writer'); ?>'
};
</script>
<script src="<?php echo esc_url($modal_js_url); ?>?ver=<?php echo CONTENT_AUTO_MANAGER_VERSION; ?>"></script>
<!-- View Reference Modal (Unified) -->
<div class="yali-modal-overlay" id="view-reference-modal-overlay">
    <div class="yali-modal large" style="width: 800px; max-width: 90%;">
        <div class="yali-modal-header">
            <h3 class="yali-modal-title" id="view-reference-modal-title"><?php _e('参考资料', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close" id="view-reference-modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="yali-modal-body" style="max-height: 70vh;">
            <div id="view-reference-modal-content" style="white-space: pre-wrap; word-break: break-word; line-height: 1.6; color: #333;"></div>
        </div>
        <div class="yali-modal-footer">
            <button type="button" class="yali-btn yali-btn-secondary" id="view-reference-close-btn"><?php _e('关闭', 'yali-ai-writer'); ?></button>
        </div>
    </div>
</div>
