<?php
// image-api-settings/views/provider-modelscope.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2><?php _e('ModelScope 设置', 'yali-ai-writer'); ?></h2>
<p><?php _e('您可以在 <a href="https://www.modelscope.cn/aigc/models" target="_blank">ModelScope AIGC模型</a> 页面查找可用的模型ID，并确保您的 ModelScope 账号已绑定阿里云账号。', 'yali-ai-writer'); ?></p>
<p class="yali-notice yali-notice-warning">
    <strong><?php _e('提示：', 'yali-ai-writer'); ?></strong><?php _e('部分模型（如Qwen/Qwen-Image）处理时间可能较长，请耐心等待结果。如需快速测试，可尝试使用响应更快的模型。', 'yali-ai-writer'); ?>
</p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="modelscope_model_id"><?php _e('模型 (Model ID)', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="text" id="modelscope_model_id" name="modelscope[model_id]" value="<?php echo esc_attr($settings['modelscope']['model_id'] ?? ''); ?>" class="regular-text yali-input">
                <p class="description yali-desc">
                    <?php _e('例如：Qwen/Qwen-Image', 'yali-ai-writer'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="modelscope_api_key"><?php _e('API Key (MODELSCOPE_SDK_TOKEN)', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="password" id="modelscope_api_key" name="modelscope[api_key]" value="<?php echo esc_attr($settings['modelscope']['api_key'] ?? ''); ?>" class="regular-text yali-input">
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
                <label for="modelscope_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="modelscope_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段英文提示词来测试上面的配置。', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_modelscope" data-provider="modelscope"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="modelscope_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
