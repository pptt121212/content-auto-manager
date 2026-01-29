<?php
/**
 * Admin page for generating browser extension API key
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ExtensionApiKey_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'), 100);
        add_action('admin_init', array($this, 'handle_generate_key'));
        add_action('wp_ajax_cam_check_verify_result', array($this, 'handle_check_verify_result'));
    }

    public function handle_check_verify_result() {
        check_ajax_referer('cam_check_verify_result', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        // 检查授权状态
        if (!class_exists('ContentAuto_License_Manager') || !ContentAuto_License_Manager::is_license_active()) {
            wp_send_json_error(array('status' => 'error', 'message' => 'Plugin not licensed'));
        }

        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        if (!$task_id) {
            wp_send_json_error('No Task ID');
        }

        $results = get_option('cam_extension_task_results', array());
        
        if (isset($results[$task_id])) {
            // Check if result is 'verified'
            $result_data = $results[$task_id];
            // If result is an array with status=verified, OR just the status string
            if ((is_array($result_data) && isset($result_data['status']) && $result_data['status'] === 'verified') || 
                ($result_data === 'verified')) { // Handle legacy/string responses if any
                 wp_send_json_success($results[$task_id]);
            } else {
                 // Might be a search result or error
                 wp_send_json_error(array('status' => 'invalid_response', 'data' => $result_data));
            }
        } else {
            // Check if it's still pending (optional, but good for debugging)
            $queue = get_option('cam_extension_task_queue', array());
            if (isset($queue[$task_id])) {
                 wp_send_json_error(array('status' => 'pending'));
            } else {
                 wp_send_json_error(array('status' => 'not_found'));
            }
        }
    }

    public function add_admin_menu() {
        add_submenu_page(
            'content-auto-manager',
            '浏览器扩展连接',
            '扩展连接',
            'manage_options',
            'cam-extension-api-key',
            array($this, 'render_page')
        );
    }

    public function handle_generate_key() {
        if (!isset($_POST['cam_generate_api_key_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['cam_generate_api_key_nonce'], 'cam_generate_api_key')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }
        
        // 检查授权状态
        if (!class_exists('ContentAuto_License_Manager') || !ContentAuto_License_Manager::is_license_active()) {
            add_settings_error('content_auto_manager_messages', 'license_error', '授权无效，无法生成 API Key。', 'error');
            return;
        }

        // Generate new key
        $new_key = wp_generate_password(32, false, false);
        update_option('cam_extension_api_key', $new_key);

        // Store temp display
        set_transient('cam_new_api_key_display', $new_key, 60);

        wp_redirect(admin_url('admin.php?page=cam-extension-api-key&generated=1'));
        exit;
    }

    public function render_page() {
        $current_key = get_option('cam_extension_api_key', '');
        $new_key = get_transient('cam_new_api_key_display');
        
        if ($new_key) {
            delete_transient('cam_new_api_key_display');
        }
        ?>
        <div class="wrap">
            <h1>浏览器扩展连接</h1>
            
            <?php 
            if (!class_exists('ContentAuto_License_Manager')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'includes/class-license-manager.php';
            }
            
            if (!ContentAuto_License_Manager::is_license_active()): 
            ?>
                <div class="notice notice-error" style="margin-top: 20px;">
                    <p><strong><?php _e('授权受限', 'content-auto-manager'); ?></strong></p>
                    <p><?php _e('您需要激活插件授权才能使用浏览器扩展连接功能。', 'content-auto-manager'); ?></p>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=content-auto-manager-publish-rules'); ?>" class="button button-primary">
                            <?php _e('前往激活授权', 'content-auto-manager'); ?>
                        </a>
                    </p>
                </div>
            <?php 
                echo '</div>'; // Close wrap
                return; // Stop rendering
            endif; 
            ?>
            
            
            <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
                <h2>API Key 配置</h2>
                <p>使用 API Key 让浏览器扩展安全地连接到您的 WordPress 网站，同步 LLM 和向量 API 配置。</p>

                <?php if ($new_key): ?>
                    <div class="notice notice-success" style="padding: 15px; margin: 15px 0;">
                        <p><strong>✅ 新 API Key 已生成！请立即复制保存：</strong></p>
                        <input type="text" value="<?php echo esc_attr($new_key); ?>" 
                               readonly 
                               style="width: 100%; font-family: monospace; font-size: 16px; padding: 10px;"
                               onclick="this.select();" />
                        <p style="color: #d63638; margin-top: 10px;">
                            <strong>⚠️ 这是唯一一次显示完整密钥的机会！刷新页面后将无法再查看。</strong>
                        </p>
                    </div>
                <?php elseif ($current_key): ?>
                    <div class="notice notice-info" style="padding: 15px; margin: 15px 0;">
                        <p><strong>当前 API Key（已隐藏）：</strong></p>
                        <code style="font-size: 14px;"><?php echo esc_html(substr($current_key, 0, 6) . '...' . substr($current_key, -4)); ?></code>
                        <p style="margin-top: 10px;">如果您忘记了密钥，请点击下方按钮生成新的。</p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-warning" style="padding: 15px; margin: 15px 0;">
                        <p><strong>尚未配置 API Key</strong></p>
                        <p>请点击下方按钮生成一个 API Key，然后在浏览器扩展中使用。</p>
                    </div>
                <?php endif; ?>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('cam_generate_api_key', 'cam_generate_api_key_nonce'); ?>
                    <button type="submit" class="button button-primary button-large">
                        <?php echo $current_key ? '🔄 重新生成 API Key' : '🔑 生成 API Key'; ?>
                    </button>
                </form>

                <hr style="margin: 30px 0;">

                <h3>连接状态检测</h3>
                <p>如果不确定浏览器扩展是否已成功连接，可以点击下方按钮发起测试。</p>
                <div style="background: #f0f6fc; padding: 15px; border-radius: 4px; border: 1px solid #cce5ff;">
                    <div style="margin-bottom: 10px;">
                        <button id="cam-verify-btn" class="button button-secondary">📡 发起连接验证请求</button>
                        <span id="cam-verify-status" style="margin-left: 10px; font-weight: bold;"></span>
                    </div>
                    <p class="description">点击后，如果浏览器扩展(Side Panel)是打开状态并已连接，将会弹出一个验证窗口。请在扩展中点击确认。</p>
                </div>

                <hr style="margin: 30px 0;">

                <h3>如何使用</h3>
                <ol>
                    <li>点击上方按钮生成 API Key</li>
                    <li>复制生成的密钥</li>
                    <li>打开浏览器扩展侧边栏</li>
                    <li>在 "Site URL" 填入：<code><?php echo esc_html(home_url()); ?></code></li>
                    <li>在 "API Key" 粘贴刚才复制的密钥</li>
                    <li>点击 "Connect & Sync"</li>
                </ol>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#cam-verify-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#cam-verify-status');
                
                $btn.prop('disabled', true).text('正在请求...');
                $status.text('⏳ 正在创建任务...').css('color', '#666');

                // 1. Create Task via REST API
                $.ajax({
                    url: '<?php echo esc_url(rest_url('content-auto-manager/v1/tasks/create')); ?>',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                        xhr.setRequestHeader('X-CAM-API-Key', '<?php echo esc_js(get_option('cam_extension_api_key', '')); ?>');
                    },
                    data: JSON.stringify({
                        type: 'connection_verify',
                        payload: { timestamp: Date.now() }
                    }),
                    contentType: 'application/json',
                    success: function(response) {
                        var taskId = response.task_id;
                        $status.text('⏳ 等待扩展响应... (请在浏览器右侧打开插件)').css('color', '#d63638');
                        $btn.text('验证中...');

                        // 2. Poll Status
                        var pollCount = 0;
                        var maxPolls = 30; // 30 * 2s = 60s timeout

                        var pollInterval = setInterval(function() {
                            pollCount++;
                            if (pollCount > maxPolls) {
                                clearInterval(pollInterval);
                                $status.text('❌ 验证超时：扩展没有在 60秒内响应。请确保扩展面板已打开。').css('color', 'red');
                                $btn.prop('disabled', false).text('📡 发起连接验证请求');
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'cam_check_verify_result',
                                task_id: taskId,
                                nonce: '<?php echo wp_create_nonce('cam_check_verify_result'); ?>'
                            }, function(res) {
                                if (res.success) {
                                    clearInterval(pollInterval);
                                    $status.text('✅ 验证成功！扩展通信正常。').css('color', 'green');
                                    $btn.prop('disabled', false).text('📡 发起连接验证请求');
                                } else {
                                    // if res.data.status === 'not_found', it might mean something wrong, or just not processed
                                }
                            });

                        }, 2000);
                    },
                    error: function(err) {
                        $status.text('Error: ' + (err.responseJSON ? err.responseJSON.message : err.statusText)).css('color', 'red');
                        $btn.prop('disabled', false).text('📡 发起连接验证请求');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize
new ContentAuto_ExtensionApiKey_Admin();

