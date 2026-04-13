/**
 * Yali AI Writer - Global Toast Handler
 * Handles displaying toast notices from localized PHP data.
 */
document.addEventListener('DOMContentLoaded', function() {
    // Check if the localized yaliToastData object exists
    if (typeof yaliToastData !== 'undefined' && yaliToastData.message) {
        
        // Use timeout to ensure it runs after UI is fully ready
        setTimeout(function() {
            if (typeof window.yaliToast === 'function') {
                window.yaliToast(yaliToastData.message, yaliToastData.type);
            } else {
                // Flash fallback if UI toast isn't loaded
                alert(yaliToastData.message);
            }
            
            // Cleanup the URL query parameters so it does not trigger again on reload
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                if (url.searchParams.has('yali_notice')) {
                    url.searchParams.delete('yali_notice');
                }
                if (url.searchParams.has('yali_message')) {
                    url.searchParams.delete('yali_message');
                }
                window.history.replaceState({}, document.title, url.toString());
            }
        }, 100);
    }
});
