<?php
// image-api-settings/views/image-api-config-form.php
if (!defined('ABSPATH')) exit;

/** @var array $settings */

// Determine the active tab. Default to 'modelscope' if nothing is set.
$active_provider = !empty($settings['provider']) ? $settings['provider'] : 'modelscope';
?>
<div class="wrap">
    <h1><?php echo esc_html__('图像API设置', 'content-auto-manager'); ?></h1>
    <p><?php echo esc_html__('配置不同的图像生成API供应商。点击下方的“保存设置”时，当前激活的选项卡对应的供应商将被设为默认图像生成器。', 'content-auto-manager'); ?></p>

    <form method="post" action="">
        <?php wp_nonce_field('cam_save_image_api_settings', 'cam_save_image_api_settings_nonce'); ?>
        
        <!-- Hidden input to store the active provider -->
        <input type="hidden" id="cam_image_api_provider" name="cam_image_api_provider" value="<?php echo esc_attr($active_provider); ?>">

        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <a href="#modelscope_settings" class="nav-tab">
                <?php echo esc_html__('ModelScope (魔搭)', 'content-auto-manager'); ?>
            </a>
            <a href="#openai_settings" class="nav-tab">
                <?php echo esc_html__('OpenAI', 'content-auto-manager'); ?>
            </a>
            <a href="#siliconflow_settings" class="nav-tab">
                <?php echo esc_html__('硅基流动', 'content-auto-manager'); ?>
            </a>
            <a href="#pollinations_settings" class="nav-tab">
                <?php echo esc_html__('Pollinations.AI', 'content-auto-manager'); ?>
            </a>
        </nav>

        <!-- Tab Content -->
        <div id="modelscope_settings" class="tab-content">
            <?php include plugin_dir_path(__FILE__) . 'provider-modelscope.php'; ?>
        </div>

        <div id="openai_settings" class="tab-content">
            <?php include plugin_dir_path(__FILE__) . 'provider-openai.php'; ?>
        </div>

        <div id="siliconflow_settings" class="tab-content">
            <?php include plugin_dir_path(__FILE__) . 'provider-siliconflow.php'; ?>
        </div>

        <div id="pollinations_settings" class="tab-content">
            <?php include plugin_dir_path(__FILE__) . 'provider-pollinations.php'; ?>
        </div>

        <?php submit_button(__('保存设置', 'content-auto-manager')); ?>
    </form>
</div>