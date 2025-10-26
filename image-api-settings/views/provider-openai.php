<?php
// image-api-settings/views/provider-openai.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2>OpenAI 设置</h2>
<p>使用 DALL·E 3 或 DALL·E 2 模型生成图像。您需要一个有效的OpenAI API密钥。</p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="openai_api_key"><?php echo esc_html__('API Key', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="password" id="openai_api_key" name="openai[api_key]" value="<?php echo esc_attr($settings['openai']['api_key'] ?? ''); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="openai_model"><?php echo esc_html__('模型', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="text" id="openai_model" name="openai[model]" value="<?php echo esc_attr($settings['openai']['model'] ?? 'gpt-image-1'); ?>" class="regular-text">
                <p class="description">
                    <?php echo esc_html__('例如：gpt-image-1, dall-e-3, dall-e-2', 'content-auto-manager'); ?>
                </p>
            </td>
        </tr>
    </tbody>
</table>
<hr>
<h2>接口测试</h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="openai_test_prompt">测试提示词</label>
            </th>
            <td>
                <textarea id="openai_test_prompt" rows="3" class="large-text"></textarea>
                <p class="description">输入一段英文提示词来测试上面的配置。</p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary" id="test_api_button_openai" data-provider="openai">生成测试图像</button>
            </td>
        </tr>
        <tr>
            <th scope="row">测试结果</th>
            <td>
                <div id="openai_test_result" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
