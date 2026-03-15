<?php
// image-api-settings/views/provider-custom.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
$custom_settings = isset($settings['custom']) ? $settings['custom'] : [];
$api_key = isset($custom_settings['api_key']) ? $custom_settings['api_key'] : '';
$base_url = isset($custom_settings['base_url']) ? $custom_settings['base_url'] : '';
$model = isset($custom_settings['model']) ? $custom_settings['model'] : '';

?>
<h3><?php _e('自定义 (OpenAI 兼容) 设置', 'yali-ai-writer'); ?></h3>
<p class="description"><?php _e('配置任意支持 OpenAI 图像生成标准 ( /v1/images/generations ) 的第三方 API。', 'yali-ai-writer'); ?></p>

<table class="form-table">
    <tr>
        <th scope="row"><label for="custom_base_url"><?php _e('接口地址 (Base URL)', 'yali-ai-writer'); ?></label></th>
        <td>
            <input type="text" id="custom_base_url" name="custom[base_url]" value="<?php echo esc_attr($base_url); ?>" class="regular-text" style="width: 400px;" placeholder="https://api.example.com/v1/images/generations" />
            <p class="description"><?php _e('请输入含协议 (http/https) 和路径的完整生成端点地址。', 'yali-ai-writer'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="custom_api_key"><?php _e('API Key', 'yali-ai-writer'); ?></label></th>
        <td>
            <input type="password" id="custom_api_key" name="custom[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
            <p class="description"><?php _e('调用该接口的授权密钥 (Bearer Token)。', 'yali-ai-writer'); ?></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="custom_model"><?php _e('模型名称 (Model)', 'yali-ai-writer'); ?></label></th>
        <td>
            <input type="text" id="custom_model" name="custom[model]" value="<?php echo esc_attr($model); ?>" class="regular-text" placeholder="dall-e-3" />
            <p class="description"><?php _e('需要调用的目标模型名称。', 'yali-ai-writer'); ?></p>
        </td>
    </tr>
</table>
<hr>
<h2><?php _e('接口测试', 'yali-ai-writer'); ?></h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="custom_test_prompt"><?php _e('测试提示词', 'yali-ai-writer'); ?></label>
            </th>
            <td>
                <textarea id="custom_test_prompt" rows="3" class="large-text yali-input"></textarea>
                <p class="description yali-desc"><?php _e('输入一段提示词来测试上面的配置，例如：星际穿越，黑洞，黑洞里冲出一辆快支离破碎的复古列车...', 'yali-ai-writer'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary yali-btn yali-btn-secondary" id="test_api_button_custom" data-provider="custom"><?php _e('生成测试图像', 'yali-ai-writer'); ?></button>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('测试结果', 'yali-ai-writer'); ?></th>
            <td>
                <div id="custom_test_result" class="yali-panel" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
