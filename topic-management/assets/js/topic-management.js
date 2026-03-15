jQuery(document).ready(function ($) {

    // View Reference Modal Logic
    $('.view-reference-btn').on('click', function (e) {
        e.preventDefault();
        var content = $(this).data('content');
        var title = $(this).data('title') || wp.i18n.__('参考资料', 'yali-ai-writer');

        // Populate modal
        $('#view-reference-modal-title').text(title);
        $('#view-reference-modal-content').text(content);

        // Show modal
        $('#view-reference-modal-overlay').addClass('active');
        $('body').css('overflow', 'hidden');
    });

    // Close Modal
    function closeRefModal() {
        $('#view-reference-modal-overlay').removeClass('active');
        $('body').css('overflow', '');
    }

    $('#view-reference-modal-close, #view-reference-close-btn, #view-reference-modal-overlay').on('click', function (e) {
        if (e.target === this || $(this).attr('id') === 'view-reference-modal-close' || $(this).attr('id') === 'view-reference-close-btn') {
            closeRefModal();
        }
    });

    // ESC to close
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#view-reference-modal-overlay').hasClass('active')) {
            closeRefModal();
        }
    });
});
