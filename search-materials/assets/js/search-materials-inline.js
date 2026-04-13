jQuery(document).ready(function($) {
    // 标签切换逻辑
    $('.yali-tab-item').on('click', function(e) {
        e.preventDefault();
        var tabId = $(this).data('tab');
        
        $('.yali-tab-item').removeClass('active');
        $(this).addClass('active');
        
        $('.tab-content').hide();
        $('#tab-' + tabId).show();
    });
    
    // =============== 搜索引擎模式逻辑 ===============
    var currentSummary = '';

    function appendLog(logs) {
        if (!logs || !logs.length) return;
        var logHtml = '';
        logs.forEach(function(l) {
            logHtml += l + '<br>';
        });
        var $logDiv = $('#material_log');
        $logDiv.append(logHtml);
        $logDiv.scrollTop($logDiv[0].scrollHeight);
    }

    function doStep(topicId, step, retryCount) {
        retryCount = retryCount || 0;
        var maxRetries = 3;

        $.ajax({
            url: searchMaterialsData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yali_ai_writer_search_material_process',
                topic_id: topicId,
                step: step,
                nonce: searchMaterialsData.nonce
            },
            timeout: 180000, 
            success: function(res) {
                if (res.success) {
                    appendLog(res.data.log);
                    
                    if (res.data.data && res.data.data.summary) {
                        currentSummary = res.data.data.summary;
                        $('#hidden_summary_data').val(currentSummary);
                        
                        var summary = currentSummary;
                        var html = summary
                            .replace(/\n/g, '<br>')
                            .replace(/^# (.*$)/gim, '<h1>$1</h1>')
                            .replace(/^## (.*$)/gim, '<h2>$1</h2>')
                            .replace(/^### (.*$)/gim, '<h3>$1</h3>')
                            .replace(/\*\*(.*)\*\*/gim, '<b>$1</b>');

                        $('#material_summary').html(html);
                        $('#btn_save_material').show();
                    }

                    if (res.data.next_step && res.data.next_step !== 'done') {
                        doStep(topicId, res.data.next_step, 0);
                    } else {
                        $('#material_spinner').removeClass('is-active');
                        $('#btn_start_material').prop('disabled', false).css('opacity', '');
                        appendLog(['<strong>====== 流程全部结束 ======</strong>']);
                    }
                } else {
                    var msg = res.data ? res.data.message : (res.message || 'Unknown Error');
                    appendLog(res.data && res.data.log ? res.data.log : []);
                    appendLog(['<span style="color:red; font-weight:bold;">错误中断: ' + msg + '</span>']);
                    $('#material_spinner').removeClass('is-active');
                    $('#btn_start_material').prop('disabled', false).css('opacity', '');
                }
            },
            error: function(xhr, status, error) {
                if (retryCount < maxRetries) {
                    var nextRetry = retryCount + 1;
                    appendLog(['<span style="color:#d63638;">请求失败 (' + status + ')，2秒后自动重试 (' + nextRetry + '/' + maxRetries + ')...</span>']);
                    setTimeout(function() {
                        doStep(topicId, step, nextRetry);
                    }, 2000);
                    return;
                }

                $('#material_spinner').removeClass('is-active');
                $('#btn_start_material').prop('disabled', false);
                var errorMsg = error;
                if (status === 'timeout') {
                    errorMsg = '请求超时 (超过180秒)。任务可能仍在后台运行，请刷新页面查看进度或重试。';
                } else if (xhr.responseText) {
                    var match = xhr.responseText.match(/<b>Fatal error<\/b>:(.*?)<br/);
                    if (match) errorMsg = 'PHP错误: ' + match[1];
                }
                appendLog(['<span style="color:red;">❌ 网络请求最终失败: ' + errorMsg + '</span>']);
                console.error('Task failed:', status, error, xhr.responseText);
            }
        });
    }

    $('#btn_start_material').on('click', function() {
        var topicId = $('#topic_id').val();
        if (!topicId) {
            alert(wp.i18n.__('请输入主题ID', 'yali-ai-writer'));
            return;
        }
        
        $('#material_result_area').show();
        $('#btn_save_material').hide();
        $('#material_log').html('');
        $('#material_summary').html('<p style="color:#666;">' + wp.i18n.__('等待处理完成...', 'yali-ai-writer') + '</p>');
        $('#material_spinner').addClass('is-active');
        $(this).prop('disabled', true).css('opacity', '0.7');
        
        appendLog(['<strong>' + wp.i18n.__('开始执行任务...', 'yali-ai-writer') + '</strong>']);
        doStep(topicId, 'init');
    });

    $('#btn_save_material').on('click', function() {
        var topicId = $('#topic_id').val();
        var summary = $('#hidden_summary_data').val();
        
        if (!topicId || !summary) {
            alert(wp.i18n.__('数据也不完整，无法保存', 'yali-ai-writer'));
            return;
        }

        if (!confirm(wp.i18n.__('确定要将此内容保存为该主题的参考资料吗？原有资料将被覆盖。', 'yali-ai-writer'))) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(wp.i18n.__('正在保存...', 'yali-ai-writer'));

        $.ajax({
            url: searchMaterialsData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yali_ai_writer_save_material_result',
                topic_id: topicId,
                summary: summary,
                nonce: searchMaterialsData.nonce
            },
            success: function(res) {
                $btn.prop('disabled', false).text(wp.i18n.__('保存为主题参考资料', 'yali-ai-writer'));
                if (res.success) {
                    alert(wp.i18n.__('保存成功！', 'yali-ai-writer'));
                } else {
                    alert(wp.i18n.__('保存失败: ', 'yali-ai-writer') + (res.data.message || res.message || wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(wp.i18n.__('保存为主题参考资料', 'yali-ai-writer'));
                alert(wp.i18n.__('网络错误，保存失败', 'yali-ai-writer'));
            }
        });
    });
    
    // =============== 浏览器插件模式逻辑 ===============
    var extSummary = '';
    var pollInterval = null;
    
    function updateExtStatus(status, type) {
        var icons = {
            'pending': '⏳',
            'processing': '🔄',
            'success': '✅',
            'error': '❌'
        };
        var colors = {
            'pending': '#666',
            'processing': '#0073aa',
            'success': '#46b450',
            'error': '#dc3232'
        };
        $('#ext_status').html('<p style="margin: 0; color: ' + colors[type] + ';">' + icons[type] + ' ' + status + '</p>');
    }
    
    function startExtensionTask(topicId) {
        $.ajax({
            url: searchMaterialsData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yali_ai_writer_extension_material_process',
                topic_id: topicId,
                is_test: true, // Enable Test Mode
                nonce: searchMaterialsData.nonce
            },
            success: function(res) {
                if (res.success) {
                    var taskId = res.data.task_id; // Capture Task ID
                    updateExtStatus(wp.i18n.__('任务已分发 (ID: ', 'yali-ai-writer') + taskId.substring(0,8) + ')，' + wp.i18n.__('等待浏览器插件响应...', 'yali-ai-writer'), 'pending');
                    
                    // 开始轮询检查结果
                    pollInterval = setInterval(function() {
                        checkExtensionResult(taskId); // Pass Task ID instead of Topic ID
                    }, 3000);
                    
                    // 3分钟超时
                    setTimeout(function() {
                        if (pollInterval) {
                            clearInterval(pollInterval);
                            pollInterval = null; // Mark explicitly as stopped
                            updateExtStatus(wp.i18n.__('请求超时。请确保浏览器插件已运行并连接。', 'yali-ai-writer'), 'error');
                            $('#ext_spinner').removeClass('is-active');
                            $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                        }
                    }, 180000);
                } else {
                    updateExtStatus(wp.i18n.__('任务分发失败', 'yali-ai-writer') + ': ' + (res.data.message || wp.i18n.__('未知错误', 'yali-ai-writer')), 'error');
                    $('#ext_spinner').removeClass('is-active');
                    $('#btn_start_extension').prop('disabled', false);
                }
            },
            error: function() {
                updateExtStatus(wp.i18n.__('网络请求失败', 'yali-ai-writer'), 'error');
                $('#ext_spinner').removeClass('is-active');
                $('#btn_start_extension').prop('disabled', false);
            }
        });
    }
    
    function checkExtensionResult(topicId) {
        $.ajax({
            url: searchMaterialsData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yali_ai_writer_check_task_result', // Use new check action
                task_id: topicId, // variable name is topicId but parameter is task_id (passed from caller)
                nonce: searchMaterialsData.nonce
            },
            success: function(res) {
                // Guard: If polling stopped (e.g. timeout occurred), ignore this late response
                if (!pollInterval) return;

                if (res.success && res.data.status === 'completed') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    
                    extSummary = res.data.result || '';
                    $('#hidden_ext_summary_data').val(extSummary);
                    
                    var html = extSummary
                        .replace(/\n/g, '<br>')
                        .replace(/^# (.*$)/gim, '<h1>$1</h1>')
                        .replace(/^## (.*$)/gim, '<h2>$1</h2>')
                        .replace(/^### (.*$)/gim, '<h3>$1</h3>')
                        .replace(/\*\*(.*)\*\*/gim, '<b>$1</b>');
                    
                    $('#ext_summary').html(html);
                    $('#btn_save_ext_material').show();
                    
                    updateExtStatus(wp.i18n.__('知识库搜索完成', 'yali-ai-writer'), 'success');
                    $('#ext_spinner').removeClass('is-active');
                    $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                } else if (res.data && res.data.status === 'waiting_for_extension') {
                    updateExtStatus(wp.i18n.__('等待浏览器插件处理中...', 'yali-ai-writer'), 'processing');
                } else if (res.data && res.data.status === 'failed') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    var errMsg = res.data.error || wp.i18n.__('未知错误', 'yali-ai-writer');
                    updateExtStatus(wp.i18n.__('搜索失败', 'yali-ai-writer') + ': ' + errMsg, 'error');
                    $('#ext_spinner').removeClass('is-active');
                    $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                }
            }
        });
    }
    
    $('#btn_start_extension').on('click', function() {
        var topicId = $('#ext_topic_id').val();
        if (!topicId) {
            alert(wp.i18n.__('请输入主题ID', 'yali-ai-writer'));
            return;
        }

        $('#ext_result_area').show();
        $('#btn_save_ext_material').hide();
        $('#ext_summary').html('<p style="color:#666;">' + wp.i18n.__('正在知识库中搜索...', 'yali-ai-writer') + '</p>');
        $('#ext_spinner').addClass('is-active');
        $(this).prop('disabled', true).css('opacity', '0.7');
        
        updateExtStatus(wp.i18n.__('正在连接浏览器插件...', 'yali-ai-writer'), 'processing');
        startExtensionTask(topicId);
    });
    
    $('#btn_save_ext_material').on('click', function() {
        var topicId = $('#ext_topic_id').val();
        var summary = $('#hidden_ext_summary_data').val();
        
        if (!topicId || !summary) {
            alert(wp.i18n.__('数据不完整，无法保存', 'yali-ai-writer'));
            return;
        }

        if (!confirm(wp.i18n.__('确定要将此内容保存为该主题的参考资料吗？原有资料将被覆盖。', 'yali-ai-writer'))) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(wp.i18n.__('正在保存...', 'yali-ai-writer'));

        $.ajax({
            url: searchMaterialsData.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'yali_ai_writer_save_material_result',
                topic_id: topicId,
                summary: summary,
                nonce: searchMaterialsData.nonce
            },
            success: function(res) {
                $btn.prop('disabled', false).text(wp.i18n.__('保存为主题参考资料', 'yali-ai-writer'));
                if (res.success) {
                    alert(wp.i18n.__('保存成功！', 'yali-ai-writer'));
                } else {
                    alert(wp.i18n.__('保存失败: ', 'yali-ai-writer') + (res.data.message || res.message || wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(wp.i18n.__('保存为主题参考资料', 'yali-ai-writer'));
                alert(wp.i18n.__('网络错误，保存失败', 'yali-ai-writer'));
            }
        });
    });
});
