<?php
/**
 * 主题任务页面
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 获取筛选参数
$status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'all';

// 处理表单提交
if (isset($_POST['submit']) && isset($_POST['content_auto_manager_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_topic_jobs')) {
        wp_die(__('安全验证失败。'));
    }
    
    // 获取表单数据
    $rule_id = intval($_POST['rule_id']);
    $topic_count = intval($_POST['topic_count']);
    
    // 验证数据
    if (empty($rule_id) || empty($topic_count) || $topic_count <= 0) {
        echo '<div class="notice notice-error"><p>' . __('请填写所有必填字段。', 'content-auto-manager') . '</p></div>';
    } else {
        // 创建主题生成任务
        $topic_task_manager = new ContentAuto_TopicTaskManager();
        $task_id = $topic_task_manager->create_topic_task($rule_id, $topic_count);
        
        if ($task_id) {
            echo '<div class="notice notice-success"><p>' . __('主题生成任务已创建。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('主题生成任务创建失败。', 'content-auto-manager') . '</p></div>';
        }
    }
}

// 获取启用的规则
$rule_manager = new ContentAuto_RuleManager();
$rules = $rule_manager->get_active_rules();

// 获取所有主题任务并根据状态筛选
$topic_task_manager = new ContentAuto_TopicTaskManager();

// 构建查询条件
$where_clause = '';
$where_values = array();

if ($status_filter !== 'all') {
    $where_clause = 'WHERE status = %s';
    $where_values = array($status_filter);
}

$tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
$query = "SELECT * FROM $tasks_table $where_clause ORDER BY created_at DESC";

if (!empty($where_values)) {
    $query = $wpdb->prepare($query, $where_values);
}

$tasks = $wpdb->get_results($query, ARRAY_A);

/**
 * 获取任务失败原因
 */
function get_topic_task_failure_reason($task_id) {
    global $wpdb;
    
    // 从任务表中直接获取错误信息
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    $task = $wpdb->get_row($wpdb->prepare("SELECT error_message, rule_id FROM {$tasks_table} WHERE id = %d", $task_id));
    
    if ($task && !empty($task->error_message)) {
        return $task->error_message;
    }
    
    // 检查API配置
    $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
    $active_api = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table} WHERE is_active = 1");
    
    if ($active_api == 0) {
        return __('没有激活的API配置', 'content-auto-manager');
    }
    
    // 检查规则状态
    if ($task) {
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$rules_table} WHERE id = %d", $task->rule_id));
        if ($rule && $rule->status == 0) {
            return __('关联的规则已被禁用', 'content-auto-manager');
        }
        
        // 检查规则是否存在
        if (!$rule) {
            return __('关联的规则不存在', 'content-auto-manager');
        }
    } else {
        return __('任务信息不完整', 'content-auto-manager');
    }
    
    return __('处理过程中出现未知错误', 'content-auto-manager');
}

/**
 * 获取任务状态标签
 */
function get_topic_job_status_label($status) {
    switch ($status) {
        case 'pending':
            return __('待处理', 'content-auto-manager');
        case 'running':
            return __('运行中', 'content-auto-manager');
        case 'processing':
            return __('处理中', 'content-auto-manager');
        case 'completed':
            return __('已完成', 'content-auto-manager');
        case 'failed':
            return __('失败', 'content-auto-manager');
        case 'paused':
            return __('已暂停', 'content-auto-manager');
        case 'cancelled':
            return __('已取消', 'content-auto-manager');
        default:
            return $status;
    }
}

/**
 * 获取规则类型的正确名称
 */
function get_rule_type_name($rule) {
    if (!$rule) {
        return __('规则不存在', 'content-auto-manager');
    }
    
    switch ($rule->rule_type) {
        case 'random_selection':
            return __('随机选择文章', 'content-auto-manager');
            
        case 'fixed_articles':
            return __('固定选择文章', 'content-auto-manager');
            
        case 'upload_text':
            return __('上传文本内容', 'content-auto-manager');
            
        case 'import_keywords':
            return __('导入关键词', 'content-auto-manager');
            
        case 'random_categories':
            return __('随机分类', 'content-auto-manager');
            
        default:
            return __('未知类型', 'content-auto-manager');
    }
}
?>

