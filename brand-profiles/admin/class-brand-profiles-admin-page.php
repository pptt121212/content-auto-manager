<?php
/**
 * Admin page for managing brand profiles.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Brand_Profiles_Admin_Page {

    public function __construct() {
        add_action('wp_ajax_cam_get_brand_profiles', [$this, 'ajax_get_brand_profiles']);
        add_action('wp_ajax_cam_get_brand_profile_details', [$this, 'ajax_get_brand_profile_details']);
        add_action('wp_ajax_cam_add_brand_profile', [$this, 'ajax_add_brand_profile']);
        add_action('wp_ajax_cam_delete_brand_profile', [$this, 'ajax_delete_brand_profile']);
        add_action('wp_ajax_cam_update_brand_profile', [$this, 'ajax_update_brand_profile']);
    }

    public function render_page() {
        // 脚本和样式通过 wp_enqueue_script/style 在 class-admin-menu.php 中统一加载
        // 避免重复加载导致事件监听器绑定多次
        include CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/views/brand-profiles-management.php';
    }

    public function ajax_get_brand_profiles() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');
        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC", ARRAY_A);
        wp_send_json_success($results);
    }

    public function ajax_get_brand_profile_details() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => __('无效的ID。', 'yali-ai-writer')]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $id = intval($_POST['id']);

        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id), ARRAY_A);

        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => __('品牌资料不存在。', 'yali-ai-writer')]);
        }
    }

    public function ajax_add_brand_profile() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        // 统一验证逻辑
        $validation = $this->validate_brand_profile_data($_POST);
        if (!$validation['valid']) {
            wp_send_json_error(['message' => $validation['message']]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';

        $data = $this->prepare_brand_profile_data($_POST);

        // 使用标题生成向量
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }
        $vector_handler = new ContentAuto_VectorApiHandler();
        $vector_result = $vector_handler->generate_embeddings_batch([$data['title']]);

        if ($vector_result && !empty($vector_result['embeddings'])) {
            $data['vector'] = $vector_result['embeddings'][0]['embedding'];
        } else {
            $data['vector'] = null;
            error_log('ContentAuto: Vector generation failed for brand profile: ' . $vector_handler->get_last_error());
        }

        $result = $wpdb->insert($table_name, $data);

        if ($result) {
            wp_send_json_success(['id' => $wpdb->insert_id, 'message' => __('品牌资料已添加。', 'yali-ai-writer')]);
        } else {
            wp_send_json_error(['message' => __('数据库插入失败。', 'yali-ai-writer')]);
        }
    }

    public function ajax_update_brand_profile() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => __('无效的ID。', 'yali-ai-writer')]);
            return;
        }

        // 统一验证逻辑
        $validation = $this->validate_brand_profile_data($_POST);
        if (!$validation['valid']) {
            wp_send_json_error(['message' => $validation['message']]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $id = intval($_POST['id']);

        $data = $this->prepare_brand_profile_data($_POST);

        // 使用标题生成向量
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }
        $vector_handler = new ContentAuto_VectorApiHandler();
        $vector_result = $vector_handler->generate_embeddings_batch([$data['title']]);

        if ($vector_result && !empty($vector_result['embeddings'])) {
            $data['vector'] = $vector_result['embeddings'][0]['embedding'];
        } else {
            $data['vector'] = null;
            error_log('ContentAuto: Vector generation failed for brand profile: ' . $vector_handler->get_last_error());
        }

        $result = $wpdb->update($table_name, $data, ['id' => $id]);

        if ($result !== false) {
            wp_send_json_success(['id' => $id, 'message' => __('品牌资料已更新。', 'yali-ai-writer')]);
        } else {
            wp_send_json_error(['message' => __('数据库更新失败。', 'yali-ai-writer')]);
        }
    }

    public function ajax_delete_brand_profile() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => __('无效的ID。', 'yali-ai-writer')]);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $id = intval($_POST['id']);

        $result = $wpdb->delete($table_name, ['id' => $id]);

        if ($result) {
            wp_send_json_success(['message' => __('品牌资料已删除。', 'yali-ai-writer')]);
        } else {
            wp_send_json_error(['message' => __('数据库删除失败。', 'yali-ai-writer')]);
        }
    }

    /**
     * 统一的品牌资料数据验证逻辑
     */
    private function validate_brand_profile_data($data) {
        // 标题始终必填（用于生成向量）
        if (!isset($data['title']) || empty(trim($data['title']))) {
            return ['valid' => false, 'message' => __('标题是必填项（用于生成向量匹配文章）。', 'yali-ai-writer')];
        }

        $type = isset($data['type']) ? sanitize_text_field($data['type']) : 'standard';

        // 根据类型进行不同验证
        if ($type === 'custom_html') {
            // 自定义HTML类型：只需要标题和HTML代码
            if (!isset($data['custom_html']) || empty(trim($data['custom_html']))) {
                return ['valid' => false, 'message' => __('自定义HTML代码是必填项。', 'yali-ai-writer')];
            }
        } elseif ($type === 'reference') {
            // 参考资料类型：需要标题和描述
            if (!isset($data['reference_description']) || empty(trim($data['reference_description']))) {
                return ['valid' => false, 'message' => __('参考资料描述是必填项。', 'yali-ai-writer')];
            }
        } else {
            // 标准类型：需要标题和图片URL
            if (!isset($data['image_url']) || empty(trim($data['image_url']))) {
                return ['valid' => false, 'message' => __('图片URL是必填项。', 'yali-ai-writer')];
            }
        }

        return ['valid' => true];
    }

    /**
     * 统一的品牌资料数据准备逻辑
     */
    private function prepare_brand_profile_data($data) {
        $type = isset($data['type']) ? sanitize_text_field($data['type']) : 'standard';
        
        $prepared_data = [
            'title' => sanitize_text_field($data['title']),
            'type' => $type,
        ];

        if ($type === 'custom_html') {
            // 自定义HTML类型
            $prepared_data['custom_html'] = wp_kses_post($data['custom_html']);
            $prepared_data['image_url'] = null;
            $prepared_data['description'] = '';
            $prepared_data['link'] = '';
        } elseif ($type === 'reference') {
            // 参考资料类型
            $prepared_data['description'] = sanitize_textarea_field($data['reference_description']);
            $prepared_data['image_url'] = null;
            $prepared_data['link'] = '';
            $prepared_data['custom_html'] = null;
        } else {
            // 标准类型
            $prepared_data['image_url'] = esc_url_raw($data['image_url']);
            $prepared_data['description'] = isset($data['description']) ? sanitize_textarea_field($data['description']) : '';
            $prepared_data['link'] = isset($data['link']) ? esc_url_raw($data['link']) : '';
            $prepared_data['custom_html'] = null;
        }

        return $prepared_data;
    }
}
