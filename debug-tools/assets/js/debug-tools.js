jQuery(document).ready(function ($) {
    // Confirmation functions for debug tools
    window.confirmClearLogs = function () {
        if (confirm(wp.i18n.__('确定要清空所有日志文件吗？\n\n此操作将永久删除logs目录下的所有.log文件，且无法恢复！\n\n请确认您已备份重要日志后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要删除所有日志文件吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                document.getElementById('clear_logs_form').submit();
            }
        }
    };

    window.confirmClearImageApiSettings = function () {
        if (confirm(wp.i18n.__('确定要清空图像API设置吗？\n\n此操作将删除所有图像API提供商的配置，且无法恢复！\n\n请确认您已备份重要配置后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清空图像API设置吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_image_api_settings_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    window.confirmResetImageApiSettings = function () {
        if (confirm(wp.i18n.__('确定要重置图像API设置为默认值吗？\n\n此操作将覆盖所有当前配置，且无法恢复！\n\n请确认您已备份重要配置后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要重置图像API设置吗？\n\n点击"确定"将继续重置，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('reset_image_api_settings_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    window.confirmClearAutoImagePostmeta = function () {
        if (confirm(wp.i18n.__('确定要清理自动配图postmeta数据吗？\n\n此操作将永久删除所有自动配图相关的postmeta记录，且无法恢复！\n\n请确认您已备份重要数据后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清理自动配图postmeta数据吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_auto_image_postmeta_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    window.confirmClearCompletedTasks = function () {
        if (confirm(wp.i18n.__('确定要清理历史队列任务吗？\n\n此操作将删除以下三个表中所有状态为"completed"的记录：\n\n• wp_content_auto_job_queue\n• wp_content_auto_topic_tasks\n• wp_content_auto_article_tasks\n\n此操作无法恢复！\n\n请确认您已备份重要数据后再继续。', 'yali-ai-writer'))) {
            if (confirm(wp.i18n.__('最后确认：\n\n您真的要清理所有已完成的队列任务记录吗？\n\n点击"确定"将继续删除，点击"取消"将放弃操作。', 'yali-ai-writer'))) {
                var form = document.getElementById('clear_completed_tasks_form');
                if (form) {
                    form.submit();
                }
            }
        }
    };

    // System check function
    window.runFullValidation = function () {
        var resultDiv = $("#validation-result");
        resultDiv.html('<div class="notice notice-info"><p>' + wp.i18n.__('正在运行系统检查...', 'yali-ai-writer') + '</p></div>');

        $.ajax({
            url: contentAutoManager.ajax_url, // Use localized ajax_url
            type: 'POST',
            data: {
                action: 'content_auto_run_full_validation',
                nonce: contentAutoManager.nonce
            },
            success: function (response) {
                if (response.success) {
                    var html = '<div class="notice notice-success"><p>' + wp.i18n.__('系统检查完成', 'yali-ai-writer') + '</p></div>';
                    html += '<div class="validation-details">';
                    html += '<h4>' + wp.i18n.__('检查结果：', 'yali-ai-writer') + '</h4>';
                    html += '<p>' + response.data.message + '</p>';

                    if (response.data.component_integration) {
                        html += '<h5>' + wp.i18n.__('组件状态：', 'yali-ai-writer') + '</h5>';
                        html += '<ul>';
                        for (var component in response.data.component_integration) {
                            var compResult = response.data.component_integration[component];
                            html += '<li><strong>' + component + '：</strong> ' + (compResult.valid ? wp.i18n.__('✓ 正常', 'yali-ai-writer') : wp.i18n.__('✗ 异常', 'yali-ai-writer')) + '</li>';
                        }
                        html += '</ul>';
                    }

                    html += '</div>';
                    resultDiv.html(html);
                } else {
                    resultDiv.html('<div class="notice notice-error yali-notice yali-notice-error"><p>' + wp.i18n.__('检查失败：', 'yali-ai-writer') + response.data.message + '</p></div>');
                }
            },
            error: function () {
                resultDiv.html('<div class="notice notice-error yali-notice yali-notice-error"><p>' + wp.i18n.__('检查失败：服务器错误', 'yali-ai-writer') + '</p></div>');
            }
        });
    };
});