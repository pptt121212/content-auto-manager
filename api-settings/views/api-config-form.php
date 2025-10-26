<?php
/**
 * API配置表单页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 处理表单提交
if (isset($_POST['submit']) && isset($_POST['content_auto_manager_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_api_config')) {
        wp_die(__('安全验证失败。'));
    }
    
    // 获取当前标签类型
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'custom';
    
    // 向量API配置唯一性检查
    if ($current_tab === 'vector') {
        $api_config_check = new ContentAuto_ApiConfig();
        $existing_vector_config = $api_config_check->get_vector_config();
        
        // 如果已存在向量API配置且不是编辑模式，则阻止创建
        if ($existing_vector_config && empty($_POST['id'])) {
            wp_die('<div class="notice notice-error"><p>' . __('错误：系统中已存在向量API配置。每个系统只允许配置一个向量API。如需修改，请编辑现有配置。', 'content-auto-manager') . '</p></div>');
        }
    }
    
    // 获取表单数据 - 只更新实际提交的字段
    $data = array();
    
    // 必需字段
    $data['name'] = sanitize_text_field($_POST['name']);
    
    // 检测当前标签类型
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'custom';
    
    if ($current_tab === 'vector') {
        // 向量API配置 - 始终写入三项；编辑时留空密钥则保留原值
        $data['vector_api_url'] = esc_url_raw($_POST['vector_api_url'] ?? '');
        $data['vector_model_name'] = sanitize_text_field($_POST['vector_model_name'] ?? '');
        $data['vector_api_type'] = sanitize_text_field($_POST['vector_api_type'] ?? 'openai');
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $existing = (new ContentAuto_ApiConfig())->get_config($_POST['id']);
            $data['vector_api_key'] = strlen(trim($_POST['vector_api_key'] ?? '')) ? sanitize_text_field($_POST['vector_api_key']) : ($existing['vector_api_key'] ?? '');
        } else {
            $data['vector_api_key'] = sanitize_text_field($_POST['vector_api_key'] ?? '');
        }
        // 向量API配置时，将传统API字段设为空
        $data['api_url'] = '';
        $data['api_key'] = '';
        $data['model_name'] = '';
        $data['temperature'] = 0.70;
        $data['max_tokens'] = 2000;
        $data['temperature_enabled'] = 0;
        $data['max_tokens_enabled'] = 0;
    } else {
        // 传统API配置 - 处理传统API字段
        $data['api_url'] = esc_url_raw($_POST['api_url']);
        $data['api_key'] = sanitize_text_field($_POST['api_key']);
        $data['model_name'] = sanitize_text_field($_POST['model_name']);
        
        // 可选字段 - 只有在表单中提交时才更新
        if (isset($_POST['temperature'])) {
            $data['temperature'] = floatval($_POST['temperature']);
        }
        if (isset($_POST['max_tokens'])) {
            $data['max_tokens'] = intval($_POST['max_tokens']);
        }
        $data['temperature_enabled'] = !empty($_POST['temperature_enabled']) ? 1 : 0;
        $data['max_tokens_enabled'] = !empty($_POST['max_tokens_enabled']) ? 1 : 0;

        // 处理新参数 - 流式输出功能已禁用，始终设置为false
        $data['stream_enabled'] = 0;
        $data['stream'] = false;

        $data['top_p_enabled'] = !empty($_POST['top_p_enabled']) ? 1 : 0;
        if (isset($_POST['top_p'])) {
            $data['top_p'] = floatval($_POST['top_p']);
        }
        
        // 传统API配置时，将向量API字段设为空
        $data['vector_api_url'] = '';
        $data['vector_api_key'] = '';
        $data['vector_model_name'] = '';
        
        // 传统API配置需要设置is_active
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    }
    
      
    // 保存数据
    $api_config = new ContentAuto_ApiConfig();
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // 更新现有配置
        $result = $api_config->update_config($_POST['id'], $data);
        if ($result !== false) {
            if (isset($_POST['is_active']) && $_POST['is_active'] == 1) {
                $api_config->set_active_config($_POST['id']);
            }
            echo '<div class="notice notice-success"><p>' . __('配置已更新并保存到API列表。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('配置更新失败。', 'content-auto-manager') . '</p></div>';
        }
    } else {
        // 创建新配置
        $config_id = $api_config->create_config($data);
        if ($config_id) {
            if (isset($_POST['is_active']) && $_POST['is_active'] == 1) {
                $api_config->set_active_config($config_id);
            }
            echo '<div class="notice notice-success"><p>' . __('配置已创建并保存到API列表。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('配置创建失败。', 'content-auto-manager') . '</p></div>';
        }
    }
}

// 处理删除操作
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    // 验证nonce
    if (!wp_verify_nonce($_GET['nonce'], 'content_auto_manager_delete_config')) {
        wp_die(__('安全验证失败。'));
    }
    
    $api_config = new ContentAuto_ApiConfig();
    $result = $api_config->delete_config($_GET['id']);
    
    if ($result) {
        echo '<div class="notice notice-success"><p>' . __('配置已删除。', 'content-auto-manager') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('配置删除失败。', 'content-auto-manager') . '</p></div>';
    }
}

// 初始化预置API类
$predefined_api = new ContentAuto_PredefinedApi();

// 处理预置API配置更新
if (isset($_POST['predefined_api_nonce']) && wp_verify_nonce($_POST['predefined_api_nonce'], 'content_auto_manager_predefined_api')) {
    $channel = isset($_POST['predefined_api_channel']) ? sanitize_text_field($_POST['predefined_api_channel']) : 'pollinations';
    $is_active = isset($_POST['predefined_api_active']) ? 1 : 0;
    
    // 获取YOUR_TOKEN（可选字段）
    $api_token = '';
    if (isset($_POST['predefined_api_token']) && !empty($_POST['predefined_api_token'])) {
        $api_token = sanitize_text_field($_POST['predefined_api_token']);
    }
    
    // 获取当前配置
    $config = $predefined_api->get_config($channel);
    
    // 检查是否为编辑模式（优先检查POST数据，其次检查editing_predefined_channel变量）
    $is_edit_mode = false;
    if (isset($_POST['editing_predefined_channel'])) {
        $is_edit_mode = $_POST['editing_predefined_channel'] === $channel;
    } else {
        $is_edit_mode = isset($editing_predefined_channel) && $editing_predefined_channel === $channel;
    }
    
    if ($config && !$is_edit_mode) {
        // 非编辑模式下，渠道已存在，提示重复添加错误
        echo '<div class="notice notice-error"><p>' . __('已添加相同渠道，保存失败。', 'content-auto-manager') . '</p></div>';
    } elseif ($config && $is_edit_mode) {
        // 编辑模式：允许修改现有配置
        $api_config = new ContentAuto_ApiConfig();
        
        $update_data = array(
            'name' => $config['name'],
            'api_url' => $config['api_url'],
            'model_name' => $config['model_name'],
            'is_active' => $is_active
        );
        
        // 如果提供了YOUR_TOKEN，更新api_key字段
        if (!empty($api_token)) {
            $update_data['api_key'] = $api_token;
        } else {
            // 如果没有提供TOKEN，保持现有的api_key或设为空
            $update_data['api_key'] = '';
        }
        
        $result = $api_config->update_config($config['id'], $update_data, true);
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . __('预置API配置已更新。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('更新预置API配置失败。', 'content-auto-manager') . '</p></div>';
        }
    } elseif (!$config && !$is_edit_mode) {
        // 新建模式：渠道不存在，创建新配置
        $new_config = $predefined_api->create_config_record($channel, $is_active);
        
        if ($new_config) {
            // 如果提供了YOUR_TOKEN，更新api_key字段
            if (!empty($api_token)) {
                $api_config = new ContentAuto_ApiConfig();
                $update_data = array(
                    'name' => $new_config['name'],
                    'api_url' => $new_config['api_url'],
                    'model_name' => $new_config['model_name'],
                    'api_key' => $api_token,
                    'is_active' => $is_active
                );
                $api_config->update_config($new_config['id'], $update_data, true);
            }
            
            echo '<div class="notice notice-success"><p>' . __('预置API配置已添加到API列表。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('添加预置API配置失败。', 'content-auto-manager') . '</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>' . __('保存预置API配置失败。', 'content-auto-manager') . '</p></div>';
    }
}

// 获取要编辑的配置
$edit_config = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $api_config = new ContentAuto_ApiConfig();
    $edit_config = $api_config->get_config($_GET['id']);
}

// 获取所有配置
$api_config = new ContentAuto_ApiConfig();
$configs = $api_config->get_configs();

// 获取预置API激活状态和渠道信息
$predefined_api_active = $predefined_api->is_active();
$predefined_api_channels = $predefined_api->get_channels();

// 检查向量API配置状态
$existing_vector_config = $api_config->get_vector_config();
$vector_config_exists = !empty($existing_vector_config);
$show_vector_form = !$vector_config_exists || !empty($edit_config);

// 如果正在编辑预置API配置，设置选中的渠道
$selected_channel = 'pollinations'; // 默认选择第一个渠道
$editing_predefined_channel = null; // 当前编辑的预置API渠道
$editing_vector_config = false; // 当前编辑的向量API配置

if ($edit_config && !empty($edit_config['predefined_channel'])) {
    $selected_channel = $edit_config['predefined_channel'];
    $editing_predefined_channel = $edit_config['predefined_channel'];
} else if ($edit_config && (!empty($edit_config['vector_api_url']) || !empty($edit_config['vector_api_key']) || !empty($edit_config['vector_model_name']))) {
    $editing_vector_config = true;
} else if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    // 检查要编辑的配置类型
    $config_to_edit = $api_config->get_config($_GET['id']);
    if ($config_to_edit && !empty($config_to_edit['predefined_channel'])) {
        $selected_channel = $config_to_edit['predefined_channel'];
        $editing_predefined_channel = $config_to_edit['predefined_channel'];
    } elseif ($config_to_edit && (!empty($config_to_edit['vector_api_url']) || !empty($config_to_edit['vector_api_key']) || !empty($config_to_edit['vector_model_name']))) {
        $editing_vector_config = true;
    }
}

// 获取当前编辑的配置信息（用于预置API表单）
$config_to_edit = null;
if ($editing_predefined_channel) {
    $config_to_edit = $predefined_api->get_config($editing_predefined_channel);
}
?>

<div class="wrap">
    <h1><?php _e('API设置', 'content-auto-manager'); ?></h1>
    
      
    <?php 
// 获取当前激活的选项卡，默认为自定义API配置
$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'custom';

// 如果正在编辑配置，根据配置类型确定应该激活的选项卡
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $api_config_obj = new ContentAuto_ApiConfig();
    $config_to_edit = $api_config_obj->get_config($_GET['id']);
    if ($config_to_edit && !empty($config_to_edit['predefined_channel'])) {
        $active_tab = 'predefined';
    } elseif ($config_to_edit && (!empty($config_to_edit['vector_api_url']) || !empty($config_to_edit['vector_api_key']) || !empty($config_to_edit['vector_model_name']))) {
        $active_tab = 'vector';
    } else {
        $active_tab = 'custom';
    }
}
?>

<!-- 选项卡导航 -->
<div class="content-auto-tabs">
    <a href="?page=content-auto-manager-api&tab=custom" class="tab-button <?php echo $active_tab === 'custom' ? 'active' : ''; ?>">
        <?php _e('自定义API配置', 'content-auto-manager'); ?>
    </a>
    <a href="?page=content-auto-manager-api&tab=predefined" class="tab-button <?php echo $active_tab === 'predefined' ? 'active' : ''; ?>">
        <?php _e('预置API配置', 'content-auto-manager'); ?>
    </a>
    <a href="?page=content-auto-manager-api&tab=vector" class="tab-button <?php echo $active_tab === 'vector' ? 'active' : ''; ?>">
        <?php _e('向量API配置', 'content-auto-manager'); ?>
    </a>
</div>

<!-- 自定义API配置表单 -->
<div id="custom-tab" class="content-auto-tab-content <?php echo $active_tab === 'custom' ? 'active' : ''; ?>">
        <div class="content-auto-section">
            <h2><?php echo $edit_config ? __('编辑配置', 'content-auto-manager') : __('自定义API配置', 'content-auto-manager'); ?></h2>
            
            <!-- 硅基流动API推荐提示 -->
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left-color: #00a0d2;">
                <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('🚀 推荐使用硅基流动API', 'content-auto-manager'); ?></h4>
                <p style="margin: 0 0 10px 0; color: #23282d;"><?php _e('硅基流动API支持多种主流大模型，可以帮助您显著提升生成内容的多样性和质量。通过一个API接口，您可以灵活使用不同的模型来满足各种内容创作需求。', 'content-auto-manager'); ?></p>
                <p style="margin: 0; color: #23282d;">
                    <?php _e('立即注册：', 'content-auto-manager'); ?>
                    <a href="https://cloud.siliconflow.cn/i/fcqQ8oKi" target="_blank" style="color: #0073aa; text-decoration: none; font-weight: bold;">
                        https://cloud.siliconflow.cn/i/fcqQ8oKi
                    </a>
                </p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_api_config', 'content_auto_manager_nonce'); ?>
                
                <?php if ($edit_config): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($edit_config['id']); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('配置名称', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="name" value="<?php echo $edit_config ? esc_attr($edit_config['name']) : ''; ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API地址', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="url" name="api_url" value="<?php echo $edit_config ? esc_attr($edit_config['api_url']) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('例如: https://api.openai.com/v1/chat/completions', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API密钥', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="password" name="api_key" value="<?php echo $edit_config ? esc_attr($edit_config['api_key']) : ''; ?>" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('模型名称', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="model_name" value="<?php echo $edit_config ? esc_attr($edit_config['model_name']) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('例如: gpt-3.5-turbo', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('温度', 'content-auto-manager'); ?></th>
                        <td>
                            <label style="margin-right: 10px;">
                                <input type="checkbox" id="temperature_enabled" name="temperature_enabled" value="1" <?php echo (!isset($edit_config['temperature_enabled']) || $edit_config['temperature_enabled']) ? 'checked' : ''; ?>>
                                <?php _e('启用', 'content-auto-manager'); ?>
                            </label>
                            <input type="number" id="temperature" name="temperature" value="<?php echo $edit_config ? esc_attr($edit_config['temperature']) : '0.7'; ?>" step="0.1" min="0" max="2" class="small-text">
                            <p class="description"><?php _e('控制生成内容的随机性，0-2之间', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('最大Token数', 'content-auto-manager'); ?></th>
                        <td>
                            <label style="margin-right: 10px;">
                                <input type="checkbox" id="max_tokens_enabled" name="max_tokens_enabled" value="1" <?php echo (!isset($edit_config['max_tokens_enabled']) || $edit_config['max_tokens_enabled']) ? 'checked' : ''; ?>>
                                <?php _e('启用', 'content-auto-manager'); ?>
                            </label>
                            <input type="number" id="max_tokens" name="max_tokens" value="<?php echo $edit_config ? esc_attr($edit_config['max_tokens']) : '2000'; ?>" min="1" max="32000" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('流式输出 (Stream)', 'content-auto-manager'); ?></th>
                        <td>
                            <label style="margin-right: 10px;">
                                <input type="checkbox" id="stream_enabled" name="stream_enabled" value="1" disabled checked>
                                <?php _e('禁用', 'content-auto-manager'); ?>
                            </label>
                            <select id="stream" name="stream" style="width: auto;" disabled>
                                <option value="false" selected><?php _e('关闭', 'content-auto-manager'); ?></option>
                                <option value="true"><?php _e('开启', 'content-auto-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('流式输出功能已禁用。为确保插件稳定性和兼容性，所有API请求将使用标准响应格式。', 'content-auto-manager'); ?></p>
                            <input type="hidden" name="stream_enabled" value="0">
                            <input type="hidden" name="stream" value="false">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('核采样参数 (Top_p)', 'content-auto-manager'); ?></th>
                        <td>
                            <label style="margin-right: 10px;">
                                <input type="checkbox" id="top_p_enabled" name="top_p_enabled" value="1" <?php echo ($edit_config && isset($edit_config['top_p_enabled']) && $edit_config['top_p_enabled']) ? 'checked' : ''; ?>>
                                <?php _e('启用', 'content-auto-manager'); ?>
                            </label>
                            <input type="number" id="top_p" name="top_p" value="<?php echo ($edit_config && isset($edit_config['top_p'])) ? esc_attr($edit_config['top_p']) : '1.0'; ?>" step="0.1" min="0" max="1" class="small-text">
                            <p class="description"><?php _e('控制生成内容的多样性，0-1之间，默认1.0', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                      <tr>
                        <th scope="row"><?php _e('设为激活', 'content-auto-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?php echo ($edit_config && $edit_config['is_active']) ? 'checked' : ''; ?>>
                                <?php _e('将此配置设为当前激活的API配置', 'content-auto-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存到API列表', 'content-auto-manager')); ?>
            </form>
        </div>
    </div>
    
    <!-- 预置API配置表单 -->
<div id="predefined-tab" class="content-auto-tab-content <?php echo $active_tab === 'predefined' ? 'active' : ''; ?>">
        <div class="content-auto-section">
            <h2><?php echo $edit_config ? __('编辑预置API配置', 'content-auto-manager') : __('预置API配置', 'content-auto-manager'); ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_predefined_api', 'predefined_api_nonce'); ?>
                
                <?php if ($editing_predefined_channel): ?>
                    <input type="hidden" name="editing_predefined_channel" value="<?php echo esc_attr($editing_predefined_channel); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('渠道选择', 'content-auto-manager'); ?></th>
                        <td>
                            <?php if ($editing_predefined_channel): ?>
                                <!-- 编辑模式：显示当前编辑的渠道 -->
                                <input type="hidden" name="predefined_api_channel" value="<?php echo esc_attr($editing_predefined_channel); ?>">
                                <div style="padding: 8px 12px; background-color: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;">
                                    <strong><?php echo esc_html($predefined_api_channels[$editing_predefined_channel]['name']); ?></strong>
                                    <span style="color: #666; margin-left: 10px;"><?php _e('(编辑模式)', 'content-auto-manager'); ?></span>
                                </div>
                                <p class="description"><?php _e('当前正在编辑的预置API渠道', 'content-auto-manager'); ?></p>
                            <?php else: ?>
                                <!-- 新建模式：允许选择渠道 -->
                                <select name="predefined_api_channel" id="predefined-api-channel">
                                    <?php foreach ($predefined_api_channels as $channel_key => $channel_info): ?>
                                        <?php 
                                        // 检查渠道是否已存在配置
                                        $existing_config = $predefined_api->get_config($channel_key);
                                        $disabled = $existing_config ? 'disabled' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($channel_key); ?>" <?php selected($selected_channel, $channel_key); ?> <?php echo $disabled; ?>>
                                            <?php echo esc_html($channel_info['name']); ?>
                                            <?php if ($existing_config): ?>
                                                (已添加)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php _e('选择要使用的预置API渠道', 'content-auto-manager'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API地址', 'content-auto-manager'); ?></th>
                        <td>
                            <code id="predefined-api-url">https://text.pollinations.ai/{prompts}</code>
                            <p class="description"><?php _e('固定参数: model=openai, private=true, json=true, seed=随机数字', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('YOUR_TOKEN (可选)', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="predefined_api_token" id="predefined-api-token" 
                                   value="<?php echo esc_attr($config_to_edit['api_key'] ?? ''); ?>" 
                                   placeholder="<?php _e('请输入您的YOUR_TOKEN', 'content-auto-manager'); ?>" 
                                   class="regular-text">
                            <p class="description"><?php _e('如果需要使用认证功能，请在此输入您的YOUR_TOKEN。留空则不使用认证。<br>申请TOKEN地址：<a href="https://auth.pollinations.ai/" target="_blank">https://auth.pollinations.ai/</a><br>使用TOKEN后，速率限制由15秒请求一次提升为5秒请求一次。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('激活状态', 'content-auto-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="predefined_api_active" value="1" <?php checked($predefined_api_active); ?>>
                                <?php _e('将此配置设为当前激活的API配置', 'content-auto-manager'); ?>
                            </label>
                            <p class="description"><?php _e('激活后，该API配置将参与下游任务轮询', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('测试连接', 'content-auto-manager'); ?></th>
                        <td>
                            <button type="button" id="test-predefined-api" class="button button-secondary">
                                <?php _e('测试预置API连接', 'content-auto-manager'); ?>
                            </button>
                            <span id="test-predefined-api-result" class="test-result"></span>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存到API列表', 'content-auto-manager')); ?>
            </form>
        </div>
    </div>
    
    <!-- 向量API配置表单 -->
    <div id="vector-tab" class="content-auto-tab-content <?php echo $active_tab === 'vector' ? 'active' : ''; ?>">
        <div class="content-auto-section">
            <?php if ($vector_config_exists && empty($edit_config)): ?>
                <h2><?php _e('向量API配置', 'content-auto-manager'); ?></h2>
                
                <div class="notice notice-warning" style="margin: 20px 0; padding: 15px; border-left-color: #ffb900;">
                    <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('📝 向量API配置已存在', 'content-auto-manager'); ?></h4>
                    <p style="margin: 0 0 10px 0; color: #23282d;"><?php _e('系统中已存在一个向量API配置，每个系统只允许配置一个向量API。', 'content-auto-manager'); ?></p>
                    <p style="margin: 0; color: #23282d;">
                        <strong><?php _e('当前配置：', 'content-auto-manager'); ?></strong>
                        <?php echo esc_html($existing_vector_config['name']); ?> - 
                        <?php echo esc_html(content_auto_manager_truncate_string($existing_vector_config['vector_model_name'], 30)); ?>
                    </p>
                    <p style="margin: 10px 0 0 0; color: #23282d;">
                        <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'edit', 'id' => $existing_vector_config['id'], 'tab' => 'vector')), 'content_auto_manager_edit_config', 'nonce'); ?>" class="button button-primary">
                            <?php _e('编辑现有配置', 'content-auto-manager'); ?>
                        </a>
                    </p>
                </div>
            <?php else: ?>
                <h2><?php echo $edit_config ? __('编辑向量API配置', 'content-auto-manager') : __('向量API配置', 'content-auto-manager'); ?></h2>
            <?php endif; ?>
            
            <?php if ($show_vector_form): ?>
                <div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left-color: #00a0d2;">
                    <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('🔗 向量API配置说明', 'content-auto-manager'); ?></h4>
                    <p style="margin: 0 0 10px 0; color: #23282d;"><?php _e('向量API用于将文本内容转换为向量嵌入，支持语义搜索和内容相似度计算。配置向量API后，系统可以为生成的主题自动创建向量嵌入数据。', 'content-auto-manager'); ?></p>
                    <p style="margin: 0 0 10px 0; color: #23282d;">
                        <strong><?php _e('注意：系统只允许配置一个向量API，该配置将全局生效。', 'content-auto-manager'); ?></strong>
                    </p>
                    <p style="margin: 0; color: #23282d;">
                        <?php _e('支持的向量API包括：OpenAI Embeddings、Cohere Embeddings、本地向量服务等。', 'content-auto-manager'); ?>
                    </p>
                </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_api_config', 'content_auto_manager_nonce'); ?>
                
                <?php if ($edit_config): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($edit_config['id']); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('配置名称', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="name" value="<?php echo $edit_config ? esc_attr($edit_config['name']) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('为此向量API配置设置一个易于识别的名称', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API地址', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="url" name="vector_api_url" value="<?php echo $edit_config ? esc_attr($edit_config['vector_api_url']) : ''; ?>" class="regular-text" required>
                            <p class="description"><?php _e('向量API的完整URL地址，例如: https://api.openai.com/v1/embeddings', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API密钥', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="password" name="vector_api_key" value="" placeholder="留空则不修改" class="regular-text" <?php echo $edit_config ? '' : 'required'; ?>>
                            <p class="description"><?php _e('访问向量API所需的认证密钥', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API类型', 'content-auto-manager'); ?></th>
                        <td>
                            <select name="vector_api_type" id="vector-api-type" required>
                                <option value="openai" <?php echo ($edit_config && ($edit_config['vector_api_type'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>>
                                    <?php _e('OpenAI Embeddings', 'content-auto-manager'); ?>
                                </option>
                                <option value="jina" <?php echo ($edit_config && ($edit_config['vector_api_type'] ?? 'openai') === 'jina') ? 'selected' : ''; ?>>
                                    <?php _e('Jina Embeddings v4', 'content-auto-manager'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('选择向量API类型：OpenAI Embeddings 或 Jina Embeddings v4', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量模型名称', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="vector_model_name" id="vector-model-name" value="<?php echo $edit_config ? esc_attr($edit_config['vector_model_name']) : ''; ?>" class="regular-text" required>
                            <p class="description" id="vector-model-description"><?php _e('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存向量API配置', 'content-auto-manager')); ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
<style>
.content-auto-section {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.status-active {
    color: #00a32a;
    font-weight: bold;
}

.status-inactive {
    color: #666;
}

.config-type-predefined {
    color: #0073aa;
    font-weight: bold;
}

.config-type-custom {
    color: #666;
}

.config-type-vector {
    color: #28a745;
    font-weight: bold;
}

.button-small {
    padding: 4px 8px;
    font-size: 12px;
}

.test-result {
    margin-left: 10px;
    font-style: italic;
}

.test-result.success {
    color: #00a32a;
}

.test-result.error {
    color: #dc3232;
}

/* 选项卡样式 */
.content-auto-tabs {
    display: flex;
    margin-bottom: 20px;
    border-bottom: 1px solid #ccc;
}

