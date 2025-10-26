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

// 处理表单提交（手工添加主题）
if (isset($_POST['submit']) && isset($_POST['content_auto_manager_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_topics')) {
        wp_die(__('安全验证失败。'));
    }
    
    // 获取表单数据
    $titles = sanitize_textarea_field($_POST['titles']);
    $reference_material = isset($_POST['reference_material']) ? sanitize_textarea_field($_POST['reference_material']) : '';

    // 验证参考资料长度（最多800字符）
    if (mb_strlen($reference_material) > 800) {
        $reference_material = mb_substr($reference_material, 0, 800);
        echo '<div class="notice notice-warning"><p>' . __('参考资料已截断至800字符。', 'content-auto-manager') . '</p></div>';
    }

    // 验证数据
    if (empty($titles)) {
        echo '<div class="notice notice-error"><p>' . __('请填写主题标题。', 'content-auto-manager') . '</p></div>';
    } else {
        // 分割主题标题
        $title_array = explode("\n", $titles);
        $database = new ContentAuto_Database();

        foreach ($title_array as $title) {
            $title = trim($title);
            if (!empty($title)) {
                // 插入主题数据
                $data = array(
                    'task_id' => '', // 手工添加的主题task_id为空字符串
                    'rule_id' => 0, // 手工添加的主题rule_id为0
                    'rule_item_index' => 0, // 手工添加的主题rule_item_index为0
                    'title' => $title,
                    'source_angle' => '',
                    'user_value' => '',
                    'seo_keywords' => '',
                    'matched_category' => '',
                    'priority_score' => 3,
                    'status' => CONTENT_AUTO_TOPIC_UNUSED,
                    'reference_material' => $reference_material // 添加参考资料字段
                );
                $database->insert('content_auto_topics', $data);
            }
        }
        
        echo '<div class="notice notice-success"><p>' . __('主题已添加。', 'content-auto-manager') . '</p></div>';
    }
}

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

// 获取分页参数
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($current_page - 1) * $per_page;

// 构建查询条件
$where = array('status' => $status);
if (!empty($task_id)) {
    $where['task_id'] = $task_id;
}

// 获取主题，按最后更新时间排序，支持分页
global $wpdb;
$topics_table = $wpdb->prefix . 'content_auto_topics';
$where_clause = '';
$where_values = array();

if (!empty($where)) {
    $where_parts = array();
    foreach ($where as $key => $value) {
        $where_parts[] = "$key = %s";
        $where_values[] = $value;
    }
    $where_clause = 'WHERE ' . implode(' AND ', $where_parts);
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
    <h1><?php _e('主题管理', 'content-auto-manager'); ?></h1>
    
    <!-- 筛选器 -->
    <div class="content-auto-section">
        <h2><?php _e('筛选主题', 'content-auto-manager'); ?></h2>
        
        <form method="get" action="">
            <input type="hidden" name="page" value="content-auto-manager-topics">
            <input type="hidden" name="paged" value="<?php echo esc_attr($current_page); ?>">
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('任务ID', 'content-auto-manager'); ?></th>
                    <td>
                        <input type="text" name="task_id" value="<?php echo esc_attr($task_id); ?>" class="regular-text">
                        <p class="description"><?php _e('输入任务ID或留空显示所有任务的主题', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                <th scope="row"><?php _e('状态', 'content-auto-manager'); ?></th>
                <td>
                    <select name="status">
                        <option value="unused" <?php selected($status, 'unused'); ?>><?php _e('未使用', 'content-auto-manager'); ?></option>
                        <option value="queued" <?php selected($status, 'queued'); ?>><?php _e('队列中', 'content-auto-manager'); ?></option>
                        <option value="used" <?php selected($status, 'used'); ?>><?php _e('已使用', 'content-auto-manager'); ?></option>
                    </select>
                </td>
            </tr>
            </table>
            
            <?php submit_button(__('筛选', 'content-auto-manager'), 'secondary'); ?>
        </form>
    </div>
    
    <!-- 手工添加主题 -->
    <div class="content-auto-section">
        <h2><?php _e('手工添加主题', 'content-auto-manager'); ?></h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_topics', 'content_auto_manager_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('主题标题', 'content-auto-manager'); ?></th>
                    <td>
                        <textarea name="titles" rows="5" cols="50" class="large-text" placeholder="<?php _e('每行一个主题标题', 'content-auto-manager'); ?>"></textarea>
                        <p class="description"><?php _e('每行输入一个主题标题，可以批量添加多个主题。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('参考资料', 'content-auto-manager'); ?></th>
                    <td>
                        <textarea name="reference_material" rows="4" cols="50" class="large-text"
                                  placeholder="<?php _e('可选：输入这些主题的参考资料，用于指导文章生成', 'content-auto-manager'); ?>"></textarea>
                        <p class="description">
                            <?php _e('参考资料将帮助AI生成更准确、更有深度的内容。所有手工添加的主题将使用相同的参考资料。最多800字符。', 'content-auto-manager'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('添加主题', 'content-auto-manager')); ?>
        </form>
    </div>
    
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

                                    if (!empty($reference_text)):
                                        $display_text = mb_substr($reference_text, 0, 30);
                                        if (mb_strlen($reference_text) > 30) {
                                            $display_text .= '...';
                                        }
                                        echo '<span class="reference-material" title="' . esc_attr($reference_text) . '">' . esc_html($display_text) . '</span>';
                                    else:
                                        echo '<span class="no-data">-</span>';
                                    endif;
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $topic['id'])), 'content_auto_manager_delete_topic', 'nonce'); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php _e('确定要删除此主题吗？', 'content-auto-manager'); ?>')">
                                        <?php _e('删除', 'content-auto-manager'); ?>
                                    </a>
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
</style>