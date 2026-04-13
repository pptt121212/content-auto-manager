jQuery(document).ready(function ($) {
    // 网址内容采集功能
    $('#fetch_content_btn').on('click', function () {
        var url = $('#content_url').val().trim();
        var button = $(this);
        var statusDiv = $('#fetch_status');
        var textArea = $('#upload_text_content');

        // 验证网址
        if (!url) {
            statusDiv.text(wp.i18n.__('请输入网址', 'yali-ai-writer')).css('color', 'red');
            return;
        }

        if (!isValidUrl(url)) {
            statusDiv.text(wp.i18n.__('请输入有效的网址', 'yali-ai-writer')).css('color', 'red');
            return;
        }

        // 显示加载状态
        button.prop('disabled', true).css('opacity', '0.7');
        statusDiv.text(wp.i18n.__('正在采集内容，请稍候...', 'yali-ai-writer')).css('color', '#666');

        // 发送AJAX请求
        $.ajax({
            url: contentAutoManager.ajaxurl, // Use localized ajaxurl
            type: 'POST',
            data: {
                action: 'yali_ai_writer_fetch_url_content',
                url: url,
                nonce: contentAutoManager.nonce
            },
            success: function (response) {
                if (response.success) {
                    var content = response.data.content;
                    if (content) {
                        // 填充完整内容到文本框（不再自动截取，让用户自由编辑删减）
                        textArea.val(content);
                        updateTextCount();
                        var charCount = mb_strlen(content);
                        if (charCount > 3000) {
                            statusDiv.text(wp.i18n.__('内容采集成功！共', 'yali-ai-writer') + charCount + wp.i18n.__('个字符，请删减至3000字符以内再保存', 'yali-ai-writer')).css('color', 'orange');
                        } else {
                            statusDiv.text(wp.i18n.__('内容采集成功！共', 'yali-ai-writer') + charCount + wp.i18n.__('个字符', 'yali-ai-writer')).css('color', 'green');
                        }
                    } else {
                        statusDiv.text(wp.i18n.__('采集的内容为空', 'yali-ai-writer')).css('color', 'orange');
                    }
                } else {
                    statusDiv.text(wp.i18n.__('采集失败：', 'yali-ai-writer') + response.data.message).css('color', 'red');
                }
            },
            error: function () {
                statusDiv.text(wp.i18n.__('采集失败：网络错误', 'yali-ai-writer')).css('color', 'red');
            },
            complete: function () {
                // 恢复按钮状态
                button.prop('disabled', false).css('opacity', '');
            }
        });
    });

    // 文本计数功能
    function updateTextCount() {
        var $input = $('#upload_text_content');
        if ($input.length === 0) return;

        var content = $input.val() || ''; // Ensure it's never undefined
        var count = mb_strlen(content);
        $('#current-count').text(count);

        // 超过限制时显示警告
        if (count > 3000) {
            $('#current-count').css('color', '#d63638');
            $('#char-limit-warning').show();
        } else {
            $('#current-count').css('color', 'inherit');
            $('#char-limit-warning').hide();
        }
    }

    // 监听文本框变化
    $('#upload_text_content').on('input keyup paste', function () {
        updateTextCount();
    });

    // 页面加载时初始化计数
    updateTextCount();

    // 验证URL格式
    function isValidUrl(string) {
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }

    // 计算字符长度（与浏览器 maxlength 保持一致，每个字符都算1个）
    function mb_strlen(str) {
        return str.length;
    }

    // --- AJAX Form Submission for Rules ---
    var ruleForm = $('#add-rule-form');
    if (ruleForm.length > 0) {
        ruleForm.on('submit', function (e) {
            e.preventDefault();

            var form = $(this);
            // Robust selector: try input first, then class
            var submitBtn = form.find('input[type="submit"]');
            if (submitBtn.length === 0) {
                submitBtn = form.find('.yali-btn-primary');
            }

            var originalBtnText = submitBtn.is('input') ? submitBtn.val() : submitBtn.text();

            // Safety check
            if (typeof contentAutoManager === 'undefined') {
                console.error('contentAutoManager is undefined');
                alert(wp.i18n.__('系统错误: 缺少必要组件 (contentAutoManager)，请刷新页面重试。', 'yali-ai-writer'));
                return;
            }

            // 检查文本内容字符数限制（仅在选择了上传文本规则类型时）
            var ruleType = form.find('input[name="rule_type"]:checked').val();
            if (ruleType === 'upload_text') {
                var textContent = $('#upload_text_content').val() || '';
                var charCount = mb_strlen(textContent);
                if (charCount > 3000) {
                    var errorMsg = wp.i18n.__('文本内容超出限制：当前', 'yali-ai-writer') + charCount + wp.i18n.__('个字符，最多允许3000个字符。请删减后再保存。', 'yali-ai-writer');
                    try {
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(errorMsg, 'error');
                        } else {
                            alert(errorMsg);
                        }
                    } catch (e) {
                        alert(errorMsg);
                    }
                    return; // 阻止提交
                }
            }

            // Disable button and show loading state
            // Use opacity for visual feedback if 'disabled' hides it in some themes
            submitBtn.prop('disabled', true).css('opacity', '0.7');

            // Collect form data
            var formData = form.serialize();
            formData += '&action=cam_save_rule'; // Add action for AJAX handler

            // Send AJAX request
            $.ajax({
                url: contentAutoManager.ajaxurl,
                type: 'POST',
                data: formData,
                success: function (response) {
                    if (response.success) {
                        // Success Feedback
                        try {
                            if (typeof window.yaliToast === 'function') {
                                window.yaliToast(response.data.message || wp.i18n.__('操作成功', 'yali-ai-writer'), 'success');
                            } else {
                                console.warn('yaliToast not found, falling back to alert');
                                // Only alert if strictly necessary, or reliance on toast is critical
                            }
                        } catch (err) {
                            console.error('Toast notification failed:', err);
                        }

                        // Differential Redirect Logic
                        // UPDATE: User requested redirect for both create and edit

                        // Delay redirect for user to see the toast
                        setTimeout(function () {
                            if (response.data.redirect_url) {
                                window.location.href = response.data.redirect_url;
                            } else {
                                // Fallback if no redirect_url (though backend sends it)
                                // For edit mode, we might want to reload or go to list.
                                // Backend sends redirect_url for both now.
                                if (response.data.type === 'update') {
                                    // If backend didn't send redirect_url for update (it should), go to list
                                    window.location.href = 'admin.php?page=yali-ai-writer-rules&message=3';
                                } else {
                                    console.error('No redirect URL provided');
                                    alert(wp.i18n.__('操作成功，但无法自动跳转。请手动刷新页面。', 'yali-ai-writer'));
                                    window.location.reload();
                                }
                            }
                        }, 1000);
                    } else {
                        // Error Handing (Business Logic Error)
                        var errorMsg = response.data ? (response.data.message || wp.i18n.__('未知错误', 'yali-ai-writer')) : wp.i18n.__('未知错误', 'yali-ai-writer');
                        try {
                            if (typeof window.yaliToast === 'function') {
                                window.yaliToast(errorMsg, 'error');
                            } else {
                                alert(wp.i18n.__('错误: ', 'yali-ai-writer') + errorMsg);
                            }
                        } catch (e) {
                            alert(wp.i18n.__('错误: ', 'yali-ai-writer') + errorMsg);
                        }

                        submitBtn.prop('disabled', false).css('opacity', '');
                    }
                },
                error: function (xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    var msg = wp.i18n.__('系统错误: ', 'yali-ai-writer') + error;
                    if (status === 'timeout') msg = wp.i18n.__('请求超时，请检查网络或稍后重试', 'yali-ai-writer');
                    if (status === 'parsererror') msg = wp.i18n.__('服务器响应格式错误', 'yali-ai-writer');

                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        msg = xhr.responseJSON.data.message;
                    }

                    try {
                        if (typeof window.yaliToast === 'function') {
                            window.yaliToast(msg, 'error');
                        } else {
                            alert(msg);
                        }
                    } catch (e) {
                        alert(msg);
                    }

                    submitBtn.prop('disabled', false).css('opacity', '');
                }
            });
        });
    }

});