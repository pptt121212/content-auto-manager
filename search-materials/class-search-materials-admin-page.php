<?php
if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_SearchMaterialsAdminPage {
    
    public function __construct() {
        // 移交给 Yali_AI_Writer_AdminMenu 统一注册，以便控制顺序
        // add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('wp_ajax_yali_ai_writer_search_material_process', [$this, 'handle_ajax_process']);
        add_action('wp_ajax_yali_ai_writer_save_material_result', [$this, 'handle_ajax_save']);
        add_action('wp_ajax_yali_ai_writer_extension_material_process', [$this, 'handle_extension_ajax_process']);
        add_action('wp_ajax_yali_ai_writer_check_task_result', [$this, 'handle_check_task_result']);
    }

    public function register_menu() {
        add_submenu_page(
            'yali-ai-writer',
            '搜索物料',
            '搜索物料',
            'manage_options',
            'content-auto-search-materials',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap yali-plugin-wrapper">
            <h1 class="yali-page-title"><span class="dashicons dashicons-search"></span> <?php _e('搜索物料 (Search Materials)', 'yali-ai-writer'); ?></h1>
            <p><?php _e('输入主题ID，系统将自动搜索、筛选、抓取并汇总相关资料。', 'yali-ai-writer'); ?></p>
            
            <!-- 标签切换 -->
            <div class="yali-tabs">
                <a href="#" class="yali-tab-item active" data-tab="search-engine"><span class="dashicons dashicons-admin-site-alt3" style="line-height:1.3; margin-right:4px;"></span> <?php _e('网络搜索', 'yali-ai-writer'); ?></a>
                <a href="#" class="yali-tab-item" data-tab="extension-rag"><span class="dashicons dashicons-book-alt" style="line-height:1.3; margin-right:4px;"></span> <?php _e('知识库搜索', 'yali-ai-writer'); ?></a>
            </div>
            
            <!-- 搜索引擎标签内容 -->
            <div id="tab-search-engine" class="tab-content" style="display: block;">
                <div class="content-auto-section yali-card">
                    <h3 style="margin-top: 0;"><?php _e('网络搜索模式', 'yali-ai-writer'); ?></h3>
                    <p class="description yali-desc"><?php _e('通过搜索引擎搜索互联网内容，抓取网页并汇总为参考资料。', 'yali-ai-writer'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="topic_id"><?php _e('主题ID', 'yali-ai-writer'); ?></label></th>
                            <td>
                                <input name="topic_id" type="number" id="topic_id" value="" class="regular-text yali-input">
                                <p class="description yali-desc"><?php _e('请输入数据库中存在的主题ID。', 'yali-ai-writer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="btn_start_material" class="button button-primary button-large yali-btn yali-btn-primary"><?php _e('开始搜集与整理', 'yali-ai-writer'); ?></button>
                        <button type="button" id="btn_save_material" class="button button-secondary button-large yali-btn yali-btn-secondary" style="display:none; margin-left: 10px;"><?php _e('保存为主题参考资料', 'yali-ai-writer'); ?></button>
                        <span class="spinner" id="material_spinner" style="float:none;"></span>
                    </div>
                </div>

                <div id="material_result_area" style="margin-top:20px; display:none; max-width: 1000px;">
                    <div class="content-auto-section yali-card">
                        <h3><?php _e('处理日志 (实时)', 'yali-ai-writer'); ?></h3>
                        <div id="material_log" class="yali-textarea-code" style="height:300px; overflow-y:auto; margin-bottom:20px;"></div>
                        
                        <h3><?php _e('汇总结果', 'yali-ai-writer'); ?></h3>
                        <div id="material_summary" class="yali-panel" style="min-height: 200px;"></div>
                        <textarea id="hidden_summary_data" style="display:none;"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 知识库搜索标签内容 -->
            <div id="tab-extension-rag" class="tab-content" style="display: none;">
                <div class="content-auto-section yali-card">
                    <h3 style="margin-top: 0;"><?php _e('知识库搜索模式', 'yali-ai-writer'); ?></h3>
                    <p class="description yali-desc"><?php _e('向已连接的浏览器插件发送请求，在本地知识库中搜索并由 AI 生成参考资料。', 'yali-ai-writer'); ?></p>
                    
                    <div class="yali-notice yali-notice-warning">
                        <strong>⚠️ <?php _e('前提条件：', 'yali-ai-writer'); ?></strong>
                        <ul style="margin: 5px 0 0 20px;">
                            <li><?php _e('浏览器插件已安装并运行', 'yali-ai-writer'); ?></li>
                            <li><?php _e('插件已连接到此网站（在插件设置页面完成连接）', 'yali-ai-writer'); ?></li>
                            <li><?php _e('插件中已导入知识库文档', 'yali-ai-writer'); ?></li>
                        </ul>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ext_topic_id"><?php _e('主题ID', 'yali-ai-writer'); ?></label></th>
                            <td>
                                <input name="ext_topic_id" type="number" id="ext_topic_id" value="" class="regular-text yali-input">
                                <p class="description yali-desc"><?php _e('请输入数据库中存在的主题ID。', 'yali-ai-writer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="btn_start_extension" class="button button-primary button-large yali-btn yali-btn-primary"><?php _e('开始知识库搜索', 'yali-ai-writer'); ?></button>
                        <button type="button" id="btn_save_ext_material" class="button button-secondary button-large yali-btn yali-btn-secondary" style="display:none; margin-left: 10px;"><?php _e('保存为主题参考资料', 'yali-ai-writer'); ?></button>
                        <span class="spinner" id="ext_spinner" style="float:none;"></span>
                    </div>
                </div>

                <div id="ext_result_area" style="margin-top:20px; display:none; max-width: 1000px;">
                    <div class="content-auto-section yali-card">
                        <h3><?php _e('任务状态', 'yali-ai-writer'); ?></h3>
                        <div id="ext_status" class="yali-panel" style="margin-bottom:20px;">
                            <p style="margin: 0;"><?php _e('等待发起请求...', 'yali-ai-writer'); ?></p>
                        </div>
                        
                        <h3><?php _e('返回结果', 'yali-ai-writer'); ?></h3>
                        <div id="ext_summary" class="yali-textarea-code" style="min-height: 200px; background:#fff;"></div>
                        <textarea id="hidden_ext_summary_data" style="display:none;"></textarea>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_ajax_process() {
        check_ajax_referer('yali_ai_writer_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $topic_id = intval($_POST['topic_id']);
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'init';

        if (!$topic_id) {
            wp_send_json_error(['message' => __('无效的主题ID', 'yali-ai-writer')]);
        }
        
        if (!class_exists('Yali_AI_Writer_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new Yali_AI_Writer_SearchMaterialsService();
        $result = $service->process_step($step, $topic_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function handle_ajax_save() {
        check_ajax_referer('yali_ai_writer_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }

        $topic_id = intval($_POST['topic_id']);
        $summary = isset($_POST['summary']) ? wp_unslash($_POST['summary']) : '';

        if (!$topic_id || empty($summary)) {
            wp_send_json_error(['message' => __('参数无效', 'yali-ai-writer')]);
        }

        if (!class_exists('Yali_AI_Writer_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new Yali_AI_Writer_SearchMaterialsService();
        $result = $service->save_material_to_topic($topic_id, $summary);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => __('保存成功', 'yali-ai-writer')]);
        }
    }
    
    /**
     * 处理浏览器插件模式的请求
     */
    public function handle_extension_ajax_process() {
        check_ajax_referer('yali_ai_writer_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $topic_id = intval($_POST['topic_id']);
        $check_only = isset($_POST['check_only']) && $_POST['check_only'] === 'true';

        if (!$topic_id) {
            wp_send_json_error(['message' => __('无效的主题ID', 'yali-ai-writer')]);
        }
        
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        // 获取主题信息
        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$topics_table} WHERE id = %d",
            $topic_id
        ), ARRAY_A);
        
        if (!$topic) {
            wp_send_json_error(['message' => __('主题不存在', 'yali-ai-writer')]);
        }
        
        // 如果只是检查状态
        if ($check_only) {
            $status = $topic['material_search_status'] ?? 'none';
            $result = $topic['reference_material'] ?? '';
            
            wp_send_json_success([
                'status' => $status,
                'result' => $result,
                'error'  => $topic['material_search_error'] ?? ''
            ]);
            return;
        }
        
        // 分发任务到浏览器插件
        if (!defined('YALI_AI_WRITER_PLUGIN_DIR')) {
            wp_send_json_error(['message' => __('环境错误：PLUGIN_DIR未定义', 'yali-ai-writer')]);
        }
        
        // 加载必需的控制器类（包括父类）
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-base-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-task-controller.php';
        
        $query = $topic['title'];
        
        $payload = array(
            'topic_id' => $topic_id,
            'query' => $query,
            'action' => 'knowledge_search',
            'is_test' => isset($_POST['is_test']) && $_POST['is_test'] === 'true'
        );
        
        // 实例化 Task_Controller 并传递正确的 namespace 参数
        $task_controller = new \ContentAutoManager\RestApi\Controllers\Task_Controller('content-auto-manager/v1');
        $task_id = $task_controller->add_task('knowledge_search', $payload);
        
        // 仅在非测试模式下更新数据库状态
        if (!$payload['is_test']) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'waiting_for_extension'
            ], ['id' => $topic_id]);
        }
        
        wp_send_json_success([
            'message' => __('任务已分发', 'yali-ai-writer'),
            'task_id' => $task_id,
            'status' => 'waiting_for_extension'
        ]);
    }
    public function handle_check_task_result() {
        check_ajax_referer('yali_ai_writer_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        if (!$task_id) {
            wp_send_json_error(['message' => 'Task ID required']);
        }

        // Direct check from result storage
        $results = get_option('cam_extension_task_results', array());
        
        if (isset($results[$task_id])) {
             $res = $results[$task_id];
             $content = '';
             $error = '';
             
             if (is_array($res)) {
                 $content = $res['answer'] ?? '';
                 $error = $res['error'] ?? '';
             } else {
                 $content = $res;
             }

             if ($content === 'NO_CONTEXT_ANSWER' || strpos($content, 'NO_CONTEXT_ANSWER') !== false) {
                 wp_send_json_success(['status' => 'failed', 'error' => __('没有找到相关参考资料 (No Context Found)', 'yali-ai-writer')]);
             } elseif ($error) {
                 wp_send_json_success(['status' => 'failed', 'error' => $error]);
             } else {
                 wp_send_json_success(['status' => 'completed', 'result' => $content]);
             }
        } else {
             wp_send_json_success(['status' => 'waiting_for_extension']);
        }
    }
}
