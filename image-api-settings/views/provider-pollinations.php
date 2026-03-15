<?php
// image-api-settings/views/provider-pollinations.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2><?php _e('Pollinations.AI 设置', 'yali-ai-writer'); ?></h2>
<p><?php _e('由于 Pollinations.AI 免费版接口目前服务不可用，<strong>必须提供 API Token 才能生成图像</strong>。请前往 <a href="https://enter.pollinations.ai" target="_blank">enter.pollinations.ai</a> 获取新版 API Key。', 'yali-ai-writer'); ?></p>

<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="pollinations_default_model"><?php _e('默认模型', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="text" id="pollinations_default_model" name="pollinations[model]" value="<?php echo esc_attr($settings['pollinations']['model'] ?? 'flux'); ?>" class="regular-text yali-input">
                <p class="description yali-desc">
                    <?php _e('可用模型：flux (默认), turbo, gptimage, kontext, seedream', 'yali-ai-writer'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="pollinations_token"><?php _e('API Token (必填)', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <input type="password" id="pollinations_token" name="pollinations[token]" value="<?php echo esc_attr($settings['pollinations']['token'] ?? ''); ?>" class="regular-text yali-input" required placeholder="pk_...">
                <p class="description yali-desc">
                    <?php _e('从 enter.pollinations.ai 获取的新密钥。必须填写才能使用服务。', 'yali-ai-writer'); ?>
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
                <label for="pollinations_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="pollinations_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段英文提示词来测试上面的配置。', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_pollinations" data-provider="pollinations"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="pollinations_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>