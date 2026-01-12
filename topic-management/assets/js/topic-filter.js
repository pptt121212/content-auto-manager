/**
 * 主题高级筛选JavaScript
 */
jQuery(document).ready(function ($) {
    // 从页面获取nonce
    var filterNonce = $('#cam-filter-nonce').val();
    var duplicateData = null;

    // 国际化文本（从PHP传入）
    var i18n = window.camTopicFilterI18n || {
        detecting: '正在检测重复标题...',
        detected: '检测完成，发现',
        duplicateTopics: '个重复主题',
        noDuplicates: '未发现重复标题',
        detectFailed: '检测失败',
        requestFailed: '请求失败',
        summary: '检测结果汇总',
        exactGroups: '完全相同标题组',
        exactTopics: '完全重复主题数',
        similarGroups: '向量相似组',
        similarTopics: '相似重复主题数',
        exactLabel: '完全相同',
        exactTitle: '完全相同的标题',
        similarLabel: '向量相似',
        similarTitle: '向量相似的标题',
        groups: '组',
        topics: '个主题',
        createdAt: '创建时间',
        category: '分类',
        keep: '保留',
        willDelete: '将删除',
        similarity: '相似度',
        noResults: '未发现重复标题',
        detectFirst: '请先检测重复标题',
        noDuplicatesToDelete: '没有需要删除的重复主题',
        confirmDeleteAll: '确定要删除所有重复主题吗？将保留每组中最早创建的主题。',
        willDelete: '将删除',
        deleting: '正在删除重复主题...',
        deleteFailed: '删除失败',
        selectTopics: '请选择要删除的主题',
        confirmDeleteSelected: '确定要删除选中的',
        topicsConfirm: '个主题吗？此操作不可撤销。',
        deletingText: '删除中...',
        deleteSelected: '删除选中'
    };

    // 更新选中计数
    function updateSelectedCount() {
        var count = $('.topic-checkbox:checked:not(:disabled)').length;
        $('#selected-count').text(count);

        if (count > 0) {
            $('#bulk-actions-section').slideDown();
        } else {
            $('#bulk-actions-section').slideUp();
        }
    }

    // 监听复选框变化
    $(document).on('change', '.topic-checkbox', updateSelectedCount);
    $(document).on('change', '#select-all-topics', updateSelectedCount);

    // 重置筛选表单
    $('#reset-filters').on('click', function () {
        $('#filter-title').val('');
        $('#filter-status').val('unused');
        $('#filter-category').val('');
        $('#filter-priority').val('');
        $('#filter-vector').val('');
        $('#filter-reference').val('');
        $('#filter-task-id').val('');
    });

    // 检测重复标题
    $('#detect-duplicates').on('click', function () {
        var $btn = $(this);
        var $status = $('#duplicate-status');
        var $results = $('#duplicate-results');
        var $deleteBtn = $('#delete-all-duplicates');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text(i18n.detecting);
        $results.hide();
        $deleteBtn.hide();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_detect_duplicates',
                nonce: filterNonce,
                status: $('#filter-status').val() || 'unused',
                threshold: $('#duplicate-threshold').val() / 100 // 转换为小数
            },
            success: function (response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    duplicateData = response.data;
                    renderDuplicateResults(response.data);

                    var totalDuplicates = response.data.summary.exact_duplicate_topics + response.data.summary.similar_duplicate_topics;
                    if (totalDuplicates > 0) {
                        $status.removeClass('loading error').addClass('success').text(
                            i18n.detected + ' ' + totalDuplicates + ' ' + i18n.duplicateTopics
                        );
                        $deleteBtn.show();
                    } else {
                        $status.removeClass('loading error').addClass('success').text(i18n.noDuplicates);
                    }
                } else {
                    $status.removeClass('loading success').addClass('error').text(response.data.message || i18n.detectFailed);
                }
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false);
                $status.removeClass('loading success').addClass('error').text(i18n.requestFailed + ': ' + error);
            }
        });
    });

    // 渲染重复检测结果
    function renderDuplicateResults(data) {
        var $results = $('#duplicate-results');
        var html = '';

        // 总结区域
        html += '<div class="duplicate-summary">';
        html += '<h4>' + i18n.summary + '</h4>';
        html += '<div class="duplicate-summary-stats">';
        html += '<div class="summary-stat">';
        html += '<span class="summary-stat-value">' + data.summary.exact_duplicate_groups + '</span>';
        html += '<span class="summary-stat-label">' + i18n.exactGroups + '</span>';
        html += '</div>';
        html += '<div class="summary-stat">';
        html += '<span class="summary-stat-value">' + data.summary.exact_duplicate_topics + '</span>';
        html += '<span class="summary-stat-label">' + i18n.exactTopics + '</span>';
        html += '</div>';
        html += '<div class="summary-stat">';
        html += '<span class="summary-stat-value">' + data.summary.similar_duplicate_groups + '</span>';
        html += '<span class="summary-stat-label">' + i18n.similarGroups + '</span>';
        html += '</div>';
        html += '<div class="summary-stat">';
        html += '<span class="summary-stat-value">' + data.summary.similar_duplicate_topics + '</span>';
        html += '<span class="summary-stat-label">' + i18n.similarTopics + '</span>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // 完全相同的标题
        if (data.exact_duplicates && data.exact_duplicates.length > 0) {
            html += '<h4><span class="exact-duplicate-label">' + i18n.exactLabel + '</span>' + i18n.exactTitle + ' (' + data.exact_duplicates.length + ' ' + i18n.groups + ')</h4>';

            data.exact_duplicates.forEach(function (group, index) {
                html += '<div class="duplicate-group">';
                html += '<div class="duplicate-group-header">';
                html += '<span class="group-title">' + escapeHtml(group.title) + '</span>';
                html += '<span class="group-meta">' + group.count + ' ' + i18n.topics + '</span>';
                html += '</div>';
                html += '<div class="duplicate-group-items">';

                group.topics.forEach(function (topic, tIndex) {
                    var isKeep = topic.id == group.keep_id;
                    var groupName = 'exact_group_' + index;

                    html += '<div class="duplicate-item ' + (isKeep ? 'keep-item' : 'delete-item') + '">';
                    html += '<div class="duplicate-item-info">';
                    html += '<div class="duplicate-item-title">ID: ' + topic.id + ' - ' + escapeHtml(topic.title) + '</div>';
                    html += '<div class="duplicate-item-meta">';
                    html += i18n.createdAt + ': ' + topic.created_at;
                    if (topic.matched_category) {
                        html += ' | ' + i18n.category + ': ' + escapeHtml(topic.matched_category);
                    }
                    html += '</div>';
                    html += '</div>';
                    html += '<div class="duplicate-item-action">';
                    // 使用单选按钮
                    html += '<label class="keep-radio-label" title="' + i18n.keep + '">';
                    html += '<input type="radio" name="' + groupName + '" value="' + topic.id + '" ' + (isKeep ? 'checked' : '') + ' class="keep-topic-radio"> ';
                    html += isKeep ? '<span class="badge-keep">' + i18n.keep + '</span>' : '<span class="badge-delete">' + i18n.willDelete + '</span>';
                    html += '</label>';
                    html += '</div>';
                    html += '</div>';
                });

                html += '</div>';
                html += '</div>';
            });
        }

        // 向量相似的标题
        if (data.similar_duplicates && data.similar_duplicates.length > 0) {
            html += '<h4><span class="similar-duplicate-label">' + i18n.similarLabel + '</span>' + i18n.similarTitle + ' (≥' + ($('#duplicate-threshold').val() || 90) + '%) (' + data.similar_duplicates.length + ' ' + i18n.groups + ')</h4>';

            data.similar_duplicates.forEach(function (group, index) {
                html += '<div class="duplicate-group">';
                html += '<div class="duplicate-group-header">';
                html += '<span class="group-title">' + i18n.similarity + ': ' + (group.similarity * 100).toFixed(1) + '%</span>';
                html += '<span class="group-meta">' + group.count + ' ' + i18n.topics + '</span>';
                html += '</div>';
                html += '<div class="duplicate-group-items">';

                group.topics.forEach(function (topic, tIndex) {
                    var isKeep = topic.id == group.keep_id;
                    var groupName = 'similar_group_' + index;

                    html += '<div class="duplicate-item ' + (isKeep ? 'keep-item' : 'delete-item') + '">';
                    html += '<div class="duplicate-item-info">';
                    html += '<div class="duplicate-item-title">ID: ' + topic.id + ' - ' + escapeHtml(topic.title) + '</div>';
                    html += '<div class="duplicate-item-meta">';
                    html += i18n.createdAt + ': ' + topic.created_at;
                    if (topic.matched_category) {
                        html += ' | ' + i18n.category + ': ' + escapeHtml(topic.matched_category);
                    }
                    html += '</div>';
                    html += '</div>';
                    html += '<div class="duplicate-item-action">';
                    // 使用单选按钮
                    html += '<label class="keep-radio-label" title="' + i18n.keep + '">';
                    html += '<input type="radio" name="' + groupName + '" value="' + topic.id + '" ' + (isKeep ? 'checked' : '') + ' class="keep-topic-radio"> ';
                    html += isKeep ? '<span class="badge-keep">' + i18n.keep + '</span>' : '<span class="badge-delete">' + i18n.willDelete + '</span>';
                    html += '</label>';
                    html += '</div>';
                    html += '</div>';
                });

                html += '</div>';
                html += '</div>';
            });
        }

        if (data.exact_duplicates.length === 0 && data.similar_duplicates.length === 0) {
            html += '<p style="text-align:center;color:#666;padding:20px;">' + i18n.noResults + '</p>';
        }

        $results.html(html).slideDown();
    }

    // 一键删除所有重复（实际上是删除未被选中的主题）
    $('#delete-all-duplicates').on('click', function () {
        if (!duplicateData) {
            alert(i18n.detectFirst);
            return;
        }

        // 收集需要删除的ID（即未被选中的所有radio对应的主题ID）
        var idsToDelete = [];
        $('.keep-topic-radio').each(function () {
            if (!$(this).prop('checked')) {
                idsToDelete.push($(this).val());
            }
        });

        if (idsToDelete.length === 0) {
            alert(i18n.noDuplicatesToDelete);
            return;
        }

        if (!confirm(i18n.confirmDeleteAll + '\n\n' + i18n.willDelete + ' ' + idsToDelete.length + ' ' + i18n.topics)) {
            return;
        }

        var $btn = $(this);
        var $status = $('#duplicate-status');


        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text(i18n.deleting);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_bulk_delete_topics',
                nonce: filterNonce,
                topic_ids: idsToDelete
            },
            success: function (response) {
                if (response.success) {
                    $status.removeClass('loading error').addClass('success').text(response.data.message);
                    // 刷新页面
                    setTimeout(function () {
                        location.reload();
                    }, 500);
                } else {
                    $status.removeClass('loading success').addClass('error').text(response.data.message || i18n.deleteFailed);
                    $btn.prop('disabled', false);
                }
            },
            error: function (xhr, status, error) {
                $status.removeClass('loading success').addClass('error').text(i18n.requestFailed + ': ' + error);
                $btn.prop('disabled', false);
            }
        });
    });

    // 绑定单选按钮变化事件，更新样式和标签
    $(document).on('change', '.keep-topic-radio', function () {
        var $radio = $(this);
        var $groupItems = $radio.closest('.duplicate-group-items');

        // 重置该组所有项的样式，并移除所有标签
        $groupItems.find('.duplicate-item').removeClass('keep-item').addClass('delete-item');
        $groupItems.find('.badge-keep').remove();
        $groupItems.find('.badge-delete').remove();

        // 重新渲染该组所有项的标签
        $groupItems.find('.duplicate-item').each(function () {
            var $item = $(this);
            var $actionDiv = $item.find('.keep-radio-label'); // 使用 label 容器
            // 再次确保移除（双重保险）
            $actionDiv.find('.badge-keep, .badge-delete').remove();

            if ($item.find('input[type="radio"]').prop('checked')) {
                $item.removeClass('delete-item').addClass('keep-item');
                $actionDiv.append('<span class="badge-keep">' + i18n.keep + '</span>');
                // 确保删除标签不存在
                $actionDiv.find('.badge-delete').remove();
            } else {
                $actionDiv.append('<span class="badge-delete">' + i18n.willDelete + '</span>');
                // 确保保留标签不存在
                $actionDiv.find('.badge-keep').remove();
            }
        });
    });


    // 批量删除选中的主题
    $('#bulk-delete-selected').on('click', function () {
        var selectedIds = [];
        $('.topic-checkbox:checked:not(:disabled)').each(function () {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert(i18n.selectTopics);
            return;
        }

        if (!confirm(i18n.confirmDeleteSelected + ' ' + selectedIds.length + ' ' + i18n.topicsConfirm)) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(i18n.deletingText);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_bulk_delete_topics',
                nonce: filterNonce,
                topic_ids: selectedIds
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || i18n.deleteFailed);
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> ' + i18n.deleteSelected);
                }
            },
            error: function (xhr, status, error) {
                alert(i18n.requestFailed + ': ' + error);
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> ' + i18n.deleteSelected);
            }
        });
    });

    // 批量生成参考资料
    $('#bulk-generate-reference').on('click', function () {
        var selectedIds = [];
        $('.topic-checkbox:checked:not(:disabled)').each(function () {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert(i18n.selectTopics || '请选择主题');
            return;
        }

        if (!confirm((i18n.confirmGenerateReference || '确定要为选中的') + ' ' + selectedIds.length + ' ' + (i18n.topicsConfirm || '个主题生成参考资料吗？'))) {
            return;
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> ' + (i18n.processing || '处理中...'));

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_bulk_generate_references',
                nonce: filterNonce,
                topic_ids: selectedIds
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    // location.reload(); // 不强制刷新，因为是后台任务
                    // 恢复按钮状态但保持选中，方便继续操作
                    $btn.prop('disabled', false).html(originalText);
                } else {
                    alert(response.data.message || (i18n.requestFailed || '请求失败'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function (xhr, status, error) {
                alert((i18n.requestFailed || '请求失败') + ': ' + error);
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // 删除所有符合筛选条件的主题
    $('#bulk-delete-all-filtered').on('click', function () {
        var totalCount = parseInt($('#filtered-total-count').text()) || 0;

        if (totalCount === 0) {
            alert(i18n.noTopicsToDelete || '没有符合条件的主题可删除');
            return;
        }

        // 检查状态是否为"未使用"
        var currentStatus = $('#filter-status').val();
        if (currentStatus !== 'unused') {
            alert(i18n.onlyUnusedCanDelete || '只能批量删除"未使用"状态的主题，请先在状态筛选中选择"未使用"');
            return;
        }

        var confirmMsg = (i18n.confirmDeleteAllFiltered || '确定要删除所有符合筛选条件的') + ' ' + totalCount + ' ' + (i18n.topicsQuestion || '个主题吗？此操作不可撤销。');
        if (!confirm(confirmMsg)) {
            return;
        }

        var $btn = $(this);
        var originalHtml = $btn.html();
        $btn.prop('disabled', true).text(i18n.deletingText || '删除中...');

        // 收集当前筛选条件
        var filterData = {
            action: 'cam_delete_all_filtered_topics',
            nonce: filterNonce,
            status: $('#filter-status').val(),
            title_keyword: $('#filter-title').val(),
            matched_category: $('#filter-category').val(),
            priority_score: $('#filter-priority').val(),
            has_vector: $('#filter-vector').val(),
            has_reference: $('#filter-reference').val(),
            task_id: $('#filter-task-id').val()
        };

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: filterData,
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || (i18n.deleteFailed || '删除失败'));
                    $btn.prop('disabled', false).html(originalHtml);
                }
            },
            error: function (xhr, status, error) {
                alert((i18n.requestFailed || '请求失败') + ': ' + error);
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // HTML转义
    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
