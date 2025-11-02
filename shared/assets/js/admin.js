/**
 * 内容自动生成管家插件JavaScript
 */

jQuery(document).ready(function($) {
    // 全选/取消全选功能
    $('#select-all-topics').on('change', function() {
        $('.topic-checkbox').prop('checked', $(this).prop('checked'));
    });
    

    
    // 表单验证
    $('form').on('submit', function() {
        var requiredFields = $(this).find('[required]');
        var isValid = true;
        
        requiredFields.each(function() {
            if (!$(this).val()) {
                $(this).addClass('error');
                isValid = false;
            } else {
                $(this).removeClass('error');
            }
        });
        
        if (!isValid) {
            alert('请填写所有必填字段。');
            return false;
        }
        
        return true;
    });
    

    
    // AJAX测试API连接
    $('.test-api-connection').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var configId = button.data('config-id');
        var originalText = button.text();
        
        // 显示加载状态
        button.prop('disabled', true).text('测试中...');
        
        // 发送AJAX请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_test_api_connection',
                config_id: configId,
                nonce: contentAutoManager.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('连接测试成功: ' + response.data.message);
                } else {
                    alert('连接测试失败: ' + response.data.message);
                }
            },
            error: function() {
                alert('连接测试失败: 服务器错误');
            },
            complete: function() {
                // 恢复按钮状态
                button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    // 动态显示/隐藏表单字段
    $('select[name="post_types"]').on('change', function() {
        var selectedType = $(this).val();
        if (selectedType === 'post') {
            $('.post-specific-fields').show();
        } else {
            $('.post-specific-fields').hide();
        }
    });
    
    // 进度条动画
    $('.progress-bar').each(function() {
        var progress = $(this).data('progress');
        $(this).animate({ width: progress + '%' }, 1000);
    });
    

    
    // --- [START] Centralized Task Action Handler ---
    // This single, delegated handler replaces the individual handlers for pause, resume, cancel, delete, and retry.
    const taskControlsHandler = (function() {
        // Use the globally available contentAutoManager object
        const nonce = window.contentAutoManager.nonce;
        const ajaxurl = window.contentAutoManager.ajaxurl;

        function performAction(action, taskId, button) {
            const originalText = button.text();
            let loadingText = '';
            let confirmMsg = '';
            // Reload the page on success to ensure the UI is always in a consistent state.
            const reloadOnSuccess = true; 

            switch (action) {
                case 'content_auto_pause_task':
                    loadingText = '暂停中...';
                    confirmMsg = '确定要暂停此任务吗？';
                    break;
                case 'content_auto_resume_task':
                    loadingText = '恢复中...';
                    confirmMsg = '确定要恢复此任务吗？';
                    break;
                case 'content_auto_retry_task':
                    loadingText = '重试中...';
                    confirmMsg = '确定要重试此任务吗？系统将只重试该任务下所有失败的子任务。';
                    break;
                case 'content_auto_delete_task':
                    loadingText = '删除中...';
                    confirmMsg = '确定要删除此任务吗？注意：任务记录将被删除，但已生成的主题数据仍会保留。';
                    break;
                case 'content_auto_cancel_task':
                    loadingText = '取消中...';
                    confirmMsg = '确定要取消此任务吗？此操作不可撤销。';
                    break;
                default:
                    // If the button doesn't have a recognized action class, do nothing.
                    return;
            }

            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }

            button.prop('disabled', true).text(loadingText);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: action,
                    task_id: taskId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        if (reloadOnSuccess) {
                            // Reloading is a simple and robust way to reflect state changes.
                            location.reload();
                        }
                    } else {
                        alert('操作失败: ' + (response.data.message || '未知错误'));
                        // Restore button on failure
                        button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr) {
                    alert('操作失败: 服务器错误。 ' + xhr.status + ' ' + xhr.statusText);
                    console.error('AJAX Error:', xhr);
                    // Restore button on failure
                    button.prop('disabled', false).text(originalText);
                }
            });
        }

        function bindEvents() {
            // Use a unique namespace (.taskControls) for robust event management.
            // This prevents conflicts with any other scripts.
            $(document).off('click.taskControls').on('click.taskControls', '.task-controls .button', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const button = $(this);
                const taskId = button.closest('.task-controls').data('task-id');
                let action = '';

                if (button.hasClass('pause-task')) action = 'content_auto_pause_task';
                else if (button.hasClass('resume-task')) action = 'content_auto_resume_task';
                else if (button.hasClass('retry-task')) action = 'content_auto_retry_task';
                else if (button.hasClass('delete-task')) action = 'content_auto_delete_task';
                else if (button.hasClass('cancel-task')) action = 'content_auto_cancel_task';

                if (action && taskId) {
                    performAction(action, taskId, button);
                }
            });
        }

        return {
            init: bindEvents
        };
    })();

    // Initialize the new centralized handler.
    taskControlsHandler.init();
    // --- [END] Centralized Task Action Handler ---
    
    // 初始化任务状态监听
    function initTaskMonitoring() {
        $('.task-row').each(function() {
            var taskId = $(this).data('task-id');
            if (taskId) {
                var status = $(this).find('.task-status').data('status');
                if (status === 'pending' || status === 'running' || status === 'processing' || status === 'paused') {
                    startTaskPolling(taskId);
                }
            }
        });
    }
    
    // 页面加载完成后启动任务监听
    initTaskMonitoring();
    
    // 页面卸载时清理轮询
    $(window).on('beforeunload', function() {
        $.each(taskPollingIntervals, function(taskId, interval) {
            clearInterval(interval);
        });
    });
});
