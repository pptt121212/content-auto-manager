/**
 * Structure Analysis Metabox script for WordPress post edit screen
 */
jQuery(document).ready(function($) {
    $('#analyze-structure-btn').on('click', function() {
        var $btn = $(this);
        var $spinner = $('#analyze-spinner');
        var $result = $('#analyze-result');
        var postId = $btn.data('post-id');
        
        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'yali_ai_writer_analyze_article_structure',
                nonce: $('#analyze_structure_nonce_field').val(),
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error inline"><p>' + wp.i18n.__('请求失败，请重试', 'yali-ai-writer') + '</p></div>');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
});
