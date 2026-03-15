<?php
// image-api-settings/views/provider-siliconflow.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2><?php _e('硅基流动 (Silicon Flow) 设置', 'yali-ai-writer'); ?></h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="siliconflow_api_key"><?php _e('API Key', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="password" id="siliconflow_api_key" name="siliconflow[api_key]" value="<?php echo esc_attr($settings['siliconflow']['api_key'] ?? ''); ?>" class="regular-text yali-input">
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="siliconflow_model"><?php _e('模型', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="text" id="siliconflow_model" name="siliconflow[model]" value="<?php echo esc_attr($settings['siliconflow']['model'] ?? 'Qwen/Qwen-Image'); ?>" class="regular-text yali-input">
                 <p class="description yali-desc">
                    <?php _e('例如：Qwen/Qwen-Image, Kwai-Kolors/Kolors', 'yali-ai-writer'); ?>
                </p>
            </td>
        </tr>
    </tbody>
</table>
<hr>
<h2><?php _e('接口测试', 'yali-ai-writer'); ?></h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="siliconflow_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="siliconflow_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段英文提示词来测试上面的配置。', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_siliconflow" data-provider="siliconflow"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="siliconflow_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
