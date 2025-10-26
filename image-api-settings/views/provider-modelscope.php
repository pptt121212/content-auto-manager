<?php
// image-api-settings/views/provider-modelscope.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */
?>
<h2>ModelScope 设置</h2>
<p>您可以在 <a href="https://www.modelscope.cn/aigc/models" target="_blank">ModelScope AIGC模型</a> 页面查找可用的模型ID，并确保您的 ModelScope 账号已绑定阿里云账号。</p>
<table class="form-table">
    <tbody>
        <tr>
            <th scope="row">
                <label for="modelscope_model_id"><?php echo esc_html__('模型 (Model ID)', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="text" id="modelscope_model_id" name="modelscope[model_id]" value="<?php echo esc_attr($settings['modelscope']['model_id'] ?? ''); ?>" class="regular-text">
                <p class="description">
                    <?php echo esc_html__('例如：Qwen/Qwen-Image', 'content-auto-manager'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="modelscope_api_key"><?php echo esc_html__('API Key (MODELSCOPE_SDK_TOKEN)', 'content-auto-manager'); ?></label>
            </th>
            <td>
                <input type="password" id="modelscope_api_key" name="modelscope[api_key]" value="<?php echo esc_attr($settings['modelscope']['api_key'] ?? ''); ?>" class="regular-text">
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
                <label for="modelscope_test_prompt">测试提示词</label>
            </th>
            <td>
                <textarea id="modelscope_test_prompt" rows="3" class="large-text"></textarea>
                <p class="description">输入一段英文提示词来测试上面的配置。</p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button button-secondary" id="test_api_button_modelscope" data-provider="modelscope">生成测试图像</button>
            </td>
        </tr>
        <tr>
            <th scope="row">测试结果</th>
            <td>
                <div id="modelscope_test_result" style="min-height: 50px;"></div>
            </td>
        </tr>
    </tbody>
</table>
