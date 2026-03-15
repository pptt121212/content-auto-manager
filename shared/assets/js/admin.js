/**
 * 内容自动生成管家插件JavaScript
 */

// Toast Notification System - Global (Defined outside ready to ensure availability)
window.yaliToast = function (message, type = 'success') {
    console.log('🔔 yaliToast called:', message, type);
    var $ = jQuery;
    // Remove existing toast
    $('.yali-toast').remove();

    var icon = type === 'success' ? 'dashicons-yes' : 'dashicons-warning';
    var toastHtml = `
        <div class="yali-toast yali-toast-${type}">
            <div class="yali-toast-icon"><span class="dashicons ${icon}"></span></div>
            <div class="yali-toast-message">${message}</div>
        </div>
    `;

    $('body').append(toastHtml);

    // Trigger reflow
    var toast = $('.yali-toast');
    toast.offset();
    toast.addClass('show');

    // Auto hide
    setTimeout(function () {
        toast.removeClass('show');
        setTimeout(function () {
            toast.remove();
        }, 300);
    }, 3000);
};

jQuery(document).ready(function ($) {
    // 全选/取消全选功能
    $('#select-all-topics').on('change', function () {
        $('.topic-checkbox').prop('checked', $(this).prop('checked'));
    });



    // 表单验证
    $('form').on('submit', function () {
        var requiredFields = $(this).find('[required]');
        var isValid = true;

        requiredFields.each(function () {
            if (!$(this).val()) {
                $(this).addClass('error');
                isValid = false;
            } else {
                $(this).removeClass('error');
            }
        });

        if (!isValid) {
            alert(wp.i18n.__('请填写所有必填字段。', 'yali-ai-writer'));
            return false;
        }

        return true;
    });

    // 动态显示/隐藏表单字段
    $('select[name="post_types"]').on('change', function () {
        var selectedType = $(this).val();
        if (selectedType === 'post') {
            $('.post-specific-fields').show();
        } else {
            $('.post-specific-fields').hide();
        }
    });

    // 进度条动画
    $('.progress-bar').each(function () {
        var progress = $(this).data('progress');
        $(this).animate({ width: progress + '%' }, 1000);
    });



    // --- Universal AJAX Form Handler ---
    $(document).on('submit', '.yali-ajax-form', function (e) {
        e.preventDefault();

        const form = $(this);
        const submitBtn = form.find('input[type="submit"], button[type="submit"]');
        const originalText = submitBtn.val() || submitBtn.text();
        const action = form.data('action');
        const nonce = form.data('nonce');

        if (!action || !nonce) {
            console.error('Missing data-action or data-nonce attribute on .yali-ajax-form');
            return;
        }

        // UI State: Saving...
        // Handle both input[type=submit] and button elements
        submitBtn.prop('disabled', true).css('opacity', '0.7');

        // Collect data
        const formData = form.serialize();
        const dataToSend = formData + '&action=' + action + '&nonce=' + nonce;

        $.ajax({
            url: contentAutoManager.ajaxurl,
            type: 'POST',
            data: dataToSend,
            success: function (response) {
                if (response.success) {
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message || wp.i18n.__('设置已保存', 'yali-ai-writer'), 'success');
                    }
                    // Trigger custom event for page-specific handling
                    form.trigger('yali:ajax-success', [response]);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer');
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(wp.i18n.__('保存失败: ', 'yali-ai-writer') + errorMsg, 'error');
                    } else {
                        alert(wp.i18n.__('保存失败: ', 'yali-ai-writer') + errorMsg);
                    }
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                if (typeof window.yaliToast === 'function') {
                    window.yaliToast(wp.i18n.__('保存请求失败: ', 'yali-ai-writer') + textStatus, 'error');
                } else {
                    alert(wp.i18n.__('保存请求失败', 'yali-ai-writer'));
                }
            },
            complete: function () {
                // Restore button state
                submitBtn.prop('disabled', false).css('opacity', '');
            }
        });
    });

    // --- [REMOVED] Centralized Task Action Handler ---
    // Pause, resume, cancel, delete, and retry are now handled by YaliActions.

    // 初始化任务状态监听
    function initTaskMonitoring() {
        $('.task-row').each(function () {
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
    $(window).on('beforeunload', function () {
        $.each(taskPollingIntervals, function (taskId, interval) {
            clearInterval(interval);
        });
    });
});
