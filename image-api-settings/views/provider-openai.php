<?php
// image-api-settings/views/provider-openai.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2><?php _e('OpenAI 设置', 'yali-ai-writer'); ?></h2>
<p><?php _e('使用 DALL·E 3 或 DALL·E 2 模型生成图像。您需要一个有效的OpenAI API密钥。', 'yali-ai-writer'); ?></p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="openai_api_key"><?php _e('API Key', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="password" id="openai_api_key" name="openai[api_key]" value="<?php echo esc_attr($settings['openai']['api_key'] ?? ''); ?>" class="regular-text yali-input">
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="openai_model"><?php _e('模型', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="text" id="openai_model" name="openai[model]" value="<?php echo esc_attr($settings['openai']['model'] ?? 'gpt-image-1'); ?>" class="regular-text yali-input">
                <p class="description yali-desc">
                    <?php _e('例如：gpt-image-1, dall-e-3, dall-e-2', 'yali-ai-writer'); ?>
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
                <label for="openai_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="openai_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段英文提示词来测试上面的配置。', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_openai" data-provider="openai"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="openai_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
