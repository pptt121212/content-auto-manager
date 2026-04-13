/**
 * Extension API Key Admin Inline Scripts
 * Extracted from class-extension-api-key-admin.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    $(function() {
        var extensionData = window.extensionApiKeyData || {};

        $('#cam-verify-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $status = $('#cam-verify-status');
            $btn.prop('disabled', true).css('opacity', '0.7');
            $status.text(wp.i18n.__('⏳ 正在创建任务...', 'yali-ai-writer')).css('color', '#666');

            // 1. Create Task via REST API
            $.ajax({
                url: extensionData.restUrl || '',
                method: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', extensionData.restNonce || '');
                    xhr.setRequestHeader('X-CAM-API-Key', extensionData.apiKey || '');
                },
                data: JSON.stringify({
                    type: 'connection_verify',
                    payload: { timestamp: Date.now() }
                }),
                contentType: 'application/json',
                success: function(response) {
                    var taskId = response.task_id;
                    $status.text(wp.i18n.__('⏳ 等待扩展响应... (请在浏览器右侧打开插件)', 'yali-ai-writer')).css('color', '#d63638');

                    // 2. Poll Status
                    var pollCount = 0;
                    var maxPolls = 30; // 30 * 2s = 60s timeout

                    var pollInterval = setInterval(function() {
                        pollCount++;
                        if (pollCount > maxPolls) {
                            clearInterval(pollInterval);
                            $status.text(wp.i18n.__('❌ 验证超时：扩展没有在 60秒内响应。请确保扩展面板已打开。', 'yali-ai-writer')).css('color', 'red');
                            $btn.prop('disabled', false).css('opacity', '');
                            return;
                        }

                        $.post(extensionData.ajaxUrl || ajaxurl, {
                            action: 'cam_check_verify_result',
                            task_id: taskId,
                            nonce: extensionData.verifyNonce || ''
                        }, function(res) {
                            if (res.success) {
                                clearInterval(pollInterval);
                                $status.text(wp.i18n.__('✅ 验证成功！扩展通信正常。', 'yali-ai-writer')).css('color', 'green');
                                $btn.prop('disabled', false).css('opacity', '');
                            }
                            // if res.data.status === 'not_found', it might mean something wrong, or just not processed
                        });

                    }, 2000);
                },
                error: function(err) {
                    $status.text(wp.i18n.__('错误：', 'yali-ai-writer') + (err.responseJSON ? err.responseJSON.message : err.statusText)).css('color', 'red');
                    $btn.prop('disabled', false).css('opacity', '');
                }
            });
        });
    });

})(jQuery);
