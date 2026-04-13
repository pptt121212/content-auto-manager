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
        if (response.success && response.data.row_html) {
            const tableBody = $('.yali-table tbody');
            const table = $('.yali-table');

            if (response.data.is_create) {
                // 新建模式：添加新行
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

                // 更新模型选择框为保存的值
                if (response.data.saved_model) {
                    const modelSelect = $('#predefined-api-model');
                    if (modelSelect.length > 0) {
                        let existingOption = modelSelect.find('option[value="' + response.data.saved_model + '"]');
                        if (existingOption.length === 0) {
                            modelSelect.prepend('<option value="' + response.data.saved_model + '">' + response.data.saved_model + ' (' + wp.i18n.__('当前保存的模型', 'yali-ai-writer') + ')</option>');
                        }
                        modelSelect.val(response.data.saved_model);
                    }
                }

                // 切换到编辑模式：添加 config_id 隐藏字段
                const form = $(this);
                if (response.data.config_id) {
                    // 如果已存在 id 输入框则更新，否则添加新的
                    let idInput = form.find('input[name="id"]');
                    if (idInput.length === 0) {
                        form.append('<input type="hidden" name="id" value="' + response.data.config_id + '">');
                    } else {
                        idInput.val(response.data.config_id);
                    }
                    
                    // 添加编辑标记
                    let editingChannelInput = form.find('input[name="editing_predefined_channel"]');
                    if (editingChannelInput.length === 0) {
                        form.append('<input type="hidden" name="editing_predefined_channel" value="pollinations">');
                    }
                    
                    // 更新表单标题或状态提示
                    const formTitle = form.find('h2, .yali-panel-title').first();
                    if (formTitle.length > 0 && !formTitle.data('edited')) {
                        formTitle.append(' <span class="yali-badge yali-badge-success">' + wp.i18n.__('已保存', 'yali-ai-writer') + '</span>');
                        formTitle.data('edited', true);
                    }
                }
            } else if (response.data.config_id) {
                // 编辑模式：更新现有行
                const existingRow = tableBody.find('button[data-config-id="' + response.data.config_id + '"]').closest('tr');
                if (existingRow.length > 0) {
                    const updatedRow = $(response.data.row_html).hide();
                    existingRow.replaceWith(updatedRow);
                    updatedRow.fadeIn(600);
                }
                
                // 更新表单中的模型选择框为保存的值
                if (response.data.saved_model) {
                    const modelSelect = $('#predefined-api-model');
                    if (modelSelect.length > 0) {
                        // 检查是否已存在该选项
                        let existingOption = modelSelect.find('option[value="' + response.data.saved_model + '"]');
                        if (existingOption.length === 0) {
                            // 如果不存在，添加新选项
                            modelSelect.prepend('<option value="' + response.data.saved_model + '">' + response.data.saved_model + ' (' + wp.i18n.__('当前保存的模型', 'yali-ai-writer') + ')</option>');
                        }
                        // 选中该选项
                        modelSelect.val(response.data.saved_model);
                    }
                }
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
