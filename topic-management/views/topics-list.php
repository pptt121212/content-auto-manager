<?php
/**
 * 主题管理页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 处理删除操作

// 处理删除操作
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    // 验证nonce
    if (!wp_verify_nonce($_GET['nonce'], 'content_auto_manager_delete_topic')) {
        wp_die(__('安全验证失败。'));
    }
    
    $database = new ContentAuto_Database();
    $result = $database->delete('content_auto_topics', array('id' => $_GET['id']));
    
    if ($result) {
        echo '<div class="notice notice-success"><p>' . __('主题已删除。', 'content-auto-manager') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('主题删除失败。', 'content-auto-manager') . '</p></div>';
    }
}

// 处理生成文章操作
if (isset($_POST['generate_articles']) && isset($_POST['topic_ids'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_generate_articles')) {
        wp_die(__('安全验证失败。'));
    }
    
    $topic_ids = $_POST['topic_ids'];
    
    if (!empty($topic_ids) && is_array($topic_ids)) {
        // 创建文章生成父任务
        $article_task_manager = new ContentAuto_ArticleTaskManager();
        $task_id = $article_task_manager->create_article_task($topic_ids);
        
        if ($task_id) {
            echo '<div class="notice notice-success"><p>' . __('文章生成父任务已创建。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('文章生成父任务创建失败。', 'content-auto-manager') . '</p></div>';
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

<div class="wrap">
    <h1>
        <?php _e('主题管理', 'content-auto-manager'); ?>
        <a href="#" id="open-manual-add-modal" class="page-title-action">
            <?php _e('手工添加主题', 'content-auto-manager'); ?>
        </a>
    </h1>
    
    <!-- 高级筛选器 -->
    <div class="content-auto-section">
        <h2><?php _e('高级筛选', 'content-auto-manager'); ?></h2>
        
        <form method="get" action="" id="advanced-filter-form">
            <input type="hidden" name="page" value="content-auto-manager-topics">
            <input type="hidden" name="paged" value="1">
            
            <div class="filter-grid">
                <!-- 第一行筛选条件 -->
                <div class="filter-row">
                    <div class="filter-item">
                        <label for="filter-title"><?php _e('标题关键字', 'content-auto-manager'); ?></label>
                        <input type="text" id="filter-title" name="title_keyword" 
                               value="<?php echo esc_attr(isset($_GET['title_keyword']) ? $_GET['title_keyword'] : ''); ?>" 
                               class="regular-text" placeholder="<?php _e('输入关键字搜索标题...', 'content-auto-manager'); ?>">
                    </div>
                    
                    <div class="filter-item">
                        <label for="filter-status"><?php _e('状态', 'content-auto-manager'); ?></label>
                        <select id="filter-status" name="status">
                            <option value="unused" <?php selected($status, 'unused'); ?>><?php _e('未使用', 'content-auto-manager'); ?></option>
                            <option value="queued" <?php selected($status, 'queued'); ?>><?php _e('队列中', 'content-auto-manager'); ?></option>
                            <option value="used" <?php selected($status, 'used'); ?>><?php _e('已使用', 'content-auto-manager'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="filter-category"><?php _e('推荐分类', 'content-auto-manager'); ?></label>
                        <select id="filter-category" name="matched_category">
                            <option value=""><?php _e('全部分类', 'content-auto-manager'); ?></option>
                            <option value="__empty__" <?php selected(isset($_GET['matched_category']) ? $_GET['matched_category'] : '', '__empty__'); ?>><?php _e('无分类', 'content-auto-manager'); ?></option>
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
                        <label for="filter-priority"><?php _e('优先级', 'content-auto-manager'); ?></label>
                        <select id="filter-priority" name="priority_score">
                            <option value=""><?php _e('全部', 'content-auto-manager'); ?></option>
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
                        <label for="filter-vector"><?php _e('生成向量', 'content-auto-manager'); ?></label>
                        <select id="filter-vector" name="has_vector">
                            <option value=""><?php _e('全部', 'content-auto-manager'); ?></option>
                            <option value="1" <?php selected(isset($_GET['has_vector']) ? $_GET['has_vector'] : '', '1'); ?>><?php _e('已生成', 'content-auto-manager'); ?></option>
                            <option value="0" <?php selected(isset($_GET['has_vector']) ? $_GET['has_vector'] : '', '0'); ?>><?php _e('未生成', 'content-auto-manager'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="filter-reference"><?php _e('参考资料', 'content-auto-manager'); ?></label>
                        <select id="filter-reference" name="has_reference">
                            <option value=""><?php _e('全部', 'content-auto-manager'); ?></option>
                            <option value="1" <?php selected(isset($_GET['has_reference']) ? $_GET['has_reference'] : '', '1'); ?>><?php _e('有参考资料', 'content-auto-manager'); ?></option>
                            <option value="0" <?php selected(isset($_GET['has_reference']) ? $_GET['has_reference'] : '', '0'); ?>><?php _e('无参考资料', 'content-auto-manager'); ?></option>
                        </select>
                    </div>
                    
                    <div class="filter-item">
                        <label for="filter-task-id"><?php _e('任务ID', 'content-auto-manager'); ?></label>
                        <input type="text" id="filter-task-id" name="task_id" 
                               value="<?php echo esc_attr($task_id); ?>" 
                               class="regular-text" placeholder="<?php _e('留空显示全部', 'content-auto-manager'); ?>">
                    </div>
                    
                    <div class="filter-item filter-buttons">
                        <label>&nbsp;</label>
                        <div class="button-group">
                            <?php submit_button(__('筛选', 'content-auto-manager'), 'primary', 'submit', false); ?>
                            <button type="button" class="button" id="reset-filters"><?php _e('重置', 'content-auto-manager'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- 重复标题检测区域 -->
        <div class="duplicate-detection-section">
            <h3><?php _e('重复标题检测', 'content-auto-manager'); ?></h3>
            <p class="description"><?php _e('检测完全相同的标题或向量相似度高于设定阈值的主题，支持一键删除重复项（保留最早创建的）', 'content-auto-manager'); ?></p>
            
            <div class="duplicate-actions">
                <div class="threshold-input-group" style="display:inline-block; margin-right:10px;">
                    <label for="duplicate-threshold"><?php _e('相似度阈值:', 'content-auto-manager'); ?></label>
                    <input type="number" id="duplicate-threshold" value="90" min="10" max="100" step="1" style="width: 60px;">%
                </div>
                <button type="button" class="button" id="detect-duplicates">
                    <span class="dashicons dashicons-search" style="vertical-align: middle;"></span>
                    <?php _e('检测重复标题', 'content-auto-manager'); ?>
                </button>
                <button type="button" class="button button-link-delete" id="delete-all-duplicates" style="display:none;">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                    <?php _e('一键删除所有重复', 'content-auto-manager'); ?>
                </button>
                <span id="duplicate-status" class="duplicate-status"></span>
            </div>
            
            <div id="duplicate-results" style="display:none;"></div>
        </div>
    </div>
    
    <!-- 批量操作区域 -->
    <div class="content-auto-section bulk-actions-section" id="bulk-actions-section" style="display:none;">
        <h3><?php _e('批量操作', 'content-auto-manager'); ?></h3>
        <div class="bulk-action-buttons">
            <span id="selected-count">0</span> <?php _e('个主题已选中', 'content-auto-manager'); ?>
            <button type="button" class="button button-link-delete" id="bulk-delete-selected">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php _e('删除选中', 'content-auto-manager'); ?>
            </button>
            <span class="bulk-separator">|</span>
            <button type="button" class="button button-link-delete" id="bulk-delete-all-filtered">
                <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                <?php _e('删除所有符合条件的主题', 'content-auto-manager'); ?> 
                (<span id="filtered-total-count"><?php echo intval($total_items); ?></span> <?php _e('条', 'content-auto-manager'); ?>)
            </button>
        </div>
        <p class="bulk-action-warning" style="margin-top: 10px; color: #856404; font-size: 12px;">
            <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
            <?php _e('提示：只能删除"未使用"状态的主题，队列中和已使用的主题不会被删除。', 'content-auto-manager'); ?>
        </p>
    </div>
    
    <!-- 手工添加主题弹窗触发器 -->

    <!-- 生成文章表单 -->
    <form method="post" action="">
        <?php wp_nonce_field('content_auto_manager_generate_articles', 'content_auto_manager_nonce'); ?>
        
        <!-- 主题列表 -->
        <div class="content-auto-section">
            <h2>
                <?php _e('主题列表', 'content-auto-manager'); ?>
                <?php if (!empty($topics)): ?>
                    <input type="submit" name="generate_articles" class="button button-primary" value="<?php _e('生成文章', 'content-auto-manager'); ?>" style="float: right;">
                <?php endif; ?>
            </h2>

            <?php if ($total_items > 0): ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <span class="displaying-num">
                            <?php
                            printf(
                                __('共 %s 条记录，当前显示第 %s-%s 条', 'content-auto-manager'),
                                '<strong>' . number_format_i18n($total_items) . '</strong>',
                                '<strong>' . number_format_i18n(($current_page - 1) * $per_page + 1) . '</strong>',
                                '<strong>' . number_format_i18n(min($current_page * $per_page, $total_items)) . '</strong>'
                            );
                            ?>
                        </span>
                    </div>
                    <div class="tablenav-pages">
                        <span class="pagination-links">
                            <?php
                            $base_url = add_query_arg(array(
                                'page' => 'content-auto-manager-topics',
                                'task_id' => $task_id,
                                'status' => $status
                            ), remove_query_arg('paged'));

                            // 上一页
                            if ($current_page > 1):
                                $prev_url = add_query_arg('paged', $current_page - 1, $base_url);
                                echo '<a class="prev-page" href="' . esc_url($prev_url) . '"><span class="screen-reader-text">' . __('上一页', 'content-auto-manager') . '</span><span aria-hidden="true">‹</span></a>';
                            else:
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">‹</span>';
                            endif;

                            // 页码显示
                            $page_links = array();
                            $dots = false;

                            for ($i = 1; $i <= $total_pages; $i++) {
                                if ($i == 1 || $i == $total_pages || ($i >= $current_page - 2 && $i <= $current_page + 2)) {
                                    if ($i == $current_page) {
                                        $page_links[] = '<span class="paging-input" aria-current="page">' . number_format_i18n($i) . '</span>';
                                    } else {
                                        $page_url = add_query_arg('paged', $i, $base_url);
                                        $page_links[] = '<a href="' . esc_url($page_url) . '">' . number_format_i18n($i) . '</a>';
                                    }
                                    $dots = false;
                                } elseif (!$dots) {
                                    $page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">...</span>';
                                    $dots = true;
                                }
                            }

                            echo implode("\n", $page_links);

                            // 下一页
                            if ($current_page < $total_pages):
                                $next_url = add_query_arg('paged', $current_page + 1, $base_url);
                                echo '<a class="next-page" href="' . esc_url($next_url) . '"><span class="screen-reader-text">' . __('下一页', 'content-auto-manager') . '</span><span aria-hidden="true">›</span></a>';
                            else:
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">›</span>';
                            endif;
                            ?>
                        </span>
                    </div>
                    <br class="clear">
                </div>
            <?php endif; ?>

            <?php if (empty($topics)): ?>
                <p><?php _e('暂无主题。', 'content-auto-manager'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column"><input type="checkbox" id="select-all-topics"></th>
                            <th><?php _e('ID', 'content-auto-manager'); ?></th>
                            <th><?php _e('主题任务ID（task_id）', 'content-auto-manager'); ?></th>
                            <th><?php _e('标题', 'content-auto-manager'); ?></th>
                            <th><?php _e('内容角度', 'content-auto-manager'); ?></th>
                            <th><?php _e('用户价值', 'content-auto-manager'); ?></th>
                            <th><?php _e('SEO关键词', 'content-auto-manager'); ?></th>
                            <th><?php _e('推荐分类', 'content-auto-manager'); ?></th>
                            <th><?php _e('优先级', 'content-auto-manager'); ?></th>
                            <th><?php _e('API配置', 'content-auto-manager'); ?></th>
                            <th><?php _e('状态', 'content-auto-manager'); ?></th>
                            <th><?php _e('生成向量', 'content-auto-manager'); ?></th>
                            <th><?php _e('参考资料', 'content-auto-manager'); ?></th>
                            <th><?php _e('操作', 'content-auto-manager'); ?></th>
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
                                <td><?php echo esc_html($topic['task_id']); ?></td>
                                <td>
                                    <strong><?php echo esc_html($topic['title']); ?></strong>
                                    <?php if (!empty($topic['task_id']) && $topic['task_id'] !== '0'): ?>
                                        <br><small class="topic-meta">
                                            任务ID: <?php echo esc_html($topic['task_id']); ?> | 
                                            项目: <?php echo $topic['rule_item_index'] + 1; ?> | 
                                            规则: <?php echo $rule ? esc_html($rule['rule_name']) : __('无', 'content-auto-manager'); ?>
                                        </small>
                                    <?php else: ?>
                                        <br><small class="topic-meta"><?php _e('手工添加', 'content-auto-manager'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['source_angle'])): ?>
                                        <span class="source-angle"><?php echo esc_html($topic['source_angle']); ?></span>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['user_value'])): ?>
                                        <div class="user-value" title="<?php echo esc_attr($topic['user_value']); ?>">
                                            <?php echo esc_html(content_auto_manager_truncate_string($topic['user_value'], 50)); ?>
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
                                            foreach ($keywords as $keyword) {
                                                echo '<span class="keyword-tag">' . esc_html($keyword) . '</span> ';
                                            }
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
                                        <div class="priority-score priority-<?php echo $topic['priority_score']; ?>">
                                            <?php echo str_repeat('★', $topic['priority_score']); ?>
                                            <small>(<?php echo $topic['priority_score']; ?>/5)</small>
                                        </div>
                                    <?php else: ?>
                                        <span class="no-data">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($topic['api_config_name'])): ?>
                                        <span class="api-config-name"><?php echo esc_html($topic['api_config_name']); ?></span>
                                    <?php elseif (!empty($topic['task_id']) && $topic['task_id'] !== '0'): ?>
                                        <span class="no-data">-</span>
                                    <?php else: ?>
                                        <span class="no-data"><?php _e('未使用API', 'content-auto-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-<?php echo esc_attr($topic['status']); ?>">
                                        <?php echo content_auto_manager_get_topic_status_label($topic['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $vector_status = isset($topic['vector_status']) ? $topic['vector_status'] : 'pending';
                                    $status_text = __('否', 'content-auto-manager');
                                    $status_class = 'vector-no';
                                    $error_title = '';

                                    if ($vector_status === 'completed') {
                                        $status_text = __('是', 'content-auto-manager');
                                        $status_class = 'vector-yes';
                                    } elseif ($vector_status === 'failed') {
                                        $status_text = __('失败', 'content-auto-manager');
                                        $status_class = 'vector-failed';
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
                                        $display_text = mb_substr($reference_text, 0, 30);
                                        $is_long = mb_strlen($reference_text) > 30;
                                        if ($is_long) {
                                            $display_text .= '...';
                                        }
                                        echo '<span class="reference-material-preview" title="' . esc_attr($reference_text) . '">' . esc_html($display_text) . '</span>';
                                        if ($is_long) {
                                            echo ' <a href="#" class="view-reference-material" data-full-content="' . esc_attr($reference_text) . '" style="font-size: 12px; white-space: nowrap;">[' . __('查看', 'content-auto-manager') . ']</a>';
                                        }
                                    } else {
                                        echo '<span class="no-data">-</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $topic['id'])), 'content_auto_manager_delete_topic', 'nonce'); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php _e('确定要删除此主题吗？', 'content-auto-manager'); ?>')">
                                        <?php _e('删除', 'content-auto-manager'); ?>
                                    </a>
                                    <button type="button" class="button button-small btn-recall-test" 
                                            data-topic-id="<?php echo esc_attr($topic['id']); ?>"
                                            data-topic-title="<?php echo esc_attr($topic['title']); ?>"
                                            <?php echo empty($topic['vector_embedding']) ? 'disabled title="' . esc_attr__('主题没有向量，无法测试召回', 'content-auto-manager') . '"' : ''; ?>>
                                        <?php _e('召回测试', 'content-auto-manager'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- 底部分页导航 -->
                <?php if ($total_items > 0 && $total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="alignleft actions bulkactions">
                            <span class="displaying-num">
                                <?php
                                printf(
                                    __('共 %s 条记录', 'content-auto-manager'),
                                    '<strong>' . number_format_i18n($total_items) . '</strong>'
                                );
                                ?>
                            </span>
                        </div>
                        <div class="tablenav-pages">
                            <span class="pagination-links">
                                <?php
                                // 上一页
                                if ($current_page > 1):
                                    $prev_url = add_query_arg('paged', $current_page - 1, $base_url);
                                    echo '<a class="prev-page" href="' . esc_url($prev_url) . '"><span class="screen-reader-text">' . __('上一页', 'content-auto-manager') . '</span><span aria-hidden="true">‹</span></a>';
                                else:
                                    echo '<span class="tablenav-pages-navspan" aria-hidden="true">‹</span>';
                                endif;

                                // 页码显示
                                echo implode("\n", $page_links);

                                // 下一页
                                if ($current_page < $total_pages):
                                    $next_url = add_query_arg('paged', $current_page + 1, $base_url);
                                    echo '<a class="next-page" href="' . esc_url($next_url) . '"><span class="screen-reader-text">' . __('下一页', 'content-auto-manager') . '</span><span aria-hidden="true">›</span></a>';
                                else:
                                    echo '<span class="tablenav-pages-navspan" aria-hidden="true">›</span>';
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

/* 分页导航样式优化 */
.tablenav {
    margin: 10px 0;
    height: auto;
    line-height: normal;
}

