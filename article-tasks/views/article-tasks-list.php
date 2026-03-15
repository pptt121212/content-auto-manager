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

$article_task_manager = new ContentAuto_ArticleTaskManager();
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
                <p class="description yali-desc"><?php printf(__('请前往 %s 页面选择主题并生成文章。', 'yali-ai-writer'), '<a href="' . admin_url('admin.php?page=yali-ai-writer-topics') . '">' . __('主题管理', 'yali-ai-writer') . '</a>'); ?></p>
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
                                        <small><?php printf(__('包含 %d 个主题', 'yali-ai-writer'), $task['total_topics']); ?></small>
                                    </div>
                                </td>
                                <td class="column-progress" data-label="<?php esc_attr_e('进度统计', 'yali-ai-writer'); ?>">
                                    <div class="progress-container">
                                        <div class="progress-stats">
                                            <span class="progress-text"><?php echo $total_processed; ?>/<?php echo $task['total_topics']; ?> (<?php echo $progress_percentage; ?>%)</span>
                                            <div class="progress-details">
                                                <span class="success-count">✓ <?php echo $task['completed_topics']; ?></span>
                                                <?php if ($task['failed_topics'] > 0): ?>
                                                    <span class="failed-count">✗ <?php echo $task['failed_topics']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="progress-bar-wrapper">
                                            <div class="progress-bar" style="width: <?php echo $progress_percentage; ?>%"></div>
                                        </div>
                                        <?php if ($success_rate > 0): ?>
                                            <div class="success-rate">
                                                <small><?php printf(__('成功率: %s%%', 'yali-ai-writer'), $success_rate); ?></small>
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
                                            <span><?php echo content_auto_manager_format_time($task['updated_at']); ?></span>
                                        </div>
                                        <?php if ($task['last_processed_at']): ?>
                                            <div class="last-processed">
                                                <strong><?php _e('最后处理:', 'yali-ai-writer'); ?></strong>
                                                <span><?php echo content_auto_manager_format_time($task['last_processed_at']); ?></span>
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
                                                data-yali-ajax-action="content_auto_delete_article_task"
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



