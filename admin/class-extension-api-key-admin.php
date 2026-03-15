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
            wp_send_json_error(__('Permission denied', 'yali-ai-writer'));
        }
        
        // 检查授权状态
        if (!class_exists('ContentAuto_License_Manager') || !ContentAuto_License_Manager::is_license_active()) {
            wp_send_json_error(array('status' => 'error', 'message' => __('Plugin not licensed', 'yali-ai-writer')));
        }

        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        if (!$task_id) {
            wp_send_json_error(__('No Task ID', 'yali-ai-writer'));
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
            'yali-ai-writer',
            __('浏览器扩展连接', 'yali-ai-writer'),
            __('扩展连接', 'yali-ai-writer'),
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
            add_settings_error('content_auto_manager_messages', 'license_error', __('授权无效，无法生成 API Key。', 'yali-ai-writer'), 'error');
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
            <h1 class="wp-heading-inline" style="display:none;"></h1>
            <hr class="wp-header-end">
            <h1><?php _e('浏览器扩展连接', 'yali-ai-writer'); ?></h1>
            
            <?php 
            if (!class_exists('ContentAuto_License_Manager')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'includes/class-license-manager.php';
            }
            
            if (!ContentAuto_License_Manager::is_license_active()): 
            ?>
                <div class="notice notice-error" style="margin-top: 20px;">
                    <p><strong><?php _e('授权受限', 'yali-ai-writer'); ?></strong></p>
                    <p><?php _e('您需要激活插件授权才能使用浏览器扩展连接功能。', 'yali-ai-writer'); ?></p>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=yali-ai-writer-publish-rules'); ?>" class="button button-primary">
                            <?php _e('前往激活授权', 'yali-ai-writer'); ?>
                        </a>
                    </p>
                </div>
            <?php 
                echo '</div>'; // Close wrap
                return; // Stop rendering
            endif; 
            ?>
            
            
            <div class="yali-card" style="max-width: 800px; margin-top: 20px;">
                <div class="yali-card-header">
                    <div class="yali-card-title"><?php _e('API Key 配置', 'yali-ai-writer'); ?></div>
                </div>
                <div class="yali-card-body">
                <p><?php _e('使用 API Key 让浏览器扩展安全地连接到您的 WordPress 网站，同步 LLM 和向量 API 配置。', 'yali-ai-writer'); ?></p>

                <?php if ($new_key): ?>
                    <div class="yali-notice yali-notice-success">
                        <p><strong><?php _e('✅ 新 API Key 已生成！请立即复制保存：', 'yali-ai-writer'); ?></strong></p>
                        <input type="text" value="<?php echo esc_attr($new_key); ?>" 
                               readonly 
                               class="yali-input"
                               style="font-family: monospace; font-size: 16px;"
                               onclick="this.select();" />
                        <p class="yali-desc" style="color: #d63638;">
                            <strong><?php _e('⚠️ 这是唯一一次显示完整密钥的机会！刷新页面后将无法再查看。', 'yali-ai-writer'); ?></strong>
                        </p>
                    </div>
                <?php elseif ($current_key): ?>
                    <div class="yali-notice yali-notice-info">
                        <p><strong><?php _e('当前 API Key（已隐藏）：', 'yali-ai-writer'); ?></strong></p>
                        <code style="font-size: 14px; background: rgba(0,0,0,0.05); padding: 2px 5px; border-radius: 4px;"><?php echo esc_html(substr($current_key, 0, 6) . '...' . substr($current_key, -4)); ?></code>
                        <p class="yali-desc"><?php _e('如果您忘记了密钥，请点击下方按钮生成新的。', 'yali-ai-writer'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="yali-notice yali-notice-warning">
                        <p><strong><?php _e('尚未配置 API Key', 'yali-ai-writer'); ?></strong></p>
                        <p><?php _e('请点击下方按钮生成一个 API Key，然后在浏览器扩展中使用。', 'yali-ai-writer'); ?></p>
                    </div>
                <?php endif; ?>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('cam_generate_api_key', 'cam_generate_api_key_nonce'); ?>
                    <button type="submit" class="yali-btn yali-btn-primary">
                        <?php echo $current_key ? __('🔄 重新生成 API Key', 'yali-ai-writer') : __('🔑 生成 API Key', 'yali-ai-writer'); ?>
                    </button>
                </form>

                <hr style="margin: 30px 0;">

                <h3 style="margin-top: 0;"><?php _e('连接状态检测', 'yali-ai-writer'); ?></h3>
                <p><?php _e('如果不确定浏览器扩展是否已成功连接，可以点击下方按钮发起测试。', 'yali-ai-writer'); ?></p>
                <div class="yali-panel">
                    <div class="yali-flex-row">
                        <button id="cam-verify-btn" class="yali-btn yali-btn-secondary"><?php _e('📡 发起连接验证请求', 'yali-ai-writer'); ?></button>
                        <span id="cam-verify-status" style="font-weight: bold;"></span>
                    </div>
                    <p class="description yali-desc"><?php _e('点击后，如果浏览器扩展(Side Panel)是打开状态并已连接，将会弹出一个验证窗口。请在扩展中点击确认。', 'yali-ai-writer'); ?></p>
                </div>

                <hr style="margin: 30px 0;">

                <h3><?php _e('如何使用', 'yali-ai-writer'); ?></h3>
                <ol>
                    <li><?php _e('点击上方按钮生成 API Key', 'yali-ai-writer'); ?></li>
                    <li><?php _e('复制生成的密钥', 'yali-ai-writer'); ?></li>
                    <li><?php _e('打开浏览器扩展侧边栏', 'yali-ai-writer'); ?></li>
                    <li><?php echo sprintf(__('在 "Site URL" 填入：<code>%s</code>', 'yali-ai-writer'), esc_html(home_url())); ?></li>
                    <li><?php _e('在 "API Key" 粘贴刚才复制的密钥', 'yali-ai-writer'); ?></li>
                    <li><?php _e('点击 "Connect & Sync"', 'yali-ai-writer'); ?></li>
                </ol>
            </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#cam-verify-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#cam-verify-status');
                $btn.prop('disabled', true).css('opacity', '0.7');
                $status.text('<?php _e('⏳ 正在创建任务...', 'yali-ai-writer'); ?>').css('color', '#666');

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
                        $status.text('<?php _e('⏳ 等待扩展响应... (请在浏览器右侧打开插件)', 'yali-ai-writer'); ?>').css('color', '#d63638');

                        // 2. Poll Status
                        var pollCount = 0;
                        var maxPolls = 30; // 30 * 2s = 60s timeout

                        var pollInterval = setInterval(function() {
                            pollCount++;
                            if (pollCount > maxPolls) {
                                clearInterval(pollInterval);
                                $status.text('<?php _e('❌ 验证超时：扩展没有在 60秒内响应。请确保扩展面板已打开。', 'yali-ai-writer'); ?>').css('color', 'red');
                                $btn.prop('disabled', false).css('opacity', '');
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'cam_check_verify_result',
                                task_id: taskId,
                                nonce: '<?php echo wp_create_nonce('cam_check_verify_result'); ?>'
                            }, function(res) {
                                if (res.success) {
                                    clearInterval(pollInterval);
                                    $status.text('<?php _e('✅ 验证成功！扩展通信正常。', 'yali-ai-writer'); ?>').css('color', 'green');
                                    $btn.prop('disabled', false).css('opacity', '');
                                } else {
                                    // if res.data.status === 'not_found', it might mean something wrong, or just not processed
                                }
                            });

                        }, 2000);
                    },
                    error: function(err) {
                        $status.text('<?php _e('错误：', 'yali-ai-writer'); ?>' + (err.responseJSON ? err.responseJSON.message : err.statusText)).css('color', 'red');
                        $btn.prop('disabled', false).css('opacity', '');
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

