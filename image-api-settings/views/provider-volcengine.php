<?php
// image-api-settings/views/provider-volcengine.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
$volcengine_settings = isset($settings['volcengine']) ? $settings['volcengine'] : [];
$api_key = isset($volcengine_settings['api_key']) ? $volcengine_settings['api_key'] : '';
$model = isset($volcengine_settings['model']) ? $volcengine_settings['model'] : 'doubao-seedream-5-0-260128';

?>
<h3><?php _e('火山引擎 (豆包) 设置', 'yali-ai-writer'); ?></h3>
<p class="description"><?php _e('配置火山引擎图像 API。', 'yali-ai-writer'); ?></p>

<table class="form-table">
    <tr>
        <th scope="row"><label for="volcengine_api_key"><?php _e('API Key', 'yali-ai-writer'); ?></label></th>
        <td>
            <input type="password" id="volcengine_api_key" name="volcengine[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
            <p class="description"><?php _e('火山引擎的 API 密钥。', 'yali-ai-writer'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="volcengine_model"><?php _e('模型 (Model)', 'yali-ai-writer'); ?></label></th>
        <td>
            <input type="text" id="volcengine_model" name="volcengine[model]" value="<?php echo esc_attr($model); ?>" class="regular-text" />
            <p class="description"><?php _e('在此输入模型名，如：doubao-seedream-4-5-251128 或 doubao-seedream-5-0-260128', 'yali-ai-writer'); ?></p>
        </td>
    </tr>
</table>
<hr>
<h2><?php _e('接口测试', 'yali-ai-writer'); ?></h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="volcengine_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="volcengine_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段提示词来测试上面的配置，例如：星际穿越，黑洞，黑洞里冲出一辆快支离破碎的复古列车...', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_volcengine" data-provider="volcengine"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="volcengine_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
