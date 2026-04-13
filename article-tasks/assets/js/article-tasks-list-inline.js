/**
 * Article Tasks List Inline Scripts
 * Extracted from article-tasks-list.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    $(function() {
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
                url: window.articleTasksData?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_get_article_task_details',
                    task_id: taskId,
                    nonce: window.articleTasksData?.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        $('#task-details-content').html(response.data.html);
                    } else {
                        showTaskDetailsError(wp.i18n.__('加载失败: ', 'yali-ai-writer') + response.data.message);
                    }
                },
                error: function() {
                    showTaskDetailsError(wp.i18n.__('加载失败: ', 'yali-ai-writer') + wp.i18n.__('服务器错误', 'yali-ai-writer'));
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
            $button.prop('disabled', true).text(wp.i18n.__('重试中...', 'yali-ai-writer'));

            // 发送重试请求
            $.ajax({
                url: window.articleTasksData?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_retry_article_task',
                    task_id: currentRetryTaskId,
                    nonce: window.articleTasksData?.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        // 显示成功消息
                        showNotice('success', response.data.message || wp.i18n.__('任务重试成功', 'yali-ai-writer'));

                        // 刷新页面或更新任务状态
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotice('error', response.data.message || wp.i18n.__('重试失败', 'yali-ai-writer'));
                    }
                },
                error: function() {
                    showNotice('error', wp.i18n.__('重试失败', 'yali-ai-writer') + ': ' + wp.i18n.__('服务器错误', 'yali-ai-writer'));
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
                '<p>' + wp.i18n.__('加载中...', 'yali-ai-writer') + '</p>' +
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
                        url: window.articleTasksData?.ajaxUrl || ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'yali_ai_writer_get_task_progress',
                            task_id: taskId,
                            nonce: window.articleTasksData?.nonce || ''
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
                                'title="' + wp.i18n.__('重试', 'yali-ai-writer') + '">' +
                                wp.i18n.__('重试', 'yali-ai-writer') + '</button>'
                            );
                        }
                    }
                }
            }
        }

        // 获取状态标签
        function getStatusLabel(status) {
            var labels = {
                'pending': wp.i18n.__('待处理', 'yali-ai-writer'),
                'processing': wp.i18n.__('处理中', 'yali-ai-writer'),
                'completed': wp.i18n.__('已完成', 'yali-ai-writer'),
                'failed': wp.i18n.__('失败', 'yali-ai-writer'),
                'paused': wp.i18n.__('已暂停', 'yali-ai-writer')
            };
            return labels[status] || status;
        }

        // 启动定期刷新（每30秒）
        setInterval(refreshProcessingTasks, 30000);

        // 页面加载完成后立即刷新一次
        setTimeout(refreshProcessingTasks, 2000);
    });

})(jQuery);
