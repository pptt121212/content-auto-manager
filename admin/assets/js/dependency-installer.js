jQuery(document).ready(function ($) {
    /**
     * Force Pin Notice to Top
     * 
     * Moves the specific dependency notice to the very top of the .wrap container,
     * ensuring it appears above the page title (H1) and overrides WP's default placement.
     */
    function pinNoticeToTop() {
        var $notice = $('.cam-dependency-notice');
        // Only run if notice exists (ignore visibility check as we hide it by default now)
        if ($notice.length === 0) return;

        var $wrap = $('.wrap').first();
        var $wpbody = $('#wpbody-content');

        // Logic: If .wrap exists, prepend to it. If not, prepend to #wpbody-content
        // Logic: Place notice before .wp-header-end if it exists (standard WP location),
        // otherwise after .wp-heading-inline, or prepend to .wrap as fallback.
        if ($wrap.length > 0) {
            var $headerEnd = $wrap.find('.wp-header-end').first();
            var $heading = $wrap.find('.wp-heading-inline').first();

            if ($heading.length > 0) {
                // Determine insertion point: prefer BEFORE the heading to be at the very top
                if ($heading.prev()[0] !== $notice[0]) {
                    $heading.before($notice);
                }
            } else if ($headerEnd.length > 0) {
                if ($headerEnd.prev()[0] !== $notice[0]) {
                    $headerEnd.before($notice);
                }
            } else {
                if ($wrap.children().first()[0] !== $notice[0]) {
                    $wrap.prepend($notice);
                }
            }
        } else if ($wpbody.length > 0) {
            if ($wpbody.children().first()[0] !== $notice[0]) {
                $wpbody.prepend($notice);
            }
        }

        // Reveal the notice after positioning to prevent layout flash
        $notice.show();
    }

    // Run immediately
    pinNoticeToTop();

    // Run again after a short delay to counter WordPress 'common.js' which moves notices
    // to .wp-header-end (which is usually BELOW the title)
    setTimeout(pinNoticeToTop, 100);
    setTimeout(pinNoticeToTop, 500);
    $(window).on('load', pinNoticeToTop);

    // Existing installer logic...
    $('#cam-install-dependency-btn').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $status = $('#cam-install-status');
        var $spinner = $btn.next('.spinner');

        if ($btn.prop('disabled')) {
            return;
        }

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $status.html(cam_dependency_vars.installing_text).css('color', '#666');

        $.ajax({
            url: cam_dependency_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'cam_install_dependency',
                nonce: cam_dependency_vars.nonce
            },
            success: function (response) {
                if (response.success) {
                    $status.html(cam_dependency_vars.success_text).css('color', '#46b450');
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    $status.html(response.data.message || cam_dependency_vars.error_text).css('color', '#dc3232');
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            },
            error: function () {
                $status.html(cam_dependency_vars.error_text).css('color', '#dc3232');
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
});