.tab-button {
    background-color: #f1f1f1;
    border: 1px solid #ccc;
    border-bottom: none;
    padding: 10px 20px;
    cursor: pointer;
    margin-right: 5px;
    border-top-left-radius: 3px;
    border-top-right-radius: 3px;
    text-decoration: none;
    color: #333;
}

.tab-button.active {
    background-color: #fff;
    border-bottom: 1px solid #fff;
    margin-bottom: -1px;
}

.content-auto-tab-content {
    display: none;
}

.content-auto-tab-content.active {
    display: block;
}
</style><script>
document.addEventListener('DOMContentLoaded', function() {
    function toggleInput(checkboxId, inputId) {
        var checkbox = document.getElementById(checkboxId);
        var input = document.getElementById(inputId);

        if (!checkbox || !input) {
            return;
        }

        function updateState() {
            input.disabled = !checkbox.checked;
        }

        checkbox.addEventListener('change', updateState);
        
        // Set initial state on page load
        updateState();
    }

    toggleInput('temperature_enabled', 'temperature');
    toggleInput('max_tokens_enabled', 'max_tokens');
    // 流式输出功能已禁用，移除toggle控制
    toggleInput('top_p_enabled', 'top_p');
    
    // 测试预置API连接
    var testButton = document.getElementById('test-predefined-api');
    if (testButton) {
        testButton.addEventListener('click', function() {
            var resultElement = document.getElementById('test-predefined-api-result');
            resultElement.textContent = '<?php _e('测试中...', 'content-auto-manager'); ?>';
            resultElement.className = 'test-result';
            
            // 获取选择的渠道
            var channelSelect = document.getElementById('predefined-api-channel');
            var channel = channelSelect ? channelSelect.value : 'pollinations';
            
            // 发送AJAX请求
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            resultElement.textContent = response.data.message;
                            resultElement.className = 'test-result success';
                        } else {
                            resultElement.textContent = response.data.message;
                            resultElement.className = 'test-result error';
                        }
                    } else {
                        resultElement.textContent = '<?php _e('连接测试失败: 服务器错误', 'content-auto-manager'); ?>';
                        resultElement.className = 'test-result error';
                    }
                }
            };
            
            // 准备请求数据
            var data = 'action=content_auto_test_predefined_api&channel=' + encodeURIComponent(channel) + '&nonce=' + contentAutoManager.nonce;
            xhr.send(data);
        });
    }
    
    // 测试配置列表中的API连接
    var testApiButtons = document.querySelectorAll('.test-api-connection');
    testApiButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            var configId = this.getAttribute('data-config-id');
            var resultElement = this.nextElementSibling || this.parentNode.querySelector('.test-result');
            
            // 如果没有找到结果元素，创建一个
            if (!resultElement) {
                resultElement = document.createElement('span');
                resultElement.className = 'test-result';
                resultElement.style.marginLeft = '10px';
                this.parentNode.appendChild(resultElement);
            }
            
            resultElement.textContent = '<?php _e('测试中...', 'content-auto-manager'); ?>';
            resultElement.className = 'test-result';
            
            // 发送AJAX请求
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.success) {
                            resultElement.textContent = response.data.message;
                            resultElement.className = 'test-result success';
                        } else {
                            resultElement.textContent = response.data.message;
                            resultElement.className = 'test-result error';
                        }
                    } else {
                        resultElement.textContent = '<?php _e('连接测试失败: 服务器错误', 'content-auto-manager'); ?>';
                        resultElement.className = 'test-result error';
                    }
                }
            };
            
            // 准备请求数据
            var data = 'action=content_auto_test_api_connection&config_id=' + encodeURIComponent(configId) + '&nonce=' + contentAutoManager.nonce;
            xhr.send(data);
        });
    }

    // 向量API类型选择动态处理
    var vectorApiTypeSelect = document.getElementById('vector-api-type');
    var vectorUrlInput = document.querySelector('input[name="vector_api_url"]');
    var vectorModelInput = document.getElementById('vector-model-name');
    var vectorModelDescription = document.getElementById('vector-model-description');

    if (vectorApiTypeSelect) {
        function updateVectorFields() {
            var selectedType = vectorApiTypeSelect.value;

            if (selectedType === 'openai') {
                // OpenAI Embeddings 配置
                if (vectorUrlInput && vectorUrlInput.value === '') {
                    vectorUrlInput.value = 'https://api.openai.com/v1/embeddings';
                }
                if (vectorModelInput && vectorModelInput.value === '') {
                    vectorModelInput.value = 'text-embedding-ada-002';
                }
                if (vectorModelDescription) {
                    vectorModelDescription.textContent = '<?php _e('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'content-auto-manager'); ?>';
                }
            } else if (selectedType === 'jina') {
                // Jina Embeddings v4 配置
                if (vectorUrlInput && vectorUrlInput.value === '') {
                    vectorUrlInput.value = 'https://api.jina.ai/v1/embeddings';
                }
                if (vectorModelInput && vectorModelInput.value === '') {
                    vectorModelInput.value = 'jina-embeddings-v4';
                }
                if (vectorModelDescription) {
                    vectorModelDescription.textContent = '<?php _e('Jina Embeddings v4 固定为1024维，请使用: jina-embeddings-v4', 'content-auto-manager'); ?>';
                }
            }
        }

        // 监听类型变化
        vectorApiTypeSelect.addEventListener('change', updateVectorFields);

        // 页面加载时初始化
        updateVectorFields();
    }
});
</script>

    <!-- 任务处理规则与当前配置 -->
    <div class="content-auto-section">
        <h2><?php _e('任务处理规则与当前配置', 'content-auto-manager'); ?></h2>
        
        <h3><?php _e('当前配置状态', 'content-auto-manager'); ?></h3>
        <p class="description">
            <?php _e('以下为当前系统中固定使用的默认任务处理参数。这些值现在直接由代码定义，不再提供后台设置。', 'content-auto-manager'); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('不同子任务最小间隔', 'content-auto-manager'); ?></th>
                <td>
                    <code><?php echo esc_html(CONTENT_AUTO_MIN_API_INTERVAL); ?> <?php _e('秒', 'content-auto-manager'); ?></code>
                    <p class="description"><?php _e('系统在处理同一个父任务下的不同子任务时，两次API调用之间的最小等待时间。', 'content-auto-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('失败后重试次数', 'content-auto-manager'); ?></th>
                <td>
                    <code><?php echo esc_html(CONTENT_AUTO_MAX_RETRIES); ?> <?php _e('次', 'content-auto-manager'); ?></code>
                    <p class="description"><?php _e('单个子任务在首次失败后，系统将尝试重新执行的最大次数。', 'content-auto-manager'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('每次重试间隔', 'content-auto-manager'); ?></th>
                <td>
                    <code><?php echo esc_html(CONTENT_AUTO_DEFAULT_RETRY_DELAY); ?> <?php _e('秒', 'content-auto-manager'); ?></code>
                    <p class="description"><?php _e('在每次重试之前，系统等待的时间。', 'content-auto-manager'); ?></p>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- 配置列表 -->
    <div class="content-auto-section">
        <h2><?php _e('配置列表', 'content-auto-manager'); ?></h2>
        
        <div class="notice notice-info" style="margin: 15px 0;">
            <p><?php _e('<strong>说明：</strong>', 'content-auto-manager'); ?></p>
            <ul style="margin: 10px 0 0 20px;">
                <li><?php _e('<strong>向量API配置</strong>：全局唯一，用于文本嵌入向量生成，不需要激活状态', 'content-auto-manager'); ?></li>
                <li><?php _e('<strong>自定义API配置</strong>：用于大模型文本生成，支持多个配置和轮询机制', 'content-auto-manager'); ?></li>
                <li><?php _e('<strong>预置API配置</strong>：预设的API服务，可直接使用', 'content-auto-manager'); ?></li>
            </ul>
            <p style="margin: 10px 0 0 0;"><?php _e('各种API配置相互独立，分别在不同的任务中使用。', 'content-auto-manager'); ?></p>
        </div>
        
        <?php if (empty($configs)): ?>
            <p><?php _e('暂无API配置，请添加一个配置。', 'content-auto-manager'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('名称', 'content-auto-manager'); ?></th>
                        <th><?php _e('API地址', 'content-auto-manager'); ?></th>
                        <th><?php _e('模型', 'content-auto-manager'); ?></th>
                        <th><?php _e('类型', 'content-auto-manager'); ?></th>
                        <th><?php _e('状态', 'content-auto-manager'); ?></th>
                        <th><?php _e('操作', 'content-auto-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configs as $config): ?>
                        <tr>
                            <td><?php echo esc_html($config['name']); ?></td>
                            <td><?php echo esc_html(content_auto_manager_truncate_string($config['api_url'], 30)); ?></td>
                            <td><?php echo esc_html($config['model_name']); ?></td>
                            <td>
                                <?php if (!empty($config['predefined_channel'])): ?>
                                    <span class="config-type-predefined"><?php _e('预置API', 'content-auto-manager'); ?></span>
                                <?php elseif (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])): ?>
                                    <span class="config-type-vector"><?php _e('向量API', 'content-auto-manager'); ?></span>
                                <?php else: ?>
                                    <span class="config-type-custom"><?php _e('自定义API', 'content-auto-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])): ?>
                                    <span class="status-active" title="<?php _e('向量API配置全局生效，无需激活状态', 'content-auto-manager'); ?>"><?php _e('已配置', 'content-auto-manager'); ?></span>
                                <?php elseif ($config['is_active']): ?>
                                    <span class="status-active"><?php _e('激活', 'content-auto-manager'); ?></span>
                                <?php else: ?>
                                    <span class="status-inactive"><?php _e('未激活', 'content-auto-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($config['predefined_channel'])): ?>
                                    <!-- 自定义API和向量API可以编辑和删除 -->
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'edit', 'id' => $config['id'], 'tab' => (!empty($config['predefined_channel']) ? 'predefined' : ((!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) ? 'vector' : 'custom')))), 'content_auto_manager_edit_config', 'nonce'); ?>" class="button button-small">
                                        <?php _e('编辑', 'content-auto-manager'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $config['id'])), 'content_auto_manager_delete_config', 'nonce'); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php _e('确定要删除此配置吗？', 'content-auto-manager'); ?>')">
                                        <?php _e('删除', 'content-auto-manager'); ?>
                                    </a>
                                    <a href="#" class="button button-small test-api-connection" data-config-id="<?php echo esc_attr($config['id']); ?>">
                                        <?php _e('测试', 'content-auto-manager'); ?>
                                    </a>
                                    <span class="test-result"></span>
                                <?php else: ?>
                                    <!-- 预置API可以测试、编辑和删除 -->
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'edit', 'id' => $config['id'], 'tab' => (!empty($config['predefined_channel']) ? 'predefined' : ((!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) ? 'vector' : 'custom')))), 'content_auto_manager_edit_config', 'nonce'); ?>" class="button button-small">
                                        <?php _e('编辑', 'content-auto-manager'); ?>
                                    </a>
                                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $config['id'])), 'content_auto_manager_delete_config', 'nonce'); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php _e('确定要删除此配置吗？', 'content-auto-manager'); ?>')">
                                        <?php _e('删除', 'content-auto-manager'); ?>
                                    </a>
                                    <a href="#" class="button button-small test-api-connection" data-config-id="<?php echo esc_attr($config['id']); ?>">
                                        <?php _e('测试', 'content-auto-manager'); ?>
                                    </a>
                                    <span class="test-result"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
