jQuery(document).ready(function ($) {
    function toggleInputState(checkboxId, inputId) {
        var checkbox = $('#' + checkboxId);
        var input = $('#' + inputId);

        if (checkbox.length === 0 || input.length === 0) {
            return;
        }

        function updateState() {
            if (checkbox.is(':checked')) {
                input.prop('disabled', false).removeClass('yali-input-disabled');
            } else {
                input.prop('disabled', true).addClass('yali-input-disabled');
            }
        }

        // Initial state
        updateState();

        // Change event
        checkbox.on('change', updateState);
    }

    toggleInputState('temperature_enabled', 'temperature');
    toggleInputState('max_tokens_enabled', 'max_tokens');
    toggleInputState('top_p_enabled', 'top_p');

    // --- Universal AJAX Success Handler for Table Updates ---
    $(document).on('yali:ajax-success', '.yali-ajax-form', function (e, response) {
        if (response.success && response.data.is_create && response.data.row_html) {
            const tableBody = $('.yali-table tbody');
            const table = $('.yali-table');
            const emptyMessage = $('.content-auto-section p:contains("' + wp.i18n.__('暂无API配置', 'yali-ai-writer') + '")');

            if (table.length === 0 && emptyMessage.length > 0) {
                // If table doesn't exist yet (empty state), reload to allow PHP to render the table structure
                location.reload();
            } else {
                // Append the new row with highlight effect
                const newRow = $(response.data.row_html).hide();
                tableBody.append(newRow);
                newRow.fadeIn(600);
            }

            // Optional: reset form if it's an "Add" form (not edit)
            const form = $(this);
            const idInput = form.find('input[name="id"]');
            if (idInput.length === 0 || idInput.val() === '') {
                form[0].reset();
            }
        }
    });

    // --- Delete Config Logic (使用 YaliActions) ---
    // 通过 data 属性自动绑定，不需要手动编写 AJAX 代码
    // 按钮需要以下属性：
    // data-yali-action="delete"
    // data-yali-ajax-action="cam_delete_api_config"
    // data-yali-id="{config_id}"
    // data-yali-id-param="id"
    // data-yali-confirm="确认消息（可选）"
});
