<?php
/**
 * 重构后的文章任务管理页面
 * 支持新的表结构和子任务管理
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

$article_task_manager = new ContentAuto_ArticleTaskManager();
$tasks = $article_task_manager->get_tasks();

function get_article_task_status_label($status) {
    switch ($status) {
        case 'pending': return __('待处理', 'content-auto-manager');
        case 'processing': return __('处理中', 'content-auto-manager');
        case 'completed': return __('已完成', 'content-auto-manager');
        case 'failed': return __('失败', 'content-auto-manager');
        case 'paused': return __('已暂停', 'content-auto-manager');
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
?>

<div class="wrap">
    <h1><?php _e('文章任务管理', 'content-auto-manager'); ?></h1>

    <div class="content-auto-section">
        <div class="section-header">
            <h2><?php _e('文章任务列表', 'content-auto-manager'); ?></h2>
            <div class="section-actions">
                <button type="button" class="button" onclick="location.reload();"><?php _e('刷新', 'content-auto-manager'); ?></button>
            </div>
        </div>

        <?php if (empty($tasks)): ?>
            <div class="empty-state">
                <p><?php _e('暂无文章生成任务。', 'content-auto-manager'); ?></p>
                <p class="description"><?php _e('文章任务将在主题生成后自动创建。', 'content-auto-manager'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped article-tasks-table">
                <thead>
                    <tr>
                        <th class="column-task-id"><?php _e('任务ID', 'content-auto-manager'); ?></th>
                        <th class="column-task-name"><?php _e('任务名称', 'content-auto-manager'); ?></th>
                        <th class="column-progress"><?php _e('进度统计', 'content-auto-manager'); ?></th>
                        <th class="column-status"><?php _e('状态', 'content-auto-manager'); ?></th>
                        <th class="column-time"><?php _e('时间信息', 'content-auto-manager'); ?></th>
                        <th class="column-actions"><?php _e('操作', 'content-auto-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): 
                        $progress = $article_task_manager->get_task_progress($task['id']);
                        $total_processed = $task['completed_topics'] + $task['failed_topics'];
                        $progress_percentage = $task['total_topics'] > 0 ? round(($total_processed / $task['total_topics']) * 100, 1) : 0;
                        $success_rate = $total_processed > 0 ? round(($task['completed_topics'] / $total_processed) * 100, 1) : 0;
                    ?>
                        <tr class="task-row" data-task-id="<?php echo esc_attr($task['id']); ?>">
                            <td class="column-task-id" data-label="<?php _e('任务ID', 'content-auto-manager'); ?>">
                                <strong><?php echo esc_html($task['article_task_id']); ?></strong>
                                <div class="task-meta">
                                    <small>ID: <?php echo esc_html($task['id']); ?></small>
                                </div>
                            </td>
                            <td class="column-task-name" data-label="<?php _e('任务名称', 'content-auto-manager'); ?>">
                                <strong><?php echo esc_html($task['name']); ?></strong>
                                <div class="task-meta">
                                    <small><?php printf(__('包含 %d 个主题', 'content-auto-manager'), $task['total_topics']); ?></small>
                                </div>
                            </td>
                            <td class="column-progress" data-label="<?php _e('进度统计', 'content-auto-manager'); ?>">
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
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $progress_percentage; ?>%"></div>
                                    </div>
                                    <?php if ($success_rate > 0): ?>
                                        <div class="success-rate">
                                            <small><?php printf(__('成功率: %s%%', 'content-auto-manager'), $success_rate); ?></small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="column-status" data-label="<?php _e('状态', 'content-auto-manager'); ?>">
                                <span class="task-status <?php echo get_article_task_status_class($task['status']); ?>">
                                    <?php echo get_article_task_status_label($task['status']); ?>
                                </span>
                                <?php if (!empty($task['error_message'])): ?>
                                    <div class="error-indicator" title="<?php echo esc_attr($task['error_message']); ?>">
                                        <span class="dashicons dashicons-warning"></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="column-time" data-label="<?php _e('时间信息', 'content-auto-manager'); ?>">
                                <div class="time-info">
                                    <div class="created-time">
                                        <strong><?php _e('更新:', 'content-auto-manager'); ?></strong>
                                        <span><?php echo content_auto_manager_format_time($task['updated_at']); ?></span>
                                    </div>
                                    <?php if ($task['last_processed_at']): ?>
                                        <div class="last-processed">
                                            <strong><?php _e('最后处理:', 'content-auto-manager'); ?></strong>
                                            <span><?php echo content_auto_manager_format_time($task['last_processed_at']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="column-actions" data-label="<?php _e('操作', 'content-auto-manager'); ?>">
                                <div class="task-actions">
                                    <button type="button" class="button button-small view-details" 
                                            data-task-id="<?php echo esc_attr($task['id']); ?>" 
                                            data-article-task-id="<?php echo esc_attr($task['article_task_id']); ?>">
                                        <?php _e('查看详情', 'content-auto-manager'); ?>
                                    </button>
                                    <?php if ($task['status'] === 'failed'): ?>
                                        <button type="button" class="button button-small retry-task" 
                                                data-task-id="<?php echo esc_attr($task['id']); ?>"
                                                title="<?php _e('重试失败的子任务', 'content-auto-manager'); ?>">
                                            <?php _e('重试', 'content-auto-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- 任务详情弹窗 -->
<div id="task-details-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2><?php _e('任务详情', 'content-auto-manager'); ?></h2>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <div id="task-details-content">
                <div class="loading-state">
                    <span class="spinner is-active"></span>
                    <p><?php _e('加载中...', 'content-auto-manager'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 重试确认弹窗 -->
<div id="retry-confirm-modal" class="modal" style="display: none;">
    <div class="modal-content small">
        <div class="modal-header">
            <h3><?php _e('确认重试', 'content-auto-manager'); ?></h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <p><?php _e('确定要重试此任务吗？', 'content-auto-manager'); ?></p>
            <p class="description"><?php _e('重试将重置失败的子任务状态为待处理，成功的子任务保持不变。', 'content-auto-manager'); ?></p>
            <div class="modal-actions">
                <button type="button" class="button button-primary confirm-retry"><?php _e('确认重试', 'content-auto-manager'); ?></button>
                <button type="button" class="button cancel-retry"><?php _e('取消', 'content-auto-manager'); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
/* 页面布局样式 */
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

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #ddd;
}