.displaying-num {
    color: #666;
    font-size: 13px;
    margin-right: 10px;
}

.tablenav-pages {
    float: right;
    margin: 0 0 9px;
}

.pagination-links {
    margin: 0;
    line-height: 28px;
}

.pagination-links a,
.pagination-links span {
    display: inline-block;
    min-width: 30px;
    padding: 0 10px;
    text-align: center;
    line-height: 28px;
    text-decoration: none;
    margin-left: 5px;
    border: 1px solid #ddd;
    background: #fff;
    color: #0073aa;
    border-radius: 3px;
}

.pagination-links a:hover {
    background: #f5f5f5;
    border-color: #999;
}

.pagination-links .paging-input {
    background: #e5e5e5;
    border-color: #bbb;
    color: #32373c;
    font-weight: bold;
}

.pagination-links .tablenav-pages-navspan {
    background: #f7f7f7;
    border-color: #ddd;
    color: #a0a5aa;
    cursor: default;
}

.prev-page,
.next-page {
    margin-left: 0;
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

/* 召回测试弹窗样式 */
.recall-test-modal {
    display: none;
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.recall-test-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border-radius: 8px;
    width: 80%;
    max-width: 900px;
    max-height: 85vh;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.recall-test-modal-header {
    background: #0073aa;
    color: #fff;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.recall-test-modal-header h3 {
    margin: 0;
    font-size: 16px;
}

.recall-test-modal-close {
    color: #fff;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
}

.recall-test-modal-close:hover {
    opacity: 0.8;
}

.recall-test-modal-body {
    padding: 20px;
    max-height: calc(85vh - 120px);
    overflow-y: auto;
}

.recall-test-loading {
    text-align: center;
    padding: 40px;
}

.recall-test-loading .spinner {
    float: none;
    margin: 0 auto 10px;
    display: block;
}

.recall-test-section {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 5px;
    border-left: 4px solid #0073aa;
}

.recall-test-section h4 {
    margin: 0 0 10px 0;
    color: #23282d;
}

.recall-test-error {
    border-left-color: #dc3232;
    background: #fef7f7;
}

.recall-test-error h4 {
    color: #dc3232;
}

.recall-test-success {
    border-left-color: #46b450;
    background: #f7fef7;
}

.recall-test-candidates-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.recall-test-candidates-table th,
.recall-test-candidates-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

.recall-test-candidates-table th {
    background: #f1f1f1;
    font-weight: 600;
}

.recall-test-candidates-table tr:hover {
    background: #f5f5f5;
}

.recall-test-final-result {
    background: #e7f7e7;
    border: 1px solid #46b450;
    border-radius: 5px;
    padding: 15px;
    margin-top: 15px;
}

.recall-test-final-result h4 {
    color: #46b450;
    margin: 0 0 10px 0;
}

.recall-test-description {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 10px;
    margin-top: 10px;
    max-height: 200px;
    overflow-y: auto;
    white-space: pre-wrap;
    font-size: 13px;
    line-height: 1.5;
}
</style>

<!-- 召回测试弹窗 -->
<div id="recall-test-modal" class="recall-test-modal">
    <div class="recall-test-modal-content">
        <div class="recall-test-modal-header">
            <h3><?php _e('参考资料召回测试', 'content-auto-manager'); ?></h3>
            <span class="recall-test-modal-close">&times;</span>
        </div>
        <div class="recall-test-modal-body">
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
        $('#recall-test-modal').show();
        
        // 显示加载状态
        $('#recall-test-result').html(
            '<div class="recall-test-loading">' +
            '<span class="spinner is-active"></span>' +
            '<p><?php _e('正在执行召回测试，请稍候...', 'content-auto-manager'); ?></p>' +
            '<p><small><?php _e('（大模型精选可能需要10-30秒）', 'content-auto-manager'); ?></small></p>' +
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
                        '<div class="recall-test-section recall-test-error">' +
                        '<h4><?php _e('测试失败', 'content-auto-manager'); ?></h4>' +
                        '<p>' + (response.data.message || '<?php _e('未知错误', 'content-auto-manager'); ?>') + '</p>' +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                $('#recall-test-result').html(
                    '<div class="recall-test-section recall-test-error">' +
                    '<h4><?php _e('请求失败', 'content-auto-manager'); ?></h4>' +
                    '<p>' + error + '</p>' +
                    '</div>'
                );
            }
        });
    });
    
    // 关闭弹窗
    $('.recall-test-modal-close, .recall-test-modal').on('click', function(e) {
        if (e.target === this) {
            $('#recall-test-modal').hide();
        }
    });
    
    // 渲染测试结果
    function renderRecallTestResult(data, topicTitle) {
        var html = '';
        
        // 主题信息
        html += '<div class="recall-test-section">';
        html += '<h4><?php _e('测试主题', 'content-auto-manager'); ?></h4>';
        html += '<p><strong>ID:</strong> ' + data.topic_id + '</p>';
        html += '<p><strong><?php _e('标题', 'content-auto-manager'); ?>:</strong> ' + escapeHtml(data.topic_title) + '</p>';
        html += '<p><strong><?php _e('向量状态', 'content-auto-manager'); ?>:</strong> ' + (data.has_vector ? '<?php _e('已生成', 'content-auto-manager'); ?>' : '<?php _e('未生成', 'content-auto-manager'); ?>') + '</p>';
        html += '</div>';
        
        // 调试信息
        if (data.debug_info) {
            html += '<div class="recall-test-section" style="border-left-color: #9b59b6;">';
            html += '<h4><?php _e('调试信息', 'content-auto-manager'); ?></h4>';
            if (data.debug_info.topic_vector_dimensions) {
                html += '<p><strong><?php _e('主题向量维度', 'content-auto-manager'); ?>:</strong> ' + data.debug_info.topic_vector_dimensions + '</p>';
            }
            if (data.debug_info.total_reference_profiles !== undefined) {
                html += '<p><strong><?php _e('参考资料总数', 'content-auto-manager'); ?>:</strong> ' + data.debug_info.total_reference_profiles + ' <?php _e('条', 'content-auto-manager'); ?></p>';
            }
            if (data.debug_info.suggestion) {
                html += '<p style="color: #e67e22;"><strong><?php _e('建议', 'content-auto-manager'); ?>:</strong> ' + escapeHtml(data.debug_info.suggestion) + '</p>';
            }
            html += '</div>';
        }
        
        // 所有参考资料相似度（调试用）
        if (data.all_profiles_similarity && data.all_profiles_similarity.length > 0) {
            html += '<div class="recall-test-section" style="border-left-color: #3498db;">';
            html += '<h4><?php _e('所有参考资料相似度', 'content-auto-manager'); ?> (' + data.all_profiles_similarity.length + ' <?php _e('条', 'content-auto-manager'); ?>)</h4>';
            html += '<p style="color: #666; font-size: 12px;"><?php _e('此表显示所有参考资料与主题的相似度，用于调试。阈值为 0.5（50%）。', 'content-auto-manager'); ?></p>';
            html += '<table class="recall-test-candidates-table">';
            html += '<thead><tr>';
            html += '<th>ID</th>';
            html += '<th><?php _e('标题', 'content-auto-manager'); ?></th>';
            html += '<th><?php _e('相似度', 'content-auto-manager'); ?></th>';
            html += '<th><?php _e('达到阈值', 'content-auto-manager'); ?></th>';
            html += '</tr></thead><tbody>';
            
            data.all_profiles_similarity.forEach(function(profile) {
                var similarity = profile.similarity;
                var similarityText = similarity !== null ? (similarity * 100).toFixed(2) + '%' : '<?php _e('解码失败', 'content-auto-manager'); ?>';
                var meetsThreshold = profile.meets_threshold;
                var rowStyle = meetsThreshold ? 'background: #e7f7e7;' : (similarity !== null && similarity < 0.3 ? 'background: #fef7f7;' : '');
                
                html += '<tr style="' + rowStyle + '">';
                html += '<td>' + profile.id + '</td>';
                html += '<td>' + escapeHtml(profile.title) + '</td>';
                html += '<td>' + similarityText + '</td>';
                html += '<td>' + (profile.error ? '<span style="color: #dc3232;">' + escapeHtml(profile.error) + '</span>' : (meetsThreshold ? '<span style="color: #46b450;">✓ <?php _e('是', 'content-auto-manager'); ?></span>' : '<span style="color: #999;">✗ <?php _e('否', 'content-auto-manager'); ?></span>')) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            html += '</div>';
        }
        
        // 错误信息
        if (data.error) {
            html += '<div class="recall-test-section recall-test-error">';
            html += '<h4><?php _e('召回失败', 'content-auto-manager'); ?></h4>';
            html += '<p>' + escapeHtml(data.error) + '</p>';
            html += '</div>';
            $('#recall-test-result').html(html);
            return;
        }
        
        // 候选列表
        if (data.candidates && data.candidates.length > 0) {
            html += '<div class="recall-test-section">';
            html += '<h4><?php _e('召回候选列表', 'content-auto-manager'); ?> (' + data.candidates.length + ' <?php _e('条', 'content-auto-manager'); ?>)</h4>';
            html += '<table class="recall-test-candidates-table">';
            html += '<thead><tr>';
            html += '<th>ID</th>';
            html += '<th><?php _e('标题', 'content-auto-manager'); ?></th>';
            html += '<th><?php _e('相似度', 'content-auto-manager'); ?></th>';
            html += '<th><?php _e('描述预览', 'content-auto-manager'); ?></th>';
            html += '</tr></thead><tbody>';
            
            data.candidates.forEach(function(candidate) {
                var isSelected = data.ai_selected && data.ai_selected.id == candidate.id;
                html += '<tr style="' + (isSelected ? 'background: #e7f7e7;' : '') + '">';
                html += '<td>' + candidate.id + (isSelected ? ' ✓' : '') + '</td>';
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
            html += '<div class="recall-test-section">';
            html += '<h4><?php _e('大模型精选结果', 'content-auto-manager'); ?></h4>';
            html += '<p><strong><?php _e('选中ID', 'content-auto-manager'); ?>:</strong> ' + data.ai_selected.id + '</p>';
            html += '<p><strong><?php _e('选中标题', 'content-auto-manager'); ?>:</strong> ' + escapeHtml(data.ai_selected.title) + '</p>';
            html += '<p><strong><?php _e('选择原因', 'content-auto-manager'); ?>:</strong> ' + escapeHtml(data.ai_selected.reason) + '</p>';
            html += '</div>';
        }
        
        // 最终结果
        if (data.final_result) {
            html += '<div class="recall-test-final-result">';
            html += '<h4><?php _e('最终召回结果', 'content-auto-manager'); ?></h4>';
            html += '<p><strong>ID:</strong> ' + data.final_result.id + '</p>';
            html += '<p><strong><?php _e('标题', 'content-auto-manager'); ?>:</strong> ' + escapeHtml(data.final_result.title) + '</p>';
            html += '<p><strong><?php _e('参考资料内容', 'content-auto-manager'); ?>:</strong></p>';
            html += '<div class="recall-test-description">' + escapeHtml(data.final_result.description) + '</div>';
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

<!-- 参考资料详情弹窗 -->
<div id="reference-material-modal" class="cam-ref-modal" style="display:none;">
    <div class="cam-ref-modal-content">
        <div class="cam-ref-modal-header">
            <h3 style="margin:0;"><?php _e('参考资料详情', 'content-auto-manager'); ?></h3>
            <span class="cam-ref-modal-close">&times;</span>
        </div>
        <div class="cam-ref-modal-body">
            <textarea id="reference-material-text" readonly></textarea>
        </div>
        <div class="cam-ref-modal-footer">
            <button type="button" class="button" id="copy-reference-btn"><?php _e('复制内容', 'content-auto-manager'); ?></button>
            <button type="button" class="button button-primary cam-ref-modal-close-btn"><?php _e('关闭', 'content-auto-manager'); ?></button>
        </div>
    </div>
</div>

<style>
    /* 参考资料弹窗样式 - 独立命名空间 */
    .cam-ref-modal {
        position: fixed;
        z-index: 100000;
        left: 0;
        top: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        overflow: hidden;
        background-color: rgba(0,0,0,0.6);
        padding: 0;
        margin: 0;
        border: none;
    }
    .cam-ref-modal-content {
        background-color: #fff;
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 60%;
        max-width: 800px;
        max-height: 60vh;
        border: none;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .cam-ref-modal-header {
        padding: 16px 20px;
        background-color: #fff;
        border-bottom: 1px solid #e5e5e5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
    }
    .cam-ref-modal-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #1d2327;
    }
    .cam-ref-modal-close {
        cursor: pointer;
        font-size: 24px;
        color: #999;
        line-height: 1;
        background: none;
        border: none;
        padding: 0;
    }
    .cam-ref-modal-close:hover {
        color: #d63638;
    }
    .cam-ref-modal-body {
        padding: 20px;
        overflow-y: auto;
        flex: 1;
        min-height: 200px;
    }
    .cam-ref-modal-body textarea {
        width: 100%;
        height: 100%;
        min-height: 250px;
        padding: 15px;
        box-sizing: border-box;
        font-family: Consolas, Monaco, 'Andale Mono', monospace;
        font-size: 13px;
        line-height: 1.6;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #f9f9f9;
        resize: none;
        outline: none;
    }
    .cam-ref-modal-footer {
        padding: 12px 20px;
        border-top: 1px solid #e5e5e5;
        background: #f7f7f7;
        text-align: right;
        flex-shrink: 0;
    }
    .cam-ref-modal-footer .button {
        margin-left: 8px;
    }
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    var modal = $('#reference-material-modal');
    
    // 关键修正：将弹窗移动到 body 直接子级，防止被其他容器样式干扰（解决"白色背景"和层级问题）
    if (modal.parent().not('body').length) {
        modal.appendTo('body');
    }
    
    var textarea = $('#reference-material-text');
    var copyBtn = $('#copy-reference-btn');
    
    // 打开弹窗
    $(document).on('click', '.view-reference-material', function(e) {
        e.preventDefault();
        var content = $(this).data('full-content');
        textarea.val(content);
        modal.fadeIn(200);
    });
    
    // 关闭弹窗
    $(document).on('click', '.cam-ref-modal-close, .cam-ref-modal-close-btn', function() {
        modal.fadeOut(200);
    });
    
    // 点击外部关闭
    $(window).on('click', function(e) {
        if ($(e.target).is(modal)) {
            modal.fadeOut(200);
        }
    });
    
    // 复制内容
    copyBtn.on('click', function() {
        textarea.select();
        try {
            document.execCommand('copy');
            var originalText = copyBtn.text();
            copyBtn.text('<?php _e('已复制!', 'content-auto-manager'); ?>');
            setTimeout(function() {
                copyBtn.text(originalText);
            }, 2000);
        } catch (err) {
            console.error('复制失败', err);
        }
    });
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
// 国际化文本
window.camTopicFilterI18n = {
    detecting: '<?php _e('正在检测重复标题...', 'content-auto-manager'); ?>',
    detected: '<?php _e('检测完成，发现', 'content-auto-manager'); ?>',
    duplicateTopics: '<?php _e('个重复主题', 'content-auto-manager'); ?>',
    noDuplicates: '<?php _e('未发现重复标题', 'content-auto-manager'); ?>',
    detectFailed: '<?php _e('检测失败', 'content-auto-manager'); ?>',
    requestFailed: '<?php _e('请求失败', 'content-auto-manager'); ?>',
    summary: '<?php _e('检测结果汇总', 'content-auto-manager'); ?>',
    exactGroups: '<?php _e('完全相同标题组', 'content-auto-manager'); ?>',
    exactTopics: '<?php _e('完全重复主题数', 'content-auto-manager'); ?>',
    similarGroups: '<?php _e('向量相似组', 'content-auto-manager'); ?>',
    similarTopics: '<?php _e('相似重复主题数', 'content-auto-manager'); ?>',
    exactLabel: '<?php _e('完全相同', 'content-auto-manager'); ?>',
    exactTitle: '<?php _e('完全相同的标题', 'content-auto-manager'); ?>',
    similarLabel: '<?php _e('向量相似', 'content-auto-manager'); ?>',
    similarTitle: '<?php _e('向量相似的标题', 'content-auto-manager'); ?>',
    groups: '<?php _e('组', 'content-auto-manager'); ?>',
    topics: '<?php _e('个主题', 'content-auto-manager'); ?>',
    createdAt: '<?php _e('创建时间', 'content-auto-manager'); ?>',
    category: '<?php _e('分类', 'content-auto-manager'); ?>',
    keep: '<?php _e('保留', 'content-auto-manager'); ?>',
    willDelete: '<?php _e('将删除', 'content-auto-manager'); ?>',
    similarity: '<?php _e('相似度', 'content-auto-manager'); ?>',
    noResults: '<?php _e('未发现重复标题', 'content-auto-manager'); ?>',
    detectFirst: '<?php _e('请先检测重复标题', 'content-auto-manager'); ?>',
    noDuplicatesToDelete: '<?php _e('没有需要删除的重复主题', 'content-auto-manager'); ?>',
    confirmDeleteAll: '<?php _e('确定要删除所有重复主题吗？将保留每组中最早创建的主题。', 'content-auto-manager'); ?>',
    deleting: '<?php _e('正在删除重复主题...', 'content-auto-manager'); ?>',
    deleteFailed: '<?php _e('删除失败', 'content-auto-manager'); ?>',
    selectTopics: '<?php _e('请选择要删除的主题', 'content-auto-manager'); ?>',
    confirmDeleteSelected: '<?php _e('确定要删除选中的', 'content-auto-manager'); ?>',
    topicsConfirm: '<?php _e('个主题吗？此操作不可撤销。', 'content-auto-manager'); ?>',
    deletingText: '<?php _e('删除中...', 'content-auto-manager'); ?>',
    deleteSelected: '<?php _e('删除选中', 'content-auto-manager'); ?>'
};
</script>
<script src="<?php echo esc_url($filter_js_url); ?>?ver=<?php echo CONTENT_AUTO_MANAGER_VERSION; ?>"></script>

<!-- 手工添加主题弹窗 -->
<div class="cam-modal-overlay" id="manual-add-modal-overlay">
    <div class="cam-modal">
        <div class="cam-modal-header">
            <h3><?php _e('手工添加主题', 'content-auto-manager'); ?></h3>
            <button type="button" class="cam-modal-close" id="manual-add-modal-close">&times;</button>
        </div>
        <div class="cam-modal-body">
            <div class="cam-form-group">
                <label for="manual-titles"><?php _e('主题标题', 'content-auto-manager'); ?> <span style="color:#d63638;">*</span></label>
                <textarea id="manual-titles" class="cam-form-control" rows="5" placeholder="<?php _e('每行输入一个主题标题，可批量添加多个主题', 'content-auto-manager'); ?>"></textarea>
                <p class="description"><?php _e('支持批量添加，每行一个标题', 'content-auto-manager'); ?></p>
            </div>
            
            <div class="cam-form-group">
                <label for="manual-reference"><?php _e('参考资料', 'content-auto-manager'); ?> <span style="color:#888;">(<?php _e('可选', 'content-auto-manager'); ?>)</span></label>
                <textarea id="manual-reference" class="cam-form-control" rows="4" placeholder="<?php _e('输入参考资料，用于指导AI生成文章内容', 'content-auto-manager'); ?>"></textarea>
                <div class="char-counter" id="reference-char-counter">0 / 800</div>
                <p class="description"><?php _e('参考资料将帮助AI生成更准确、有深度的内容。所有主题共享同一参考资料。', 'content-auto-manager'); ?></p>
            </div>
            
            <div class="cam-form-group">
                <label for="manual-category"><?php _e('目标分类', 'content-auto-manager'); ?></label>
                <select id="manual-category" class="cam-form-control">
                    <option value=""><?php _e('AI 智能自动匹配', 'content-auto-manager'); ?></option>
                    <?php
                    $all_categories = get_categories(array('hide_empty' => false));
                    foreach ($all_categories as $cat) {
                        echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
                    }
                    ?>
                </select>
                <p class="description"><?php _e('指定分类或让AI根据内容自动匹配', 'content-auto-manager'); ?></p>
            </div>
        </div>
        <div class="cam-modal-footer">
            <button type="button" class="cam-btn cam-btn-secondary" id="manual-add-cancel"><?php _e('取消', 'content-auto-manager'); ?></button>
            <button type="button" class="cam-btn cam-btn-primary" id="manual-add-submit"><?php _e('添加主题', 'content-auto-manager'); ?></button>
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
    adding: '<?php _e('正在添加...', 'content-auto-manager'); ?>',
    addSuccess: '<?php _e('添加成功', 'content-auto-manager'); ?>',
    addFailed: '<?php _e('添加失败', 'content-auto-manager'); ?>',
    requestFailed: '<?php _e('请求失败', 'content-auto-manager'); ?>',
    pleaseEnterTitle: '<?php _e('请至少输入一个主题标题', 'content-auto-manager'); ?>'
};
</script>
<script src="<?php echo esc_url($modal_js_url); ?>?ver=<?php echo CONTENT_AUTO_MANAGER_VERSION; ?>"></script>
