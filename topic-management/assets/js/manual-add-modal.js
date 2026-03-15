/**
 * Manual Add Topic Modal JavaScript
 * Compatible with yali-ui-kit modal patterns.
 */
jQuery(document).ready(function ($) {
    var nonce = $('#cam-manual-add-nonce').val();
    var maxReferenceLength = 800;

    // I18n
    var i18n = window.camManualAddI18n || {
        adding: wp.i18n.__('正在添加...', 'yali-ai-writer'),
        addSuccess: wp.i18n.__('添加成功', 'yali-ai-writer'),
        addFailed: wp.i18n.__('添加失败', 'yali-ai-writer'),
        requestFailed: wp.i18n.__('请求失败', 'yali-ai-writer'),
        pleaseEnterTitle: wp.i18n.__('请至少输入一个主题标题', 'yali-ai-writer'),
        close: wp.i18n.__('关闭', 'yali-ai-writer'),
        add: wp.i18n.__('添加主题', 'yali-ai-writer')
    };

    // Open Modal
    $('#open-manual-add-modal').on('click', function (e) {
        e.preventDefault();
        $('#manual-add-modal-overlay').addClass('active');
        $('body').css('overflow', 'hidden');
        // Focus on title input
        setTimeout(function () {
            $('#manual-titles').focus();
        }, 300);
    });

    // Close Modal Function
    function closeModal() {
        $('#manual-add-modal-overlay').removeClass('active');
        $('body').css('overflow', '');
    }

    // Close Button Actions
    // Using .yali-modal-close for the X button, and #manual-add-cancel for the cancel button
    $('#manual-add-modal-close, #manual-add-cancel').on('click', closeModal);

    // Click Overlay to Close
    $('#manual-add-modal-overlay').on('click', function (e) {
        // Ensure we are clicking the overlay wrapper, not the modal itself
        // (yali-modal-overlay wraps yali-modal)
        if (e.target === this) {
            closeModal();
        }
    });

    // ESC Key to Close
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#manual-add-modal-overlay').hasClass('active')) {
            closeModal();
        }
    });

    // Reference Character Counter
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

    // Submit Form
    $('#manual-add-submit').on('click', function () {
        var $btn = $(this);
        var titles = $('#manual-titles').val().trim();
        var reference = $('#manual-reference').val();
        var categoryId = $('#manual-category').val();

        // Validation
        if (!titles) {
            if (typeof window.yaliToast === 'function') {
                window.yaliToast(i18n.pleaseEnterTitle, 'error');
            } else {
                alert(i18n.pleaseEnterTitle);
            }
            $('#manual-titles').focus();
            return;
        }

        // Disable Button
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(i18n.adding);

        // AJAX Request
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
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message || i18n.success || wp.i18n.__('添加成功', 'yali-ai-writer'), 'success');
                    } else {
                        alert(response.data.message || i18n.success || wp.i18n.__('添加成功', 'yali-ai-writer'));
                    }
                    // Clear Form
                    $('#manual-titles').val('');
                    $('#manual-reference').val('');
                    $('#manual-category').val('');
                    $('#reference-char-counter').text('0 / ' + maxReferenceLength).removeClass('warning error');
                    // Close and Reload
                    closeModal();
                    location.reload();
                } else {
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message || i18n.addFailed || wp.i18n.__('添加失败', 'yali-ai-writer'), 'error');
                    } else {
                        alert(response.data.message || i18n.addFailed || wp.i18n.__('添加失败', 'yali-ai-writer'));
                    }
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function (xhr, status, error) {
                if (typeof window.yaliToast === 'function') {
                    window.yaliToast((i18n.requestFailed || wp.i18n.__('请求失败', 'yali-ai-writer')) + ': ' + error, 'error');
                } else {
                    alert((i18n.requestFailed || wp.i18n.__('请求失败', 'yali-ai-writer')) + ': ' + error);
                }
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
