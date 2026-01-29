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

// 处理 Jina Reader API 配置表单提交
if (isset($_POST['submit_reader_api'])) {
    if (!isset($_POST['content_auto_manager_reader_nonce']) || !wp_verify_nonce($_POST['content_auto_manager_reader_nonce'], 'content_auto_manager_reader_config')) {
        wp_die(__('安全验证失败。'));
    }
    $jina_api_key = sanitize_text_field($_POST['jina_api_key']);
    update_option('content_auto_jina_api_key', $jina_api_key);

    // 保存搜索黑名单
    $blacklist_raw = isset($_POST['material_search_blacklist']) ? $_POST['material_search_blacklist'] : '';
    $blacklist_arr = array_filter(array_map('trim', explode("\n", $blacklist_raw)));
    update_option('content_auto_material_search_blacklist', array_values($blacklist_arr));
    echo '<div class="notice notice-success"><p>' . __('Jina Reader API 配置已保存。', 'content-auto-manager') . '</p></div>';
}

// 处理搜索API配置表单提交
if (isset($_POST['submit_search_api'])) {
    // 验证nonce
    if (!isset($_POST['content_auto_manager_search_nonce']) || !wp_verify_nonce($_POST['content_auto_manager_search_nonce'], 'content_auto_manager_search_config')) {
        wp_die(__('安全验证失败。'));
    }

    $search_settings = array(
        'region' => sanitize_text_field($_POST['search_region']),
        'max_results' => intval($_POST['search_max_results']),
        'safesearch' => sanitize_text_field($_POST['search_safesearch']),
        'time' => sanitize_text_field($_POST['search_time']),
        'backend' => sanitize_text_field($_POST['search_backend']),
    );

    update_option('content_auto_search_settings', $search_settings);
    echo '<div class="notice notice-success"><p>' . __('搜索API配置已保存。', 'content-auto-manager') . '</p></div>';
}

