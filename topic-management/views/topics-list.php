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

// 表单处理已移至 admin/form-handlers.php，在 admin_init 钩子中执行

// 获取筛选参数
$task_id = isset($_GET['task_id']) ? sanitize_text_field($_GET['task_id']) : '';
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : YALI_AI_WRITER_TOPIC_UNUSED;
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
$topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
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
$rule_manager = new Yali_AI_Writer_RuleManager();
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
                                $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
                                $existing_categories = $wpdb->get_col("
                                    SELECT DISTINCT matched_category 
                                    FROM {$topics_table} 
                                    WHERE matched_category IS NOT NULL AND matched_category != ''
                                    ORDER BY matched_category ASC
                                ");
                                foreach ($existing_categories as $cat) {
                                    echo '<option value="' . esc_attr($cat) . '" ' . selected(isset($_GET['matched_category']) && $_GET['matched_category'] === $cat, true, false) . '>' . esc_html($cat) . '</option>';
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
                            <a href="<?php echo esc_url(admin_url('admin.php?page=yali-ai-writer-topics')); ?>" class="button yali-btn yali-btn-secondary" id="reset-filters" style="margin-left: 5px;"><?php _e('重置', 'yali-ai-writer'); ?></a>
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
                                                <?php wp_nonce_field('yali_ai_writer_manager_generate_articles', 'yali_ai_writer_manager_nonce'); ?>        <!-- 主题列表 -->
        <div class="yali-card">
            <div class="yali-card-header">
                <div class="yali-card-title" style="font-size: 1.3em; font-weight: 600;"><?php _e('主题列表', 'yali-ai-writer'); ?></div>
                <div class="yali-card-actions" style="display: flex; gap: 10px; align-items: center;">
                    <a href="#" id="open-manual-add-modal" class="button yali-btn yali-btn-secondary">
                        <span class="dashicons dashicons-plus-alt2"></span> <?php _e('手工添加主题', 'yali-ai-writer'); ?>
                    </a>
                <?php if (!empty($topics)): ?>
                    <input type="submit" name="generate_articles" class="button button-primary yali-btn yali-btn-primary" value="<?php esc_attr_e('生成文章', 'yali-ai-writer'); ?>">
                    <input type="submit" name="deep_writing" class="button button-secondary yali-btn yali-btn-secondary" value="<?php esc_attr_e('深度写作', 'yali-ai-writer'); ?>" style="margin-left: 5px;">
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
                                        <?php echo ($topic['status'] === YALI_AI_WRITER_TOPIC_QUEUED || $topic['status'] === YALI_AI_WRITER_TOPIC_USED) ? 'disabled' : ''; ?>>
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
                                            <?php _e('项目:', 'yali-ai-writer'); ?> <?php echo intval($topic['rule_item_index'] + 1); ?> | 
                                            <?php _e('规则:', 'yali-ai-writer'); ?> <?php echo $rule ? esc_html($rule['rule_name']) : esc_html__('无', 'yali-ai-writer'); ?>
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
                                            <?php echo esc_html(yali_ai_writer_manager_truncate_string($topic['user_value'], 10)); ?>
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
                                        <div class="priority-score priority-<?php echo esc_attr($topic['priority_score']); ?>" style="white-space: nowrap;">
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
                                    <span class="<?php echo esc_attr($status_class); ?>">
                                        <?php echo yali_ai_writer_manager_get_topic_status_label($topic['status']); ?>
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
                                    <span class="<?php echo esc_attr($status_class); ?>" title="<?php echo esc_attr($error_title); ?>">
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
                                                data-yali-ajax-action="yali_ai_writer_delete_topic"
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


<div id="deep-writing-confirm-modal" class="yali-modal-overlay">
    <div class="yali-modal" style="max-width: 560px;">
        <div class="yali-modal-header">
            <h3><?php _e('深度写作', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close" id="deep-writing-confirm-modal-close" aria-label="<?php esc_attr_e('关闭', 'yali-ai-writer'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="yali-modal-body">
            <p>
                <?php
                echo wp_kses_post(sprintf(
                    __('深度写作需要打开%s，每篇文章约执行5~30分钟', 'yali-ai-writer'),
                    '<a href="https://www.yaliai.com/product/extension/" target="_blank" rel="noopener" class="yali-link">' . esc_html__('鸭梨AI浏览器扩展', 'yali-ai-writer') . '</a>'
                ));
                ?>
            </p>
            <p>
                <?php
                echo wp_kses_post(sprintf(
                    __('在此期间不要关闭%s。', 'yali-ai-writer'),
                    '<a href="https://www.yaliai.com/product/extension/" target="_blank" rel="noopener" class="yali-link">' . esc_html__('鸭梨AI浏览器扩展', 'yali-ai-writer') . '</a>'
                ));
                ?>
            </p>
            <p><?php _e('完成后会将文章发布到文章列表，转来为草稿，请校对没问题后发布。', 'yali-ai-writer'); ?></p>
            <p><?php _e('文章中会自动配图，请提前配置好图像API。', 'yali-ai-writer'); ?></p>
        </div>
        <div class="yali-modal-footer">
            <button type="button" class="yali-btn yali-btn-secondary" id="deep-writing-confirm-cancel"><?php _e('取消', 'yali-ai-writer'); ?></button>
            <button type="button" class="yali-btn yali-btn-primary" id="deep-writing-confirm-submit"><?php _e('确认写作', 'yali-ai-writer'); ?></button>
        </div>
    </div>
</div>




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



<!-- 高级筛选配置 -->
<input type="hidden" id="cam-filter-nonce" value="<?php echo wp_create_nonce('cam_topic_filter'); ?>">


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


<input type="hidden" id="cam-manual-add-nonce" value="<?php echo wp_create_nonce('cam_manual_add_topics'); ?>">

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
