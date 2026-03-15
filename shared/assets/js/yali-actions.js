/**
 * Yali Actions - 统一操作按钮库
 * 提供通用删除、状态切换、批量操作等功能
 * @version 1.0.0
 */

(function ($) {
    'use strict';

    // 命名空间
    window.YaliActions = window.YaliActions || {};

    // 配置
    var config = {
        // 从 WordPress 本地化脚本获取
        ajaxUrl: window.contentAutoManager ? window.contentAutoManager.ajaxurl : ajaxurl,
        nonce: window.contentAutoManager ? window.contentAutoManager.nonce : '',
        strings: window.yaliActionStrings || {
            confirmDelete: wp.i18n.__('确定要删除吗？此操作不可撤销。', 'yali-ai-writer'),
            deleteSuccess: wp.i18n.__('删除成功', 'yali-ai-writer'),
            deleteFailed: wp.i18n.__('删除失败', 'yali-ai-writer'),
            loading: wp.i18n.__('处理中...', 'yali-ai-writer'),
            serverError: wp.i18n.__('服务器错误', 'yali-ai-writer'),
            networkError: wp.i18n.__('网络请求失败', 'yali-ai-writer'),
            selectItems: wp.i18n.__('请选择要删除的项目', 'yali-ai-writer'),
            itemsCount: wp.i18n.__(' (%d 个项目)', 'yali-ai-writer')
        }
    };

    /**
     * 工具函数：显示通知
     */
    function showNotice(message, type) {
        if (typeof window.yaliToast === 'function') {
            window.yaliToast(message, type);
        } else if (typeof wp !== 'undefined' && wp.i18n) {
            // 回退到 alert
            alert(message);
        } else {
            alert(message);
        }
    }

    /**
     * 工具函数：移除表格行
     */
    function removeRow($button, options) {
        options = options || {};
        var $row = $button.closest('tr');

        if ($row.length === 0) {
            // 如果找不到行，刷新页面
            if (options.reloadOnEmpty !== false) {
                location.reload();
            }
            return;
        }

        // 淡出动画
        $row.fadeOut(400, function () {
            $(this).remove();

            // 检查是否为空表
            var $tbody = $row.closest('tbody');
            if ($tbody.find('tr').length === 0 && options.reloadOnEmpty !== false) {
                location.reload();
            }

            // 执行回调
            if (typeof options.onRemoved === 'function') {
                options.onRemoved();
            }
        });
    }

    /**
     * 通用删除操作
     * @param {Object} options 配置选项
     * @param {string} options.url AJAX URL
     * @param {Object} options.data 请求数据
     * @param {jQuery} options.$button 按钮元素
     * @param {string} options.confirmMsg 确认消息
     * @param {Function} options.onSuccess 成功回调
     * @param {Function} options.onError 错误回调
     */
    YaliActions.delete = function (options) {
        options = $.extend({
            confirmMsg: config.strings.confirmDelete,
            reloadOnEmpty: true,
            loadingText: config.strings.loading,
            rowSelector: 'tr'
        }, options);

        // 确认对话框
        if (!confirm(options.confirmMsg)) {
            return;
        }

        var $button = options.$button;
        var originalText = $button.text();

        // 禁用按钮，但不改变原本的文本
        $button.prop('disabled', true);

        // 如果配置了 loadingText，仅增加 disabled 效果即可，也可根据需求加上透明度：
        $button.css('opacity', '0.7');

        // 从按钮上获取专属 nonce，如果不存在则使用全局 nonce
        var actionNonce = $button.data('yali-nonce') || config.nonce;

        // 发送AJAX请求
        $.ajax({
            url: options.url || config.ajaxUrl,
            type: 'POST',
            data: $.extend({
                nonce: actionNonce
            }, options.data),
            success: function (response) {
                if (response.success) {
                    // 显示成功消息
                    showNotice(
                        response.data.message || config.strings.deleteSuccess,
                        'success'
                    );

                    // 移除行
                    removeRow($button, {
                        reloadOnEmpty: options.reloadOnEmpty,
                        onRemoved: function () {
                            if (typeof options.onSuccess === 'function') {
                                options.onSuccess(response);
                            }
                        }
                    });
                } else {
                    // 显示错误消息
                    var errorMsg = response.data && response.data.message
                        ? response.data.message
                        : config.strings.deleteFailed;
                    showNotice(errorMsg, 'error');

                    // 恢复按钮
                    $button.prop('disabled', false).css('opacity', '');

                    if (typeof options.onError === 'function') {
                        options.onError(response);
                    }
                }
            },
            error: function (xhr, textStatus) {
                showNotice(
                    config.strings.networkError + ': ' + textStatus,
                    'error'
                );

                // 恢复按钮
                $button.prop('disabled', false).css('opacity', '');

                if (typeof options.onError === 'function') {
                    options.onError(xhr);
                }
            }
        });
    };

    /**
     * 批量删除操作
     * @param {Object} options
     * @param {Array} options.ids 要删除的ID数组
     * @param {string} options.url AJAX端点
     * @param {string} options.idParam ID参数名
     * @param {string} options.action AJAX action
     * @param {Function} options.onSuccess 成功回调
     */
    YaliActions.bulkDelete = function (options) {
        options = $.extend({
            idParam: 'ids',
            confirmMsg: config.strings.confirmDelete,
            onSuccess: null
        }, options);

        if (!options.ids || options.ids.length === 0) {
            alert(options.selectItemsMsg || config.strings.selectItems);
            return;
        }

        var confirmMessage = options.confirmMsg;
        if (options.ids.length > 1) {
            confirmMessage += config.strings.itemsCount.replace('%d', options.ids.length);
        }

        if (!confirm(confirmMessage)) {
            return;
        }

        var data = {
            action: options.action,
            nonce: options.nonce || config.nonce
        };
        data[options.idParam] = options.ids;

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: data,
            success: function (response) {
                if (response.success) {
                    showNotice(response.data.message || config.strings.deleteSuccess, 'success');
                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(response);
                    } else {
                        location.reload();
                    }
                } else {
                    showNotice(
                        response.data.message || config.strings.deleteFailed,
                        'error'
                    );
                }
            },
            error: function () {
                showNotice(config.strings.networkError, 'error');
            }
        });
    };

    /**
     * 通用AJAX操作
     * @param {Object} options
     */
    YaliActions.ajax = function (options) {
        options = $.extend({
            type: 'POST',
            dataType: 'json',
            showLoading: true,
            loadingText: config.strings.loading,
            confirmMsg: null,
            successMsg: null,
            errorMsg: null
        }, options);

        // 确认对话框
        if (options.confirmMsg && !confirm(options.confirmMsg)) {
            return;
        }

        var $button = options.$button;
        var originalText = $button ? $button.text() : '';

        // 禁用按钮，仅开启半透明
        if (options.showLoading && $button) {
            $button.prop('disabled', true).css('opacity', '0.7');
        }

        // 构建请求数据
        var actionNonce = options.nonce || ($button ? $button.data('yali-nonce') : null) || config.nonce;
        var requestData = $.extend({
            nonce: actionNonce
        }, options.data);

        $.ajax({
            url: options.url || config.ajaxUrl,
            type: options.type,
            data: requestData,
            dataType: options.dataType,
            success: function (response) {
                // 恢复按钮状态
                if ($button) {
                    $button.prop('disabled', false).css('opacity', '');
                }

                if (response.success) {
                    var successMessage = (response.data && response.data.message) ? response.data.message : options.successMsg;
                    if (successMessage) {
                        showNotice(successMessage, 'success');
                    }
                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(response);
                    }
                } else {
                    var errorMsg = response.data && response.data.message
                        ? response.data.message
                        : (options.errorMsg || config.strings.deleteFailed);
                    showNotice(errorMsg, 'error');

                    if (typeof options.onError === 'function') {
                        options.onError(response);
                    }
                }
            },
            error: function (xhr, textStatus) {
                // 恢复按钮状态
                if ($button) {
                    $button.prop('disabled', false).css('opacity', '');
                }

                showNotice(
                    (options.errorMsg || config.strings.networkError) + ': ' + textStatus,
                    'error'
                );

                if (typeof options.onError === 'function') {
                    options.onError(xhr);
                }
            }
        });
    };

    /**
     * 自动初始化通用的删除按钮
     * 通过 data 属性自动绑定
     */
    YaliActions.init = function () {
        // 通用删除按钮：data-yali-action="delete"
        $(document).on('click.yali.actions', '[data-yali-action="delete"]', function (e) {
            e.preventDefault();

            var $button = $(this);
            var action = $button.data('yali-ajax-action');
            var id = $button.data('yali-id');
            var idParam = $button.data('yali-id-param') || 'id';
            var confirmMsg = $button.data('yali-confirm') || config.strings.confirmDelete;

            if (!action || !id) {
                console.error('YaliActions: Missing required data attributes', {
                    action: action,
                    id: id
                });
                return;
            }

            var data = { action: action };
            data[idParam] = id;

            YaliActions.delete({
                $button: $button,
                data: data,
                confirmMsg: confirmMsg
            });
        });

        // 通用AJAX按钮：data-yali-action="ajax"
        $(document).on('click.yali.actions', '[data-yali-action="ajax"]', function (e) {
            e.preventDefault();

            var $button = $(this);
            var action = $button.data('yali-ajax-action');
            var confirmMsg = $button.data('yali-confirm');
            var successMsg = $button.data('yali-success-msg');
            var actionNonce = $button.data('yali-nonce');
            var reload = $button.data('yali-reload') === true || $button.data('yali-reload') === 'true';

            if (!action) {
                console.error('YaliActions: Missing yali-ajax-action attribute');
                return;
            }

            // 收集额外的 data 属性作为请求参数
            var extraData = {};
            $.each($button.data(), function (key, value) {
                if (key.indexOf('yaliParam') === 0) {
                    var paramName = key.replace('yaliParam', '').toLowerCase();
                    extraData[paramName] = value;
                }
            });

            YaliActions.ajax({
                $button: $button,
                data: $.extend({ action: action }, extraData),
                nonce: actionNonce,
                confirmMsg: confirmMsg,
                successMsg: successMsg,
                onSuccess: function () {
                    if (reload) {
                        setTimeout(function () {
                            location.reload();
                        }, 500);
                    }
                }
            });
        });
    };

    // 自动初始化
    $(document).ready(function () {
        YaliActions.init();
    });

})(jQuery);
