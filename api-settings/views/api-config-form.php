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
$predefined_api = new Yali_AI_Writer_PredefinedApi();

// 获取要编辑的配置
$edit_config = null;
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $api_config = new Yali_AI_Writer_ApiConfig();
    $edit_config = $api_config->get_config(intval($_GET['id']));
}

// 获取所有配置
$api_config = new Yali_AI_Writer_ApiConfig();
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
    $config_to_edit = $api_config->get_config(intval($_GET['id']));
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
    $api_config_obj = new Yali_AI_Writer_ApiConfig();
    $config_to_edit = $api_config_obj->get_config(intval($_GET['id']));
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
                <?php wp_nonce_field('yali_ai_writer_manager_api_config', 'yali_ai_writer_manager_nonce'); ?>
                
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
                <?php wp_nonce_field('yali_ai_writer_manager_predefined_api', 'predefined_api_nonce'); ?>
                
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
                                <select class="yali-select" name="predefined_api_model" id="predefined-api-model" style="min-width: 250px;" required>
                                    <!-- 由 JS 动态填充 -->
                                    <?php
                                    // 编辑模式下显示已保存的模型
                                    if ($edit_config && !empty($edit_config['model_name'])) {
                                        echo '<option value="' . esc_attr($edit_config['model_name']) . '">' . esc_html($edit_config['model_name']) . ' (' . __('当前保存的模型', 'yali-ai-writer') . ')</option>';
                                    } else {
                                        // 新建模式下显示提示选项
                                        echo '<option value="">↗️ ' . __('请先点击"同步多模型"获取可用模型列表', 'yali-ai-writer') . '</option>';
                                    }
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
                                    _e('由 Pollinations 提供的多种顶级开源、商业模型。点击"同步多模型"获取最新可用模型列表。', 'yali-ai-writer');
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
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'edit', 'id' => $existing_vector_config['id'], 'tab' => 'vector')), 'yali_ai_writer_manager_edit_config', 'nonce')); ?>" class="button-primary yali-btn yali-btn-primary">
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
                <?php wp_nonce_field('yali_ai_writer_manager_api_config', 'yali_ai_writer_manager_vector_nonce'); ?>
                
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
            $search_settings = get_option('yali_ai_writer_search_settings', []);
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
            $current_license = get_option('yali_ai_writer_manager_license_key', '');
            
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
                <?php wp_nonce_field('yali_ai_writer_manager_search_config', 'yali_ai_writer_manager_search_nonce'); ?>
                <!-- 用于测试连接的nonce -->
                <?php wp_nonce_field('yali_ai_writer_manager_nonce', 'test_search_nonce_field'); ?>
                
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

$results = yali_ai_writer_search('<?php _e('关键词', 'yali-ai-writer'); ?>');

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
                <?php wp_nonce_field('yali_ai_writer_manager_reader_config', 'yali_ai_writer_manager_reader_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('API Key', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="password" name="jina_api_key" value="<?php echo esc_attr(get_option('yali_ai_writer_jina_api_key', '')); ?>" class="regular-text yali-input" placeholder="jina_..." autocomplete="off">
                            <p class="description yali-desc"><?php _e('请输入您的 Jina Reader API Key。留空则使用匿名模式。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('搜索结果过滤黑名单', 'yali-ai-writer'); ?></th>
                        <td>
                            <?php 
                            $blacklist = get_option('yali_ai_writer_material_search_blacklist', ['csdn.net', 'zhihu.com']);
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
                            <td><code style="font-size: 12px; background: #f0f0f1; padding: 2px 5px; border-radius: 3px;"><?php echo esc_html(yali_ai_writer_manager_truncate_string($config['api_url'], 30)); ?></code></td>
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
                                $edit_url = wp_nonce_url($edit_url, 'yali_ai_writer_manager_edit_config', 'nonce');
                                $delete_url = wp_nonce_url(add_query_arg(array('action' => 'delete', 'id' => $config['id'])), 'yali_ai_writer_manager_delete_config', 'nonce');
                                ?>
                                <div class="yali-btn-group-center">
                                    <a href="<?php echo esc_url($edit_url); ?>" class="yali-btn yali-btn-secondary yali-btn-small">
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
