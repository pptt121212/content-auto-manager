jQuery(document).ready(function($) {
    // 网址内容采集功能
    $('#fetch_content_btn').on('click', function() {
        var url = $('#content_url').val().trim();
        var button = $(this);
        var statusDiv = $('#fetch_status');
        var textArea = $('#upload_text_content');

        // 验证网址
        if (!url) {
            statusDiv.text('请输入网址').css('color', 'red');
            return;
        }

        if (!isValidUrl(url)) {
            statusDiv.text('请输入有效的网址').css('color', 'red');
            return;
        }

        // 显示加载状态
        button.prop('disabled', true).text('采集中...');
        statusDiv.text('正在采集内容，请稍候...').css('color', '#666');

        // 发送AJAX请求
        $.ajax({
            url: contentAutoManager.ajaxurl, // Use localized ajaxurl
            type: 'POST',
            data: {
                action: 'content_auto_fetch_url_content',
                url: url,
                nonce: contentAutoManager.nonce
            },
            success: function(response) {
                if (response.success) {
                    var content = response.data.content;
                    if (content) {
                        // 填充到文本框（最多3000字符）
                        var truncatedContent = content.substring(0, 3000);
                        textArea.val(truncatedContent);
                        updateTextCount();
                        statusDiv.text('内容采集成功！已截取前3000个字符').css('color', 'green');
                    } else {
                        statusDiv.text('采集的内容为空').css('color', 'orange');
                    }
                } else {
                    statusDiv.text('采集失败：' + response.data.message).css('color', 'red');
                }
            },
            error: function() {
                statusDiv.text('采集失败：网络错误').css('color', 'red');
            },
            complete: function() {
                // 恢复按钮状态
                button.prop('disabled', false).text('采集内容');
            }
        });
    });

    // 文本计数功能
    function updateTextCount() {
        var content = $('#upload_text_content').val();
        var count = mb_strlen(content);
        $('#current-count').text(count);

        // 超过限制时显示警告颜色
        if (count > 3000) {
            $('#current-count').css('color', 'red');
        } else {
            $('#current-count').css('color', 'inherit');
        }
    }

    // 监听文本框变化
    $('#upload_text_content').on('input keyup paste', function() {
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

    // 计算中文字符长度
    function mb_strlen(str) {
        return str.replace(/[\u4e00-\u9fa5]/g, 'aa').length;
    }
});