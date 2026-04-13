/**
 * Debug Tools Inline Scripts
 * Extracted from debug-tools.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    // 获取 localized data
    var debugToolsData = window.debugToolsData || {};

    // 确认清空日志文件
    window.confirmClearLogs = function() {
        if (confirm(wp.i18n.__('确定要清空所有日志文件吗？\n\n此操作将永久删除logs目录下的所有.log文件，且无法恢复！\n\n请确认您已备份重要日志后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要删除所有日志文件吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                document.getElementById('clear_logs_form').submit();
            }
        }
    };

    // 确认清空图像API设置
    window.confirmClearImageApiSettings = function() {
        if (confirm(wp.i18n.__('确定要清空图像API设置吗？\n\n此操作将删除所有图像API提供商的配置，且无法恢复！\n\n请确认您已备份重要配置后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清空图像API设置吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_image_api_settings_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    // 确认重置图像API设置
    window.confirmResetImageApiSettings = function() {
        if (confirm(wp.i18n.__('确定要重置图像API设置为默认值吗？\n\n此操作将覆盖所有当前配置，且无法恢复！\n\n请确认您已备份重要配置后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要重置图像API设置吗？\n\n点击"确定"将继续重置，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('reset_image_api_settings_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    // 确认清理自动配图postmeta数据
    window.confirmClearAutoImagePostmeta = function() {
        if (confirm(wp.i18n.__('确定要清理自动配图postmeta数据吗？\n\n此操作将永久删除所有自动配图相关的postmeta记录，且无法恢复！\n\n请确认您已备份重要数据后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清理自动配图postmeta数据吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_auto_image_postmeta_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    // 确认清理历史队列任务
    window.confirmClearCompletedTasks = function() {
        if (confirm(wp.i18n.__('确定要清理历史队列任务吗？\n\n此操作将删除以下三个表中所有状态为"completed"的记录：\n\n• wp_yali_ai_writer_job_queue\n• wp_yali_ai_writer_topic_tasks\n• wp_yali_ai_writer_article_tasks\n\n此操作无法恢复！\n\n请确认您已备份重要数据后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清理所有已完成的队列任务记录吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_completed_tasks_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    // 调试模式控制功能
    $(function() {
        $('#enable-debug-mode').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text(wp.i18n.__('启用中...', 'yali-ai-writer'));

            $.ajax({
                url: debugToolsData.ajaxUrl || ajaxurl,
                method: 'POST',
                data: {
                    action: 'yali_ai_writer_toggle_debug_mode',
                    mode: 'enable',
                    nonce: debugToolsData.debugModeNonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(wp.i18n.__('启用失败：', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                        button.prop('disabled', false).text('✅ ' + wp.i18n.__('启用调试模式', 'yali-ai-writer'));
                    }
                },
                error: function() {
                    alert(wp.i18n.__('请求失败，请重试', 'yali-ai-writer'));
                    button.prop('disabled', false).text('✅ ' + wp.i18n.__('启用调试模式', 'yali-ai-writer'));
                }
            });
        });

        $('#disable-debug-mode').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text(wp.i18n.__('关闭中...', 'yali-ai-writer'));

            $.ajax({
                url: debugToolsData.ajaxUrl || ajaxurl,
                method: 'POST',
                data: {
                    action: 'yali_ai_writer_toggle_debug_mode',
                    mode: 'disable',
                    nonce: debugToolsData.debugModeNonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(wp.i18n.__('关闭失败：', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                        button.prop('disabled', false).text('❌ ' + wp.i18n.__('关闭调试模式', 'yali-ai-writer'));
                    }
                },
                error: function() {
                    alert(wp.i18n.__('请求失败，请重试', 'yali-ai-writer'));
                    button.prop('disabled', false).text('❌ ' + wp.i18n.__('关闭调试模式', 'yali-ai-writer'));
                }
            });
        });

        $('#view-debug-logs').on('click', function() {
            var button = $(this);
            var logsContainer = $('#debug-logs-content');
            var logsDisplay = $('#logs-display');

            if (logsContainer.is(':visible')) {
                logsContainer.hide();
                button.text(button.data('view-text'));
                return;
            }

            button.prop('disabled', true).text(button.data('loading-text'));

            $.ajax({
                url: debugToolsData.ajaxUrl || ajaxurl,
                method: 'POST',
                data: {
                    action: 'yali_ai_writer_get_debug_logs',
                    nonce: debugToolsData.debugLogsNonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        logsDisplay.text(response.data.logs || wp.i18n.__('暂无日志内容', 'yali-ai-writer'));
                        logsContainer.show();
                        button.text(button.data('hide-text'));
                    } else {
                        alert(wp.i18n.__('获取日志失败：', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                        button.text(button.data('view-text'));
                    }
                    button.prop('disabled', false);
                },
                error: function() {
                    alert(wp.i18n.__('请求失败，请重试', 'yali-ai-writer'));
                    button.prop('disabled', false).text(button.data('view-text'));
                }
            });
        });

        $('#clear-debug-logs').on('click', function() {
            if (!confirm(wp.i18n.__('确定要清空所有调试日志吗？此操作不可逆！', 'yali-ai-writer'))) {
                return;
            }

            var button = $(this);
            button.prop('disabled', true).text(wp.i18n.__('清空中...', 'yali-ai-writer'));

            $.ajax({
                url: debugToolsData.ajaxUrl || ajaxurl,
                method: 'POST',
                data: {
                    action: 'yali_ai_writer_clear_debug_logs',
                    nonce: debugToolsData.debugLogsClearNonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        alert(wp.i18n.__('日志已清空', 'yali-ai-writer'));
                        $('#debug-logs-content').hide();
                        $('#view-debug-logs').text('📄 ' + wp.i18n.__('查看调试日志', 'yali-ai-writer'));
                    } else {
                        alert(wp.i18n.__('清空失败：', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                    }
                    button.prop('disabled', false).text('🗑️ ' + wp.i18n.__('清空日志', 'yali-ai-writer'));
                },
                error: function() {
                    alert(wp.i18n.__('请求失败，请重试', 'yali-ai-writer'));
                    button.prop('disabled', false).text('🗑️ ' + wp.i18n.__('清空日志', 'yali-ai-writer'));
                }
            });
        });
    });

})(jQuery);