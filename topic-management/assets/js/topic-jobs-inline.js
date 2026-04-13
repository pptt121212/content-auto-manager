/**
 * Topic Jobs Inline Scripts
 * Extracted from topic-jobs.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // 规则选择交互逻辑
        var ruleSelect = document.querySelector('select[name="rule_id"]');
        var extensionNotice = document.getElementById('browser-extension-notice');

        // 获取 localized data
        var topicJobsData = window.topicJobsData || {};
        var ruleTypeMap = topicJobsData.ruleTypeMap || {};
        var ruleReferenceMap = topicJobsData.ruleReferenceMap || {};
        var extensionRagGlobal = topicJobsData.extensionRagGlobal || false;

        if (ruleSelect && extensionNotice) {
            ruleSelect.addEventListener('change', function() {
                var ruleId = this.value;
                if (!ruleId) {
                    extensionNotice.style.display = 'none';
                    return;
                }

                var ruleType = ruleTypeMap[ruleId] || '';
                var hasRuleReference = ruleReferenceMap[ruleId] || false;

                // 锁定生成数量逻辑：如果是采集网址仿写，强制锁定为1
                var topicCountInput = document.querySelector('input[name="topic_count"]');
                if (ruleType === 'collect_url_rewrite') {
                    if (topicCountInput) {
                        topicCountInput.value = 1;
                        topicCountInput.readOnly = true;
                        topicCountInput.title = wp.i18n.__('采集网址仿写规则每次仅生成1个主题', 'yali-ai-writer');
                        topicCountInput.style.backgroundColor = '#f0f0f1';
                    }
                } else if (topicCountInput) {
                    topicCountInput.readOnly = false;
                    topicCountInput.title = '';
                    topicCountInput.style.backgroundColor = '';
                }

                // 判断是否需要显示插件提示
                // 1. 如果是采集网址仿写，必须要插件来采集URL内容
                var isCollectUrl = (ruleType === 'collect_url_rewrite');

                // 2. 如果全局开启了知识库搜索，且规则自身没有配置参考资料（因为规则参考资料优先级高于全局），则需要插件
                var needsRag = extensionRagGlobal && !hasRuleReference;

                if (isCollectUrl || needsRag) {
                    extensionNotice.style.display = 'block';
                } else {
                    extensionNotice.style.display = 'none';
                }
            });
        }

        // 全选/取消全选功能
        var selectAllCheckbox = document.getElementById('select_all_tasks');
        var taskCheckboxes = document.querySelectorAll('.task-checkbox');
        var bulkRetryButton = document.querySelector('.bulk-retry-tasks');
        var bulkActionsInfo = document.querySelector('.bulk-actions-info');

        if (selectAllCheckbox && taskCheckboxes.length > 0) {
            // 全选功能
            selectAllCheckbox.addEventListener('change', function() {
                var isChecked = this.checked;
                taskCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = isChecked;
                });
                updateBulkActions();
            });

            // 单个复选框变化时更新全选状态
            taskCheckboxes.forEach(function(checkbox) {
                checkbox.addEventListener('change', updateBulkActions);
            });

            // 更新批量操作按钮状态
            function updateBulkActions() {
                var checkedCount = document.querySelectorAll('.task-checkbox:checked').length;

                if (bulkRetryButton) {
                    bulkRetryButton.disabled = checkedCount === 0;
                }

                if (bulkActionsInfo) {
                    bulkActionsInfo.textContent = checkedCount > 0 ?
                        wp.i18n.__('已选择 %s 个任务', 'yali-ai-writer').replace('%s', checkedCount) : '';
                }
            }

            // 批量重试功能
            if (bulkRetryButton) {
                bulkRetryButton.addEventListener('click', function(e) {
                    e.preventDefault();

                    var checkedBoxes = document.querySelectorAll('.task-checkbox:checked');
                    if (checkedBoxes.length === 0) {
                        alert(wp.i18n.__('请至少选择一个任务进行重试', 'yali-ai-writer'));
                        return;
                    }

                    var confirmMessage = wp.i18n.__('确定要重试选中的 %s 个任务吗？', 'yali-ai-writer').replace('%s', checkedBoxes.length);
                    if (!confirm(confirmMessage)) {
                        return;
                    }

                    var taskIds = Array.from(checkedBoxes).map(function(cb) { return cb.value; });

                    // 显示加载状态，保留原文字，加上半透明效果
                    bulkRetryButton.disabled = true;
                    bulkRetryButton.style.opacity = '0.7';

                    // 发送AJAX请求
                    jQuery.ajax({
                        url: topicJobsData.ajaxUrl || ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'yali_ai_writer_bulk_retry_topic_tasks',
                            task_ids: taskIds,
                            nonce: topicJobsData.nonce || ''
                        },
                        success: function(response) {
                            if (response.success) {
                                alert(wp.i18n.__('批量重试请求已提交，任务将在后台处理。', 'yali-ai-writer'));
                                location.reload();
                            } else {
                                var errorMsg = wp.i18n.__('批量重试失败: ', 'yali-ai-writer') + 
                                    (response.data && response.data.message ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer'));
                                alert(errorMsg);
                            }
                        },
                        error: function() {
                            alert(wp.i18n.__('批量重试失败: 服务器错误', 'yali-ai-writer'));
                        },
                        complete: function() {
                            bulkRetryButton.disabled = false;
                            bulkRetryButton.style.opacity = '';
                        }
                    });
                });
            }
        }
    });

})();
