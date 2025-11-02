jQuery(document).ready(function($) {
    // Debug: Check if contentAutoManager object is available
    if (typeof contentAutoManager === 'undefined') {
        console.error('contentAutoManager object is not loaded!');
        return;
    }

    // Tab switching logic
    const tabs = $('.nav-tab-wrapper .nav-tab');
    const tabContents = $('.tab-content');
    const activeProviderInput = $('#cam_image_api_provider');

    function activateTab(tab) {
        const target = $(tab).attr('href');
        tabs.removeClass('nav-tab-active');
        tabContents.removeClass('active');
        $(tab).addClass('nav-tab-active');
        $(target).addClass('active');
        const provider = target.replace('#', '').replace('_settings', '');
        activeProviderInput.val(provider);
    }

    tabs.on('click', function(e) {
        e.preventDefault();
        activateTab(this);
    });

    const initialProvider = activeProviderInput.val() || 'modelscope';
    let initialTab = $('.nav-tab-wrapper .nav-tab[href="#' + initialProvider + '_settings"]');
    if (initialTab.length === 0) {
        initialTab = tabs.first();
    }
    activateTab(initialTab);

    // --- ModelScope Async Test Logic ---
    let modelscopePollInterval;
    let modelscopePollTimeout;

    function stopModelScopePolling() {
        clearInterval(modelscopePollInterval);
        clearTimeout(modelscopePollTimeout);
    }

    function pollModelScopeTask(taskId, config, resultDiv) {
        const maxPollTime = 120000; // 2 minutes

        // Stop polling after timeout
        modelscopePollTimeout = setTimeout(function() {
            stopModelScopePolling();
            resultDiv.html('<p style="color: red;"><strong>测试失败:</strong> 轮询超时 (2分钟)。</p>');
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
                success: function(response) {
                    if (!response.success) {
                        stopModelScopePolling();
                        resultDiv.html('<p style="color: red;"><strong>检查任务状态失败:</strong> ' + response.data.message + '</p>');
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
                                resultDiv.html('<p style="color: red;"><strong>测试成功但未返回图像:</strong> ' + (task.message || '') + '</p>');
                            }
                            break;
                        case 'FAILED':
                            stopModelScopePolling();
                            resultDiv.html('<p style="color: red;"><strong>生成失败:</strong> ' + (task.message || '未知错误') + '</p>');
                            break;
                        case 'PENDING':
                        case 'RUNNING':
                        case 'PROCESSING':
                            resultDiv.find('.cam-test-status').text('状态: ' + task.task_status + '...');
                            break;
                        default:
                            stopModelScopePolling();
                            resultDiv.html('<p style="color: red;"><strong>未知任务状态:</strong> ' + task.task_status + '</p>');
                            break;
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    stopModelScopePolling();
                    resultDiv.html('<p style="color: red;"><strong>轮询请求失败:</strong> ' + textStatus + ' - ' + errorThrown + '</p>');
                }
            });
        }
    }

    // Use event delegation to handle dynamically hidden/showed buttons
    $(document).on('click', '#test_api_button_modelscope', function() {
        stopModelScopePolling(); // Stop any previous polling
        const resultDiv = $('#modelscope_test_result');
        const prompt = $('#modelscope_test_prompt').val();
        const config = {
            model_id: $('#modelscope_model_id').val(),
            api_key: $('#modelscope_api_key').val()
        };

        if (!prompt) {
            resultDiv.html('<p style="color: red;">请输入测试提示词。</p>');
            return;
        }

        resultDiv.html('<p>✅ 任务已提交，正在等待结果... <span class="cam-test-status"></span></p><span class="spinner is-active" style="float: none; margin-top: 5px;"></span>');

        $.ajax({
            url: contentAutoManager.ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_modelscope_start_task',
                nonce: contentAutoManager.nonce,
                config: config,
                prompt: prompt
            },
            success: function(response) {
                if (response.success) {
                    pollModelScopeTask(response.data.task_id, config, resultDiv);
                } else {
                    resultDiv.html('<p style="color: red;"><strong>提交任务失败:</strong> ' + response.data.message + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                resultDiv.html('<p style="color: red;"><strong>提交任务的AJAX请求失败:</strong> ' + textStatus + ' - ' + errorThrown + '</p>');
            }
        });
    });

    // --- Synchronous Test Logic (OpenAI, Silicon Flow, Pollinations) ---
    // Use event delegation for other test buttons as well
    $(document).on('click', '#test_api_button_openai, #test_api_button_siliconflow, #test_api_button_pollinations', function() {
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
        }

        if (!prompt) {
            resultDiv.html('<p style="color: red;">请输入测试提示词。</p>');
            return;
        }

        resultDiv.html('<p>正在生成图像，请稍候...</p><span class="spinner is-active" style="float: none; margin-top: 5px;"></span>');

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
            success: function(response) {
                if (response.success) {
                    const img = '<img src="data:image/jpeg;base64,' + response.data.base64_image + '" style="max-width: 100%; height: auto; margin-top: 10px;">';
                    resultDiv.html(img);
                } else {
                    const errorMsg = response.data && response.data.message ? response.data.message : '发生未知错误。';
                    resultDiv.html('<p style="color: red;"><strong>测试失败:</strong> ' + errorMsg + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                resultDiv.html('<p style="color: red;"><strong>AJAX 请求失败:</strong> ' + textStatus + ' - ' + errorThrown + '</p>');
            }
        });
    });
});
