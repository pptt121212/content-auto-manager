<?php

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Api_Ajax_Handler {

    public function __construct() {
        add_action('wp_ajax_cam_save_api_settings', array($this, 'handle_save_settings'));
        add_action('wp_ajax_cam_delete_api_config', array($this, 'handle_delete_config'));
    }

    public function handle_delete_config() {
        if (!check_ajax_referer('cam_delete_api_config', 'nonce', false)) {
            wp_send_json_error(array('message' => __('安全验证失败，请刷新页面重试。', 'yali-ai-writer')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        }

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(array('message' => __('无效的配置ID。', 'yali-ai-writer')));
        }

        $api_config = new ContentAuto_ApiConfig();
        $result = $api_config->delete_config($id);

        if ($result) {
            $this->trigger_config_changed($id);
            wp_send_json_success(array('message' => __('API配置已删除。', 'yali-ai-writer')));
        } else {
            wp_send_json_error(array('message' => __('删除失败，请稍后重试。', 'yali-ai-writer')));
        }
    }

    public function handle_save_settings() {
        // 验证 nonce
        if (!check_ajax_referer('cam_save_api_settings', 'nonce', false)) {
            wp_send_json_error(array('message' => __('安全验证失败，请刷新页面重试。', 'yali-ai-writer')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        }

        $submission_type = isset($_POST['submission_type']) ? sanitize_text_field($_POST['submission_type']) : '';

        switch ($submission_type) {
            case 'reader':
                $this->save_reader_settings();
                break;
            case 'search':
                $this->save_search_settings();
                break;
            case 'custom':
                $this->save_custom_api_settings();
                break;
            case 'vector':
                $this->save_vector_api_settings();
                break;
            case 'predefined':
                $this->save_predefined_api_settings();
                break;
            default:
                wp_send_json_error(array('message' => __('未知的提交类型: ', 'yali-ai-writer') . $submission_type));
        }
    }

    private function save_reader_settings() {
        $jina_api_key = sanitize_text_field($_POST['jina_api_key']);
        update_option('content_auto_jina_api_key', $jina_api_key);

        // 保存搜索黑名单
        $blacklist_raw = isset($_POST['material_search_blacklist']) ? $_POST['material_search_blacklist'] : '';
        // 统一换行符
        $blacklist_raw = str_replace("\r\n", "\n", $blacklist_raw);
        $blacklist_raw = str_replace("\r", "\n", $blacklist_raw);
        $blacklist_arr = array_filter(array_map('trim', explode("\n", $blacklist_raw)));
        update_option('content_auto_material_search_blacklist', array_values($blacklist_arr));

        wp_send_json_success(array('message' => __('Jina Reader API 配置已保存。', 'yali-ai-writer')));
    }

    private function save_search_settings() {
        $search_settings = array(
            'region' => sanitize_text_field($_POST['search_region']),
            'max_results' => intval($_POST['search_max_results']),
            'safesearch' => sanitize_text_field($_POST['search_safesearch']),
            'time' => sanitize_text_field($_POST['search_time']),
            'backend' => sanitize_text_field($_POST['search_backend']),
        );

        update_option('content_auto_search_settings', $search_settings);
        wp_send_json_success(array('message' => __('搜索API配置已保存。', 'yali-ai-writer')));
    }

    private function save_custom_api_settings() {
        $data = array();
        
        // 必需字段
        $data['name'] = sanitize_text_field($_POST['name']);
        $data['api_url'] = esc_url_raw($_POST['api_url']);
        $data['api_key'] = sanitize_text_field($_POST['api_key']);
        $data['model_name'] = sanitize_text_field($_POST['model_name']);
        $data['api_type'] = sanitize_text_field($_POST['api_type'] ?? 'openai');
        
        // 可选字段
        if (isset($_POST['temperature'])) {
            $data['temperature'] = floatval($_POST['temperature']);
        }
        if (isset($_POST['max_tokens'])) {
            $data['max_tokens'] = intval($_POST['max_tokens']);
        }
        $data['temperature_enabled'] = !empty($_POST['temperature_enabled']) ? 1 : 0;
        $data['max_tokens_enabled'] = !empty($_POST['max_tokens_enabled']) ? 1 : 0;

        $data['stream_enabled'] = 1; 
        $data['stream'] = isset($_POST['stream']) && $_POST['stream'] === 'true';

        $data['top_p_enabled'] = !empty($_POST['top_p_enabled']) ? 1 : 0;
        if (isset($_POST['top_p'])) {
            $data['top_p'] = floatval($_POST['top_p']);
        }
        
        // 设为空的字段 (因为是 Custom API)
        $data['vector_api_url'] = '';
        $data['vector_api_key'] = '';
        $data['vector_model_name'] = '';
        
        $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

        $this->process_api_config_save($data);
    }

    private function save_vector_api_settings() {
        // 检查唯一性
        if (empty($_POST['id'])) {
            $api_config_check = new ContentAuto_ApiConfig();
            $existing_vector_config = $api_config_check->get_vector_config();
            if ($existing_vector_config) {
                wp_send_json_error(array('message' => __('系统中已存在向量API配置。每个系统只允许配置一个向量API。如需修改，请编辑现有配置。', 'yali-ai-writer')));
            }
        }

        $data = array();
        $data['name'] = sanitize_text_field($_POST['name']);
        $data['vector_api_url'] = esc_url_raw($_POST['vector_api_url'] ?? '');
        $data['vector_model_name'] = sanitize_text_field($_POST['vector_model_name'] ?? '');
        $data['vector_api_type'] = sanitize_text_field($_POST['vector_api_type'] ?? 'openai');
        
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            $existing = (new ContentAuto_ApiConfig())->get_config($_POST['id']);
            $data['vector_api_key'] = strlen(trim($_POST['vector_api_key'] ?? '')) ? sanitize_text_field($_POST['vector_api_key']) : ($existing['vector_api_key'] ?? '');
        } else {
            $data['vector_api_key'] = sanitize_text_field($_POST['vector_api_key'] ?? '');
        }

        // 设为空的字段 (因为是 Vector API)
        $data['api_url'] = '';
        $data['api_key'] = '';
        $data['model_name'] = '';
        $data['temperature'] = 0.70;
        $data['max_tokens'] = 2000;
        $data['temperature_enabled'] = 0;
        $data['max_tokens_enabled'] = 0;

        $this->process_api_config_save($data);
    }

    private function save_predefined_api_settings() {
        $predefined_api = new ContentAuto_PredefinedApi();
        
        $channel = isset($_POST['predefined_api_channel']) ? sanitize_text_field($_POST['predefined_api_channel']) : 'pollinations';
        $is_active = isset($_POST['predefined_api_active']) ? 1 : 0;
        
        $api_token = '';
        if (isset($_POST['predefined_api_token']) && !empty($_POST['predefined_api_token'])) {
            $api_token = sanitize_text_field($_POST['predefined_api_token']);
        }

        $selected_model = __('官方API', 'yali-ai-writer');
        if ($channel === 'pollinations') {
            $selected_model = isset($_POST['predefined_api_model']) ? sanitize_text_field($_POST['predefined_api_model']) : 'openai-large';
        }
        
        $config = $predefined_api->get_config($channel);
        
        $is_edit_mode = false;
        if (isset($_POST['editing_predefined_channel'])) {
            $is_edit_mode = $_POST['editing_predefined_channel'] === $channel;
        }

        if ($config && !$is_edit_mode) {
             wp_send_json_error(array('message' => __('已添加相同渠道，保存失败。', 'yali-ai-writer')));
        } elseif ($config && $is_edit_mode) {
            // 编辑模式
             $api_config = new ContentAuto_ApiConfig();
            
            $update_data = array(
                'name' => $config['name'],
                'api_url' => $config['api_url'],
                'model_name' => $selected_model,
                'is_active' => $is_active
            );
            
            if (!empty($api_token)) {
                $update_data['api_key'] = $api_token;
            } else {
                $update_data['api_key'] = '';
            }
            
            $result = $api_config->update_config($config['id'], $update_data, true);
            
            if ($result !== false) {
                 $this->trigger_config_changed($config['id']);
                 wp_send_json_success(array('message' => __('预置API配置已更新。', 'yali-ai-writer')));
            } else {
                 wp_send_json_error(array('message' => __('更新预置API配置失败。', 'yali-ai-writer')));
            }

        } elseif (!$config && !$is_edit_mode) {
            // 新建模式
            $new_config = $predefined_api->create_config_record($channel, $is_active);
            
            if ($new_config) {
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
                
                $this->trigger_config_changed($new_config['id']);
                
                // Get the complete config to generate HTML
                $api_config_helper = new ContentAuto_ApiConfig(); // Helper to get full config if needed, or use $new_config
                $row_html = $this->get_config_row_html($new_config);

                wp_send_json_success(array(
                    'message' => __('预置API配置已添加到API列表。', 'yali-ai-writer'),
                    'row_html' => $row_html,
                    'is_create' => true
                ));
            } else {
                wp_send_json_error(array('message' => __('添加预置API配置失败。', 'yali-ai-writer')));
            }
        } else {
             wp_send_json_error(array('message' => __('保存预置API配置失败。', 'yali-ai-writer')));
        }
    }

    private function process_api_config_save($data) {
        $api_config = new ContentAuto_ApiConfig();
        
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            // Update
            $result = $api_config->update_config($_POST['id'], $data);
            if ($result !== false) {
                if (isset($data['is_active']) && $data['is_active'] == 1) {
                    $api_config->set_active_config($_POST['id']);
                }
                $this->trigger_config_changed($_POST['id']);
                wp_send_json_success(array('message' => __('配置已更新并保存到API列表。', 'yali-ai-writer')));
            } else {
                wp_send_json_error(array('message' => __('配置更新失败。', 'yali-ai-writer')));
            }
        } else {
            // Create
            $config_id = $api_config->create_config($data);
            if ($config_id) {
                if (isset($data['is_active']) && $data['is_active'] == 1) {
                    $api_config->set_active_config($config_id);
                }
                $this->trigger_config_changed($config_id);
                
                // Get the complete config to generate HTML
                $new_config = $api_config->get_config($config_id);
                $row_html = $this->get_config_row_html($new_config);
                
                wp_send_json_success(array(
                    'message' => __('配置已创建并保存到API列表。', 'yali-ai-writer'),
                    'row_html' => $row_html,
                    'is_create' => true
                ));
            } else {
                wp_send_json_error(array('message' => __('配置创建失败。', 'yali-ai-writer')));
            }
        }
    }

    private function get_config_row_html($config) {
        $tab = (!empty($config['predefined_channel']) ? 'predefined' : ((!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) ? 'vector' : 'custom'));
        $edit_url = admin_url('admin.php?page=yali-ai-writer-api&action=edit&id=' . $config['id'] . '&tab=' . $tab);
        $edit_url = wp_nonce_url($edit_url, 'content_auto_manager_edit_config', 'nonce');
        
        $type_badge = '';
        if (!empty($config['predefined_channel'])) {
            $type_badge = '<span class="yali-badge yali-badge-info">' . __('预置API', 'yali-ai-writer') . '</span>';
        } elseif (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) {
            $type_badge = '<span class="yali-badge yali-badge-warning">' . __('向量API', 'yali-ai-writer') . '</span>';
        } else {
            $type_badge = '<span class="yali-badge">' . __('自定义API', 'yali-ai-writer') . '</span>';
        }

        $status_badge = '';
        if (!empty($config['vector_api_url']) || !empty($config['vector_api_key']) || !empty($config['vector_model_name'])) {
            $status_badge = '<span class="yali-badge yali-badge-success" title="' . esc_attr(__('向量API配置全局生效，无需激活状态', 'yali-ai-writer')) . '">' . __('已配置', 'yali-ai-writer') . '</span>';
        } elseif ($config['is_active']) {
            $status_badge = '<span class="yali-badge yali-badge-success">' . __('已激活', 'yali-ai-writer') . '</span>';
        } else {
            $status_badge = '<span class="yali-badge yali-badge-neutral">' . __('未激活', 'yali-ai-writer') . '</span>';
        }

        $delete_nonce = wp_create_nonce('cam_delete_api_config');

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
        $api_badge_cls = isset($api_type_class[$api_type]) ? $api_type_class[$api_type] : 'yali-badge-neutral';
        $api_badge_text = isset($api_type_labels[$api_type]) ? $api_type_labels[$api_type] : strtoupper($api_type);
        
        $api_type_badge = '<span class="yali-badge ' . esc_attr($api_badge_cls) . '">' . esc_html($api_badge_text) . '</span>';
        if (!empty($config['predefined_channel']) || !empty($config['vector_api_url'])) {
            $api_type_badge = '<span class="yali-badge yali-badge-neutral">-</span>';
        }

        ob_start();
        ?>
        <tr>
            <td><strong><?php echo esc_html(__($config['name'], 'yali-ai-writer')); ?></strong></td>
            <td><code style="font-size: 12px; background: #f0f0f1; padding: 2px 5px; border-radius: 3px;"><?php echo esc_html(content_auto_manager_truncate_string($config['api_url'], 30)); ?></code></td>
            <td><?php echo esc_html(__($config['model_name'], 'yali-ai-writer')); ?></td>
            <td class="yali-text-center"><?php echo $api_type_badge; ?></td>
            <td class="yali-text-center"><?php echo $type_badge; ?></td>
            <td class="yali-text-center"><?php echo $status_badge; ?></td>
            <td class="yali-text-center">
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
                            data-yali-nonce="<?php echo esc_attr($delete_nonce); ?>"
                            data-yali-confirm="<?php echo esc_attr(__('确定要删除此API配置吗？此操作不可撤销。', 'yali-ai-writer')); ?>">
                        <?php _e('删除', 'yali-ai-writer'); ?>
                    </button>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    private function trigger_config_changed($config_id) {
        if (class_exists('ContentAuto_LayeredCacheManager')) {
            ContentAuto_LayeredCacheManager::on_config_changed('api_config_modified', $config_id);
        } else {
            $layered_cache_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'key/layered-cache-manager.php';
            if (file_exists($layered_cache_file)) {
                include_once $layered_cache_file;
                if (class_exists('ContentAuto_LayeredCacheManager')) {
                    ContentAuto_LayeredCacheManager::on_config_changed('api_config_modified', $config_id);
                }
            }
        }
    }
}

new ContentAuto_Api_Ajax_Handler();
