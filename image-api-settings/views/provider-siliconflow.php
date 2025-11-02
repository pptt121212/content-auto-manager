<?php
// image-api-settings/views/provider-siliconflow.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2>硅基流动 (Silicon Flow) 设置</h2>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="siliconflow_api_key"><?php echo esc_html__('API Key', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="password" id="siliconflow_api_key" name="siliconflow[api_key]" value="<?php echo esc_attr($settings['siliconflow']['api_key'] ?? ''); ?>" class="regular-text">
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="siliconflow_model"><?php echo esc_html__('模型', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="text" id="siliconflow_model" name="siliconflow[model]" value="<?php echo esc_attr($settings['siliconflow']['model'] ?? 'Qwen/Qwen-Image'); ?>" class="regular-text">
                 <p class="description">
                    <?php echo esc_html__('例如：Qwen/Qwen-Image, Kwai-Kolors/Kolors', 'content-auto-manager'); ?>
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
                <label for="siliconflow_test_prompt">测试提示词</label>
            </th>
            <td>
                <textarea id="siliconflow_test_prompt" rows="3" class="large-text"></textarea>
                <p class="description">输入一段英文提示词来测试上面的配置。</p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary" id="test_api_button_siliconflow" data-provider="siliconflow">生成测试图像</button>
            </td>
        </tr>
        <tr>
            <th scope="row">测试结果</th>
            <td>
                <div id="siliconflow_test_result" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
