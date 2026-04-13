/**
 * Topics List Inline Scripts
 * Extracted from topics-list.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function($) {
    'use strict';

    $(function() {
        var recallTestNonce = window.topicsListData?.recallTestNonce || '';
        var $deepWritingButton = $('input[name="deep_writing"]');
        var $deepWritingForm = $deepWritingButton.closest('form');
        var $deepWritingModal = $('#deep-writing-confirm-modal');

        function closeDeepWritingModal() {
            $deepWritingModal.removeClass('active');
            $('body').css('overflow', '');
        }

        function openDeepWritingModal() {
            $deepWritingModal.addClass('active');
            $('body').css('overflow', 'hidden');
        }

        function hasSelectedTopics() {
            return $deepWritingForm.find('input.topic-checkbox:checked').length > 0;
        }

        $deepWritingButton.on('click', function(e) {
            if (!$deepWritingForm.length || !hasSelectedTopics()) {
                return;
            }

            e.preventDefault();
            openDeepWritingModal();
        });

        $('#deep-writing-confirm-submit').on('click', function(e) {
            e.preventDefault();

            if (!$deepWritingForm.length) {
                closeDeepWritingModal();
                return;
            }

            $deepWritingForm.find('#deep-writing-submit-hidden').remove();
            $('<input>', {
                type: 'hidden',
                id: 'deep-writing-submit-hidden',
                name: 'deep_writing',
                value: '1'
            }).appendTo($deepWritingForm);

            closeDeepWritingModal();
            $deepWritingForm[0].submit();
        });

        $(document).on('click', '#deep-writing-confirm-cancel, #deep-writing-confirm-modal-close', function(e) {
            e.preventDefault();
            closeDeepWritingModal();
        });

        $deepWritingModal.on('click', function(e) {
            if (e.target === this) {
                closeDeepWritingModal();
            }
        });

        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' && $deepWritingModal.hasClass('active')) {
                closeDeepWritingModal();
            }
        });

        // 召回测试按钮点击
        $('.btn-recall-test').on('click', function() {
            var topicId = $(this).data('topic-id');
            var topicTitle = $(this).data('topic-title');

            // 显示弹窗
            $('#recall-test-modal').addClass('active');

            // 显示加载状态
            $('#recall-test-result').html(
                '<div class="yali-panel" style="text-align: center; padding: 40px;">' +
                '<span class="spinner is-active" style="float: none; margin: 0 auto 15px; display: block;"></span>' +
                '<p>' + wp.i18n.__('正在执行召回测试，请稍候...', 'yali-ai-writer') + '</p>' +
                '<p style="color: var(--yali-text-muted); font-size: 12px; margin-top: 8px;">' + wp.i18n.__('（大模型精选可能需要10-30秒）', 'yali-ai-writer') + '</p>' +
                '</div>'
            );

            // 发送AJAX请求
            $.ajax({
                url: window.topicsListData?.ajaxUrl || ajaxurl,
                type: 'POST',
                data: {
                    action: 'cam_test_reference_recall',
                    nonce: recallTestNonce,
                    topic_id: topicId
                },
                success: function(response) {
                    if (response.success) {
                        renderRecallTestResult(response.data, topicTitle);
                    } else {
                        $('#recall-test-result').html(
                            '<div class="yali-notice yali-notice-error">' +
                            '<h4 style="margin: 0 0 8px 0;">' + wp.i18n.__('测试失败', 'yali-ai-writer') + '</h4>' +
                            '<p>' + (response.data.message || wp.i18n.__('未知错误', 'yali-ai-writer')) + '</p>' +
                            '</div>'
                        );
                    }
                },
                error: function(xhr, status, error) {
                    $('#recall-test-result').html(
                        '<div class="yali-notice yali-notice-error">' +
                        '<h4 style="margin: 0 0 8px 0;">' + wp.i18n.__('请求失败', 'yali-ai-writer') + '</h4>' +
                        '<p>' + error + '</p>' +
                        '</div>'
                    );
                }
            });
        });

        // 关闭弹窗
        $('.yali-modal-close, .yali-modal-overlay').on('click', function(e) {
            if (e.target === this || $(e.target).closest('.yali-modal-close').length) {
                $('#recall-test-modal').removeClass('active');
            }
        });

        // 渲染测试结果
        function renderRecallTestResult(data, topicTitle) {
            var html = '';

            // 主题信息
            html += '<div class="yali-panel" style="margin-bottom: 16px;">';
            html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-primary);">' + wp.i18n.__('测试主题', 'yali-ai-writer') + '</h4>';
            html += '<p style="margin: 4px 0;"><strong>ID:</strong> ' + data.topic_id + '</p>';
            html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('标题:', 'yali-ai-writer') + '</strong> ' + escapeHtml(data.topic_title) + '</p>';
            html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('向量状态:', 'yali-ai-writer') + '</strong> ' + (data.has_vector ? '<span style="color: var(--yali-success);">' + wp.i18n.__('已生成', 'yali-ai-writer') + '</span>' : '<span style="color: var(--yali-warning);">' + wp.i18n.__('未生成', 'yali-ai-writer') + '</span>') + '</p>';
            html += '</div>';

            // 调试信息
            if (data.debug_info) {
                html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid #9b59b6;">';
                html += '<h4 style="margin: 0 0 12px 0; color: #9b59b6;">' + wp.i18n.__('调试信息', 'yali-ai-writer') + '</h4>';
                if (data.debug_info.topic_vector_dimensions) {
                    html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('主题向量维度:', 'yali-ai-writer') + '</strong> ' + data.debug_info.topic_vector_dimensions + '</p>';
                }
                if (data.debug_info.total_reference_profiles !== undefined) {
                    html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('参考资料总数:', 'yali-ai-writer') + '</strong> ' + data.debug_info.total_reference_profiles + ' ' + wp.i18n.__('条', 'yali-ai-writer') + '</p>';
                }
                if (data.debug_info.suggestion) {
                    html += '<p style="margin: 4px 0; color: var(--yali-warning);"><strong>' + wp.i18n.__('建议:', 'yali-ai-writer') + '</strong> ' + escapeHtml(data.debug_info.suggestion) + '</p>';
                }
                html += '</div>';
            }

            // 所有参考资料相似度（调试用）
            if (data.all_profiles_similarity && data.all_profiles_similarity.length > 0) {
                html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid var(--yali-info);">';
                html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-info);">' + wp.i18n.__('所有参考资料相似度', 'yali-ai-writer') + ' (' + data.all_profiles_similarity.length + ' ' + wp.i18n.__('条', 'yali-ai-writer') + ')</h4>';
                html += '<p style="color: var(--yali-text-muted); font-size: 12px; margin-bottom: 12px;">' + wp.i18n.__('此表显示所有参考资料与主题的相似度，用于调试。阈值为 0.5（50%）。', 'yali-ai-writer') + '</p>';
                html += '<table class="yali-table">';
                html += '<thead><tr>';
                html += '<th>ID</th>';
                html += '<th>' + wp.i18n.__('标题', 'yali-ai-writer') + '</th>';
                html += '<th>' + wp.i18n.__('相似度', 'yali-ai-writer') + '</th>';
                html += '<th>' + wp.i18n.__('达到阈值', 'yali-ai-writer') + '</th>';
                html += '</tr></thead><tbody>';

                data.all_profiles_similarity.forEach(function(profile) {
                    var similarity = profile.similarity;
                    var similarityText = similarity !== null ? (similarity * 100).toFixed(2) + '%' : wp.i18n.__('解码失败', 'yali-ai-writer');
                    var meetsThreshold = profile.meets_threshold;
                    var rowClass = meetsThreshold ? 'yali-bg-success' : (similarity !== null && similarity < 0.3 ? 'yali-bg-error' : '');

                    html += '<tr class="' + rowClass + '">';
                    html += '<td>' + profile.id + '</td>';
                    html += '<td>' + escapeHtml(profile.title) + '</td>';
                    html += '<td>' + similarityText + '</td>';
                    html += '<td>' + (profile.error ? '<span class="yali-text-danger">' + escapeHtml(profile.error) + '</span>' : (meetsThreshold ? '<span class="yali-text-success">✓ ' + wp.i18n.__('是', 'yali-ai-writer') + '</span>' : '<span class="yali-text-muted">✗ ' + wp.i18n.__('否', 'yali-ai-writer') + '</span>')) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                html += '</div>';
            }

            if (data.error) {
                html += '<div class="yali-notice yali-notice-error" style="margin-bottom: 16px;">';
                html += '<h4 style="margin: 0 0 8px 0;">' + wp.i18n.__('召回失败', 'yali-ai-writer') + '</h4>';
                html += '<p>' + escapeHtml(data.error) + '</p>';
                html += '</div>';
                $('#recall-test-result').html(html);
                return;
            }

            // 候选列表
            if (data.candidates && data.candidates.length > 0) {
                html += '<div class="yali-panel" style="margin-bottom: 16px;">';
                html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-primary);">' + wp.i18n.__('召回候选列表', 'yali-ai-writer') + ' (' + data.candidates.length + ' ' + wp.i18n.__('条', 'yali-ai-writer') + ')</h4>';
                html += '<table class="yali-table">';
                html += '<thead><tr>';
                html += '<th>ID</th>';
                html += '<th>' + wp.i18n.__('标题', 'yali-ai-writer') + '</th>';
                html += '<th>' + wp.i18n.__('相似度', 'yali-ai-writer') + '</th>';
                html += '<th>' + wp.i18n.__('描述预览', 'yali-ai-writer') + '</th>';
                html += '</tr></thead><tbody>';

                data.candidates.forEach(function(candidate) {
                    var isSelected = data.ai_selected && data.ai_selected.id == candidate.id;
                    html += '<tr class="' + (isSelected ? 'yali-bg-success' : '') + '">';
                    html += '<td>' + candidate.id + (isSelected ? ' <span class="yali-text-success">✓</span>' : '') + '</td>';
                    html += '<td>' + escapeHtml(candidate.title) + '</td>';
                    html += '<td>' + (candidate.similarity * 100).toFixed(2) + '%</td>';
                    html += '<td>' + escapeHtml(candidate.description_preview) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                html += '</div>';
            }

            // AI选择结果
            if (data.ai_selected) {
                html += '<div class="yali-panel" style="margin-bottom: 16px; border-left: 4px solid var(--yali-success);">';
                html += '<h4 style="margin: 0 0 12px 0; color: var(--yali-success);">' + wp.i18n.__('大模型精选结果', 'yali-ai-writer') + '</h4>';
                html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('选中ID:', 'yali-ai-writer') + '</strong> ' + data.ai_selected.id + '</p>';
                html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('选中标题:', 'yali-ai-writer') + '</strong> ' + escapeHtml(data.ai_selected.title) + '</p>';
                html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('选择原因:', 'yali-ai-writer') + '</strong> ' + escapeHtml(data.ai_selected.reason) + '</p>';
                html += '</div>';
            }

            // 最终结果
            if (data.final_result) {
                html += '<div class="yali-notice yali-notice-success" style="margin-top: 16px;">';
                html += '<h4 style="margin: 0 0 12px 0;">' + wp.i18n.__('最终召回结果', 'yali-ai-writer') + '</h4>';
                html += '<p style="margin: 4px 0;"><strong>ID:</strong> ' + data.final_result.id + '</p>';
                html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('标题:', 'yali-ai-writer') + '</strong> ' + escapeHtml(data.final_result.title) + '</p>';
                html += '<p style="margin: 4px 0;"><strong>' + wp.i18n.__('参考资料内容:', 'yali-ai-writer') + '</strong></p>';
                html += '<div style="background: var(--yali-card); border: 1px solid var(--yali-border); border-radius: 4px; padding: 12px; margin-top: 8px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; font-size: 13px; line-height: 1.5;">' + escapeHtml(data.final_result.description) + '</div>';
                html += '</div>';
            }

            $('#recall-test-result').html(html);
        }

        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // ====== View Reference Modal Logic ======
        var $refModal = $('#view-reference-modal-overlay');
        var $refContentDiv = $('#view-reference-modal-content');
        
        if ($refModal.parent().not('body').length) {
            $refModal.appendTo('body');
        }
        
        $(document).on('click', '.view-reference-btn', function(e) {
            e.preventDefault();
            var content = $(this).data('content');
            var title = $(this).data('title') || wp.i18n.__('参考资料', 'yali-ai-writer');
            
            $('#view-reference-modal-title').text(title);
            $refContentDiv.html(escapeHtml(content));
            
            $refModal.addClass('active');
            $('body').css('overflow', 'hidden'); 
        });
        
        function closeRefModal() {
            $refModal.removeClass('active');
            $('body').css('overflow', '');
        }
        
        $(document).on('click', '#view-reference-modal-close, #view-reference-close-btn', function(e) {
            e.preventDefault();
            closeRefModal();
        });
        
        $refModal.on('click', function(e) {
            if (e.target === this) {
                closeRefModal();
            }
        });
        
        $(document).on('keyup', function(e) {
            if (e.key === "Escape" && $refModal.hasClass('active')) {
                closeRefModal();
            }
        });
    });

})(jQuery);
