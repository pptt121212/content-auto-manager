<?php
/**
 * 发布规则管理渲染类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PublishRulesAdminPage {
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
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_save_publish_rules')) {
            wp_send_json_error(['message' => __('安全验证失败', 'yali-ai-writer')]);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }

        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/class-image-prompt-manager.php';
        $default_image_prompt = ContentAuto_ImagePromptManager::get_default_template();

        $image_prompt_from_post = isset($_POST['image_prompt_template']) ? trim(wp_unslash($_POST['image_prompt_template'])) : null;
        $material_mode = isset($_POST['material_collection_mode']) ? sanitize_text_field($_POST['material_collection_mode']) : 'none';
        $enable_auto_material_search = ($material_mode !== 'none') ? 1 : 0;

        $data = array(
            'post_status' => sanitize_text_field($_POST['post_status']),
            'author_id' => intval($_POST['author_id']),
            'category_mode' => sanitize_text_field($_POST['category_mode']),
            'category_ids' => isset($_POST['category_ids']) ? maybe_serialize($_POST['category_ids']) : '',
            'fallback_category_ids' => isset($_POST['fallback_category_ids']) ? maybe_serialize($_POST['fallback_category_ids']) : '',
            'target_length' => sanitize_text_field($_POST['target_length']),
            'knowledge_depth' => sanitize_text_field($_POST['knowledge_depth']),
            'reader_role' => sanitize_text_field($_POST['reader_role']),
            'normalize_output' => isset($_POST['normalize_output']) ? 1 : 0,
            'structure_mode' => isset($_POST['structure_mode']) ? sanitize_text_field($_POST['structure_mode']) : 'generic',
            'auto_image_insertion' => isset($_POST['auto_image_insertion']) ? 1 : 0,
            'max_auto_images' => isset($_POST['max_auto_images']) ? intval($_POST['max_auto_images']) : 1,
            'skip_first_image_placeholder' => isset($_POST['skip_first_image_placeholder']) ? 1 : 0,
            'enable_internal_linking' => isset($_POST['enable_internal_linking']) ? 1 : 0,
            'enable_brand_profile_insertion' => isset($_POST['enable_brand_profile_insertion']) ? 1 : 0,
            'brand_profile_position' => isset($_POST['brand_profile_position']) ? sanitize_text_field($_POST['brand_profile_position']) : 'before_second_paragraph',
            'enable_reference_material' => isset($_POST['enable_reference_material']) ? 1 : 0,
            'enable_ai_reference_select' => isset($_POST['enable_ai_reference_select']) ? 1 : 0,
            'enable_auto_material_search' => $enable_auto_material_search,
            'material_collection_mode' => $material_mode,
            'enable_intent_inference' => isset($_POST['enable_intent_inference']) ? 1 : 0,
            'publish_interval_minutes' => intval($_POST['publish_interval_minutes']),
            'publish_language' => sanitize_text_field($_POST['publish_language']),
            'role_description' => sanitize_textarea_field($_POST['role_description']),
            'image_prompt_mode' => isset($_POST['image_prompt_mode']) ? sanitize_text_field($_POST['image_prompt_mode']) : 'default',
            'image_prompt_template' => empty(trim($image_prompt_from_post)) ? $default_image_prompt : $image_prompt_from_post,
            'enable_editor_assistant' => isset($_POST['enable_editor_assistant']) ? 1 : 0,
        );

        $database = new ContentAuto_Database();
        $existing_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));

        if ($existing_rule) {
            $result = $database->update('content_auto_publish_rules', $data, array('id' => 1));
            if ($result !== false) {
                wp_send_json_success(['message' => __('发布规则已更新', 'yali-ai-writer')]);
            } else {
                wp_send_json_error(['message' => __('发布规则更新失败', 'yali-ai-writer')]);
            }
        } else {
            $data['id'] = 1;
            $rule_id = $database->insert('content_auto_publish_rules', $data);
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
        $database = new ContentAuto_Database();
        $publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));
        
        // 2. CSS/JS Injection Logic (Standardized)
        $tokens_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/assets/css/brand-tokens.css';
        $base_kit_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/assets/css/yali-ui-kit.css';
        $style_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/assets/css/publish-rules.css';
        
        $tokens_css = file_exists($tokens_path) ? file_get_contents($tokens_path) : '';
        $base_kit_css = file_exists($base_kit_path) ? file_get_contents($base_kit_path) : '';
        $style_css = file_exists($style_path) ? file_get_contents($style_path) : '';

        // 3. Render Output
        ?>
        <div class="wrap yali-plugin-wrapper">
            <style type="text/css">
                <?php echo $tokens_css; ?>
                <?php echo $base_kit_css; ?>
                <?php echo $style_css; ?>
                
                /* Specific overrides for publish rules if needed */
                .yali-license-status { margin-top: 8px; font-weight: 600; padding: 10px 15px; border-radius: 8px; display: inline-block; }
                .yali-license-status.valid { background: #ecfdf5; color: #059669; border: 1px solid #10b981; }
                .yali-license-status.invalid { background: #fef2f2; color: #dc2626; border: 1px solid #f87171; }
                
                /* Layout Refinement: Open form look */
                .yali-card .form-table { margin-top: 0 !important; }
                .yali-card .form-table th { padding-left: 0 !important; width: 180px !important; color: var(--yali-text); font-weight: 600; }
                .yali-card .form-table td { padding: 15px 10px !important; }
                
                /* Remove box-within-box look */
                .yali-card .yali-table { border: none !important; box-shadow: none !important; background: transparent !important; }
                .yali-card .yali-table td, .yali-card .yali-table th { border-bottom: 1px solid #f1f5f9 !important; }
                .yali-card .yali-table tr:last-child td { border-bottom: none !important; }
                
                /* Disable row hover background as requested */
                .yali-card .yali-table tbody tr:hover,
                .yali-card .form-table tbody tr:hover { background: transparent !important; }
            </style>
            
            <?php 
            // Pass standard variables to the view
            $is_licensed = ContentAuto_License_Manager::is_license_active();
            
            // Include the view file - it will have access to variables in this scope
            include CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/views/publish-rules.php'; 
            ?>
        </div>
        <?php
    }
}