<div class="wrap">
      <h1><?php _e('主题任务', 'content-auto-manager'); ?></h1>
    
    <!-- 创建任务表单 -->
    <div class="content-auto-section">
        <h2><?php _e('创建主题生成任务', 'content-auto-manager'); ?></h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_topic_jobs', 'content_auto_manager_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('选择规则', 'content-auto-manager'); ?></th>
                    <td>
                        <?php if (empty($rules)): ?>
                            <p><?php _e('暂无启用的规则，请先创建并启用规则。', 'content-auto-manager'); ?></p>
                        <?php else: ?>
                            <select name="rule_id" class="regular-text" required>
                                <option value=""><?php _e('请选择规则', 'content-auto-manager'); ?></option>
                                <?php foreach ($rules as $rule): ?>
                                    <option value="<?php echo esc_attr($rule->id); ?>">
                                        <?php echo esc_html($rule->rule_name); ?> (<?php echo esc_html(get_rule_type_name($rule)); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('注意：如果在任务执行过程中修改规则，任务将使用修改后的规则内容继续执行。', 'content-auto-manager'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('生成主题数量', 'content-auto-manager'); ?></th>
                    <td>
                        <input type="number" name="topic_count" value="<?php echo CONTENT_AUTO_DEFAULT_TOPIC_COUNT; ?>" min="1" max="100" class="small-text" required <?php echo empty($rules) ? 'disabled' : ''; ?>>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('创建任务', 'content-auto-manager')); ?>
        </form>
    </div>
    
    <!-- 任务列表 -->
    <div class="content-auto-section">
        <h2><?php _e('任务列表', 'content-auto-manager'); ?></h2>
        
        <!-- 筛选器 -->
        <div class="tablenav top">
            <form method="get" action="">
                <input type="hidden" name="page" value="content-auto-manager-topic-jobs">
                <div class="alignleft actions">
                    <select name="status_filter" id="status_filter">
                        <option value="all"><?php _e('所有状态', 'content-auto-manager'); ?></option>
                        <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('失败', 'content-auto-manager'); ?></option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('待处理', 'content-auto-manager'); ?></option>
                        <option value="processing" <?php selected($status_filter, 'processing'); ?>><?php _e('处理中', 'content-auto-manager'); ?></option>
                        <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('已完成', 'content-auto-manager'); ?></option>
                    </select>
                    <input type="submit" class="button" value="<?php _e('筛选', 'content-auto-manager'); ?>">
                </div>
            </form>
        </div>
        
        <?php if (empty($tasks)): ?>
            <p><?php _e('暂无主题生成任务。', 'content-auto-manager'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="select_all_tasks">
                        </th>
                        <th><?php _e('主题任务ID', 'content-auto-manager'); ?></th>
                        <th><?php _e('规则', 'content-auto-manager'); ?></th>
                        <th><?php _e('进度', 'content-auto-manager'); ?></th>
                        <th><?php _e('主题数量', 'content-auto-manager'); ?></th>
                        <th><?php _e('状态', 'content-auto-manager'); ?></th>
                        <th><?php _e('创建时间', 'content-auto-manager'); ?></th>
                        <th><?php _e('操作', 'content-auto-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                        // 获取规则信息
                        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}content_auto_rules WHERE id = %d", $task['rule_id']));
                        
                        // 获取进度信息
                        $progress = $topic_task_manager->get_task_progress($task['topic_task_id']);
                        ?>
                        <tr class="task-row <?php echo $task['status'] === 'failed' ? 'failed-task' : ''; ?>" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                            <td>
                                <?php if ($task['status'] === 'failed'): ?>
                                    <input type="checkbox" name="task_ids[]" value="<?php echo esc_attr($task['topic_task_id']); ?>" class="task-checkbox">
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($task['topic_task_id']); ?></td>
                            <td>
                                <?php if ($rule): ?>
                                    <?php echo esc_html($rule->rule_name); ?> 
                                    (<?php echo esc_html(get_rule_type_name($rule)); ?>)
                                <?php else: ?>
                                    <?php _e('规则不存在', 'content-auto-manager'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($progress): ?>
                                    <div class="progress-info">
                                        <div class="progress-text">
                                            <?php printf(__('规则项目: %d/%d', 'content-auto-manager'), $progress['current_item'], $progress['total_items']); ?>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%"></div>
                                        </div>
                                        <div class="progress-topics">
                                            <?php printf(__('生成主题数: %d/%d', 'content-auto-manager'), $progress['generated_topics'], $progress['expected_topics']); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php _e('无进度信息', 'content-auto-manager'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php printf(__('每项目%d个 (总计%d个)', 'content-auto-manager'), $task['topic_count_per_item'], $task['total_expected_topics']); ?>
                            </td>
                            <td>
                                <span class="task-status status-<?php echo esc_attr($task['status']); ?>" data-status="<?php echo esc_attr($task['status']); ?>">
                                    <?php echo get_topic_job_status_label($task['status']); ?>
                                </span>
                                <?php if ($task['status'] === CONTENT_AUTO_STATUS_FAILED): ?>
                                    <br><span class="failure-reason task-error"><?php echo esc_html(get_topic_task_failure_reason($task['topic_task_id'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo content_auto_manager_format_time($task['created_at']); ?></td>
                            <td>
                                <div class="task-controls" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                    <?php // 暂停：只在任务是"待处理"、"运行中"或"处理中"时显示。 ?>
                                    <?php if (in_array($task['status'], ['pending', 'running', 'processing'])) : ?>
                                        <button class="button button-small pause-task" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                            <?php _e('暂停', 'content-auto-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php // 恢复：只在任务是“已暂停”时显示。 ?>
                                    <?php if ($task['status'] === 'paused') : ?>
                                        <button class="button button-small resume-task" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                            <?php _e('恢复', 'content-auto-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php // 重试：只在任务是“失败”时显示。 ?>
                                    <?php if ($task['status'] === 'failed') : ?>
                                        <button class="button button-small retry-task" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                            <?php _e('重试', 'content-auto-manager'); ?>
                                        </button>
                                    <?php endif; ?>

                                    <?php // 删除：在任务不是“已完成”的任何状态下，都应该可以删除。 ?>
                                    <?php if ($task['status'] !== 'completed') : ?>
                                        <button class="button button-small delete-task" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                            <?php _e('删除', 'content-auto-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- 批量操作按钮 -->
            <div class="bulk-actions">
                <button class="button button-primary bulk-retry-tasks" disabled>
                    <?php _e('批量重试选中任务', 'content-auto-manager'); ?>
                </button>
                <span class="bulk-actions-info"></span>
            </div>
        <?php endif; ?>
    </div>
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

.status-pending {
    color: #0073aa;
    font-weight: bold;
    background-color: #e8f0f8;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-running {
    color: #00a32a;
    font-weight: bold;
    background-color: #e8f5e9;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-processing {
    color: #ff6f00;
    font-weight: bold;
    background-color: #fff3e0;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-completed {
    color: #00a32a;
    font-weight: bold;
    background-color: #e8f5e9;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-failed {
    color: #dc3232;
    font-weight: bold;
    background-color: #ffebee;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-paused {
    color: #ff9800;
    font-weight: bold;
    background-color: #fff8e1;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.status-cancelled {
    color: #757575;
    font-weight: bold;
    background-color: #f5f5f5;
    padding: 2px 6px;
    border-radius: 3px;
    display: inline-block;
}

.failure-reason {
    color: #dc3232;
    font-size: 12px;
    font-style: italic;
    display: block;
    margin-top: 5px;
}

.button-small {
    padding: 4px 8px;
    font-size: 12px;
    margin: 0 2px;
}

.progress-info {
    min-width: 150px;
}

.progress-text, .progress-topics {
    font-size: 12px;
    color: #666;
    margin-bottom: 2px;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background-color: #f0f0f0;
    border-radius: 4px;
    overflow: hidden;
    margin: 2px 0;
}

.progress-fill {
    height: 100%;
    background-color: #0073aa;
    transition: width 0.3s ease;
}

.status-processing .progress-fill {
    background-color: #ff6f00;
}

.task-controls {
    display: flex;
    align-items: center;
    gap: 4px;
}

.task-controls .button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0;
}

.task-controls .dashicons {
    font-size: 16px;
    margin: 0;
}

.task-row {
    transition: background-color 0.2s ease;
}

.task-row:hover {
    background-color: #f9f9f9;
}

.task-status {
    display: inline-block;
    min-width: 80px;
    text-align: center;
}

.task-error {
    max-width: 200px;
    word-wrap: break-word;
}

/* Loading states */
.task-controls .button.loading {
    opacity: 0.6;
    cursor: not-allowed;
}

.task-controls .button.loading .dashicons {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

/* Responsive design */
@media screen and (max-width: 782px) {
    .task-controls {
        flex-direction: column;
        gap: 4px;
    }
    
    .task-controls .button {
        width: 100%;
        min-width: auto;
    }
    
    .progress-info {
        min-width: 100px;
    }
}

/* Bulk actions */
.bulk-actions {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

.bulk-actions .button {
    margin-right: 10px;
}

.bulk-actions-info {
    color: #666;
    font-size: 13px;
}

/* 页面头部样式 */
.page-header-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.page-header-actions h1 {
    margin: 0;
    font-size: 23px;
    font-weight: 400;
    line-height: 1.3;
}
</style>


<script>
document.addEventListener('DOMContentLoaded', function() {
    // 全选/取消全选功能
    const selectAllCheckbox = document.getElementById('select_all_tasks');
    const taskCheckboxes = document.querySelectorAll('.task-checkbox');
    const bulkRetryButton = document.querySelector('.bulk-retry-tasks');
    const bulkActionsInfo = document.querySelector('.bulk-actions-info');

    if (selectAllCheckbox && taskCheckboxes.length > 0) {
        // 全选功能
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            taskCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateBulkActions();
        });

        // 单个复选框变化时更新全选状态
        taskCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        // 更新批量操作按钮状态
        function updateBulkActions() {
            const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
            
            if (bulkRetryButton) {
                bulkRetryButton.disabled = checkedCount === 0;
            }
            
            if (bulkActionsInfo) {
                bulkActionsInfo.textContent = checkedCount > 0 ? 
                    `已选择 ${checkedCount} 个任务` : '';
            }
        }

        // 批量重试功能
        if (bulkRetryButton) {
            bulkRetryButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                const checkedBoxes = document.querySelectorAll('.task-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    alert('请至少选择一个任务进行重试');
                    return;
                }
                
                if (!confirm(`确定要重试选中的 ${checkedBoxes.length} 个任务吗？`)) {
                    return;
                }
                
                const taskIds = Array.from(checkedBoxes).map(cb => cb.value);
                
                // 显示加载状态
                const originalText = bulkRetryButton.textContent;
                bulkRetryButton.textContent = '处理中...';
                bulkRetryButton.disabled = true;
                
                // 发送AJAX请求
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'content_auto_bulk_retry_topic_tasks',
                        task_ids: taskIds,
                        nonce: '<?php echo wp_create_nonce("content_auto_manager_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('批量重试请求已提交，任务将在后台处理。');
                            location.reload();
                        } else {
                            alert('批量重试失败: ' + (response.data?.message || '未知错误'));
                        }
                    },
                    error: function() {
                        alert('批量重试失败: 服务器错误');
                    },
                    complete: function() {
                        bulkRetryButton.textContent = originalText;
                        bulkRetryButton.disabled = false;
                    }
                });
            });
        }
    }
});
</script>

</body>
</html>
