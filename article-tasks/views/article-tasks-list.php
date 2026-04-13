<?php
/**
 * 重构后的文章任务管理页面
 * 支持新的表结构和子任务管理
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

$article_task_manager = new Yali_AI_Writer_ArticleTaskManager();
$tasks = $article_task_manager->get_tasks();

function get_article_task_status_label($status) {
    switch ($status) {
        case 'pending': return __('待处理', 'yali-ai-writer');
        case 'processing': return __('处理中', 'yali-ai-writer');
        case 'completed': return __('已完成', 'yali-ai-writer');
        case 'failed': return __('失败', 'yali-ai-writer');
        case 'paused': return __('已暂停', 'yali-ai-writer');
        default: return $status;
    }
}

function get_article_task_status_class($status) {
    switch ($status) {
        case 'pending': return 'status-pending';
        case 'processing': return 'status-processing';
        case 'completed': return 'status-completed';
        case 'failed': return 'status-failed';
        case 'paused': return 'status-paused';
        default: return 'status-unknown';
    }
}

/**
 * 翻译文章任务错误消息
 * 将中文错误消息转换为英文
 */
function yali_translate_task_error_message($error_message) {
    if (empty($error_message)) {
        return $error_message;
    }

    // 匹配 "子任务 {id} 处理失败（非API错误，直接标记为最终失败）"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败（非API错误，直接标记为最终失败）|processing failed \(non-API error, marked as final failure\))$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %s 处理失败（非API错误，直接标记为最终失败）', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配 "子任务 {id} 处理失败"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败|processing failed)$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %s 处理失败', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配 "子任务 {id} 处理失败. 阶段: {stage}, 详情: {details}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败|processing failed)\.\s*(?:阶段:|Stage:)\s*([^,]+),\s*(?:详情:|Details:)\s*(.+)$/is', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 处理失败. 阶段: %2$s, 详情: %3$s', 'yali-ai-writer'), $matches[1], trim($matches[2]), trim($matches[3]));
    }

    // 匹配 "子任务 {id} 最终失败（非API错误）. 阶段: {stage}, 详情: {details}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:最终失败（非API错误）|final failure \(non-API error\))\.\s*(?:阶段:|Stage:)\s*([^,]+),\s*(?:详情:|Details:)\s*(.+)$/is', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 最终失败（非API错误）. 阶段: %2$s, 详情: %3$s', 'yali-ai-writer'), $matches[1], trim($matches[2]), trim($matches[3]));
    }

    // 匹配 "子任务 {id} 处理超时: {node}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理超时:|processing timeout:)\s*(.+)$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 处理超时: %2$s', 'yali-ai-writer'), $matches[1], trim($matches[2]));
    }

    // 匹配 "子任务处理异常: {message}"
    if (preg_match('/^子任务处理异常:\s*(.+)$/s', $error_message, $matches)) {
        return sprintf(__('子任务处理异常: %s', 'yali-ai-writer'), trim($matches[1]));
    }

    // 匹配固定错误消息
    $fixed_messages = array(
        '文章内容生成失败' => __('Article content generation failed', 'yali-ai-writer'),
        '创建WordPress文章失败' => __('Failed to create WordPress post', 'yali-ai-writer'),
        '任务不存在' => __('Task does not exist', 'yali-ai-writer'),
    );

    if (isset($fixed_messages[$error_message])) {
        return $fixed_messages[$error_message];
    }

    // 匹配状态消息 "所有 {count} 个子任务都完成"
    if (preg_match('/^所有\s+(\d+)\s+个子任务都完成$/', $error_message, $matches)) {
        return sprintf(__('All %d subtasks completed', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "所有 {count} 个子任务都失败"
    if (preg_match('/^所有\s+(\d+)\s+个子任务都失败$/', $error_message, $matches)) {
        return sprintf(__('All %d subtasks failed', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "{count} 个子任务完成，{count} 个子任务失败"
    if (preg_match('/^(\d+)\s+个子任务完成，(\d+)\s+个子任务失败$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) completed, %d subtask(s) failed', 'yali-ai-writer'), $matches[1], $matches[2]);
    }

    // 匹配状态消息 "有 {count} 个子任务正在处理"
    if (preg_match('/^有\s+(\d+)\s+个子任务正在处理$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) processing', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "有 {count} 个子任务待处理"
    if (preg_match('/^有\s+(\d+)\s+个子任务待处理$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) pending', 'yali-ai-writer'), $matches[1]);
    }

    // 如果都不匹配，返回原始消息
    return $error_message;
}
?>

<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-media-document"></span> <?php _e('文章任务管理', 'yali-ai-writer'); ?></h1>

    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('文章任务列表', 'yali-ai-writer'); ?></div>
            <div class="yali-card-actions">
                <button type="button" class="yali-btn yali-btn-secondary" onclick="location.reload();">
                    <span class="dashicons dashicons-update" style="line-height: 1;"></span> <?php _e('刷新', 'yali-ai-writer'); ?>
                </button>
            </div>
        </div>

        <?php if (empty($tasks)): ?>
            <div class="yali-notice yali-notice-info">
                <p><?php _e('暂无文章生成任务。', 'yali-ai-writer'); ?></p>
                <p class="description yali-desc"><?php printf(esc_html__('请前往 %s 页面选择主题并生成文章。', 'yali-ai-writer'), '<a href="' . esc_url(admin_url('admin.php?page=yali-ai-writer-topics')) . '">' . esc_html__('主题管理', 'yali-ai-writer') . '</a>'); ?></p>
            </div>
        <?php else: ?>
            <div class="yali-table-responsive">
                <table class="wp-list-table widefat fixed striped yali-table">
                    <thead>
                        <tr>
                            <th class="column-task-id"><?php _e('任务ID', 'yali-ai-writer'); ?></th>
                            <th class="column-task-name"><?php _e('任务名称', 'yali-ai-writer'); ?></th>
                            <th class="column-progress"><?php _e('进度统计', 'yali-ai-writer'); ?></th>
                            <th class="column-status"><?php _e('状态', 'yali-ai-writer'); ?></th>
                            <th class="column-time"><?php _e('时间信息', 'yali-ai-writer'); ?></th>
                            <th class="column-actions"><?php _e('操作', 'yali-ai-writer'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php foreach ($tasks as $task): 
                            $progress = $article_task_manager->get_task_progress($task['id']);
                            $total_processed = $task['completed_topics'] + $task['failed_topics'];
                            $progress_percentage = $task['total_topics'] > 0 ? round(($total_processed / $task['total_topics']) * 100, 1) : 0;
                            $success_rate = $total_processed > 0 ? round(($task['completed_topics'] / $total_processed) * 100, 1) : 0;
                        ?>
                            <tr class="task-row" data-task-id="<?php echo esc_attr($task['id']); ?>">
                                <td class="column-task-id" data-label="<?php esc_attr_e('任务ID', 'yali-ai-writer'); ?>">
                                    <strong><?php echo esc_html($task['article_task_id']); ?></strong>
                                    <div class="task-meta">
                                        <small>ID: <?php echo esc_html($task['id']); ?></small>
                                    </div>
                                </td>
                                <td class="column-task-name" data-label="<?php esc_attr_e('任务名称', 'yali-ai-writer'); ?>">
                                    <strong><?php
                                        // 解析中文格式的任务名称 "文章任务组 - 2026-02-11 14:45:50 (6个主题)"
                                        $task_name = $task['name'];
                                        if (preg_match('/^文章任务组\s+-\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+\((\d+)个主题\)$/', $task_name, $matches)) {
                                            $datetime = $matches[1];
                                            $count = intval($matches[2]);
                                            echo esc_html(sprintf(__('Article Task Group - %s (%d topics)', 'yali-ai-writer'), $datetime, $count));
                                        } else {
                                            echo esc_html(__($task_name, 'yali-ai-writer'));
                                        }
                                    ?></strong>
                                    <div class="task-meta">
                                        <small><?php printf(esc_html__('包含 %d 个主题', 'yali-ai-writer'), intval($task['total_topics'])); ?></small>
                                    </div>
                                </td>
                                <td class="column-progress" data-label="<?php esc_attr_e('进度统计', 'yali-ai-writer'); ?>">
                                    <div class="progress-container">
                                        <div class="progress-stats">
                                            <span class="progress-text"><?php echo esc_html(intval($total_processed)); ?>/<?php echo esc_html(intval($task['total_topics'])); ?> (<?php echo esc_html(intval($progress_percentage)); ?>%)</span>
                                            <div class="progress-details">
                                                <span class="success-count">✓ <?php echo esc_html(intval($task['completed_topics'])); ?></span>
                                                <?php if ($task['failed_topics'] > 0): ?>
                                                    <span class="failed-count">✗ <?php echo esc_html(intval($task['failed_topics'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="progress-bar-wrapper">
                                            <div class="progress-bar" style="width: <?php echo esc_attr(max(0, min(100, intval($progress_percentage)))); ?>%"></div>
                                        </div>
                                        <?php if ($success_rate > 0): ?>
                                            <div class="success-rate">
                                                <small><?php printf(esc_html__('成功率: %s%%', 'yali-ai-writer'), esc_html($success_rate)); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="column-status" data-label="<?php esc_attr_e('状态', 'yali-ai-writer'); ?>">
                                    <div class="status-wrapper" style="display: flex; align-items: center; gap: 6px;">
                                        <?php
                                        $status_class = 'yali-badge yali-badge-neutral';
                                        $status_label = $task['status'];
                                        
                                        switch ($task['status']) {
                                            case 'completed':
                                                $status_class = 'yali-badge yali-badge-success';
                                                $status_label = __('已完成', 'yali-ai-writer');
                                                break;
                                            case 'processing':
                                                $status_class = 'yali-badge yali-badge-warning';
                                                $status_label = __('进行中', 'yali-ai-writer');
                                                break;
                                            case 'running':
                                                $status_class = 'yali-badge yali-badge-warning';
                                                $status_label = __('运行中', 'yali-ai-writer');
                                                break;
                                            case 'error':
                                            case 'failed':
                                                $status_class = 'yali-badge yali-badge-error';
                                                $status_label = __('失败', 'yali-ai-writer');
                                                break;
                                            case 'pending':
                                                $status_class = 'yali-badge yali-badge-info';
                                                $status_label = __('等待中', 'yali-ai-writer');
                                                break;
                                        }
                                        ?>
                                        <span class="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                                        <?php if (!empty($task['error_message'])): ?>
                                            <div class="error-indicator" title="<?php echo esc_attr(yali_translate_task_error_message($task['error_message'])); ?>" style="display: inline-flex;">
                                                <span class="dashicons dashicons-warning" style="color: var(--yali-danger); font-size: 18px; width: 18px; height: 18px;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="column-time" data-label="<?php esc_attr_e('时间信息', 'yali-ai-writer'); ?>">
                                    <div class="time-info">
                                        <div class="created-time">
                                            <strong><?php _e('更新:', 'yali-ai-writer'); ?></strong>
                                            <span><?php echo yali_ai_writer_manager_format_time($task['updated_at']); ?></span>
                                        </div>
                                        <?php if ($task['last_processed_at']): ?>
                                            <div class="last-processed">
                                                <strong><?php _e('最后处理:', 'yali-ai-writer'); ?></strong>
                                                <span><?php echo yali_ai_writer_manager_format_time($task['last_processed_at']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="column-actions" data-label="<?php esc_attr_e('操作', 'yali-ai-writer'); ?>">
                                    <div class="task-actions">
                                        <button type="button" class="yali-btn-small view-details" 
                                                data-task-id="<?php echo esc_attr($task['id']); ?>" 
                                                data-article-task-id="<?php echo esc_attr($task['article_task_id']); ?>">
                                            <?php _e('查看详情', 'yali-ai-writer'); ?>
                                        </button>
                                        <?php if ($task['status'] === 'failed'): ?>
                                            <button type="button" class="yali-btn-small yali-btn-warning retry-task" 
                                                    data-task-id="<?php echo esc_attr($task['id']); ?>"
                                                    title="<?php esc_attr_e('重试', 'yali-ai-writer'); ?>">
                                                <?php _e('重试', 'yali-ai-writer'); ?>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="yali-btn-small yali-btn-danger" 
                                                data-yali-action="delete"
                                                data-yali-ajax-action="yali_ai_writer_delete_article_task"
                                                data-yali-id="<?php echo esc_attr($task['id']); ?>"
                                                data-yali-id-param="task_id"
                                                data-yali-confirm="<?php echo esc_attr(__('确定要删除此任务吗？此操作不可撤销。', 'yali-ai-writer')); ?>"
                                                title="<?php esc_attr_e('删除', 'yali-ai-writer'); ?>">
                                            <?php _e('删除', 'yali-ai-writer'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 任务详情弹窗 -->
<div id="task-details-modal" class="yali-modal-overlay">
    <div class="yali-modal large">
        <div class="yali-modal-header">
            <h3><?php _e('任务详情', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close action-close-modal">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="yali-modal-body">
            <div id="task-details-content">
                <div class="loading-state">
                    <span class="spinner is-active"></span>
                    <p><?php _e('加载中...', 'yali-ai-writer'); ?></p>
                </div>
            </div>
        </div>
        <div class="yali-modal-footer">
            <button type="button" class="yali-btn yali-btn-secondary action-close-modal"><?php _e('关闭', 'yali-ai-writer'); ?></button>
        </div>
    </div>
</div>

<!-- 重试确认弹窗 -->
<div id="retry-confirm-modal" class="yali-modal-overlay">
    <div class="yali-modal small">
        <div class="yali-modal-header">
            <h3><?php _e('确认重试', 'yali-ai-writer'); ?></h3>
            <button type="button" class="yali-modal-close action-close-modal">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="yali-modal-body">
            <p><?php _e('确定要重试此任务吗？', 'yali-ai-writer'); ?></p>
            <p class="description"><?php _e('重试将重置失败的子任务状态为待处理，成功的子任务保持不变。', 'yali-ai-writer'); ?></p>
        </div>
        <div class="yali-modal-footer">
            <button type="button" class="yali-btn yali-btn-secondary cancel-retry"><?php _e('取消', 'yali-ai-writer'); ?></button>
            <button type="button" class="yali-btn yali-btn-primary confirm-retry"><?php _e('确认重试', 'yali-ai-writer'); ?></button>
        </div>
    </div>
</div>




</body>
</html>
