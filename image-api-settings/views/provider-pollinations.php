<?php
// image-api-settings/views/provider-pollinations.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2>Pollinations.AI 设置</h2>
<p>使用 Pollinations.AI 生成图像。您可以在 <a href="https://github.com/pollinations/pollinations/blob/master/APIDOCS.md" target="_blank">Pollinations API 文档</a> 中查找更多信息。</p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="pollinations_default_model"><?php echo esc_html__('默认模型', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="text" id="pollinations_default_model" name="pollinations[model]" value="<?php echo esc_attr($settings['pollinations']['model'] ?? 'flux'); ?>" class="regular-text">
                <p class="description">
                    <?php echo esc_html__('例如：flux, turbo,nanobanana,seedream', 'content-auto-manager'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="pollinations_token"><?php echo esc_html__('API Token (可选)', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="password" id="pollinations_token" name="pollinations[token]" value="<?php echo esc_attr($settings['pollinations']['token'] ?? ''); ?>" class="regular-text">
                <p class="description">
                    <?php echo esc_html__('用于身份验证的API令牌（如有则自动启用无徽标模式）', 'content-auto-manager'); ?>
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
                <label for="pollinations_test_prompt">测试提示词</label>
            </th>
            <td>
                <textarea id="pollinations_test_prompt" rows="3" class="large-text"></textarea>
                <p class="description">输入一段英文提示词来测试上面的配置。</p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary" id="test_api_button_pollinations" data-provider="pollinations">生成测试图像</button>
            </td>
        </tr>
        <tr>
            <th scope="row">测试结果</th>
            <td>
                <div id="pollinations_test_result" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>