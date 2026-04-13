<?php
/**
 * 图像生成提示词编辑器
 * 用于编辑config/image-prompts.json配置文件
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('您没有权限访问此页面。', 'yali-ai-writer'));
}

$config_file = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/config/image-prompts.json';
$message = '';
$error = '';

// 处理保存请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_image_prompts'])) {
    check_admin_referer('edit_image_prompts_nonce');
    
    $json_content = stripslashes($_POST['prompts_json']);
    
    // 验证JSON格式
    $decoded = json_decode($json_content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = __('JSON格式错误: ', 'yali-ai-writer') . json_last_error_msg();
    } else {
        // 保存文件
        $result = file_put_contents($config_file, $json_content);
        if ($result === false) {
            $error = __('保存失败，请检查文件权限。', 'yali-ai-writer');
        } else {
            $message = __('保存成功！', 'yali-ai-writer');
        }
    }
}

// 读取当前配置
$current_json = '';
if (file_exists($config_file)) {
    $current_json = file_get_contents($config_file);
} else {
    $error = __('配置文件不存在: ', 'yali-ai-writer') . $config_file;
}

// 确保JSON格式化显示
if (!empty($current_json)) {
    $decoded = json_decode($current_json, true);
    if ($decoded !== null) {
        $current_json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
?>

<div class="wrap">
    <h1><?php _e('图像生成提示词编辑器', 'yali-ai-writer'); ?></h1>
    
    <div class="yali-card" style="margin-top: 20px;">
        <div class="yali-notice yali-notice-info">
            <p>
                <strong><?php _e('说明：', 'yali-ai-writer'); ?></strong>
                <?php _e('在此编辑AI编辑助手中的4个图像生成提示词。修改后保存即可立即生效。', 'yali-ai-writer'); ?>
            </p>
            <p>
                <?php _e('配置文件位置：', 'yali-ai-writer'); ?>
                <code>editor-assistant/config/image-prompts.json</code>
            </p>
        </div>
        
        <?php if ($message): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($error); ?></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('edit_image_prompts_nonce'); ?>
            
            <p>
                <label for="prompts_json" style="font-weight: 600; display: block; margin-bottom: 10px;">
                    <?php _e('提示词配置 (JSON格式)', 'yali-ai-writer'); ?>
                </label>
                <textarea 
                    name="prompts_json" 
                    id="prompts_json" 
                    rows="40" 
                    style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5;"
                    class="large-text code"
                ><?php echo esc_textarea($current_json); ?></textarea>
            </p>
            
            <p class="submit">
                <button type="submit" name="save_image_prompts" class="yali-btn yali-btn-primary">
                    <span class="dashicons dashicons-save" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php _e('保存配置', 'yali-ai-writer'); ?>
                </button>
                <a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary" style="margin-left: 10px;">
                    <?php _e('返回发布规则', 'yali-ai-writer'); ?>
                </a>
            </p>
        </form>
    </div>
    
    <div class="yali-card" style="margin-top: 20px;">
        <h3><?php _e('提示词结构说明', 'yali-ai-writer'); ?></h3>
        <ul style="list-style-type: disc; margin-left: 20px;">
            <li><code>prompt_title</code> - <?php _e('显示在编辑器菜单中的标题', 'yali-ai-writer'); ?></li>
            <li><code>prompt_content</code> - <?php _e('发送给AI的完整提示词指令', 'yali-ai-writer'); ?></li>
            <li><code>prompt_desc</code> - <?php _e('功能描述说明', 'yali-ai-writer'); ?></li>
            <li><code>word</code> - <?php _e('配置对象，包含type和value', 'yali-ai-writer'); ?></li>
            <li><code>is_image_generation</code> - <?php _e('标记是否为图像生成提示词', 'yali-ai-writer'); ?></li>
            <li><code>image_style</code> - <?php _e('图像风格标识', 'yali-ai-writer'); ?></li>
        </ul>
    </div>
</div>
