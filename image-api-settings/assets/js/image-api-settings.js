jQuery(document).ready(function ($) {
    // Debug: Check if contentAutoManager object is available
    if (typeof contentAutoManager === 'undefined') {
        console.error('contentAutoManager object is not loaded!');
        return;
    }

    // Check for success notice on page load and trigger Toast
    if ($('.yali-notice-success').length > 0) {
        if (typeof window.yaliToast === 'function') {
            window.yaliToast(wp.i18n.__('设置已保存', 'yali-ai-writer'), 'success');
        }
    }

    // Tab switching logic
    const tabs = $('.yali-tabs .yali-tab-item');
    const tabContents = $('.yali-tab-content');
    const activeProviderInput = $('#cam_image_api_provider');

    function activateTab(tab) {
        const target = $(tab).attr('href');
        tabs.removeClass('active');
        tabContents.removeClass('active');
        $(tab).addClass('active');
        $(target).addClass('active');
        const provider = target.replace('#', '').replace('_settings', '');
        activeProviderInput.val(provider);
    }

    tabs.on('click', function (e) {
        e.preventDefault();
        activateTab(this);
    });

    const initialProvider = activeProviderInput.val() || 'modelscope';
    let initialTab = $('.yali-tabs .yali-tab-item[href="#' + initialProvider + '_settings"]');
    if (initialTab.length === 0) {
        initialTab = tabs.first();
    }
    activateTab(initialTab);

    activateTab(initialTab);

    // AJAX Save is now handled by the universal handler in admin.js


    // Remove legacy detection logic since we don't reload anymore, 
    // but keep it for one version just in case of cached old JS + new PHP edge cases
    if ($('.yali-notice-success').length > 0) {
        $('.yali-notice-success').hide();
    }

    // --- ModelScope Async Test Logic ---
    let modelscopePollInterval;
    let modelscopePollTimeout;

    function stopModelScopePolling() {
        clearInterval(modelscopePollInterval);
        clearTimeout(modelscopePollTimeout);
    }

    function pollModelScopeTask(taskId, config, resultDiv) {
        const maxPollTime = 300000; // 5 minutes (increased to handle longer processing times during peak load)

        // Stop polling after timeout
        modelscopePollTimeout = setTimeout(function () {
            stopModelScopePolling();
            resultDiv.html('<p style="color: orange;"><strong>' + wp.i18n.__('测试状态:', 'yali-ai-writer') + '</strong> ' + wp.i18n.__('轮询超时 (5分钟)，任务可能仍在处理中。您可以稍后手动检查任务状态或尝试使用处理速度更快的模型。', 'yali-ai-writer') + '</p>');
        }, maxPollTime);

        // Poll immediately, then set interval
        checkStatus();
        modelscopePollInterval = setInterval(checkStatus, 5000);

        function checkStatus() {
            $.ajax({
                url: contentAutoManager.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cam_modelscope_check_task',
                    nonce: contentAutoManager.nonce,
                    task_id: taskId,
                    config: config
                },
                success: function (response) {
                    if (!response.success) {
                        stopModelScopePolling();
                        resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('检查任务状态失败:', 'yali-ai-writer') + '</strong> ' + response.data.message + '</p>');
                        return;
                    }

                    const task = response.data.task;
                    switch (task.task_status) {
                        case 'SUCCEED':
                            stopModelScopePolling();
                            if (task.base64_image) {
                                const img = '<img src="data:image/jpeg;base64,' + task.base64_image + '" style="max-width: 100%; height: auto; margin-top: 10px;">';
                                resultDiv.html(img);
                            } else {
                                resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('测试成功但未返回图像:', 'yali-ai-writer') + '</strong> ' + (task.message || '') + '</p>');
                            }
                            break;
                        case 'FAILED':
                            stopModelScopePolling();
                            resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('生成失败:', 'yali-ai-writer') + '</strong> ' + (task.message || wp.i18n.__('未知错误', 'yali-ai-writer')) + '</p>');
                            break;
                        case 'PENDING':
                        case 'RUNNING':
                        case 'PROCESSING':
                            // 显示时间戳，让用户知道仍在轮询中
                            const now = new Date();
                            const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0') + ':' + now.getSeconds().toString().padStart(2, '0');
                            resultDiv.find('.cam-test-status').text(wp.i18n.__('状态: ', 'yali-ai-writer') + task.task_status + '... (' + wp.i18n.__('最后更新: ', 'yali-ai-writer') + timeStr + ')');
                            break;
                        default:
                            stopModelScopePolling();
                            resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('未知任务状态:', 'yali-ai-writer') + '</strong> ' + task.task_status + '</p>');
                            break;
                    }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    stopModelScopePolling();
                    let errMsg = textStatus + ' - ' + errorThrown;
                    if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        errMsg = jqXHR.responseJSON.data.message;
                    }
                    resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('轮询请求失败:', 'yali-ai-writer') + '</strong> ' + errMsg + '</p>');
                }
            });
        }
    }

    // Use event delegation to handle dynamically hidden/showed buttons
    $(document).on('click', '#test_api_button_modelscope', function () {
        stopModelScopePolling(); // Stop any previous polling
        const resultDiv = $('#modelscope_test_result');
        const prompt = $('#modelscope_test_prompt').val();
        const config = {
            model_id: $('#modelscope_model_id').val(),
            api_key: $('#modelscope_api_key').val()
        };

        if (!prompt) {
            resultDiv.html('<p style="color: red;">' + wp.i18n.__('请输入测试提示词。', 'yali-ai-writer') + '</p>');
            return;
        }

        resultDiv.html('<p>✅ ' + wp.i18n.__('任务已提交，正在等待结果... ', 'yali-ai-writer') + '<span class="cam-test-status">' + wp.i18n.__('状态: ', 'yali-ai-writer') + 'SUBMITTED</span></p><p style="font-size: 12px; color: #666;">' + wp.i18n.__('注意: 某些模型可能需要较长时间处理，请耐心等待...', 'yali-ai-writer') + '</p><span class="spinner is-active" style="float: none; margin-top: 5px;"></span>');

        $.ajax({
            url: contentAutoManager.ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_modelscope_start_task',
                nonce: contentAutoManager.nonce,
                config: config,
                prompt: prompt
            },
            success: function (response) {
                if (response.success) {
                    pollModelScopeTask(response.data.task_id, config, resultDiv);
                } else {
                    resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('提交任务失败:', 'yali-ai-writer') + '</strong> ' + response.data.message + '</p>');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                let errMsg = textStatus + ' - ' + errorThrown;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errMsg = jqXHR.responseJSON.data.message;
                }
                resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('提交任务的AJAX请求失败:', 'yali-ai-writer') + '</strong> ' + errMsg + '</p>');
            }
        });
    });

    // --- Synchronous Test Logic (OpenAI, Silicon Flow, Pollinations, Volcengine) ---
    // Use event delegation for other test buttons as well
    $(document).on('click', '#test_api_button_openai, #test_api_button_siliconflow, #test_api_button_pollinations, #test_api_button_volcengine', function () {
        const provider = $(this).data('provider');
        const resultDiv = $('#' + provider + '_test_result');
        const prompt = $('#' + provider + '_test_prompt').val();
        let config = {};

        if (provider === 'openai') {
            config.model = $('#openai_model').val();
            config.api_key = $('#openai_api_key').val();
        } else if (provider === 'siliconflow') {
            config.model = $('#siliconflow_model').val();
            config.api_key = $('#siliconflow_api_key').val();
        } else if (provider === 'pollinations') {
            config.model = $('#pollinations_default_model').val();
            config.token = $('#pollinations_token').val();
        } else if (provider === 'volcengine') {
            config.model = $('#volcengine_model').val();
            config.api_key = $('#volcengine_api_key').val();
        } else if (provider === 'custom') {
            config.base_url = $('#custom_base_url').val();
            config.model = $('#custom_model').val();
            config.api_key = $('#custom_api_key').val();
        }

        if (!prompt) {
            resultDiv.html('<p style="color: red;">' + wp.i18n.__('请输入测试提示词。', 'yali-ai-writer') + '</p>');
            return;
        }

        resultDiv.html('<p>' + wp.i18n.__('正在生成图像，请稍候...', 'yali-ai-writer') + '</p><span class="spinner is-active" style="float: none; margin-top: 5px;"></span>');

        $.ajax({
            url: contentAutoManager.ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_test_image_api',
                nonce: contentAutoManager.nonce,
                provider: provider,
                config: config,
                prompt: prompt
            },
            success: function (response) {
                if (response.success) {
                    const img = '<img src="data:image/jpeg;base64,' + response.data.base64_image + '" style="max-width: 100%; height: auto; margin-top: 10px;">';
                    resultDiv.html(img);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : wp.i18n.__('发生未知错误。', 'yali-ai-writer');
                    resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('测试失败:', 'yali-ai-writer') + '</strong> ' + errorMsg + '</p>');
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                let errMsg = textStatus + ' - ' + errorThrown;
                if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    errMsg = jqXHR.responseJSON.data.message;
                }
                resultDiv.html('<p style="color: red;"><strong>' + wp.i18n.__('AJAX 请求失败:', 'yali-ai-writer') + '</strong> ' + errMsg + '</p>');
            }
        });
    });

    // --- Pollinations Image Models Sync Logic ---
    $(document).on('click', '#refresh-pollinations-image-models', function () {
        const refreshBtn = $(this);
        const modelSelect = $('#pollinations_default_model');
        const statusSpan = $('#pollinations-model-refresh-status');
        const currentSelected = modelSelect.val();

        refreshBtn.prop('disabled', true);
        statusSpan.show();

        $.ajax({
            url: contentAutoManager.ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_fetch_pollinations_image_models',
                nonce: contentAutoManager.nonce
            },
            success: function (response) {
                if (response.success && response.data.models) {
                    modelSelect.empty();

                    // 分离免费和付费模型
                    const freeModels = response.data.models.filter(function (m) { return m.is_free; });
                    const paidModels = response.data.models.filter(function (m) { return m.is_paid; });

                    // 添加免费模型分组
                    if (freeModels.length > 0) {
                        const freeGroup = $('<optgroup>').attr('label', '🔒 ' + wp.i18n.__('免费模型', 'yali-ai-writer') + ' (' + freeModels.length + ')');
                        freeModels.forEach(function (m) {
                            const opt = $('<option>').val(m.id).text(m.id + ' - ' + m.description);
                            if (m.id === currentSelected) {
                                opt.prop('selected', true);
                            }
                            freeGroup.append(opt);
                        });
                        modelSelect.append(freeGroup);
                    }

                    // 添加付费模型分组
                    if (paidModels.length > 0) {
                        const paidGroup = $('<optgroup>').attr('label', '💎 ' + wp.i18n.__('付费模型', 'yali-ai-writer') + ' (' + paidModels.length + ')');
                        paidModels.forEach(function (m) {
                            const opt = $('<option>').val(m.id).text(m.id + ' - ' + m.description);
                            if (m.id === currentSelected) {
                                opt.prop('selected', true);
                            }
                            paidGroup.append(opt);
                        });
                        modelSelect.append(paidGroup);
                    }

                    alert('✨ ' + wp.i18n.__('图像模型同步成功！', 'yali-ai-writer') + '\n' +
                        wp.i18n.__('免费模型:', 'yali-ai-writer') + ' ' + freeModels.length + '\n' +
                        wp.i18n.__('付费模型:', 'yali-ai-writer') + ' ' + paidModels.length);
                } else {
                    alert(wp.i18n.__('❌ 获取失败:', 'yali-ai-writer') + ' ' +
                        (response.data ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
            },
            error: function () {
                alert(wp.i18n.__('网络连接错误，请稍后再试。', 'yali-ai-writer'));
            },
            complete: function () {
                refreshBtn.prop('disabled', false);
                statusSpan.hide();
            }
        });
    });
});
