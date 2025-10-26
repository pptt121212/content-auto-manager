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
        $view_html = file_get_contents(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/views/brand-profiles-management.php');
        $style_css = file_get_contents(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/assets/css/brand-profiles.css');
        $script_js = file_get_contents(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/assets/js/brand-profiles.js');

        $localized_data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('brand_profiles_nonce')
        ];

        ?>
        <div class="wrap">
            <style type="text/css">
                <?php echo $style_css; ?>
            </style>
            
            <?php echo $view_html; ?>

            <script type="text/javascript">
                const brandProfilesManager = <?php echo json_encode($localized_data); ?>;
            </script>
            <script type="text/javascript">
                <?php echo $script_js; ?>
            </script>
        </div>
        <?php
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
            wp_send_json_error(['message' => '无效的ID。']);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $id = intval($_POST['id']);

        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id), ARRAY_A);

        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => '品牌资料不存在。']);
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

        // Generate vector from title only for better comparison with article topics
        $text_to_vectorize = $data['title'];
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }
        $vector_handler = new ContentAuto_VectorApiHandler();
        $vector_result = $vector_handler->generate_embeddings_batch([$text_to_vectorize]);

        if ($vector_result && !empty($vector_result['embeddings'])) {
            $data['vector'] = $vector_result['embeddings'][0]['embedding'];
        } else {
            $data['vector'] = null;
            error_log('ContentAuto: Vector generation failed for brand profile: ' . $vector_handler->get_last_error());
        }

        $result = $wpdb->insert($table_name, $data);

        if ($result) {
            wp_send_json_success(['id' => $wpdb->insert_id, 'message' => '品牌资料已添加。']);
        } else {
            wp_send_json_error(['message' => '数据库插入失败。']);
        }
    }

    public function ajax_update_brand_profile() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => '无效的ID。']);
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

        // Generate vector from title only for better comparison with article topics
        $text_to_vectorize = $data['title'];
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }
        $vector_handler = new ContentAuto_VectorApiHandler();
        $vector_result = $vector_handler->generate_embeddings_batch([$text_to_vectorize]);

        if ($vector_result && !empty($vector_result['embeddings'])) {
            $data['vector'] = $vector_result['embeddings'][0]['embedding'];
        } else {
            $data['vector'] = null;
            error_log('ContentAuto: Vector generation failed for brand profile: ' . $vector_handler->get_last_error());
        }

        $result = $wpdb->update($table_name, $data, ['id' => $id]);

        if ($result !== false) {
            wp_send_json_success(['id' => $id, 'message' => '品牌资料已更新。']);
        } else {
            wp_send_json_error(['message' => '数据库更新失败。']);
        }
    }

    public function ajax_delete_brand_profile() {
        check_ajax_referer('brand_profiles_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => '无效的ID。']);
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_brand_profiles';
        $id = intval($_POST['id']);

        $result = $wpdb->delete($table_name, ['id' => $id]);

        if ($result) {
            wp_send_json_success(['message' => '品牌资料已删除。']);
        } else {
            wp_send_json_error(['message' => '数据库删除失败。']);
        }
    }

    /**
     * 统一的品牌资料数据验证逻辑
     */
    private function validate_brand_profile_data($data) {
        // 标题始终必填（用于生成向量）
        if (!isset($data['title']) || empty(trim($data['title']))) {
            return ['valid' => false, 'message' => '标题是必填项（用于生成向量匹配文章）。'];
        }

        $type = isset($data['type']) ? sanitize_text_field($data['type']) : 'standard';

        // 根据类型进行不同验证
        if ($type === 'custom_html') {
            // 自定义HTML类型：只需要标题和HTML代码
            if (!isset($data['custom_html']) || empty(trim($data['custom_html']))) {
                return ['valid' => false, 'message' => '自定义HTML代码是必填项。'];
            }
        } elseif ($type === 'reference') {
            // 参考资料类型：需要标题和描述
            if (!isset($data['reference_description']) || empty(trim($data['reference_description']))) {
                return ['valid' => false, 'message' => '参考资料描述是必填项。'];
            }
        } else {
            // 标准类型：需要标题和图片URL
            if (!isset($data['image_url']) || empty(trim($data['image_url']))) {
                return ['valid' => false, 'message' => '图片URL是必填项。'];
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