<script>
jQuery(document).ready(function($) {
    // 注入 i18n
    window.camArticleTasksI18n = {
        loading: '<?php _e('加载中...', 'yali-ai-writer'); ?>',
        loadFailed: '<?php _e('加载失败: ', 'yali-ai-writer'); ?>',
        serverError: '<?php _e('服务器错误', 'yali-ai-writer'); ?>',
        retrying: '<?php _e('重试中...', 'yali-ai-writer'); ?>',
        retrySuccess: '<?php _e('任务重试成功', 'yali-ai-writer'); ?>',
        retryFailed: '<?php _e('重试失败', 'yali-ai-writer'); ?>',
        pending: '<?php _e('待处理', 'yali-ai-writer'); ?>',
        processing: '<?php _e('处理中', 'yali-ai-writer'); ?>',
        completed: '<?php _e('已完成', 'yali-ai-writer'); ?>',
        failed: '<?php _e('失败', 'yali-ai-writer'); ?>',
        paused: '<?php _e('已暂停', 'yali-ai-writer'); ?>',
        retryLabel: '<?php _e('重试', 'yali-ai-writer'); ?>'
    };

    var currentRetryTaskId = null;
    
    // 查看详情按钮点击事件
    $(document).on('click', '.view-details', function(e) {
        e.preventDefault();
        
        var taskId = $(this).data('task-id');
        var articleTaskId = $(this).data('article-task-id');
        
        // 显示加载状态
        showTaskDetailsModal();
        
        // 通过AJAX获取任务详情
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_get_article_task_details',
                task_id: taskId,
                nonce: '<?php echo wp_create_nonce("content_auto_manager_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('#task-details-content').html(response.data.html);
                } else {
                    showTaskDetailsError(camArticleTasksI18n.loadFailed + response.data.message);
                }
            },
            error: function() {
                showTaskDetailsError(camArticleTasksI18n.loadFailed + camArticleTasksI18n.serverError);
            }
        });
    });
    
    // 重试任务按钮点击事件
    $(document).on('click', '.retry-task', function(e) {
        e.preventDefault();
        
        currentRetryTaskId = $(this).data('task-id');
        $('#retry-confirm-modal').addClass('active');
    });
    
    // 确认重试
    $(document).on('click', '.confirm-retry', function(e) {
        e.preventDefault();
        
        if (!currentRetryTaskId) return;
        
        var $button = $(this);
        var originalText = $button.text();
        
        // 显示加载状态
        $button.prop('disabled', true).text(camArticleTasksI18n.retrying);
        
        // 发送重试请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_retry_article_task',
                task_id: currentRetryTaskId,
                nonce: '<?php echo wp_create_nonce("content_auto_manager_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // 显示成功消息
                    showNotice('success', response.data.message || camArticleTasksI18n.retrySuccess);
                    
                    // 刷新页面或更新任务状态
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice('error', response.data.message || camArticleTasksI18n.retryFailed);
                }
            },
            error: function() {
                showNotice('error', camArticleTasksI18n.retryFailed + ': ' + camArticleTasksI18n.serverError);
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
                $('#retry-confirm-modal').removeClass('active');
                currentRetryTaskId = null;
            }
        });
    });
    
    // 取消重试
    $(document).on('click', '.cancel-retry', function(e) {
        e.preventDefault();
        $('#retry-confirm-modal').removeClass('active');
        currentRetryTaskId = null;
    });
    
    // 关闭弹窗事件 - 使用 action-close-modal 类
    $(document).on('click', '.action-close-modal', function(e) {
        e.preventDefault();
        $(this).closest('.yali-modal-overlay').removeClass('active');
    });
    
    // 点击弹窗背景关闭
    $(document).on('click', '.yali-modal-overlay', function(e) {
        if (e.target === this) {
            $(this).removeClass('active');
        }
    });
    
    // ESC键关闭弹窗
    $(document).on('keyup', function(e) {
        if (e.key === "Escape") {
            $('.yali-modal-overlay.active').removeClass('active');
        }
    });
    
    // 工具函数：显示任务详情弹窗
    function showTaskDetailsModal() {
        $('#task-details-content').html(
            '<div class="loading-state">' +
            '<span class="spinner is-active"></span>' +
            '<p>' + camArticleTasksI18n.loading + '</p>' +
            '</div>'
        );
        $('#task-details-modal').addClass('active');
    }
    
    // 工具函数：显示任务详情错误
    function showTaskDetailsError(message) {
        $('#task-details-content').html(
            '<div class="error-state">' +
            '<p class="error">' + message + '</p>' +
            '</div>'
        );
    }
    
    // 工具函数：显示通知
    function showNotice(type, message) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        
        // 插入到页面顶部
        $('.wrap h1').after($notice);
        
        // 自动消失
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // 添加关闭按钮功能
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    // 定期刷新处理中的任务状态
    function refreshProcessingTasks() {
        $('.task-row').each(function() {
            var $row = $(this);
            var $status = $row.find('.task-status');
            
            if ($status.hasClass('status-processing')) {
                var taskId = $row.data('task-id');
                
                // 获取任务进度
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'content_auto_get_task_progress',
                        task_id: taskId,
                        nonce: '<?php echo wp_create_nonce("content_auto_manager_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            updateTaskRowDisplay($row, response.data);
                        }
                    }
                });
            }
        });
    }
    
    // 更新任务行显示
    function updateTaskRowDisplay($row, taskData) {
        // 更新进度
        var $progressFill = $row.find('.progress-fill');
        var $progressText = $row.find('.progress-text');
        var $successCount = $row.find('.success-count');
        var $failedCount = $row.find('.failed-count');
        
        if (taskData.progress_percentage !== undefined) {
            $progressFill.css('width', taskData.progress_percentage + '%');
        }
        
        if (taskData.current_item !== undefined && taskData.total_items !== undefined) {
            $progressText.text(taskData.current_item + '/' + taskData.total_items + ' (' + (taskData.progress_percentage || 0) + '%)');
        }
        
        if (taskData.completed_topics !== undefined) {
            $successCount.text('✓ ' + taskData.completed_topics);
        }
        
        if (taskData.failed_topics !== undefined && taskData.failed_topics > 0) {
            $failedCount.text('✗ ' + taskData.failed_topics).show();
        }
        
        // 更新状态
        if (taskData.status) {
            var $status = $row.find('.task-status');
            $status.removeClass('status-pending status-processing status-completed status-failed status-paused');
            $status.addClass('status-' + taskData.status);
            $status.text(getStatusLabel(taskData.status));
            
            // 如果任务完成，停止刷新并可能显示重试按钮
            if (taskData.status === 'completed' || taskData.status === 'failed') {
                if (taskData.status === 'failed') {
                    // 添加重试按钮（如果还没有）
                    var $actions = $row.find('.task-actions');
                    if ($actions.find('.retry-task').length === 0) {
                        $actions.append(
                            '<button type="button" class="button button-small retry-task" ' +
                            'data-task-id="' + $row.data('task-id') + '" ' +
                            'title="' + camArticleTasksI18n.retryLabel + '">' +
                            camArticleTasksI18n.retryLabel + '</button>'
                        );
                    }
                }
            }
        }
    }
    
    // 获取状态标签
    function getStatusLabel(status) {
        var labels = {
            'pending': camArticleTasksI18n.pending,
            'processing': camArticleTasksI18n.processing,
            'completed': camArticleTasksI18n.completed,
            'failed': camArticleTasksI18n.failed,
            'paused': camArticleTasksI18n.paused
        };
        return labels[status] || status;
    }
    
    // 启动定期刷新（每30秒）
    setInterval(refreshProcessingTasks, 30000);
    
    // 页面加载完成后立即刷新一次
    setTimeout(refreshProcessingTasks, 2000);
});
</script>

</body>
</html>
