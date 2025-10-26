<?php
/**
 * 调试工具AJAX处理器
 */

if (!defined('ABSPATH')) {
    exit;
}

// 注册AJAX动作
add_action('wp_ajax_content_auto_toggle_debug_mode', 'content_auto_handle_toggle_debug_mode');
add_action('wp_ajax_content_auto_get_debug_logs', 'content_auto_handle_get_debug_logs');
add_action('wp_ajax_content_auto_clear_debug_logs', 'content_auto_handle_clear_debug_logs');

/**
 * 处理调试模式开关
 */
function content_auto_handle_toggle_debug_mode() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
        return;
    }
    
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'debug_mode_toggle')) {
        wp_send_json_error('安全验证失败');
        return;
    }
    
    $mode = sanitize_text_field($_POST['mode']);
    
    if ($mode === 'enable') {
        update_option('content_auto_debug_mode', true);
        wp_send_json_success('调试模式已启用');
    } elseif ($mode === 'disable') {
        update_option('content_auto_debug_mode', false);
        wp_send_json_success('调试模式已关闭');
    } else {
        wp_send_json_error('无效的操作模式');
    }
}

/**
 * 获取调试日志
 */
function content_auto_handle_get_debug_logs() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
        return;
    }
    
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'debug_logs_view')) {
        wp_send_json_error('安全验证失败');
        return;
    }
    
    try {
        // 使用日志系统获取最新日志
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        $logger = new ContentAuto_PluginLogger();
        
        $recent_logs = $logger->get_recent_logs(50); // 获取最近50条日志
        
        if (empty($recent_logs)) {
            wp_send_json_success(['logs' => '暂无调试日志内容']);
            return;
        }
        
        // 格式化日志输出
        $formatted_logs = '';
        foreach ($recent_logs as $log) {
            $formatted_logs .= sprintf(
                "[%s] [%s] %s\n",
                $log['log_time'],
                $log['log_level'],
                $log['log_message']
            );
            
            // 如果有上下文信息且包含提示词，只显示部分内容
            if (!empty($log['log_context'])) {
                if (is_array($log['log_context'])) {
                    if (isset($log['log_context']['prompt_content'])) {
                        // 截取提示词内容的前1000字符
                        $prompt_preview = mb_substr($log['log_context']['prompt_content'], 0, 1000, 'UTF-8');
                        $formatted_logs .= "提示词内容预览: " . $prompt_preview . "...\n";
                    } else {
                        $formatted_logs .= "上下文: " . json_encode($log['log_context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                    }
                }
            }
            $formatted_logs .= "\n";
        }
        
        wp_send_json_success(['logs' => $formatted_logs]);
        
    } catch (Exception $e) {
        wp_send_json_error('获取日志失败: ' . $e->getMessage());
    }
}

/**
 * 清空调试日志
 */
function content_auto_handle_clear_debug_logs() {
    // 验证权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error('权限不足');
        return;
    }
    
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'debug_logs_clear')) {
        wp_send_json_error('安全验证失败');
        return;
    }
    
    try {
        // 使用日志系统清空日志
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        $logger = new ContentAuto_PluginLogger();
        
        $result = $logger->clear_log();
        
        if ($result) {
            wp_send_json_success('日志已清空');
        } else {
            wp_send_json_error('清空日志失败，可能没有日志文件');
        }
        
    } catch (Exception $e) {
        wp_send_json_error('清空日志失败: ' . $e->getMessage());
    }
}
?>