// 处理自定义API和向量API表单提交（排除预置API）
if ((isset($_POST['submit']) || isset($_POST['submit_custom_api']) || isset($_POST['submit_vector_api'])) && !isset($_POST['submit_predefined_api']) && !isset($_POST['predefined_api_nonce'])) {
    
    // 验证nonce - 支持两种nonce字段
    $nonce_valid = false;
    if (isset($_POST['content_auto_manager_nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_api_config');
    } elseif (isset($_POST['content_auto_manager_vector_nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['content_auto_manager_vector_nonce'], 'content_auto_manager_api_config');
    }
    
    if (!$nonce_valid) {
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
} else {
    // 新建模式：检查哪个渠道还没有配置，优先选择
    $pollinations_config = $predefined_api->get_config('pollinations');
    $official_config = $predefined_api->get_config('official');
    
    if (!$pollinations_config && !$official_config) {
        // 如果两个渠道都没有配置，默认选择pollinations
        $selected_channel = 'pollinations';
    } elseif (!$pollinations_config) {
        // 如果pollinations没有配置，选择它
        $selected_channel = 'pollinations';
    } elseif (!$official_config) {
        // 如果official没有配置，选择它
        $selected_channel = 'official';
    } else {
        // 如果都已配置，默认选择pollinations（虽然会被禁用）
        $selected_channel = 'pollinations';
    }
}

// 获取当前编辑的配置信息（用于预置API表单）
$config_to_edit = null;
if ($editing_predefined_channel) {
    // 编辑模式：使用数据库中的实际配置数据（包含is_active字段）
    if ($edit_config) {
        $config_to_edit = $edit_config;
    } else {
        $config_to_edit = $predefined_api->get_config($editing_predefined_channel);
    }
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
    <a href="?page=content-auto-manager-api&tab=search" class="tab-button <?php echo $active_tab === 'search' ? 'active' : ''; ?>">
        <?php _e('搜索API设置', 'content-auto-manager'); ?>
    </a>
    <a href="?page=content-auto-manager-api&tab=reader" class="tab-button <?php echo $active_tab === 'reader' ? 'active' : ''; ?>">
        <?php _e('Jina Reader API', 'content-auto-manager'); ?>
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
                            <input type="password" name="api_key" value="<?php echo $edit_config ? esc_attr($edit_config['api_key']) : ''; ?>" class="regular-text" autocomplete="current-password" required>
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
                
                <?php submit_button(__('保存到API列表', 'content-auto-manager'), 'primary', 'submit_custom_api'); ?>
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
                    <tr id="api-url-row">
                        <th scope="row"><?php _e('API地址', 'content-auto-manager'); ?></th>
                        <td>
                            <code id="predefined-api-url">
                                <?php 
                                // 根据选中的渠道显示相应的API地址
                                if ($selected_channel === 'official') {
                                    echo 'https://key.kdjingpai.com/api-proxy.php';
                                } else {
                                    echo 'https://text.pollinations.ai/{prompts}';
                                }
                                ?>
                            </code>
                            <p class="description">
                                <?php 
                                // 根据选中的渠道显示相应的描述
                                if ($selected_channel === 'official') {
                                    _e('插件官方API服务，通过授权码验证使用。<br><strong>如何申请使用：</strong><br>1. 联系插件作者微信：qn006699 获取插件授权码后使用<br>2. 在发布规则中配置授权码<br>3. 即可开始使用官方API服务', 'content-auto-manager');
                                } else {
                                    _e('固定参数: model=openai, private=true, json=true, seed=随机数字', 'content-auto-manager');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('YOUR_TOKEN (可选)', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="text" name="predefined_api_token" id="predefined-api-token" 
                                   value="<?php echo esc_attr($config_to_edit['api_key'] ?? ''); ?>" 
                                   placeholder="<?php _e('请输入您的YOUR_TOKEN', 'content-auto-manager'); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php 
                                // 根据选中的渠道显示相应的TOKEN描述
                                if ($selected_channel === 'official') {
                                    _e('插件官方API使用授权码认证，无需在此输入TOKEN。请在发布规则中配置授权码。<br><strong>获取授权码方式：</strong><br>• 联系插件作者微信：qn006699 获取插件授权码后使用', 'content-auto-manager');
                                } else {
                                    _e('如果需要使用认证功能，请在此输入您的YOUR_TOKEN。留空则不使用认证。<br>申请TOKEN地址：<a href="https://auth.pollinations.ai/" target="_blank">https://auth.pollinations.ai/</a><br>使用TOKEN后，速率限制由15秒请求一次提升为5秒请求一次。', 'content-auto-manager');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="quota-info-row" style="display: none;">
                        <th scope="row"><?php _e('剩余配额', 'content-auto-manager'); ?></th>
                        <td>
                            <div id="quota-info-display" style="font-size: 16px; font-weight: bold; margin-bottom: 8px;">
                                <span id="quota-info-result" class="quota-result"><?php _e('正在获取配额信息...', 'content-auto-manager'); ?></span>
                            </div>
                            <p class="description"><?php _e('当前域名授权码的剩余使用次数，一次成功API请求消耗1次，缺少授权码或配额不足时请联系插件作者微信：qn006699 进行充值', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('激活状态', 'content-auto-manager'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="predefined_api_active" value="1" <?php 
                                // 编辑模式：显示当前编辑配置的激活状态
                                if ($editing_predefined_channel && $config_to_edit) {
                                    checked($config_to_edit['is_active']);
                                } else {
                                    // 新建模式：默认不激活
                                    echo '';
                                }
                                ?>>
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
                
                <?php submit_button(__('保存到API列表', 'content-auto-manager'), 'primary', 'submit_predefined_api'); ?>
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
                <?php wp_nonce_field('content_auto_manager_api_config', 'content_auto_manager_vector_nonce'); ?>
                
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
                            <input type="password" name="vector_api_key" value="" placeholder="留空则不修改" class="regular-text" autocomplete="current-password" <?php echo $edit_config ? '' : 'required'; ?>>
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
                
                <?php submit_button(__('保存向量API配置', 'content-auto-manager'), 'primary', 'submit_vector_api'); ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 搜索API配置表单 -->
    <div id="search-tab" class="content-auto-tab-content <?php echo $active_tab === 'search' ? 'active' : ''; ?>">
        <div class="content-auto-section">
            <h2><?php _e('搜索API设置', 'content-auto-manager'); ?></h2>
            
            <?php 
            $search_settings = get_option('content_auto_search_settings', []);
            $region = isset($search_settings['region']) ? $search_settings['region'] : 'wt-wt';
            $max_results = isset($search_settings['max_results']) ? intval($search_settings['max_results']) : 10;
            $safesearch = isset($search_settings['safesearch']) ? $search_settings['safesearch'] : 'moderate';
            $time = isset($search_settings['time']) ? $search_settings['time'] : '';
            $backend = isset($search_settings['backend']) ? $search_settings['backend'] : 'html';
            ?>

            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left-color: #00a0d2;">
                <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('🔍 搜索API配置说明', 'content-auto-manager'); ?></h4>
                <p style="margin: 0 0 10px 0; color: #23282d;"><?php _e('此API是系统内置的搜索服务(基于DuckDuckGo)，不需要繁琐的密钥配置。', 'content-auto-manager'); ?></p>
                <p style="margin: 0; color: #23282d;">
                    <?php _e('您可以在此调整搜索的默认行为参数。', 'content-auto-manager'); ?>
                </p>
            </div>
            
            <?php
            // 获取授权信息用于显示
            $current_license = get_option('content_auto_manager_license_key', '');
            
            // 获取当前域名逻辑 (与类中逻辑保持一致)
            $current_domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            if (empty($current_domain)) {
                $site_url = get_site_url();
                $parsed = parse_url($site_url);
                $current_domain = $parsed['host'] ?? '';
            }
            // 提取根域名 (例如 test.58jingpai.com -> 58jingpai.com)
            if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $current_domain, $regs)) {
                $current_domain = $regs['domain'];
            }
            ?>
            
             <div class="notice notice-warning" style="margin: 20px 0; padding: 15px; border-left-color: #f0ad4e;">
                <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('🔑 授权信息诊断', 'content-auto-manager'); ?></h4>
                <p style="margin: 0 0 5px 0; color: #23282d;">
                    <strong><?php _e('当前读取到的授权码 License Key:', 'content-auto-manager'); ?></strong> 
                    <code><?php echo !empty($current_license) ? esc_html($current_license) : __('未设置 (请在发布规则中配置)', 'content-auto-manager'); ?></code>
                </p>
                <p style="margin: 0; color: #23282d;">
                    <strong><?php _e('当前识别到的域名 Domain:', 'content-auto-manager'); ?></strong> 
                    <code><?php echo esc_html($current_domain); ?></code>
                </p>
                <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                    <?php _e('* 搜索API将使用上述授权码和域名进行鉴权。如遇 401 错误，请检查您的授权码是否有效，以及域名是否与授权绑定的域名一致。', 'content-auto-manager'); ?>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_search_config', 'content_auto_manager_search_nonce'); ?>
                <!-- 用于测试连接的nonce -->
                <?php wp_nonce_field('content_auto_manager_nonce', 'test_search_nonce_field'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('搜索地区 (Region)', 'content-auto-manager'); ?></th>
                        <td>
                            <select name="search_region">
                                <option value="wt-wt" <?php selected($region, 'wt-wt'); ?>><?php _e('全球 (wt-wt)', 'content-auto-manager'); ?></option>
                                <option value="cn-zh" <?php selected($region, 'cn-zh'); ?>><?php _e('中国 (cn-zh)', 'content-auto-manager'); ?></option>
                                <option value="us-en" <?php selected($region, 'us-en'); ?>><?php _e('美国 (us-en)', 'content-auto-manager'); ?></option>
                                <option value="jp-jp" <?php selected($region, 'jp-jp'); ?>><?php _e('日本 (jp-jp)', 'content-auto-manager'); ?></option>
                                <option value="uk-en" <?php selected($region, 'uk-en'); ?>><?php _e('英国 (uk-en)', 'content-auto-manager'); ?></option>
                                <option value="de-de" <?php selected($region, 'de-de'); ?>><?php _e('德国 (de-de)', 'content-auto-manager'); ?></option>
                                <option value="fr-fr" <?php selected($region, 'fr-fr'); ?>><?php _e('法国 (fr-fr)', 'content-auto-manager'); ?></option>
                            </select>
                            <p class="description"><?php _e('选择搜索结果的优先地区。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('最大结果数 (Max Results)', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="number" name="search_max_results" value="<?php echo esc_attr($max_results); ?>" min="1" max="50" class="small-text">
                            <p class="description"><?php _e('返回搜索结果的最大数量 (1-50)。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('安全搜索 (Safe Search)', 'content-auto-manager'); ?></th>
                        <td>
                            <select name="search_safesearch">
                                <option value="moderate" <?php selected($safesearch, 'moderate'); ?>><?php _e('适中 (Moderate)', 'content-auto-manager'); ?></option>
                                <option value="off" <?php selected($safesearch, 'off'); ?>><?php _e('关闭 (Off)', 'content-auto-manager'); ?></option>
                                <option value="on" <?php selected($safesearch, 'on'); ?>><?php _e('严格 (Strict)', 'content-auto-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><?php _e('时间范围 (Time)', 'content-auto-manager'); ?></th>
                        <td>
                            <select name="search_time">
                                <option value="" <?php selected($time, ''); ?>><?php _e('不限', 'content-auto-manager'); ?></option>
                                <option value="d" <?php selected($time, 'd'); ?>><?php _e('过去一天', 'content-auto-manager'); ?></option>
                                <option value="w" <?php selected($time, 'w'); ?>><?php _e('过去一周', 'content-auto-manager'); ?></option>
                                <option value="m" <?php selected($time, 'm'); ?>><?php _e('过去一月', 'content-auto-manager'); ?></option>
                                <option value="y" <?php selected($time, 'y'); ?>><?php _e('过去一年', 'content-auto-manager'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('后端引擎 (Backend)', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="hidden" name="search_backend" value="lite">
                            <input type="text" value="Lite (轻量级 - 速度快)" class="regular-text" disabled>
                            <p class="description"><?php _e('系统强制使用 Lite 引擎，不允许修改。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存搜索配置', 'content-auto-manager'), 'primary', 'submit_search_api'); ?>
            </form>
            
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">

            <h3><?php _e('公共调用方法 (Helper Function)', 'content-auto-manager'); ?></h3>
            <div class="notice notice-success" style="margin: 10px 0; padding: 15px; border-left-color: #28a745;">
                <p style="margin: 0 0 10px 0;"><strong><?php _e('如何在其他代码中调用搜索？', 'content-auto-manager'); ?></strong></p>
                <p style="margin: 0;"><?php _e('系统已封装好全局辅助函数，您可以在主题或插件的任何位置直接调用，无需关心鉴权和配置。', 'content-auto-manager'); ?></p>
            </div>
            <p>
                <textarea class="large-text code" rows="22" readonly style="font-family: monospace; background: #f6f7f7;">
// 直接调用搜索，自动使用后台配置（推荐）
$results = content_auto_search('关键词');

if (!is_wp_error($results) && $results['success']) {
    // 处理结果列表
    foreach ($results['results'] as $item) {
        echo $item['title'];    // 标题
        echo $item['link'];     // 链接
        echo $item['snippet'];  // 摘要
        echo $item['position']; // 排名
    }
}

/* 
 * 返回数据结构示例:
 * [
 *   "success" => true,
 *   "count" => 10,
 *   "results" => [
 *     [
 *       "title" => "标题...",
 *       "link" => "https://...",
 *       "snippet" => "摘要内容...",
 *       "position" => 1
 *     ],
 *     ...
 *   ]
 * ]
 */</textarea>
            </p>
            
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">
            
            <h3><?php _e('搜索测试区域', 'content-auto-manager'); ?></h3>
            <table class="form-table">
                 <tr>
                    <th scope="row"><?php _e('测试关键词', 'content-auto-manager'); ?></th>
                    <td>
                        <input type="text" id="test_search_query" class="regular-text" placeholder="输入关键词" style="width: 300px;">
                        <button type="button" id="btn_test_search" class="button button-secondary"><?php _e('立即搜索', 'content-auto-manager'); ?></button>
                    </td>
                </tr>
                 <tr>
                    <th scope="row"><?php _e('测试结果', 'content-auto-manager'); ?></th>
                    <td>
                        <div id="search_test_result" style="background:#f0f6fc; padding:15px; border:1px solid #c3c4c7; max-height: 400px; overflow:auto; border-radius:4px; min-height: 100px;">
                            <span style="color:#666;"><?php _e('结果将显示在这里...', 'content-auto-manager'); ?></span>
                        </div>
                    </td>
                </tr>
            </table>
            
            <script>
            jQuery(document).ready(function($) {
                $('#btn_test_search').on('click', function() {
                    var query = $('#test_search_query').val();
                    var resultDiv = $('#search_test_result');
                    
                    if (!query) {
                        alert('<?php _e('请输入搜索关键词', 'content-auto-manager'); ?>');
                        return;
                    }
                    
                    resultDiv.html('<?php _e('正在搜索...', 'content-auto-manager'); ?>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_test_search_api',
                            nonce: $('#test_search_nonce_field').val(),
                            query: query
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;
                                var html = '<p><strong><?php _e('找到结果数：', 'content-auto-manager'); ?></strong> ' + data.count + '</p>';
                                
                                if (data.results && data.results.length > 0) {
                                    $.each(data.results, function(index, item) {
                                        html += '<div style="margin-bottom:15px; border-bottom:1px solid #ddd; padding-bottom:10px;">';
                                        html += '<div style="font-weight:bold; margin-bottom:5px;">';
                                        var pos = (item.position !== undefined) ? item.position : (index + 1);
                                        html += '<span style="color:#666; margin-right:5px;">[' + pos + ']</span>';
                                        html += '<a href="' + item.link + '" target="_blank" style="text-decoration:none;">' + item.title + '</a>';
                                        html += '</div>';
                                        html += '<div style="font-size:13px; line-height:1.5;">' + item.snippet + '</div>';
                                        html += '<div style="font-size:12px; color:#00a32a; margin-top:3px;">' + item.link + '</div>';
                                        html += '</div>';
                                    });
                                } else {
                                    html += '<p><?php _e('未找到相关结果。', 'content-auto-manager'); ?></p>';
                                }
                                
                                resultDiv.html(html);
                            } else {
                                resultDiv.html('<span style="color:#dc3232;"><?php _e('错误：', 'content-auto-manager'); ?> ' + (response.data.message || 'Unknown error') + '</span>');
                            }
                        },
                        error: function(xhr, status, error) {
                            resultDiv.html('<span style="color:#dc3232;"><?php _e('系统错误：', 'content-auto-manager'); ?> ' + error + '</span>');
                        }
                    });
                });
            });
            </script>
        </div>
    </div>
    
    <!-- Jina Reader API 配置表单 -->
    <div id="reader-tab" class="content-auto-tab-content <?php echo $active_tab === 'reader' ? 'active' : ''; ?>">
        <div class="content-auto-section">
            <h2><?php _e('Jina Reader API 配置', 'content-auto-manager'); ?></h2>
            
            <div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left-color: #00a0d2;">
                <h4 style="margin: 0 0 10px 0; color: #23282d;"><?php _e('🚀 Jina Reader API 说明', 'content-auto-manager'); ?></h4>
                <p style="margin: 0 0 10px 0; color: #23282d;"><?php _e('Jina Reader 用于将网页内容抓取并转换为对 LLM 友好的 Markdown 格式。', 'content-auto-manager'); ?></p>
                <p style="margin: 0 0 10px 0; color: #23282d;">
                    <?php _e('申请地址：', 'content-auto-manager'); ?>
                    <a href="https://jina.ai/reader/" target="_blank" style="color: #0073aa; text-decoration: none; font-weight: bold;">
                        https://jina.ai/reader/
                    </a>
                </p>
                <p style="margin: 0; color: #23282d;">
                    <?php _e('配置说明：留空则使用免费匿名模式（20次/分钟）。填入 KEY 可解锁更高频次（500次/分钟）。', 'content-auto-manager'); ?>
                </p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_reader_config', 'content_auto_manager_reader_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'content-auto-manager'); ?></th>
                        <td>
                            <input type="password" name="jina_api_key" value="<?php echo esc_attr(get_option('content_auto_jina_api_key', '')); ?>" class="regular-text" placeholder="jina_..." autocomplete="off">
                            <p class="description"><?php _e('请输入您的 Jina Reader API Key。留空则使用匿名模式。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('搜索结果过滤黑名单', 'content-auto-manager'); ?></th>
                        <td>
                            <?php 
                            $blacklist = get_option('content_auto_material_search_blacklist', ['csdn.net', 'zhihu.com']);
                            $blacklist_str = is_array($blacklist) ? implode("\n", $blacklist) : $blacklist;
                            ?>
                            <textarea name="material_search_blacklist" rows="6" class="large-text code" placeholder="csdn.net&#10;zhihu.com"><?php echo esc_textarea($blacklist_str); ?></textarea>
                            <p class="description"><?php _e('在此处管理需要在“搜索物料”阶段自动剔除的域名或关键词。每行一个。<br>默认建议过滤 <code>csdn.net</code> 和 <code>zhihu.com</code> 等 UGC 内容平台以保证素材专业度。', 'content-auto-manager'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存 Jina Reader 配置', 'content-auto-manager'), 'primary', 'submit_reader_api'); ?>
            </form>
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

.quota-result {
    margin-left: 10px;
    font-style: italic;
}

.quota-result.success {
    color: #00a32a;
}

.quota-result.error {
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
    
    // 获取配额信息函数
    function getQuotaInfo(channel) {
        var resultElement = document.getElementById('quota-info-result');
        if (!resultElement) return;
        
        // 只有插件官方API才支持获取配额信息
        if (channel !== 'official') {
            return;
        }
        
        resultElement.textContent = '<?php _e('正在获取配额信息...', 'content-auto-manager'); ?>';
        resultElement.className = 'quota-result';
        
        // 发送AJAX请求
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        var quota = response.data.quota_balance || 0;
                        var color = quota > 10 ? '#28a745' : (quota > 0 ? '#ffc107' : '#dc3545');
                        var statusText = quota > 10 ? '充足' : (quota > 0 ? '不足' : '已用完');
                        resultElement.innerHTML = '<span style="color: ' + color + '; font-size: 18px;">' + quota + ' 次</span> <span style="color: #666; font-size: 14px;">(' + statusText + ')</span>';
                        resultElement.className = 'quota-result success';
                    } else {
                        resultElement.innerHTML = '<span style="color: #dc3232;">' + response.data.message + '</span>';
                        resultElement.className = 'quota-result error';
                    }
                } else {
                    resultElement.innerHTML = '<span style="color: #dc3232;"><?php _e('获取配额信息失败: 服务器错误', 'content-auto-manager'); ?></span>';
                    resultElement.className = 'quota-result error';
                }
            }
        };
        
        // 准备请求数据
        var data = 'action=content_auto_get_quota_info&channel=' + encodeURIComponent(channel) + '&nonce=' + contentAutoManager.nonce;
        xhr.send(data);
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
    });

    // 预置API渠道切换动态处理
    var predefinedChannelSelect = document.getElementById('predefined-api-channel');
    var predefinedApiUrl = document.getElementById('predefined-api-url');
    var predefinedApiDescription = predefinedApiUrl ? predefinedApiUrl.nextElementSibling : null;
    var predefinedTokenInput = document.querySelector('input[name="predefined_api_token"]');
    var predefinedTokenDescription = predefinedTokenInput ? predefinedTokenInput.parentNode.querySelector('.description') : null;

    // 获取当前选择的渠道值（支持编辑模式和新建模式）
    function getCurrentSelectedChannel() {
        // 新建模式：从select获取
        if (predefinedChannelSelect && predefinedChannelSelect.value && !predefinedChannelSelect.disabled) {
            return predefinedChannelSelect.value;
        }
        
        // 编辑模式：从隐藏字段获取
        var hiddenChannelInput = document.querySelector('input[name="predefined_api_channel"][type="hidden"]');
        if (hiddenChannelInput && hiddenChannelInput.value) {
            return hiddenChannelInput.value;
        }
        
        // 新建模式但select可能被禁用：从选中的option获取
        if (predefinedChannelSelect) {
            var selectedOption = predefinedChannelSelect.querySelector('option:checked') || predefinedChannelSelect.querySelector('option[selected]');
            if (selectedOption && selectedOption.value) {
                return selectedOption.value;
            }
        }
        
        // 最后的默认值
        return '<?php echo esc_js($selected_channel); ?>';
    }

    function updatePredefinedChannelInfo() {
        var selectedChannel = getCurrentSelectedChannel();
        
        // 获取TOKEN相关的DOM元素
        var tokenRow = predefinedTokenInput ? predefinedTokenInput.closest('tr') : null;
        // 获取配额信息行
        var quotaRow = document.getElementById('quota-info-row');
        // 获取API地址行
        var apiUrlRow = document.getElementById('api-url-row');

        if (selectedChannel === 'pollinations') {
            // Pollinations渠道配置
            if (predefinedApiUrl) {
                predefinedApiUrl.textContent = 'https://text.pollinations.ai/{prompts}';
            }
            if (predefinedApiDescription) {
                predefinedApiDescription.innerHTML = <?php echo json_encode(__('固定参数: model=openai, private=true, json=true, seed=随机数字', 'content-auto-manager')); ?>;
            }
            if (predefinedTokenDescription) {
                predefinedTokenDescription.innerHTML = <?php echo json_encode(__('如果需要使用认证功能，请在此输入您的YOUR_TOKEN。留空则不使用认证。<br>申请TOKEN地址：<a href="https://auth.pollinations.ai/" target="_blank">https://auth.pollinations.ai/</a><br>使用TOKEN后，速率限制由15秒请求一次提升为5秒请求一次。', 'content-auto-manager')); ?>;
            }
            // 显示TOKEN输入框
            if (tokenRow) {
                tokenRow.style.display = '';
            }
            // 显示API地址行
            if (apiUrlRow) {
                apiUrlRow.style.display = '';
            }
            // 隐藏配额信息行
            if (quotaRow) {
                quotaRow.style.display = 'none';
            }
        } else if (selectedChannel === 'official') {
            // 插件官方API渠道配置
            if (predefinedApiUrl) {
                predefinedApiUrl.textContent = 'https://key.kdjingpai.com/api-proxy.php';
            }
            if (predefinedApiDescription) {
                predefinedApiDescription.innerHTML = <?php echo json_encode(__('插件官方API服务，通过授权码验证使用。<br><strong>如何申请使用：</strong><br>1. 联系插件作者微信：qn006699 获取插件授权码后使用<br>2. 在发布规则中配置授权码<br>3. 即可开始使用官方API服务', 'content-auto-manager')); ?>;
            }
            // 隐藏TOKEN输入框，因为插件官方API不需要TOKEN
            if (tokenRow) {
                tokenRow.style.display = 'none';
            }
            // 隐藏API地址行，因为插件官方API不需要显示地址
            if (apiUrlRow) {
                apiUrlRow.style.display = 'none';
            }
            // 显示配额信息行
            if (quotaRow) {
                quotaRow.style.display = '';
                // 自动获取配额信息
                setTimeout(function() {
                    getQuotaInfo('official');
                }, 500);
            }
        }
    }

    // 监听渠道变化（仅在新建模式下存在select元素）
    if (predefinedChannelSelect) {
        predefinedChannelSelect.addEventListener('change', updatePredefinedChannelInfo);
    }

    // 页面加载时初始化（无论是编辑模式还是新建模式都执行）
    updatePredefinedChannelInfo();

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
                    vectorModelDescription.textContent = <?php echo json_encode(__('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'content-auto-manager')); ?>;
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
                    vectorModelDescription.textContent = <?php echo json_encode(__('Jina Embeddings v4 固定为1024维，请使用: jina-embeddings-v4', 'content-auto-manager')); ?>;
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
