/**
 * Enhanced Dashboard Inline Scripts
 * Extracted from enhanced-dashboard.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    $(function() {
        var dashboardData = window.enhancedDashboardData || {};

        // 清除队列按钮点击
        $('#clear-queue-btn').on('click', function(e) {
            e.preventDefault();
            $('#clear-queue-modal').addClass('active');
            $('body').css('overflow', 'hidden');
        });

        // 模态框关闭
        $('#clear-queue-modal-close, #clear-queue-modal-cancel').on('click', function() {
            $('#clear-queue-modal').removeClass('active');
            $('body').css('overflow', '');
        });

        // 点击背景关闭
        $('#clear-queue-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).removeClass('active');
                $('body').css('overflow', '');
            }
        });

        // 确认清除队列
        $('#confirm-clear-queue').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $btnText = $button.html();

            // 显示加载状态
            $button.addClass('clearing-queue');
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('清除中...', 'yali-ai-writer'));

            // 发送AJAX请求
            $.ajax({
                url: dashboardData.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_clear_task_queue',
                    nonce: dashboardData.clearQueueNonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        // 显示成功消息
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message, 'success');
                        } else {
                            alert(response.data.message);
                        }

                        // 关闭模态框
                        $('#clear-queue-modal').removeClass('active');

                        // 刷新页面数据（可选）
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // 显示错误消息
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message || wp.i18n.__('清除队列失败', 'yali-ai-writer'), 'error');
                        } else {
                            alert(response.data.message || wp.i18n.__('清除队列失败', 'yali-ai-writer'));
                        }
                    }

                    // 恢复按钮状态
                    $button.removeClass('clearing-queue');
                    $button.prop('disabled', false);
                    $button.html($btnText);
                },
                error: function() {
                    var errorMsg = wp.i18n.__('网络请求失败，请稍后重试', 'yali-ai-writer');
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(errorMsg, 'error');
                    } else {
                        alert(errorMsg);
                    }
                    // 恢复按钮状态
                    $button.removeClass('clearing-queue');
                    $button.prop('disabled', false);
                    $button.html($btnText);
                }
            });
        });

        // 批量清理 - 打开模态框
        $('#bulk-clean-btn').on('click', function(e) {
            e.preventDefault();
            $('#bulk-clean-modal').addClass('active');
            $('body').css('overflow', 'hidden');
        });

        // 批量清理 - 关闭模态框
        $('#bulk-clean-modal-close, #bulk-clean-modal-cancel').on('click', function() {
            $('#bulk-clean-modal').removeClass('active');
            $('body').css('overflow', '');
        });

        // 批量清理 - 点击背景关闭
        $('#bulk-clean-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).removeClass('active');
                $('body').css('overflow', '');
            }
        });

        // 批量清理 - 确认
        $('#confirm-bulk-clean').on('click', function(e) {
            e.preventDefault();

            var data = {
                action: 'yali_ai_writer_bulk_clean_tasks',
                nonce: dashboardData.bulkCleanNonce || ''
            };

            // 获取选中的清理选项
            var hasSelection = false;
            $('#bulk-clean-options input[type="checkbox"]:checked').each(function() {
                data[this.name] = $(this).val();
                hasSelection = true;
            });

            if (!hasSelection) {
                alert(wp.i18n.__('请至少选择一项进行清理', 'yali-ai-writer'));
                return;
            }

            var $button = $(this);
            var $btnText = $button.html();

            // 显示加载状态
            $button.addClass('clearing-queue');
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('清理中...', 'yali-ai-writer'));

            // 发送AJAX请求
            $.ajax({
                url: dashboardData.ajaxUrl || ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        // 显示成功消息
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message, 'success');
                        } else {
                            alert(response.data.message);
                        }

                        // 关闭模态框
                        $('#bulk-clean-modal').removeClass('active');

                        // 刷新页面数据
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // 显示错误消息
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(response.data.message || wp.i18n.__('清理失败', 'yali-ai-writer'), 'error');
                        } else {
                            alert(response.data.message || wp.i18n.__('清理失败', 'yali-ai-writer'));
                        }
                    }

                    // 恢复按钮状态
                    $button.removeClass('clearing-queue');
                    $button.prop('disabled', false);
                    $button.html($btnText);
                },
                error: function() {
                    var errorMsg = wp.i18n.__('网络请求失败，请稍后重试', 'yali-ai-writer');
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(errorMsg, 'error');
                    } else {
                        alert(errorMsg);
                    }
                    // 恢复按钮状态
                    $button.removeClass('clearing-queue');
                    $button.prop('disabled', false);
                    $button.html($btnText);
                }
            });
        });

        // 保存语言设置
        $('#save-language-setting').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var originalHtml = $button.html();
            var locale = $('#yali-plugin-language-select').val();

            $button.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('保存中...', 'yali-ai-writer'));

            $.ajax({
                url: dashboardData.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'cam_save_language_setting',
                    nonce: dashboardData.nonce || '',
                    plugin_locale: locale
                },
                success: function(response) {
                    if (response.success) {
                        $button.html('<span class="dashicons dashicons-yes"></span> ' + wp.i18n.__('已保存', 'yali-ai-writer'));
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(response.data.message || 'Error saving setting');
                        $button.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    alert(wp.i18n.__('网络请求失败，请稍后重试', 'yali-ai-writer'));
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        });

        // ESC键关闭模态框
        $(document).on('keydown', function(e) {
            if (e.keyCode === 27) { // ESC键
                if ($('#clear-queue-modal').hasClass('active')) {
                    $('#clear-queue-modal').removeClass('active');
                    $('body').css('overflow', '');
                }
                if ($('#bulk-clean-modal').hasClass('active')) {
                    $('#bulk-clean-modal').removeClass('active');
                    $('body').css('overflow', '');
                }
            }
        });
    });

})(jQuery);
