<?php
// image-api-settings/views/image-api-config-form.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */

// Determine the active tab. Default to 'modelscope' if nothing is set.
$active_provider = !empty($settings['provider']) ? $settings['provider'] : 'modelscope';
?>
<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-images-alt2"></span> <?php _e('配图 API 设置', 'yali-ai-writer'); ?></h1>
    <p><?php _e('配置不同的图像生成API供应商。点击下方的“保存设置”时，当前激活的选项卡对应的供应商将被设为默认图像生成器。', 'yali-ai-writer'); ?></p>

    <form method="post" action="" class="yali-ajax-form" data-action="cam_save_image_api_settings" data-nonce="<?php echo wp_create_nonce('cam_save_image_api_settings'); ?>">
        <?php wp_nonce_field('cam_save_image_api_settings', 'cam_save_image_api_settings_nonce'); ?>
        
        <!-- Hidden input to store the active provider -->
        <input type="hidden" id="cam_image_api_provider" name="cam_image_api_provider" value="<?php echo esc_attr($active_provider); ?>">

        <!-- Tab Navigation -->
        <div class="yali-tabs">
            <a href="#modelscope_settings" class="yali-tab-item <?php echo $active_provider === 'modelscope' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-format-gallery"></span>
                <?php _e('ModelScope (魔搭)', 'yali-ai-writer'); ?>
            </a>
            <a href="#openai_settings" class="yali-tab-item <?php echo $active_provider === 'openai' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-superhero"></span>
                OpenAI
            </a>
            <a href="#siliconflow_settings" class="yali-tab-item <?php echo $active_provider === 'siliconflow' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-cloud"></span>
                <?php _e('硅基流动', 'yali-ai-writer'); ?>
            </a>
            <a href="#pollinations_settings" class="yali-tab-item <?php echo $active_provider === 'pollinations' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-art"></span>
                Pollinations.AI
            </a>
            <a href="#volcengine_settings" class="yali-tab-item <?php echo $active_provider === 'volcengine' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-share-alt"></span>
                <?php _e('火山引擎', 'yali-ai-writer'); ?>
            </a>
            <a href="#custom_settings" class="yali-tab-item <?php echo $active_provider === 'custom' ? 'active' : ''; ?>">
                <span class="dashicons dashicons-admin-generic"></span>
                <?php _e('自定义 API', 'yali-ai-writer'); ?>
            </a>
        </div>

        <!-- Tab Content -->
        <div id="modelscope_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'modelscope' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-modelscope.php'; ?>
        </div>

        <div id="openai_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'openai' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-openai.php'; ?>
        </div>

        <div id="siliconflow_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'siliconflow' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-siliconflow.php'; ?>
        </div>

        <div id="pollinations_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'pollinations' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-pollinations.php'; ?>
        </div>

        <div id="volcengine_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'volcengine' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-volcengine.php'; ?>
        </div>

        <div id="custom_settings" class="yali-tab-content yali-card <?php echo $active_provider === 'custom' ? 'active' : ''; ?>">
            <?php include plugin_dir_path(__FILE__) . 'provider-custom.php'; ?>
        </div>

        <?php submit_button(__('保存设置', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary'); ?>
    </form>
</div>