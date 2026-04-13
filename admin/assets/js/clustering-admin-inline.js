/**
 * Clustering Admin Page Inline Scripts
 * Extracted from class-clustering-admin-page.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    $(function() {
        var pollingInterval = null;
        var $btn = $('#start-clustering-btn');
        var $console = $('#clustering-console');
        var $badge = $('#clustering-status-badge');

        var clusteringData = window.clusteringAdminData || {};

        function startPolling() {
            if (pollingInterval) clearInterval(pollingInterval);
            $console.show();
            pollingInterval = setInterval(fetchStatus, 3000);
        }

        function stopPolling() {
            if (pollingInterval) clearInterval(pollingInterval);
        }

        function appendToConsole(text) {
            // simple diff check to avoid flickering
            var currentHtml = $console.html();
            // format text lines with <br>
            var newHtml = text.replace(/\n/g, '<br>');
            if (currentHtml !== newHtml) {
                $console.html(newHtml);
                $console.scrollTop($console[0].scrollHeight);
            }
        }

        function fetchStatus() {
            $.ajax({
                url: clusteringData.ajaxUrl || ajaxurl,
                type: 'POST',
                data: { 
                    action: 'yali_ai_writer_get_clustering_status',
                    nonce: clusteringData.nonce || ''
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var statusObj = response.data;

                        if (statusObj.status !== 'idle') {
                            $btn.prop('disabled', true).css('opacity', '0.7');
                            $console.show();
                            appendToConsole(statusObj.progress_message || '');

                            if (statusObj.status === 'running') {
                                $badge.text(wp.i18n.__('聚类正在后台进行中...', 'yali-ai-writer')).css('color', '#2271b1');
                            } else if (statusObj.status === 'completed') {
                                $badge.text(wp.i18n.__('聚类已完成！', 'yali-ai-writer')).css('color', 'green');
                                stopPolling();
                                $btn.prop('disabled', false).css('opacity', '');
                            } else if (statusObj.has_error) {
                                $badge.text(wp.i18n.__('执行出错，已停止。', 'yali-ai-writer')).css('color', 'red');
                                stopPolling();
                                $btn.prop('disabled', false).css('opacity', '');
                            }
                        } else {
                            $btn.prop('disabled', false).css('opacity', '');
                            stopPolling();
                        }
                    }
                },
                error: function() {
                    // silently fail on network error and retry next loop
                }
            });
        }

        $btn.on('click', function(e) {
            e.preventDefault();
            if (!confirm(wp.i18n.__('这是一个高消耗操作，将在后台运行。确定要开始吗？', 'yali-ai-writer'))) return;

            $btn.prop('disabled', true).css('opacity', '0.7');
            $badge.text(wp.i18n.__('正在启动后台进程...', 'yali-ai-writer')).css('color', '#2271b1');

            $.ajax({
                url: clusteringData.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'yali_ai_writer_start_vector_clustering',
                    nonce: clusteringData.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        startPolling();
                    } else {
                        alert(response.data.message || 'Error occurred');
                        $btn.prop('disabled', false).css('opacity', '');
                        $badge.text('');
                    }
                },
                error: function() {
                    alert(wp.i18n.__('网络错误，启动命令发送失败。', 'yali-ai-writer'));
                    $btn.prop('disabled', false).css('opacity', '');
                    $badge.text('');
                }
            });
        });

        // Initial poll to restore state if a task is already running in background
        fetchStatus();
    });

})(jQuery);