.section-actions {
    display: flex;
    gap: 10px;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    background: #f9f9f9;
    border-radius: 5px;
}

.empty-state .description {
    color: #666;
    margin-top: 10px;
}

/* 表格样式增强 */
.article-tasks-table {
    margin-top: 0;
}

.article-tasks-table th {
    background: #f1f1f1;
    font-weight: 600;
}

.column-task-id { width: 15%; }
.column-task-name { width: 25%; }
.column-progress { width: 20%; }
.column-status { width: 12%; }
.column-time { width: 18%; }
.column-actions { width: 10%; }

/* 任务行样式 */
.task-row:hover {
    background-color: #f8f9fa;
}

.task-meta {
    margin-top: 4px;
}

.task-meta small {
    color: #666;
    font-size: 12px;
}

/* 进度条样式 */
.progress-container {
    min-width: 150px;
}

.progress-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}

.progress-text {
    font-weight: 600;
    font-size: 13px;
}

.progress-details {
    display: flex;
    gap: 8px;
    font-size: 12px;
}

.success-count {
    color: #00a32a;
    font-weight: 600;
}

.failed-count {
    color: #dc3232;
    font-weight: 600;
}

.progress-bar {
    width: 100%;
    height: 8px;
    background-color: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 3px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #00a32a 0%, #46b450 100%);
    transition: width 0.3s ease;
}

.success-rate {
    text-align: right;
}

.success-rate small {
    color: #666;
    font-size: 11px;
}

/* 状态样式 */
.task-status {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-pending {
    background: #e3f2fd;
    color: #1976d2;
}

.status-processing {
    background: #fff3e0;
    color: #f57c00;
}

.status-completed {
    background: #e8f5e8;
    color: #2e7d32;
}

.status-failed {
    background: #ffebee;
    color: #c62828;
}

.status-paused {
    background: #f3e5f5;
    color: #7b1fa2;
}

.error-indicator {
    margin-top: 4px;
}

.error-indicator .dashicons {
    color: #dc3232;
    font-size: 16px;
    cursor: help;
}

/* 时间信息样式 */
.time-info {
    font-size: 12px;
}

.time-info > div {
    margin-bottom: 3px;
}

.time-info strong {
    display: inline-block;
    width: 50px;
    font-size: 11px;
    color: #666;
}

/* 操作按钮样式 */
.task-actions {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.task-actions .button {
    font-size: 11px;
    padding: 4px 8px;
    height: auto;
    line-height: 1.2;
}

.retry-task {
    background: #ff9800;
    border-color: #f57c00;
    color: white;
}

.retry-task:hover {
    background: #f57c00;
    border-color: #ef6c00;
}

/* 弹窗样式 */
.modal {
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
}

.modal-content {
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-content.small {
    max-width: 500px;
}

.modal-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
}

.modal-header h2,
.modal-header h3 {
    margin: 0;
    font-size: 18px;
    color: #333;
}

.modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1;
}

.close {
    color: #666;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    padding: 4px;
    line-height: 1;
    border: none;
    background: none;
}

.close:hover {
    color: #333;
}

.loading-state {
    text-align: center;
    padding: 40px 20px;
}

.loading-state .spinner {
    margin-bottom: 16px;
}

/* 详情表格样式 */
.task-details-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.task-details-table th,
.task-details-table td {
    border: 1px solid #ddd;
    padding: 12px 8px;
    text-align: left;
    vertical-align: top;
}

.task-details-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    width: 30%;
}

.subtasks-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.subtasks-table th,
.subtasks-table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
    font-size: 13px;
}

