<?php
/**
 * 发布规则管理渲染类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_PublishRulesAdminPage {
    public static function init() {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_cam_save_publish_rules', [__CLASS__, 'save_rules_ajax']);
    }

    public static function enqueue_assets($hook) {
        // Enqueue admin.js globally or specifically here if needed (it's already global in class-admin-menu.php)
        
            /* 
             * Universal AJAX Handler is now used. 
             * publish-rules.js is no longer needed.
             * admin.js is already enqueued globally.
             */

    }

    /**
     * AJAX Handler for saving publish rules
     */
    public static function save_rules_ajax() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cam_save_publish_rules')) {
            wp_send_json_error(['message' => __('安全验证失败', 'yali-ai-writer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }

        require_once YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/class-image-prompt-manager.php';
        $default_image_prompt = Yali_AI_Writer_ImagePromptManager::get_default_template();

        $image_prompt_from_post = isset($_POST['image_prompt_template']) ? trim(wp_unslash($_POST['image_prompt_template'])) : null;
        $material_mode = isset($_POST['material_collection_mode']) ? sanitize_text_field(wp_unslash($_POST['material_collection_mode'])) : 'none';
        $enable_auto_material_search = ($material_mode !== 'none') ? 1 : 0;

        $data = array(
            'post_status' => isset($_POST['post_status']) ? sanitize_text_field(wp_unslash($_POST['post_status'])) : 'publish',
            'author_id' => isset($_POST['author_id']) ? intval(wp_unslash($_POST['author_id'])) : get_current_user_id(),
            'category_mode' => isset($_POST['category_mode']) ? sanitize_text_field(wp_unslash($_POST['category_mode'])) : 'auto',
            'category_ids' => isset($_POST['category_ids']) ? maybe_serialize(array_map('intval', (array) wp_unslash($_POST['category_ids']))) : '',
            'fallback_category_ids' => isset($_POST['fallback_category_ids']) ? maybe_serialize(array_map('intval', (array) wp_unslash($_POST['fallback_category_ids']))) : '',
            'target_length' => isset($_POST['target_length']) ? sanitize_text_field(wp_unslash($_POST['target_length'])) : 'medium',
            'knowledge_depth' => isset($_POST['knowledge_depth']) ? sanitize_text_field(wp_unslash($_POST['knowledge_depth'])) : 'standard',
            'reader_role' => isset($_POST['reader_role']) ? sanitize_text_field(wp_unslash($_POST['reader_role'])) : 'general',
            'normalize_output' => isset($_POST['normalize_output']) ? 1 : 0,
            'structure_mode' => isset($_POST['structure_mode']) ? sanitize_text_field(wp_unslash($_POST['structure_mode'])) : 'generic',
            'auto_image_insertion' => isset($_POST['auto_image_insertion']) ? 1 : 0,
            'max_auto_images' => isset($_POST['max_auto_images']) ? intval(wp_unslash($_POST['max_auto_images'])) : 1,
            'skip_first_image_placeholder' => isset($_POST['skip_first_image_placeholder']) ? 1 : 0,
            'enable_internal_linking' => isset($_POST['enable_internal_linking']) ? 1 : 0,
            'enable_brand_profile_insertion' => isset($_POST['enable_brand_profile_insertion']) ? 1 : 0,
            'brand_profile_position' => isset($_POST['brand_profile_position']) ? sanitize_text_field(wp_unslash($_POST['brand_profile_position'])) : 'before_second_paragraph',
            'enable_reference_material' => isset($_POST['enable_reference_material']) ? 1 : 0,
            'enable_ai_reference_select' => isset($_POST['enable_ai_reference_select']) ? 1 : 0,
            'enable_auto_material_search' => $enable_auto_material_search,
            'material_collection_mode' => $material_mode,
            'enable_intent_inference' => isset($_POST['enable_intent_inference']) ? 1 : 0,
            'publish_interval_minutes' => isset($_POST['publish_interval_minutes']) ? intval(wp_unslash($_POST['publish_interval_minutes'])) : 0,
            'publish_language' => isset($_POST['publish_language']) ? sanitize_text_field(wp_unslash($_POST['publish_language'])) : 'zh_CN',
            'role_description' => isset($_POST['role_description']) ? sanitize_textarea_field(wp_unslash($_POST['role_description'])) : '',
            'image_prompt_mode' => isset($_POST['image_prompt_mode']) ? sanitize_text_field(wp_unslash($_POST['image_prompt_mode'])) : 'default',
            'image_prompt_template' => empty(trim($image_prompt_from_post)) ? $default_image_prompt : $image_prompt_from_post,
            'enable_editor_assistant' => isset($_POST['enable_editor_assistant']) ? 1 : 0,
        );

        $database = new Yali_AI_Writer_Database();
        $existing_rule = $database->get_row('yali_ai_writer_publish_rules', array('id' => 1));

        if ($existing_rule) {
            $result = $database->update('yali_ai_writer_publish_rules', $data, array('id' => 1));
            if ($result !== false) {
                wp_send_json_success(['message' => __('发布规则已更新', 'yali-ai-writer')]);
            } else {
                wp_send_json_error(['message' => __('发布规则更新失败', 'yali-ai-writer')]);
            }
        } else {
            $data['id'] = 1;
            $rule_id = $database->insert('yali_ai_writer_publish_rules', $data);
            if ($rule_id) {
                wp_send_json_success(['message' => __('发布规则已创建', 'yali-ai-writer')]);
            } else {
                wp_send_json_error(['message' => __('发布规则创建失败', 'yali-ai-writer')]);
            }
        }
    }

    /**
     * 渲染完整页面
     */
    public function render_page() {
        // 1. Preparation - Load data
        $database = new Yali_AI_Writer_Database();
        $publish_rule = $database->get_row('yali_ai_writer_publish_rules', array('id' => 1));
        
        // 2. Render Output
        ?>
        <div class="wrap yali-plugin-wrapper">
            
            <?php 
            // Pass standard variables to the view
            $is_licensed = Yali_AI_Writer_License_Manager::is_license_active();
            
            // Include the view file - it will have access to variables in this scope
            include YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/views/publish-rules.php'; 
            ?>
        </div>
        <?php
    }
}
