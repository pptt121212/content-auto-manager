/**
 * 手工添加主题弹窗JavaScript
 */
jQuery(document).ready(function ($) {
    var nonce = $('#cam-manual-add-nonce').val();
    var maxReferenceLength = 800;

    // 国际化文本
    var i18n = window.camManualAddI18n || {
        adding: '正在添加...',
        addSuccess: '添加成功',
        addFailed: '添加失败',
        requestFailed: '请求失败',
        pleaseEnterTitle: '请至少输入一个主题标题',
        close: '关闭',
        add: '添加主题'
    };

    // 打开弹窗
    $('#open-manual-add-modal').on('click', function (e) {
        e.preventDefault();
        $('#manual-add-modal-overlay').addClass('active');
        $('body').css('overflow', 'hidden');
        // 焦点到标题输入框
        setTimeout(function () {
            $('#manual-titles').focus();
        }, 300);
    });

    // 关闭弹窗
    function closeModal() {
        $('#manual-add-modal-overlay').removeClass('active');
        $('body').css('overflow', '');
    }

    // 关闭按钮
    $('#manual-add-modal-close, #manual-add-cancel').on('click', closeModal);

    // 点击遮罩层关闭
    $('#manual-add-modal-overlay').on('click', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // ESC键关闭
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#manual-add-modal-overlay').hasClass('active')) {
            closeModal();
        }
    });

    // 参考资料字符计数
    $('#manual-reference').on('input', function () {
        var length = $(this).val().length;
        var $counter = $('#reference-char-counter');

        $counter.text(length + ' / ' + maxReferenceLength);

        if (length > maxReferenceLength) {
            $counter.removeClass('warning').addClass('error');
        } else if (length > maxReferenceLength * 0.8) {
            $counter.removeClass('error').addClass('warning');
        } else {
            $counter.removeClass('warning error');
        }
    });

    // 提交表单
    $('#manual-add-submit').on('click', function () {
        var $btn = $(this);
        var titles = $('#manual-titles').val().trim();
        var reference = $('#manual-reference').val();
        var categoryId = $('#manual-category').val();

        // 验证
        if (!titles) {
            alert(i18n.pleaseEnterTitle);
            $('#manual-titles').focus();
            return;
        }

        // 禁用按钮
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(i18n.adding);

        // 发送AJAX请求
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cam_manual_add_topics',
                nonce: nonce,
                titles: titles,
                reference_material: reference,
                target_category_id: categoryId
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    // 清空表单
                    $('#manual-titles').val('');
                    $('#manual-reference').val('');
                    $('#manual-category').val('');
                    $('#reference-char-counter').text('0 / ' + maxReferenceLength).removeClass('warning error');
                    // 关闭弹窗并刷新页面
                    closeModal();
                    location.reload();
                } else {
                    alert(response.data.message || i18n.addFailed);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function (xhr, status, error) {
                alert(i18n.requestFailed + ': ' + error);
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