.subtasks-table th {
    background-color: #f1f1f1;
    font-weight: 600;
}

.subtask-status {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 2px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.subtask-status.completed {
    background: #e8f5e8;
    color: #2e7d32;
}

.subtask-status.failed {
    background: #ffebee;
    color: #c62828;
}

.subtask-status.pending {
    background: #e3f2fd;
    color: #1976d2;
}

.subtask-status.processing {
    background: #fff3e0;
    color: #f57c00;
}

/* 确认弹窗样式 */
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #e0e0e0;
}

/* 响应式设计 */
@media screen and (max-width: 768px) {
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .article-tasks-table,
    .article-tasks-table thead,
    .article-tasks-table tbody,
    .article-tasks-table th,
    .article-tasks-table td,
    .article-tasks-table tr {
        display: block;
    }
    
    .article-tasks-table thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
    }
    
    .article-tasks-table tr {
        border: 1px solid #ccc;
        margin-bottom: 10px;
        padding: 10px;
        border-radius: 5px;
        background: #fff;
    }
    
    .article-tasks-table td {
        border: none;
        position: relative;
        padding-left: 50% !important;
        padding-top: 8px;
        padding-bottom: 8px;
    }
    
    .article-tasks-table td:before {
        content: attr(data-label) ": ";
        position: absolute;
        left: 6px;
        width: 45%;
        padding-right: 10px;
        white-space: nowrap;
        font-weight: 600;
        color: #333;
    }
    
    .modal-content {
        width: 95%;
        margin: 10px;
    }
    
    .modal-header {
        padding: 16px 20px 12px;
    }
    
    .modal-body {
        padding: 16px 20px;
    }
}

@media screen and (max-width: 480px) {
    .task-actions {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .task-actions .button {
        flex: 1;
        min-width: 60px;
    }
}
</style>


<script>
jQuery(document).ready(function($) {
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
                    showTaskDetailsError('<?php _e('加载失败: ', 'content-auto-manager'); ?>' + response.data.message);
                }
            },
            error: function() {
                showTaskDetailsError('<?php _e('加载失败: 服务器错误', 'content-auto-manager'); ?>');
            }
        });
    });
    
    // 重试任务按钮点击事件
    $(document).on('click', '.retry-task', function(e) {
        e.preventDefault();
        
        currentRetryTaskId = $(this).data('task-id');
        $('#retry-confirm-modal').show();
    });
    
    // 确认重试
    $(document).on('click', '.confirm-retry', function(e) {
        e.preventDefault();
        
        if (!currentRetryTaskId) return;
        
        var $button = $(this);
        var originalText = $button.text();
        
        // 显示加载状态
        $button.prop('disabled', true).text('<?php _e('重试中...', 'content-auto-manager'); ?>');
        
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
                    showNotice('success', response.data.message || '<?php _e('任务重试成功', 'content-auto-manager'); ?>');
                    
                    // 刷新页面或更新任务状态
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice('error', response.data.message || '<?php _e('重试失败', 'content-auto-manager'); ?>');
                }
            },
            error: function() {
                showNotice('error', '<?php _e('重试失败: 服务器错误', 'content-auto-manager'); ?>');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
                $('#retry-confirm-modal').hide();
                currentRetryTaskId = null;
            }
        });
    });
    
    // 取消重试
    $(document).on('click', '.cancel-retry', function(e) {
        e.preventDefault();
        $('#retry-confirm-modal').hide();
        currentRetryTaskId = null;
    });
    
    // 关闭弹窗事件
    $(document).on('click', '.close', function(e) {
        e.preventDefault();
        $(this).closest('.modal').hide();
    });
    
    // 点击弹窗背景关闭
    $(document).on('click', '.modal', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // ESC键关闭弹窗
    $(document).on('keyup', function(e) {
        if (e.key === "Escape") {
            $('.modal:visible').hide();
        }
    });
    
    // 工具函数：显示任务详情弹窗
    function showTaskDetailsModal() {
        $('#task-details-content').html(
            '<div class="loading-state">' +
            '<span class="spinner is-active"></span>' +
            '<p><?php _e('加载中...', 'content-auto-manager'); ?></p>' +
            '</div>'
        );
        $('#task-details-modal').show();
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
                            'title="<?php _e('重试失败的子任务', 'content-auto-manager'); ?>">' +
                            '<?php _e('重试', 'content-auto-manager'); ?></button>'
                        );
                    }
                }
            }
        }
    }
    
    // 获取状态标签
    function getStatusLabel(status) {
        var labels = {
            'pending': '<?php _e('待处理', 'content-auto-manager'); ?>',
            'processing': '<?php _e('处理中', 'content-auto-manager'); ?>',
            'completed': '<?php _e('已完成', 'content-auto-manager'); ?>',
            'failed': '<?php _e('失败', 'content-auto-manager'); ?>',
            'paused': '<?php _e('已暂停', 'content-auto-manager'); ?>'
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
