<?php
/**
 * API配置表单页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 以前的 PHP 表单处理逻辑已移除，现改用 Universal AJAX Handler 处理 (see class-api-ajax-handler.php)

// 初始化预置API类
$predefined_api = new ContentAuto_PredefinedApi();

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

<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-rest-api"></span> <?php _e('API设置', 'yali-ai-writer'); ?></h1>

    <!-- 顶部折叠说明区域 -->
    <details class="yali-accordion">
        <summary><?php _e('如何选择和配置 API？点击查看详细说明', 'yali-ai-writer'); ?></summary>
        <div class="yali-accordion-content">
            <div class="yali-api-guide-grid">
                <div class="yali-api-guide-item">
                    <h4><span class="dashicons dashicons-admin-generic"></span> <?php _e('自定义 API', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('用于大模型文本生成。您可以添加多个配置，系统将根据健康状态自动进行任务轮询，确保高并发写作时的稳定性。', 'yali-ai-writer'); ?></p>
                </div>
                <div class="yali-api-guide-item">
                    <h4><span class="dashicons dashicons-cloud"></span> <?php _e('预置 API', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('系统内置的高质量模型服务。无需繁琐配置，只需选择频道（如 Pollinations 或 官方 API）即可快速开启 AI 写作。', 'yali-ai-writer'); ?></p>
                </div>
                <div class="yali-api-guide-item">
                    <h4><span class="dashicons dashicons-database-view"></span> <?php _e('向量 API', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('全系统唯一配置。用于将内容转化为向量嵌入，实现语义搜索、知识库召回 and 内容相似度去重，提升内容关联性。', 'yali-ai-writer'); ?></p>
                </div>
                <div class="yali-api-guide-item">
                    <h4><span class="dashicons dashicons-search"></span> <?php _e('搜索 API', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('基于插件授权的内置搜索服务。用于在生成内容前进行联网搜索，为 AI 提供最新的背景资料 and 事实支撑。', 'yali-ai-writer'); ?></p>
                </div>
                <div class="yali-api-guide-item">
                    <h4><span class="dashicons dashicons-welcome-view-site"></span> <?php _e('Jina Reader', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('网页解析服务。配合搜索 API 或知识库使用，可将复杂的网页链接转化为 AI 易于理解的纯净 Markdown 格式。', 'yali-ai-writer'); ?></p>
                </div>
            </div>
        </div>
    </details>
    
      
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
<div class="yali-tabs">
    <a href="?page=yali-ai-writer-api&tab=custom" class="yali-tab-item <?php echo $active_tab === 'custom' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-admin-generic"></span> <?php _e('自定义API', 'yali-ai-writer'); ?>
    </a>
    <a href="?page=yali-ai-writer-api&tab=predefined" class="yali-tab-item <?php echo $active_tab === 'predefined' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-cloud"></span> <?php _e('预置API', 'yali-ai-writer'); ?>
    </a>
    <a href="?page=yali-ai-writer-api&tab=vector" class="yali-tab-item <?php echo $active_tab === 'vector' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-database-view"></span> <?php _e('向量API', 'yali-ai-writer'); ?>
    </a>
    <a href="?page=yali-ai-writer-api&tab=search" class="yali-tab-item <?php echo $active_tab === 'search' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-search"></span> <?php _e('搜索API', 'yali-ai-writer'); ?>
    </a>
    <a href="?page=yali-ai-writer-api&tab=reader" class="yali-tab-item <?php echo $active_tab === 'reader' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-welcome-view-site"></span> <?php _e('Jina Reader', 'yali-ai-writer'); ?>
    </a>
</div>

<!-- 自定义API配置表单 -->
<div id="custom-tab" class="yali-tab-content <?php echo $active_tab === 'custom' ? 'active' : ''; ?>">
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-admin-settings"></span> <?php echo $edit_config ? __('编辑配置', 'yali-ai-writer') : __('自定义API配置', 'yali-ai-writer'); ?></h2>
            
            <!-- 硅基流动API推荐提示 -->
            <div class="yali-notice yali-notice-info">
                <h4><span class="dashicons dashicons-lightbulb" style="font-size: 18px; width: 18px; height: 18px; line-height: 18px;"></span> <?php _e('推荐使用硅基流动API', 'yali-ai-writer'); ?></h4>
                <p><?php _e('硅基流动API支持多种主流大模型，可以帮助您显著提升生成内容的多样性和质量。通过一个API接口，您可以灵活使用不同的模型来满足各种内容创作需求。', 'yali-ai-writer'); ?></p>
                <p>
                    <?php _e('立即注册：', 'yali-ai-writer'); ?>
                    <a href="https://cloud.siliconflow.cn/i/fcqQ8oKi" target="_blank" class="yali-link">
                        https://cloud.siliconflow.cn/i/fcqQ8oKi
                    </a>
                </p>
            </div>
            
            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_api_settings'); ?>">
                <input type="hidden" name="submission_type" value="custom">
                <?php wp_nonce_field('content_auto_manager_api_config', 'content_auto_manager_nonce'); ?>
                
                <?php if ($edit_config): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($edit_config['id']); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('配置名称', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="text" name="name" value="<?php echo $edit_config ? esc_attr($edit_config['name']) : ''; ?>" class="regular-text yali-input" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API类型', 'yali-ai-writer'); ?></th>
                        <td>
                            <select class="yali-select" name="api_type" id="api-type" required>
                                <option value="openai" <?php echo ($edit_config && ($edit_config['api_type'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>>
                                    <?php _e('OpenAI 兼容格式', 'yali-ai-writer'); ?>
                                </option>
                                <option value="gemini" <?php echo ($edit_config && ($edit_config['api_type'] ?? '') === 'gemini') ? 'selected' : ''; ?>>
                                    Google Gemini
                                </option>
                                <option value="claude" <?php echo ($edit_config && ($edit_config['api_type'] ?? '') === 'claude') ? 'selected' : ''; ?>>
                                    Anthropic Claude
                                </option>
                            </select>
                            <p class="description yali-desc"><?php _e('选择API类型后，下方配置项会根据所选类型自动调整默认值', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API地址', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="url" name="api_url" id="api-url" value="<?php echo $edit_config ? esc_attr($edit_config['api_url']) : ''; ?>" class="regular-text yali-input" required>
                            <p class="description yali-desc" id="api-url-desc"><?php _e('例如: https://api.openai.com/v1/chat/completions', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API密钥', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="password" name="api_key" value="<?php echo $edit_config ? esc_attr($edit_config['api_key']) : ''; ?>" class="regular-text yali-input" autocomplete="current-password" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('模型名称', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="text" name="model_name" value="<?php echo $edit_config ? esc_attr($edit_config['model_name']) : ''; ?>" class="regular-text yali-input" required>
                            <p class="description yali-desc"><?php _e('例如: gpt-3.5-turbo', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('温度', 'yali-ai-writer'); ?></th>
                        <td>
                            <div class="yali-switch-group">
                                <label class="yali-switch">
                                    <input type="checkbox" id="temperature_enabled" name="temperature_enabled" value="1" <?php echo ($edit_config && isset($edit_config['temperature_enabled']) && $edit_config['temperature_enabled']) ? 'checked' : ''; ?>>
                                    <span class="yali-switch-slider"></span>
                                </label>
                                <span class="yali-switch-label"><?php echo ($edit_config && isset($edit_config['temperature_enabled']) && $edit_config['temperature_enabled']) ? __('开启', 'yali-ai-writer') : __('关闭', 'yali-ai-writer'); ?></span>
                                <input type="number" id="temperature" name="temperature" value="<?php echo $edit_config ? esc_attr($edit_config['temperature']) : '0.7'; ?>" step="0.1" min="0" max="2" class="yali-input">
                            </div>
                            <p class="description yali-desc"><?php _e('控制生成内容的随机性，0-2之间', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('最大Token数', 'yali-ai-writer'); ?></th>
                        <td>
                            <div class="yali-switch-group">
                                <label class="yali-switch">
                                    <input type="checkbox" id="max_tokens_enabled" name="max_tokens_enabled" value="1" <?php echo ($edit_config && isset($edit_config['max_tokens_enabled']) && $edit_config['max_tokens_enabled']) ? 'checked' : ''; ?>>
                                    <span class="yali-switch-slider"></span>
                                </label>
                                <span class="yali-switch-label"><?php echo ($edit_config && isset($edit_config['max_tokens_enabled']) && $edit_config['max_tokens_enabled']) ? __('开启', 'yali-ai-writer') : __('关闭', 'yali-ai-writer'); ?></span>
                                <input type="number" id="max_tokens" name="max_tokens" value="<?php echo $edit_config ? esc_attr($edit_config['max_tokens']) : '2000'; ?>" min="1" max="32000" class="yali-input">
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('核采样参数 (Top_p)', 'yali-ai-writer'); ?></th>
                        <td>
                            <div class="yali-switch-group">
                                <label class="yali-switch">
                                    <input type="checkbox" id="top_p_enabled" name="top_p_enabled" value="1" <?php echo ($edit_config && isset($edit_config['top_p_enabled']) && $edit_config['top_p_enabled']) ? 'checked' : ''; ?>>
                                    <span class="yali-switch-slider"></span>
                                </label>
                                <span class="yali-switch-label"><?php echo ($edit_config && isset($edit_config['top_p_enabled']) && $edit_config['top_p_enabled']) ? __('开启', 'yali-ai-writer') : __('关闭', 'yali-ai-writer'); ?></span>
                                <input type="number" id="top_p" name="top_p" value="<?php echo ($edit_config && isset($edit_config['top_p'])) ? esc_attr($edit_config['top_p']) : '1.0'; ?>" step="0.1" min="0" max="1" class="yali-input">
                            </div>
                            <p class="description yali-desc"><?php _e('控制生成内容的多样性，0-1之间，默认1.0', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('流式输出 (Stream)', 'yali-ai-writer'); ?></th>
                        <td>
                            <!-- [修订] 移除了多余的 Checkbox，只保留下拉框 -->
                            <select class="yali-select" id="stream" name="stream" style="width: auto;">
                                <option value="false" <?php echo (!isset($edit_config) || (isset($edit_config['stream']) && !$edit_config['stream'])) ? 'selected' : ''; ?>><?php _e('关闭 (默认)', 'yali-ai-writer'); ?></option>
                                <option value="true" <?php echo ($edit_config && isset($edit_config['stream']) && $edit_config['stream']) ? 'selected' : ''; ?>><?php _e('开启', 'yali-ai-writer'); ?></option>
                            </select>
                            <p class="description yali-desc"><?php _e('开启后将使用 Server-Sent Events (SSE) 流式传输数据，可防止长文生成超时。如果您遇到 Ghost task 错误，建议关闭此项。', 'yali-ai-writer'); ?></p>
                            <!-- 保持 stream_enabled 为 1 以兼容数据库 Schema -->
                            <input type="hidden" name="stream_enabled" value="1">
                        </td>
                    </tr>
                      <tr>
                        <th scope="row"><?php _e('设为激活', 'yali-ai-writer'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="is_active" value="1" <?php echo ($edit_config && $edit_config['is_active']) ? 'checked' : ''; ?>>
                                <?php _e('将此配置设为当前激活的API配置', 'yali-ai-writer'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存到API列表', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_custom_api'); ?>
            </form>
        </div>
    </div>
    
    <div id="predefined-tab" class="yali-tab-content <?php echo $active_tab === 'predefined' ? 'active' : ''; ?>">
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-cloud-saved"></span> <?php echo $edit_config ? __('编辑预置API配置', 'yali-ai-writer') : __('预置API配置', 'yali-ai-writer'); ?></h2>
            
            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_api_settings'); ?>">
                <input type="hidden" name="submission_type" value="predefined">
                <?php wp_nonce_field('content_auto_manager_predefined_api', 'predefined_api_nonce'); ?>
                
                <?php if ($editing_predefined_channel): ?>
                    <input type="hidden" name="editing_predefined_channel" value="<?php echo esc_attr($editing_predefined_channel); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('渠道选择', 'yali-ai-writer'); ?></th>
                        <td>
                            <?php if ($editing_predefined_channel): ?>
                                <!-- 编辑模式：显示当前编辑的渠道 -->
                                <input type="hidden" name="predefined_api_channel" value="<?php echo esc_attr($editing_predefined_channel); ?>">
                                <div style="padding: 8px 12px; background-color: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px;">
                                    <strong><?php echo esc_html($predefined_api_channels[$editing_predefined_channel]['name']); ?></strong>
                                    <span style="color: #666; margin-left: 10px;"><?php _e('(编辑模式)', 'yali-ai-writer'); ?></span>
                                </div>
                                <p class="description yali-desc"><?php _e('当前正在编辑的预置API渠道', 'yali-ai-writer'); ?></p>
                            <?php else: ?>
                                <!-- 新建模式：允许选择渠道 -->
                                <select class="yali-select" name="predefined_api_channel" id="predefined-api-channel">
                                    <?php foreach ($predefined_api_channels as $channel_key => $channel_info): ?>
                                        <?php 
                                        // 检查渠道是否已存在配置
                                        $existing_config = $predefined_api->get_config($channel_key);
                                        $disabled = $existing_config ? 'disabled' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($channel_key); ?>" <?php selected($selected_channel, $channel_key); ?> <?php echo $disabled; ?>>
                                            <?php echo esc_html($channel_info['name']); ?>
                                            <?php if ($existing_config): ?>
                                                <?php _e('(已添加)', 'yali-ai-writer'); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description yali-desc"><?php _e('选择要使用的预置API渠道', 'yali-ai-writer'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr id="predefined-model-row" style="<?php echo $selected_channel === 'official' ? 'display: none;' : ''; ?>">
                        <th scope="row"><?php _e('模型选择', 'yali-ai-writer'); ?></th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <select class="yali-select" name="predefined_api_model" id="predefined-api-model" style="min-width: 250px;">
                                    <!-- 由 JS 动态填充 -->
                                    <?php 
                                    // 为首屏渲染提供初始值（编辑模式下）
                                    $current_model = $config_to_edit['model_name'] ?? 'openai-large';
                                    echo '<option value="' . esc_attr($current_model) . '">' . esc_html($current_model) . '</option>';
                                    ?>
                                </select>
                                <button type="button" id="refresh-pollinations-models" class="button button-secondary yali-btn yali-btn-secondary yali-btn-small">
                                    <span class="dashicons dashicons-update"></span> <?php _e('同步多模型', 'yali-ai-writer'); ?>
                                </button>
                                <span id="model-refresh-status" style="display: none; color: #666; font-size: 13px;">
                                    <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span><?php _e('正在同步官方模型...', 'yali-ai-writer'); ?>
                                </span>
                            </div>
                            <p class="description yali-desc" id="predefined-model-description"><?php _e('根据选中的渠道选择相应的 AI 模型', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr id="api-url-row">
                        <th scope="row"><?php _e('API地址', 'yali-ai-writer'); ?></th>
                        <td>
                            <code id="predefined-api-url">
                                <?php 
                                // 根据选中的渠道显示相应的API地址
                                if ($selected_channel === 'official') {
                                    echo 'https://key.kdjingpai.com/api-proxy.php';
                                } else {
                                    echo 'https://gen.pollinations.ai/v1/chat/completions';
                                }
                                ?>
                            </code>
                            <p class="description yali-desc">
                                <?php 
                                // 根据选中的渠道显示相应的描述
                                if ($selected_channel === 'official') {
                                    _e('插件官方API服务，通过授权码验证使用。可在发布规则中配置授权码，即刻开启。', 'yali-ai-writer');
                                } else {
                                    _e('由 Pollinations 提供的多种顶级开源、商业模型。建议模型: openai-large (Llama 3.3 70B)。', 'yali-ai-writer');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API Key (必填)', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="text" name="predefined_api_token" id="predefined-api-token" 
                                   value="<?php echo esc_attr($config_to_edit['api_key'] ?? ''); ?>" 
                                   placeholder="<?php echo esc_attr(__('请输入您的 API Key', 'yali-ai-writer')); ?>" 
                                   class="regular-text yali-input" required>
                            <p class="description yali-desc">
                                <?php 
                                // 根据选中的渠道显示相应的TOKEN描述
                                if ($selected_channel === 'official') {
                                    _e('官方API通过系统授权验证，无需手动填写 Key。获取授权码请联系作者微信：qn006699。', 'yali-ai-writer');
                                } else {
                                    _e('Pollinations 现在需要 API Key 才能稳定使用。<a href="https://enter.pollinations.ai/" target="_blank">点击此处免费申请您的 Key</a>。推荐绑定 Key 以解锁多模型同步。', 'yali-ai-writer');
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr id="quota-info-row" style="display: none;">
                        <th scope="row"><?php _e('剩余配额', 'yali-ai-writer'); ?></th>
                        <td>
                            <div id="quota-info-display" style="font-size: 16px; font-weight: bold; margin-bottom: 8px;">
                                <span id="quota-info-result" class="quota-result"><?php _e('正在获取配额信息...', 'yali-ai-writer'); ?></span>
                            </div>
                            <p class="description yali-desc"><?php _e('当前域名授权码的剩余使用次数，一次成功API请求消耗1次，缺少授权码或配额不足时请联系插件作者微信：qn006699 进行充值', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr id="pollinations-account-row" style="display: none;">
                        <th scope="row"><?php _e('账户与用量信息', 'yali-ai-writer'); ?></th>
                        <td>
                            <div id="pollinations-account-display" class="pollinations-account-stats">
                                <div class="stats-loading">
                                    <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span><?php _e('正在拉取账户信息...', 'yali-ai-writer'); ?>
                                </div>
                            </div>
                            <p class="description yali-desc"><?php _e('实时显示您的 Pollinations 账户余额、历史总用量及今日用量统计。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('激活状态', 'yali-ai-writer'); ?></th>
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
                                <?php _e('将此配置设为当前激活的API配置', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description yali-desc"><?php _e('激活后，该API配置将参与下游任务轮询', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存到API列表', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_predefined_api'); ?>
            </form>
        </div>
    </div>
    
    <!-- 向量API配置表单 -->
    <div id="vector-tab" class="yali-tab-content <?php echo $active_tab === 'vector' ? 'active' : ''; ?>">
        <div class="content-auto-section yali-card">
            <?php if ($vector_config_exists && empty($edit_config)): ?>
                <h2><span class="dashicons dashicons-database-view"></span> <?php _e('向量API配置', 'yali-ai-writer'); ?></h2>
                
                <table class="form-table" style="margin-top: 0;">
                    <tr>
                        <th scope="row"><?php _e('配置名称', 'yali-ai-writer'); ?></th>
                        <td><strong><?php echo esc_html(__($existing_vector_config['name'], 'yali-ai-writer')); ?></strong></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量模型', 'yali-ai-writer'); ?></th>
                        <td><code><?php echo esc_html(__($existing_vector_config['vector_model_name'], 'yali-ai-writer')); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API地址', 'yali-ai-writer'); ?></th>
                        <td><code style="word-break: break-all;"><?php echo esc_html($existing_vector_config['vector_api_url']); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('API类型', 'yali-ai-writer'); ?></th>
                        <td><span class="status-active"><?php echo esc_html(strtoupper($existing_vector_config['vector_api_type'] ?? 'OPENAI')); ?></span></td>
                    </tr>
                </table>
                <div style="margin: 25px 0 10px 0;">
                    <a href="<?php echo wp_nonce_url(add_query_arg(array('action' => 'edit', 'id' => $existing_vector_config['id'], 'tab' => 'vector')), 'content_auto_manager_edit_config', 'nonce'); ?>" class="button-primary yali-btn yali-btn-primary">
                        <span class="dashicons dashicons-edit" style="margin-top: 4px;"></span> <?php _e('编辑现有配置', 'yali-ai-writer'); ?>
                    </a>
                </div>
                <div class="yali-notice yali-notice-info">
                   <p><?php _e('注意：系统中只允许配置一个向量API。由于向量模型对全系统效果（如相似度计算）有全局影响，保持其唯一性可确保数据的一致性。如需更换服务商或模型，请点击上方“编辑”按钮。', 'yali-ai-writer'); ?></p>
                </div>
            <?php else: ?>
                <h2><span class="dashicons dashicons-database-add"></span> <?php echo $edit_config ? __('编辑向量API配置', 'yali-ai-writer') : __('向量API配置', 'yali-ai-writer'); ?></h2>
            <?php endif; ?>
            
            <?php if ($show_vector_form): ?>
                <div class="yali-notice yali-notice-info">
                    <h4><span class="dashicons dashicons-info"></span> <?php _e('向量API配置说明', 'yali-ai-writer'); ?></h4>
                    <p><?php _e('向量API用于将文本内容转换为向量嵌入，支持语义搜索 and 内容相似度计算。配置向量API后，系统可以为生成的主题自动创建向量嵌入数据。', 'yali-ai-writer'); ?></p>
                    <p><strong><?php _e('注意：系统只允许配置一个向量API，该配置将全局生效。', 'yali-ai-writer'); ?></strong></p>
                    <p><?php _e('支持的向量API包括：OpenAI Embeddings、Cohere Embeddings、本地向量服务等。', 'yali-ai-writer'); ?></p>
                </div>
            
            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_api_settings'); ?>">
                <input type="hidden" name="submission_type" value="vector">
                <?php wp_nonce_field('content_auto_manager_api_config', 'content_auto_manager_vector_nonce'); ?>
                
                <?php if ($edit_config): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($edit_config['id']); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('配置名称', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="text" name="name" value="<?php echo $edit_config ? esc_attr($edit_config['name']) : ''; ?>" class="regular-text yali-input" required>
                            <p class="description yali-desc"><?php _e('为此向量API配置设置一个易于识别的名称', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API地址', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="url" name="vector_api_url" value="<?php echo $edit_config ? esc_attr($edit_config['vector_api_url']) : ''; ?>" class="regular-text yali-input" required>
                            <p class="description yali-desc"><?php _e('向量API的完整URL地址，例如: https://api.openai.com/v1/embeddings', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API密钥', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="password" name="vector_api_key" value="" placeholder="<?php echo esc_attr(__('留空则不修改', 'yali-ai-writer')); ?>" class="regular-text yali-input" autocomplete="current-password" <?php echo $edit_config ? '' : 'required'; ?>>
                            <p class="description yali-desc"><?php _e('访问向量API所需的认证密钥', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量API类型', 'yali-ai-writer'); ?></th>
                        <td>
                            <select class="yali-select" name="vector_api_type" id="vector-api-type" required>
                                <option value="openai" <?php echo ($edit_config && ($edit_config['vector_api_type'] ?? 'openai') === 'openai') ? 'selected' : ''; ?>>
                                    OpenAI Embeddings
                                </option>
                                <option value="jina" <?php echo ($edit_config && ($edit_config['vector_api_type'] ?? 'openai') === 'jina') ? 'selected' : ''; ?>>
                                    Jina Embeddings v4
                                </option>
                            </select>
                            <p class="description yali-desc"><?php _e('选择向量API类型：OpenAI Embeddings 或 Jina Embeddings v4', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('向量模型名称', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="text" name="vector_model_name" id="vector-model-name" value="<?php echo $edit_config ? esc_attr($edit_config['vector_model_name']) : ''; ?>" class="regular-text yali-input" required>
                            <p class="description yali-desc" id="vector-model-description"><?php _e('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存向量API配置', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_vector_api'); ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 搜索API配置表单 -->
    <div id="search-tab" class="yali-tab-content <?php echo $active_tab === 'search' ? 'active' : ''; ?>">
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-search"></span> <?php _e('搜索API设置', 'yali-ai-writer'); ?></h2>
            
            <?php 
            $search_settings = get_option('content_auto_search_settings', []);
            $region = isset($search_settings['region']) ? $search_settings['region'] : 'wt-wt';
            $max_results = isset($search_settings['max_results']) ? intval($search_settings['max_results']) : 10;
            $safesearch = isset($search_settings['safesearch']) ? $search_settings['safesearch'] : 'moderate';
            $time = isset($search_settings['time']) ? $search_settings['time'] : '';
            $backend = isset($search_settings['backend']) ? $search_settings['backend'] : 'html';
            ?>

            <div class="yali-notice yali-notice-info">
                <h4><?php _e('🔍 搜索API配置说明', 'yali-ai-writer'); ?></h4>
                <p><?php _e('此API是系统内置的搜索服务(基于DuckDuckGo)，不需要繁琐的密钥配置。', 'yali-ai-writer'); ?></p>
                <p><?php _e('您可以在此调整搜索的默认行为参数。', 'yali-ai-writer'); ?></p>
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
            
             <div class="yali-info-box yali-info-box-warning">
                <h4><span class="dashicons dashicons-shield"></span> <?php _e('授权信息诊断', 'yali-ai-writer'); ?></h4>
                <p><strong><?php _e('当前授权码 License Key:', 'yali-ai-writer'); ?></strong> <code><?php echo !empty($current_license) ? esc_html($current_license) : __('未设置 (请在发布规则中配置)', 'yali-ai-writer'); ?></code></p>
                <p><strong><?php _e('识别到域名 Domain:', 'yali-ai-writer'); ?></strong> <code><?php echo esc_html($current_domain); ?></code></p>
                <p class="description yali-desc"><?php _e('* 搜索API将使用上述授权码及域名进行鉴权。如遇 401 错误，请检查授权码有效性及域名绑定状态。', 'yali-ai-writer'); ?></p>
            </div>

            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_api_settings'); ?>">
                <input type="hidden" name="submission_type" value="search">
                <?php wp_nonce_field('content_auto_manager_search_config', 'content_auto_manager_search_nonce'); ?>
                <!-- 用于测试连接的nonce -->
                <?php wp_nonce_field('content_auto_manager_nonce', 'test_search_nonce_field'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('搜索地区 (Region)', 'yali-ai-writer'); ?></th>
                        <td>
                            <select class="yali-select" name="search_region">
                                <option value="wt-wt" <?php selected($region, 'wt-wt'); ?>><?php _e('全球 (wt-wt)', 'yali-ai-writer'); ?></option>
                                <option value="cn-zh" <?php selected($region, 'cn-zh'); ?>><?php _e('中国 (cn-zh)', 'yali-ai-writer'); ?></option>
                                <option value="us-en" <?php selected($region, 'us-en'); ?>><?php _e('美国 (us-en)', 'yali-ai-writer'); ?></option>
                                <option value="jp-jp" <?php selected($region, 'jp-jp'); ?>><?php _e('日本 (jp-jp)', 'yali-ai-writer'); ?></option>
                                <option value="uk-en" <?php selected($region, 'uk-en'); ?>><?php _e('英国 (uk-en)', 'yali-ai-writer'); ?></option>
                                <option value="de-de" <?php selected($region, 'de-de'); ?>><?php _e('德国 (de-de)', 'yali-ai-writer'); ?></option>
                                <option value="fr-fr" <?php selected($region, 'fr-fr'); ?>><?php _e('法国 (fr-fr)', 'yali-ai-writer'); ?></option>
                                <option value="es-es" <?php selected($region, 'es-es'); ?>><?php _e('西班牙 (es-es)', 'yali-ai-writer'); ?></option>
                            </select>
                            <p class="description yali-desc"><?php _e('选择搜索结果的优先地区。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('最大结果数 (Max Results)', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="number" name="search_max_results" value="<?php echo esc_attr($max_results); ?>" min="1" max="50" class="small-text yali-input">
                            <p class="description yali-desc"><?php _e('返回搜索结果的最大数量 (1-50)。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('安全搜索 (Safe Search)', 'yali-ai-writer'); ?></th>
                        <td>
                            <select class="yali-select" name="search_safesearch">
                                <option value="moderate" <?php selected($safesearch, 'moderate'); ?>><?php _e('适中 (Moderate)', 'yali-ai-writer'); ?></option>
                                <option value="off" <?php selected($safesearch, 'off'); ?>><?php _e('关闭 (Off)', 'yali-ai-writer'); ?></option>
                                <option value="on" <?php selected($safesearch, 'on'); ?>><?php _e('严格 (Strict)', 'yali-ai-writer'); ?></option>
                            </select>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><?php _e('时间范围 (Time)', 'yali-ai-writer'); ?></th>
                        <td>
                            <select class="yali-select" name="search_time">
                                <option value="" <?php selected($time, ''); ?>><?php _e('不限', 'yali-ai-writer'); ?></option>
                                <option value="d" <?php selected($time, 'd'); ?>><?php _e('过去一天', 'yali-ai-writer'); ?></option>
                                <option value="w" <?php selected($time, 'w'); ?>><?php _e('过去一周', 'yali-ai-writer'); ?></option>
                                <option value="m" <?php selected($time, 'm'); ?>><?php _e('过去一月', 'yali-ai-writer'); ?></option>
                                <option value="y" <?php selected($time, 'y'); ?>><?php _e('过去一年', 'yali-ai-writer'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('后端引擎 (Backend)', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="hidden" name="search_backend" value="lite">
                            <input type="text" value="<?php echo esc_attr(__('Lite (轻量级 - 速度快)', 'yali-ai-writer')); ?>" class="regular-text yali-input" disabled>
                            <p class="description yali-desc"><?php _e('系统强制使用 Lite 引擎，不允许修改。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存搜索配置', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_search_api'); ?>
            </form>
            
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;">

            <div class="yali-info-box yali-info-box-success">
                <h3><?php _e('如何在代码中调用搜索？', 'yali-ai-writer'); ?></h3>
                <p><?php _e('系统已封装好全局辅助函数，您可以在主题或插件的任何位置直接调用，无需关心鉴权和配置。', 'yali-ai-writer'); ?></p>
            </div>
            
            <div style="margin-top: 20px;">
                <textarea class="large-text code yali-textarea-code" rows="22" readonly style="font-family: monospace; background: #f6f7f7;">
// <?php _e('直接调用搜索，自动使用后台配置（推荐）', 'yali-ai-writer'); ?>

$results = content_auto_search('<?php _e('关键词', 'yali-ai-writer'); ?>');

if (!is_wp_error($results) && $results['success']) {
    // <?php _e('处理结果列表', 'yali-ai-writer'); ?>

    foreach ($results['results'] as $item) {
        echo $item['title'];    // <?php _e('标题', 'yali-ai-writer'); ?>

        echo $item['link'];     // <?php _e('链接', 'yali-ai-writer'); ?>

        echo $item['snippet'];  // <?php _e('摘要', 'yali-ai-writer'); ?>

        echo $item['position']; // <?php _e('排名', 'yali-ai-writer'); ?>

    }
}

/* 
 * <?php _e('返回数据结构示例:', 'yali-ai-writer'); ?>

 * [
 *   "success" => true,
 *   "count" => 10,
 *   "results" => [
 *     [
 *       "title" => "<?php _e('标题...', 'yali-ai-writer'); ?>",
 *       "link" => "https://...",
 *       "snippet" => "<?php _e('摘要内容...', 'yali-ai-writer'); ?>",
 *       "position" => 1
 *     ],
 *     ...
 *   ]
 * ]
 */</textarea>
            </div>
            
            <!-- 搜索测试区域 -->
            <div class="yali-search-test-box" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border: 1px solid #ccd0d4; border-radius: 6px;">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-search"></span> <?php _e('搜索测试区域', 'yali-ai-writer'); ?>
                </h3>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display: block; font-weight: 500; margin-bottom: 5px;"><?php _e('测试关键词', 'yali-ai-writer'); ?></label>
                        <input type="text" id="search-test-keyword" placeholder="<?php echo esc_attr(__('请输入搜索关键词', 'yali-ai-writer')); ?>" class="regular-text yali-input" style="width: 100%;">
                    </div>
                </div>
                <button type="button" id="search-test-btn" class="yali-btn yali-btn-primary">
                    <span class="dashicons dashicons-search"></span> <?php _e('立即搜索', 'yali-ai-writer'); ?>
                </button>

                <div id="search-test-results" style="margin-top: 20px;">
                    <label style="display: block; font-weight: 500; margin-bottom: 10px;"><?php _e('测试结果', 'yali-ai-writer'); ?></label>
                    <div id="search-test-content" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; min-height: 100px; max-height: 400px; overflow-y: auto; font-size: 13px; border-radius: 4px; color: #666;">
                        <?php _e('测试结果将在此处实时显示...', 'yali-ai-writer'); ?>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('#search-test-btn').on('click', function() {
                    var query = $('#search-test-keyword').val();
                    var resultDiv = $('#search-test-content');
                    
                    if (!query) {
                        alert(<?php echo json_encode(__('请输入搜索关键词', 'yali-ai-writer')); ?>);
                        return;
                    }
                    
                    resultDiv.html(<?php echo json_encode(__('正在搜索...', 'yali-ai-writer')); ?>);
                    
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
                                 var html = '<p><strong>' + <?php echo json_encode(__('找到结果数：', 'yali-ai-writer')); ?> + '</strong> ' + data.count + '</p>';
                                
                                if (data.results && data.results.length > 0) {
                                    $.each(data.results, function(index, item) {
                                        html += '<div style="margin-bottom:15px; border-bottom:1px solid #ddd; padding-bottom:10px;">';
                                        html += '<div style="font-weight:bold; margin-bottom:5px;">';
                                        var pos = (item.position !== undefined) ? item.position : (index + 1);
                                        html += '<span style="color:#666; margin-right:5px;">[' + pos + ']</span>';
                                        html += '<a href="' + item.link + '" target="_blank" style="text-decoration:none;">' + item.title + '</a>';
                                        html += '</div>';
                                        html += '<div style="font-size:13px; line-height:1.5;">' + item.snippet + '</div>';
                                        html += '<div style="font-size:12px; color:var(--yali-success); margin-top:3px;">' + item.link + '</div>';
                                        html += '</div>';
                                    });
                                } else {
                                     html += '<p>' + <?php echo json_encode(__('未找到相关结果。', 'yali-ai-writer')); ?> + '</p>';
                                }
                                
                                resultDiv.html(html);
                            } else {
                                 resultDiv.html('<span style="color:#dc3232;">' + <?php echo json_encode(__('错误： ', 'yali-ai-writer')); ?> + (response.data.message || 'Unknown error') + '</span>');
                            }
                        },
                        error: function(xhr, status, error) {
                             resultDiv.html('<span style="color:#dc3232;">' + <?php echo json_encode(__('系统错误： ', 'yali-ai-writer')); ?> + error + '</span>');
                        }
                    });
                });
            });
            </script>
        </div>
    </div>
    
    <!-- Jina Reader API 配置表单 -->
    <div id="reader-tab" class="yali-tab-content <?php echo $active_tab === 'reader' ? 'active' : ''; ?>">
        <div class="content-auto-section yali-card">
            <h2><span class="dashicons dashicons-welcome-view-site"></span> <?php _e('Jina Reader API 配置', 'yali-ai-writer'); ?></h2>
            
            <div class="yali-notice yali-notice-info">
                <h4><span class="dashicons dashicons-welcome-view-site"></span> <?php _e('Jina Reader API 说明', 'yali-ai-writer'); ?></h4>
                <p><?php _e('Jina Reader 用于将网页内容抓取并转换为对 LLM 友好的 Markdown 格式。', 'yali-ai-writer'); ?></p>
                <p>
                    <?php _e('申请地址：', 'yali-ai-writer'); ?>
                    <a href="https://jina.ai/reader/" target="_blank" class="yali-link">
                        https://jina.ai/reader/
                    </a>
                </p>
                <p class="description yali-desc"><?php _e('* 留空则使用免费匿名模式（20次/分钟）。填入 KEY 可解锁更高频次（500次/分钟）。', 'yali-ai-writer'); ?></p>
            </div>
            
            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_api_settings'); ?>">
                <input type="hidden" name="submission_type" value="reader">
                <?php wp_nonce_field('content_auto_manager_reader_config', 'content_auto_manager_reader_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="password" name="jina_api_key" value="<?php echo esc_attr(get_option('content_auto_jina_api_key', '')); ?>" class="regular-text yali-input" placeholder="jina_..." autocomplete="off">
                            <p class="description yali-desc"><?php _e('请输入您的 Jina Reader API Key。留空则使用匿名模式。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('搜索结果过滤黑名单', 'yali-ai-writer'); ?></th>
                        <td>
                            <?php 
                            $blacklist = get_option('content_auto_material_search_blacklist', ['csdn.net', 'zhihu.com']);
                            $blacklist_str = is_array($blacklist) ? implode("\n", $blacklist) : $blacklist;
                            ?>
                            <textarea name="material_search_blacklist" rows="6" class="large-text code yali-textarea-code yali-input" placeholder="csdn.net&#10;zhihu.com"><?php echo esc_textarea($blacklist_str); ?></textarea>
                            <p class="description yali-desc"><?php _e('在此处管理需要在“搜索物料”阶段自动剔除的域名或关键词。每行一个。<br>默认建议过滤 <code>csdn.net</code> 和 <code>zhihu.com</code> 等 UGC 内容平台以保证素材专业度度。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('保存 Jina Reader 配置', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_reader_api'); ?>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function toggleInput(checkboxId, inputId) {
        var checkbox = document.getElementById(checkboxId);
        var input = document.getElementById(inputId);

        if (!checkbox || !input) {
            return;
        }

        function updateState() {
            input.disabled = !checkbox.checked;
            // Update label text dynamically
            var labelSpan = checkbox.closest('.yali-switch-group').querySelector('.yali-switch-label');
            if (labelSpan) {
                labelSpan.textContent = checkbox.checked ? <?php echo json_encode(__('开启', 'yali-ai-writer')); ?> : <?php echo json_encode(__('关闭', 'yali-ai-writer')); ?>;
            }
        }

        checkbox.addEventListener('change', updateState);
        
        // Set initial state on page load
        updateState();
    }

    toggleInput('temperature_enabled', 'temperature');
    toggleInput('max_tokens_enabled', 'max_tokens');
    // 流式输出功能已禁用，移除toggle控制
    toggleInput('top_p_enabled', 'top_p');
    
    
    // 获取配额信息函数
    function getQuotaInfo(channel) {
        var resultElement = document.getElementById('quota-info-result');
        if (!resultElement) return;
        
        // 只有插件官方API才支持获取配额信息
        if (channel !== 'official') {
            return;
        }
        
        resultElement.textContent = '<?php _e('正在获取配额信息...', 'yali-ai-writer'); ?>';
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
                        var statusText = quota > 10 ? <?php echo json_encode(__('充足', 'yali-ai-writer')); ?> : (quota > 0 ? <?php echo json_encode(__('不足', 'yali-ai-writer')); ?> : <?php echo json_encode(__('已用完', 'yali-ai-writer')); ?>);
                        resultElement.innerHTML = '<span style="color: ' + color + '; font-size: 18px;">' + quota + ' ' + <?php echo json_encode(__('次', 'yali-ai-writer')); ?> + '</span> <span style="color: #666; font-size: 14px;">(' + statusText + ')</span>';
                        resultElement.className = 'quota-result success';
                    } else {
                        resultElement.innerHTML = '<span style="color: #dc3232;">' + response.data.message + '</span>';
                        resultElement.className = 'quota-result error';
                    }
                } else {
                    resultElement.innerHTML = '<span style="color: #dc3232;"><?php _e('获取配额信息失败: 服务器错误', 'yali-ai-writer'); ?></span>';
                    resultElement.className = 'quota-result error';
                }
            }
        };
        
        // 准备请求数据
        var data = 'action=content_auto_get_quota_info&channel=' + encodeURIComponent(channel) + '&nonce=' + contentAutoManager.nonce;
        xhr.send(data);
    }
    
    // 测试配置列表中的API连接 (使用事件委托，支持动态添加的配置)
    jQuery(document).on('click', '.test-api-connection', function(e) {
        e.preventDefault();
        
        var $button = jQuery(this);
        var configId = $button.data('config-id');
        var originalText = $button.text();
        
        // 禁用按钮并加上半透明效果，保留原文字
        $button.prop('disabled', true).css('opacity', '0.7');
        
        // 发送AJAX请求
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_test_api_connection',
                config_id: configId,
                nonce: contentAutoManager.nonce
            },
            success: function(response) {
                if (response.success) {
                    // 显示成功的 toast 消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message, 'success');
                    } else {
                        alert(response.data.message);
                    }
                } else {
                    // 显示错误的 toast 消息
                    if (typeof window.yaliToast === 'function') {
                        window.yaliToast(response.data.message, 'error');
                    } else {
                        alert('<?php _e('测试失败: ', 'yali-ai-writer'); ?>' + response.data.message);
                    }
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = '<?php _e('连接测试失败: ', 'yali-ai-writer'); ?>';
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage += xhr.responseJSON.data.message;
                } else {
                    errorMessage += error;
                }
                
                if (typeof window.yaliToast === 'function') {
                    window.yaliToast(errorMessage, 'error');
                } else {
                    alert(errorMessage);
                }
            },
            complete: function() {
                // 恢复按钮状态
                $button.prop('disabled', false).css('opacity', '');
            }
        });
    });

    // API类型切换动态处理
    var apiTypeSelect = document.getElementById('api-type');
    var apiUrlInput = document.getElementById('api-url');
    var apiUrlDesc = document.getElementById('api-url-desc');
    var modelNameInput = document.querySelector('input[name="model_name"]');
    
    // API类型对应的默认值配置
    var apiTypeDefaults = {
        'openai': {
            url: '',
            urlDesc: '<?php _e('例如: https://api.openai.com/v1/chat/completions', 'yali-ai-writer'); ?>',
            model: 'gpt-3.5-turbo'
        },
        'gemini': {
            url: 'https://generativelanguage.googleapis.com/v1beta/models',
            urlDesc: '<?php _e('例如: https://generativelanguage.googleapis.com/v1beta/models', 'yali-ai-writer'); ?>',
            model: 'gemini-3-flash-preview'
        },
        'claude': {
            url: 'https://api.anthropic.com/v1/messages',
            urlDesc: '<?php _e('例如: https://api.anthropic.com/v1/messages', 'yali-ai-writer'); ?>',
            model: 'claude-3-5-sonnet-20241022'
        }
    };
    
    // 收集所有默认URL列表，用于判断当前URL是否为系统预填的默认值
    var allDefaultUrls = [];
    for (var key in apiTypeDefaults) {
        if (apiTypeDefaults[key].url) {
            allDefaultUrls.push(apiTypeDefaults[key].url);
        }
    }
    
    // 收集所有默认模型列表
    var allDefaultModels = [];
    for (var key in apiTypeDefaults) {
        if (apiTypeDefaults[key].model) {
            allDefaultModels.push(apiTypeDefaults[key].model);
        }
    }
    
    // 更新API类型相关的默认值（isUserSwitch: 用户主动切换时为 true）
    function updateApiTypeDefaults(isUserSwitch) {
        if (!apiTypeSelect) return;
        
        var selectedType = apiTypeSelect.value;
        var defaults = apiTypeDefaults[selectedType];
        
        if (defaults) {
            if (apiUrlInput) {
                // 用户主动切换时：如果URL为空或仍为某个类型的默认URL，则自动更新
                if (isUserSwitch) {
                    if (!apiUrlInput.value || allDefaultUrls.indexOf(apiUrlInput.value) !== -1) {
                        apiUrlInput.value = defaults.url;
                    }
                } else {
                    // 页面初始化：仅在空时填入
                    if (!apiUrlInput.value) {
                        apiUrlInput.value = defaults.url;
                    }
                }
            }
            if (apiUrlDesc) {
                apiUrlDesc.textContent = defaults.urlDesc;
            }
            if (modelNameInput) {
                if (isUserSwitch) {
                    if (!modelNameInput.value || allDefaultModels.indexOf(modelNameInput.value) !== -1) {
                        modelNameInput.value = defaults.model;
                    }
                } else {
                    if (!modelNameInput.value) {
                        modelNameInput.value = defaults.model;
                    }
                }
            }
        }
    }
    
    // 监听API类型切换
    if (apiTypeSelect) {
        apiTypeSelect.addEventListener('change', function() {
            updateApiTypeDefaults(true);
        });
        // 页面加载时初始化（非用户切换）
        updateApiTypeDefaults(false);
    }

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
        var modelSelect = document.getElementById('predefined-api-model');
        var savedModel = '<?php echo esc_js($config_to_edit['model_name'] ?? ''); ?>';
        
        // 获取相关 DOM 元素
        var tokenRow = predefinedTokenInput ? predefinedTokenInput.closest('tr') : null;
        var quotaRow = document.getElementById('quota-info-row');
        var apiUrlRow = document.getElementById('api-url-row');
        var modelRow = document.getElementById('predefined-model-row');
        var refreshBtn = document.getElementById('refresh-pollinations-models');

        // 更新模型选项
        if (modelSelect) {
            modelSelect.innerHTML = '';
            var models = [];
            
            if (selectedChannel === 'pollinations') {
                if (modelRow) modelRow.style.display = '';
                if (refreshBtn) refreshBtn.style.display = '';
                
                models = [
                    { value: 'openai-large', text: 'openai-large (' + <?php echo json_encode(__('顶级模型，性能最强', 'yali-ai-writer')); ?> + ')' },
                    { value: 'openai', text: 'openai (' + <?php echo json_encode(__('标准模型，响应快', 'yali-ai-writer')); ?> + ')' },
                    { value: 'openai-fast', text: 'openai-fast (' + <?php echo json_encode(__('极速模型', 'yali-ai-writer')); ?> + ')' },
                    { value: 'claude-large', text: 'claude-large (Anthropic Claude 3.5)' },
                    { value: 'claude', text: 'claude (' + <?php echo json_encode(__('Claude 3 标准版', 'yali-ai-writer')); ?> + ')' },
                    { value: 'gemini-large', text: 'gemini-large (Google Gemini Pro)' },
                    { value: 'gemini', text: 'gemini (' + <?php echo json_encode(__('Gemini 标准版', 'yali-ai-writer')); ?> + ')' },
                    { value: 'deepseek', text: 'deepseek (DeepSeek V3)' },
                    { value: 'kimi', text: 'kimi (Moonshot AI)' },
                    { value: 'qwen-coder', text: 'qwen-coder (' + <?php echo json_encode(__('通义千问', 'yali-ai-writer')); ?> + ')' },
                    { value: 'grok', text: 'grok (xAI Grok 2)' },
                    { value: 'perplexity-reasoning', text: 'perplexity-reasoning (' + <?php echo json_encode(__('联网搜索推理', 'yali-ai-writer')); ?> + ')' },
                    { value: 'mistral', text: 'mistral (Mistral AI)' },
                    { value: 'glm', text: 'glm (' + <?php echo json_encode(__('智谱清言', 'yali-ai-writer')); ?> + ')' },
                    { value: 'minimax', text: 'minimax (' + <?php echo json_encode(__('海螺 AI', 'yali-ai-writer')); ?> + ')' }
                ];
            } else if (selectedChannel === 'official') {
                if (modelRow) modelRow.style.display = 'none';
                if (refreshBtn) refreshBtn.style.display = 'none';
                models = [];
            }
            
            models.forEach(function(m) {
                var opt = document.createElement('option');
                opt.value = m.value;
                opt.text = m.text;
                if (m.value === savedModel) {
                    opt.selected = true;
                }
                modelSelect.appendChild(opt);
            });
        }

        // 更新渠道特定的 UI
        if (selectedChannel === 'pollinations') {
            // Pollinations 渠道配置
            if (predefinedApiUrl) {
                predefinedApiUrl.textContent = 'https://gen.pollinations.ai/v1/chat/completions';
            }
            if (predefinedApiDescription) {
                predefinedApiDescription.innerHTML = <?php echo json_encode(__('模型: openai-large (默认), 兼容 OpenAI API 格式。支持流式输出。', 'yali-ai-writer')); ?>;
            }
            if (predefinedTokenDescription) {
                predefinedTokenDescription.innerHTML = <?php echo json_encode(__('Pollinations 现在需要 API Key 才能稳定使用。<br><strong>申请地址：</strong><a href="https://enter.pollinations.ai/" target="_blank">https://enter.pollinations.ai/</a><br>使用 API Key 后，不仅连接更稳定，且通过该接口生成的文章内容质量更高。', 'yali-ai-writer')); ?>;
            }
            if (predefinedTokenInput) {
                predefinedTokenInput.setAttribute('required', 'required');
                predefinedTokenInput.setAttribute('placeholder', <?php echo json_encode(__('请输入您的 API Key', 'yali-ai-writer')); ?>);
                // 更新 Label
                var label = tokenRow ? tokenRow.querySelector('th') : null;
                if (label) label.textContent = <?php echo json_encode(__('API Key (必填)', 'yali-ai-writer')); ?>;
            }
            // 显示 TOKEN 输入框
            if (tokenRow) tokenRow.style.display = '';
            // 显示 API 地址行
            if (apiUrlRow) apiUrlRow.style.display = '';
            // 隐藏配额信息行
            if (quotaRow) quotaRow.style.display = 'none';

            // 显示 Pollinations 账户信息行
            var accountRow = document.getElementById('pollinations-account-row');
            if (accountRow) {
                accountRow.style.display = '';
                var apiKey = (predefinedTokenInput) ? predefinedTokenInput.value : '';
                if (apiKey) {
                    getPollinationsAccountInfo(apiKey);
                } else {
                    var display = document.getElementById('pollinations-account-display');
                    if (display) display.innerHTML = '<div class="stats-loading">' + <?php echo json_encode(__('请输入 API Key 以查看账户信息', 'yali-ai-writer')); ?> + '</div>';
                }
            }

        } else if (selectedChannel === 'official') {
            // 插件官方 API 渠道配置
            if (predefinedApiUrl) {
                predefinedApiUrl.textContent = 'https://key.kdjingpai.com/api-proxy.php';
            }
            if (predefinedApiDescription) {
                predefinedApiDescription.innerHTML = <?php echo json_encode(__('插件官方API服务，通过授权码验证使用。<br><strong>如何申请使用：</strong><br>1. 联系插件作者微信：qn006699 获取插件授权码后使用<br>2. 在发布规则中配置授权码<br>3. 即可开始使用官方API服务', 'yali-ai-writer')); ?>;
            }
            // 隐藏 TOKEN 输入框
            if (tokenRow) tokenRow.style.display = 'none';
            if (predefinedTokenInput) {
                predefinedTokenInput.removeAttribute('required');
            }
            // 隐藏 API 地址行
            if (apiUrlRow) apiUrlRow.style.display = 'none';
            // 隐藏 Pollinations 账户信息行
            var accountRow = document.getElementById('pollinations-account-row');
            if (accountRow) accountRow.style.display = 'none';
            
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

    // 获取 Pollinations 账户信息
    function getPollinationsAccountInfo(apiKey) {
        var display = document.getElementById('pollinations-account-display');
        if (!display) return;
        
        display.innerHTML = '<div class="stats-loading"><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>' + <?php echo json_encode(__('正在拉取账户信息...', 'yali-ai-writer')); ?> + '</div>';
        
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_get_pollinations_account_info',
                nonce: '<?php echo wp_create_nonce('content_auto_manager_nonce'); ?>',
                api_key: apiKey
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '';
                    var hasData = false;
                    
                    // 1. 核心余额 (Pollen)
                    if (data.balance) {
                        var pVal = parseFloat(data.balance.pollen || 0);
                        var label = data.balance.is_budget ? <?php echo json_encode(__('Key 预算 (Pollen)', 'yali-ai-writer')); ?> : <?php echo json_encode(__('花粉余额 (Pollen)', 'yali-ai-writer')); ?>;
                        var color = data.balance.is_budget ? 'var(--yali-warning)' : 'var(--yali-primary)';
                        html += '<div class="pollinations-stat-card"><span class="stat-label">' + label + '</span><span class="stat-value" style="color:' + color + '">' + pVal.toFixed(2) + '</span><span class="stat-unit">Credits</span></div>';
                        
                        if (!data.balance.is_budget) {
                            html += '<div class="pollinations-stat-card"><span class="stat-label">' + <?php echo json_encode(__('账户金额 (USD)', 'yali-ai-writer')); ?> + '</span><span class="stat-value">$' + parseFloat(data.balance.usd || 0).toFixed(2) + '</span><span class="stat-unit">USD</span></div>';
                        }
                        hasData = true;
                    }
                    
                    // 2. 概览信息 (Tier & Usage)
                    if (data.profile) {
                        html += '<div class="pollinations-stat-card"><span class="stat-label">' + <?php echo json_encode(__('账户等级', 'yali-ai-writer')); ?> + '</span><span class="stat-value">' + (data.profile.tier || 'Microbe').toUpperCase() + '</span><span class="stat-unit">' + (data.profile.email || '') + '</span></div>';
                        hasData = true;
                    }

                    if (data.usage) {
                        html += '<div class="pollinations-stat-card"><span class="stat-label">' + <?php echo json_encode(__('累计消耗 (Pollen)', 'yali-ai-writer')); ?> + '</span><span class="stat-value">' + parseFloat(data.usage.pollen_spent || 0).toFixed(2) + '</span><span class="stat-unit">Spent</span></div>';
                        html += '<div class="pollinations-stat-card"><span class="stat-label">' + <?php echo json_encode(__('累计 Token', 'yali-ai-writer')); ?> + '</span><span class="stat-value">' + parseInt(data.usage.total_tokens || 0).toLocaleString() + '</span><span class="stat-unit">Total</span></div>';
                        hasData = true;
                    }

                    // 3. 今日明细
                    if (data.daily_usage) {
                        html += '<div class="pollinations-stat-card" style="background: rgba(34, 197, 94, 0.03); border-color: rgba(34, 197, 94, 0.15);"><span class="stat-label">' + <?php echo json_encode(__('今日消耗 (Pollen)', 'yali-ai-writer')); ?> + '</span><span class="stat-value" style="color: var(--yali-success);">' + parseFloat(data.daily_usage.pollen_spent || 0).toFixed(3) + '</span><span class="stat-unit">Credits</span></div>';
                        html += '<div class="pollinations-stat-card" style="background: rgba(34, 197, 94, 0.03); border-color: rgba(34, 197, 94, 0.15);"><span class="stat-label">' + <?php echo json_encode(__('今日 Token', 'yali-ai-writer')); ?> + '</span><span class="stat-value" style="color: var(--yali-success);">' + parseInt(data.daily_usage.total_tokens || 0).toLocaleString() + '</span><span class="stat-unit">Tokens</span></div>';
                        hasData = true;
                    }
                    
                    // 4. 权限提醒
                    var perms = data.permissions || [];
                    if (!perms.includes('balance') || !perms.includes('usage')) {
                        html += '<div class="pollinations-stat-card" style="grid-column: 1 / -1; background: rgba(245, 158, 11, 0.05); border-color: rgba(245, 158, 11, 0.2); text-align:left;">';
                        html += '<span class="stat-label" style="color:var(--yali-warning)">' + <?php echo json_encode(__('权限提醒 (Missing Scopes)', 'yali-ai-writer')); ?> + '</span>';
                        html += '<div style="font-size:12px; line-height:1.4; color:var(--yali-text-muted); padding-top:4px;"><?php echo esc_js(__('当前 API Key 缺失 <b>account:balance</b> 或 <b>account:usage</b> 权限。如需查看完整账单，请在 Pollinations 仪表板重新生成 Key 并勾选相应权限。', 'yali-ai-writer')); ?></div></div>';
                        // 即使没有数据，也要显示这个提醒
                        hasData = true;
                    }
                    
                    if (!hasData) {
                        display.innerHTML = '<div class="stats-loading">' + <?php echo json_encode(__('未能获取账户详细数据，请确认 API Key 是否有 account 相关权限。', 'yali-ai-writer')); ?> + '</div>';
                    } else {
                        display.innerHTML = html;
                    }
                } else {
                    display.innerHTML = '<div class="stats-loading" style="color: var(--yali-error);">' + <?php echo json_encode(__('获取失败: ', 'yali-ai-writer')); ?> + (response.data ? response.data.message : <?php echo json_encode(__('密钥失效或接口请求频率限制', 'yali-ai-writer')); ?>) + '</div>';
                }
            },
            error: function() {
                display.innerHTML = '<div class="stats-loading" style="color: var(--yali-error);">' + <?php echo json_encode(__('网络连接异常', 'yali-ai-writer')); ?> + '</div>';
            }
        });
    }

    // 监听 API Key 变化
    if (predefinedTokenInput) {
        var timeout = null;
        predefinedTokenInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                var selectedChannel = getCurrentSelectedChannel();
                if (selectedChannel === 'pollinations' && predefinedTokenInput.value) {
                    getPollinationsAccountInfo(predefinedTokenInput.value);
                }
            }, 800);
        });
    }

    // 监听渠道变化（仅在新建模式下存在select元素）
    if (predefinedChannelSelect) {
        predefinedChannelSelect.addEventListener('change', updatePredefinedChannelInfo);
    }

    // 页面加载时初始化（无论是编辑模式还是新建模式都执行）
    updatePredefinedChannelInfo();

    // 动态获取 Pollinations 模型列表
    function fetchPollinationsModels() {
        var refreshBtn = document.getElementById('refresh-pollinations-models');
        var statusSpan = document.getElementById('model-refresh-status');
        var modelSelect = document.getElementById('predefined-api-model');
        var currentSelected = modelSelect ? modelSelect.value : '';

        if (refreshBtn) refreshBtn.disabled = true;
        if (statusSpan) statusSpan.style.display = '';

        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'content_auto_fetch_pollinations_models',
                nonce: '<?php echo wp_create_nonce('content_auto_manager_nonce'); ?>'
            },
            success: function(response) {
                if (response.success && response.data.models) {
                    if (modelSelect) {
                        modelSelect.innerHTML = '';
                        // 预定义的友好标签映射
                        var friendlyNames = {
                            'openai-large': 'openai-large (<?php echo esc_js(__('顶级模型，性能最强', 'yali-ai-writer')); ?>)',
                            'openai': 'openai (<?php echo esc_js(__('标准模型，响应快', 'yali-ai-writer')); ?>)',
                            'openai-fast': 'openai-fast (<?php echo esc_js(__('极速模型', 'yali-ai-writer')); ?>)',
                            'claude-large': 'claude-large (Anthropic Claude 3.5)',
                            'claude': 'claude (<?php echo esc_js(__('Claude 3 标准版', 'yali-ai-writer')); ?>)',
                            'gemini-large': 'gemini-large (Google Gemini Pro)',
                            'gemini': 'gemini (<?php echo esc_js(__('Gemini 标准版', 'yali-ai-writer')); ?>)',
                            'deepseek': 'deepseek (DeepSeek V3)',
                            'kimi': 'kimi (Moonshot AI)',
                            'qwen-coder': 'qwen-coder (<?php echo esc_js(__('通义千问', 'yali-ai-writer')); ?>)',
                            'grok': 'grok (xAI Grok 2)',
                            'perplexity-reasoning': 'perplexity-reasoning (<?php echo esc_js(__('联网搜索推理', 'yali-ai-writer')); ?>)',
                            'mistral': 'mistral (Mistral AI)',
                            'glm': 'glm (<?php echo esc_js(__('智谱清言', 'yali-ai-writer')); ?>)',
                            'minimax': 'minimax (<?php echo esc_js(__('海螺 AI', 'yali-ai-writer')); ?>)'
                        };

                        response.data.models.forEach(function(m) {
                            var opt = document.createElement('option');
                            opt.value = m.id;
                            opt.text = friendlyNames[m.id] || m.id; // 如果没有友好名称则显示 ID
                            if (m.id === currentSelected) {
                                opt.selected = true;
                            }
                            modelSelect.appendChild(opt);
                        });
                        
                        alert(<?php echo json_encode(__('✨ 模型列表同步成功！现已加载最新可用模型。', 'yali-ai-writer')); ?>);
                    }
                } else {
                    alert(<?php echo json_encode(__('❌ 获取失败: ', 'yali-ai-writer')); ?> + (response.data ? response.data.message : <?php echo json_encode(__('未知错误', 'yali-ai-writer')); ?>));
                }
            },
            error: function() {
                alert(<?php echo json_encode(__('网络连接错误，请稍后再试。', 'yali-ai-writer')); ?>);
            },
            complete: function() {
                if (refreshBtn) refreshBtn.disabled = false;
                if (statusSpan) statusSpan.style.display = 'none';
            }
        });
    }

    // 绑定刷新按钮事件
    var refreshBtn = document.getElementById('refresh-pollinations-models');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', fetchPollinationsModels);
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
                    vectorModelDescription.textContent = <?php echo json_encode(__('用于向量嵌入的模型名称，例如: text-embedding-ada-002', 'yali-ai-writer')); ?>;
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
                    vectorModelDescription.textContent = <?php echo json_encode(__('Jina Embeddings v4 固定为1024维，请使用: jina-embeddings-v4', 'yali-ai-writer')); ?>;
                }
                
                // Jina 可选填 Key
                var vectorApiKeyInput = document.querySelector('input[name="vector_api_key"]');
                if (vectorApiKeyInput) {
                    vectorApiKeyInput.removeAttribute('required');
                    vectorApiKeyInput.setAttribute('placeholder', <?php echo json_encode(__('Jina v4 可选填密钥，留空则允许', 'yali-ai-writer')); ?>);
                }
            }
            
            // 补充 OpenAI 配置时的 Key 必填逻辑
            if (selectedType === 'openai') {
                var vectorApiKeyInput = document.querySelector('input[name="vector_api_key"]');
                if (vectorApiKeyInput) {
                    var isEditConfig = <?php echo (isset($edit_config) && $edit_config) ? 'true' : 'false'; ?>;
                    // 如果不是编辑模式（新建模式），则OpenAI必须填Key
                    if (!isEditConfig) {
                        vectorApiKeyInput.setAttribute('required', 'required');
                    }
                    vectorApiKeyInput.setAttribute('placeholder', <?php echo json_encode(__('留空则不修改', 'yali-ai-writer')); ?>);
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

    
    <!-- 配置列表 -->
    <div class="content-auto-section yali-card">
        <h2><span class="dashicons dashicons-list-view"></span> <?php _e('配置列表', 'yali-ai-writer'); ?></h2>
        
        
        <?php if (empty($configs)): ?>
            <p><?php _e('暂无API配置，请添加一个配置。', 'yali-ai-writer'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped yali-table">
                <thead>
                    <tr>
                        <th><?php _e('名称', 'yali-ai-writer'); ?></th>
                        <th><?php _e('API地址', 'yali-ai-writer'); ?></th>
                        <th><?php _e('模型', 'yali-ai-writer'); ?></th>
                        <th class="yali-text-center" style="width: 100px;"><?php _e('API类型', 'yali-ai-writer'); ?></th>
                        <th class="yali-text-center" style="width: 100px;"><?php _e('配置类型', 'yali-ai-writer'); ?></th>
                        <th class="yali-text-center" style="width: 100px;"><?php _e('状态', 'yali-ai-writer'); ?></th>
                        <th class="yali-text-center" style="width: 200px;"><?php _e('操作', 'yali-ai-writer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($configs as $config): ?>
                        <tr>
                            <td><strong><?php echo esc_html(__($config['name'], 'yali-ai-writer')); ?></strong></td>
                            <td><code style="font-size: 12px; background: #f0f0f1; padding: 2px 5px; border-radius: 3px;"><?php echo esc_html(content_auto_manager_truncate_string($config['api_url'], 30)); ?></code></td>
                            <td><?php echo esc_html(__($config['model_name'], 'yali-ai-writer')); ?></td>
                            <td class="yali-text-center">
                                <?php if (!empty($config['predefined_channel']) || !empty($config['vector_api_url'])): ?>
                                    <span class="yali-badge yali-badge-neutral">-</span>
                                <?php else: ?>
                                    <?php 
                                    $api_type = isset($config['api_type']) ? $config['api_type'] : 'openai';
                                    $api_type_labels = array(
                                        'openai' => 'OpenAI',
                                        'gemini' => 'Gemini',
                                        'claude' => 'Claude'
                                    );
                                    $api_type_class = array(
                                        'openai' => 'yali-badge-success',
                                        'gemini' => 'yali-badge-info',
                                        'claude' => 'yali-badge-warning'
                                    );
                                    ?>
                                    <span class="yali-badge <?php echo esc_attr($api_type_class[$api_type] ?? 'yali-badge-neutral'); ?>">
                                        <?php echo esc_html($api_type_labels[$api_type] ?? strtoupper($api_type)); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="yali-text-center">
                                <?php if (!empty($config['predefined_channel'])): ?>
                                    <span class="yali-badge yali-badge-info"><?php _e('预置API', 'yali-ai-writer'); ?></span>
                                <?php elseif (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])): ?>
                                    <span class="yali-badge yali-badge-warning"><?php _e('向量API', 'yali-ai-writer'); ?></span>
                                <?php else: ?>
                                    <span class="yali-badge"><?php _e('自定义API', 'yali-ai-writer'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="yali-text-center">
                                <?php if (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])): ?>
                                    <span class="yali-badge yali-badge-success" title="<?php echo esc_attr(__('向量API配置全局生效，无需激活状态', 'yali-ai-writer')); ?>"><?php _e('已配置', 'yali-ai-writer'); ?></span>
                                <?php elseif ($config['is_active']): ?>
                                    <span class="yali-badge yali-badge-success"><?php _e('已激活', 'yali-ai-writer'); ?></span>
                                <?php else: ?>
                                    <span class="yali-badge yali-badge-neutral"><?php _e('未激活', 'yali-ai-writer'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="yali-text-center">
                                <?php
                                $tab = (!empty($config['predefined_channel']) ? 'predefined' : ((!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) ? 'vector' : 'custom'));
                                $edit_url = admin_url('admin.php?page=yali-ai-writer-api&action=edit&id=' . $config['id'] . '&tab=' . $tab);
                                $edit_url = wp_nonce_url($edit_url, 'content_auto_manager_edit_config', 'nonce');
                                $delete_url = wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $config['id'])), 'content_auto_manager_delete_config', 'nonce');
                                ?>
                                <div class="yali-btn-group-center">
                                    <a href="<?php echo $edit_url; ?>" class="yali-btn yali-btn-secondary yali-btn-small">
                                        <?php _e('编辑', 'yali-ai-writer'); ?>
                                    </a>
                                    <button type="button" class="yali-btn yali-btn-secondary yali-btn-small test-api-connection" data-config-id="<?php echo esc_attr($config['id']); ?>">
                                        <?php _e('测试', 'yali-ai-writer'); ?>
                                    </button>
                                    <button type="button" class="yali-btn yali-btn-danger yali-btn-small cam-delete-config"
                                            data-yali-action="delete"
                                            data-yali-ajax-action="cam_delete_api_config"
                                            data-yali-id="<?php echo esc_attr($config['id']); ?>"
                                            data-yali-id-param="id"
                                            data-yali-nonce="<?php echo esc_attr(wp_create_nonce('cam_delete_api_config')); ?>"
                                            data-yali-confirm="<?php echo esc_attr(__('确定要删除此API配置吗？此操作不可撤销。', 'yali-ai-writer')); ?>">
                                        <?php _e('删除', 'yali-ai-writer'); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
