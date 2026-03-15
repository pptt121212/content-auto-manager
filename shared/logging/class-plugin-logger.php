<?php
/**
 * 插件自定义日志类
 * 将日志写入插件根目录下的logs文件夹中
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PluginLogger {
    
    private $logs_dir;
    private $current_log_file;

    public function __construct() {
        // 使用 __DIR__ 计算插件根目录，避免依赖可能未定义的常量
        $plugin_dir = defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR') 
            ? CONTENT_AUTO_MANAGER_PLUGIN_DIR 
            : dirname(dirname(__DIR__)) . '/';
        $this->logs_dir = $plugin_dir . 'logs';
        $this->current_log_file = $this->logs_dir . '/' . date('Y-m-d') . '.log';
    }
    
    /**
     * 记录日志信息到文件
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别 (DEBUG, INFO, WARNING, ERROR)
     * @param array $context 上下文信息
     * @return bool 是否成功写入
     */
    public function log($message, $level = 'INFO', $context = array()) {
        // 确保日志目录存在
        if (!$this->ensure_logs_directory()) {
            return false;
        }
        
        // 构建日志条目
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] [{$level}] {$message}";
        
        // 添加上下文信息
        if (!empty($context)) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
                $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
            }
            $context_str = @json_encode($context, $flags);
            if ($context_str === false) {
                $context_str = "JSON Encode Error: " . json_last_error_msg() . "\n" . print_r($context, true);
            }
            $log_entry .= "\nContext: " . $context_str;
        }
        
        $log_entry .= "\n";
        
        // 尝试写入日志文件
        $result = @file_put_contents($this->current_log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // 如果写入失败，尝试修复权限后重试
        if ($result === false) {
            if ($this->fix_directory_permissions()) {
                $result = @file_put_contents($this->current_log_file, $log_entry, FILE_APPEND | LOCK_EX);
            }
        }
        
        return $result !== false;
    }
    
    /**
     * 确保日志目录存在且可写
     * 
     * @return bool
     */
    private function ensure_logs_directory() {
        // 如果目录已存在，检查是否可写
        if (file_exists($this->logs_dir)) {
            if (is_writable($this->logs_dir)) {
                return true;
            }
            
            // 尝试修复权限
            if ($this->fix_directory_permissions()) {
                return true;
            }
            
            // 如果修复失败，尝试删除并重新创建目录
            // 这只在目录为空时有效
            if ($this->recreate_directory()) {
                return true;
            }
            
            // 记录权限问题（使用 WordPress 错误日志）
            $this->log_permission_error();
            return false;
        }
        
        // 尝试创建目录
        if (wp_mkdir_p($this->logs_dir)) {
            // 设置权限为 0755（所有者可读写执行，其他人可读执行）
            @chmod($this->logs_dir, 0755);
            
            // 创建 .htaccess 保护文件
            $this->create_htaccess_protection();
            
            return is_writable($this->logs_dir);
        }
        
        return false;
    }
    
    /**
     * 修复目录权限
     * 
     * @return bool
     */
    private function fix_directory_permissions() {
        if (!file_exists($this->logs_dir)) {
            return false;
        }
        
        // 尝试设置更宽松的权限（0775），让同一用户组也能写入
        @chmod($this->logs_dir, 0775);
        
        // 如果仍然不可写，尝试使用 WordPress 文件系统 API
        if (!is_writable($this->logs_dir) && function_exists('WP_Filesystem')) {
            global $wp_filesystem;
            
            if (!is_a($wp_filesystem, 'WP_Filesystem_Base')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            
            if (is_a($wp_filesystem, 'WP_Filesystem_Base') && $wp_filesystem->is_writable($this->logs_dir)) {
                return true;
            }
        }
        
        return is_writable($this->logs_dir);
    }
    
    /**
     * 尝试删除并重新创建目录
     * 用于处理目录所有者不一致的情况
     * 
     * @return bool
     */
    private function recreate_directory() {
        if (!file_exists($this->logs_dir)) {
            return false;
        }
        
        // 检查目录是否为空（只包含 .htaccess 或为空）
        $files = scandir($this->logs_dir);
        $non_system_files = array_diff($files, array('.', '..', '.htaccess'));
        
        // 如果目录不为空（有日志文件），不要删除
        if (!empty($non_system_files)) {
            return false;
        }
        
        // 尝试删除目录
        // 先删除 .htaccess
        $htaccess_file = $this->logs_dir . '/.htaccess';
        if (file_exists($htaccess_file)) {
            @unlink($htaccess_file);
        }
        
        // 尝试删除目录
        if (!@rmdir($this->logs_dir)) {
            return false;
        }
        
        // 重新创建目录
        if (wp_mkdir_p($this->logs_dir)) {
            @chmod($this->logs_dir, 0755);
            $this->create_htaccess_protection();
            return is_writable($this->logs_dir);
        }
        
        return false;
    }
    
    /**
     * 记录权限错误到 WordPress 错误日志
     */
    private function log_permission_error() {
        $user_info = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : array('name' => 'unknown');
        $dir_owner = function_exists('posix_getpwuid') ? @posix_getpwuid(fileowner($this->logs_dir)) : array('name' => 'unknown');
        
        $error_message = sprintf(
            '[鸭梨AI写作插件] 日志目录权限错误：无法写入 %s。当前用户: %s，目录所有者: %s。请检查服务器权限配置或将目录权限设置为 777。',
            $this->logs_dir,
            $user_info['name'],
            isset($dir_owner['name']) ? $dir_owner['name'] : 'unknown'
        );
        
        // 使用 WordPress 错误日志
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($error_message);
        }
        
        // 存储到 transient，用于显示管理员通知
        set_transient('yali_ai_writer_permission_error', $error_message, HOUR_IN_SECONDS);
    }
    
    /**
     * 创建 .htaccess 保护文件
     */
    private function create_htaccess_protection() {
        $htaccess_file = $this->logs_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            @file_put_contents($htaccess_file, "Deny from all\n");
        }
    }
    
    /**
     * 记录调试信息
     */
    public function debug($message, $context = array()) {
        $this->log($message, 'DEBUG', $context);
    }
    
    /**
     * 记录普通信息
     */
    public function info($message, $context = array()) {
        $this->log($message, 'INFO', $context);
    }
    
    /**
     * 记录警告信息
     */
    public function warning($message, $context = array()) {
        $this->log($message, 'WARNING', $context);
    }
    
    /**
     * 记录错误信息
     */
    public function error($message, $context = array()) {
        $this->log($message, 'ERROR', $context);
    }
    
    /**
     * 记录成功信息
     * 兼容其他模块的 log_success 调用
     * 
     * @param string $code 操作代码
     * @param string $message 日志消息
     * @param array $context 上下文信息
     * @param array|null $performance_data 性能数据（可选）
     */
    public function log_success($code, $message, $context = array(), $performance_data = null) {
        $full_context = array_merge(
            array('code' => $code),
            is_array($context) ? $context : array()
        );
        
        if ($performance_data !== null) {
            $full_context['performance'] = $performance_data;
        }
        
        $this->log("[SUCCESS] {$code}: {$message}", 'INFO', $full_context);
    }
    
    /**
     * 清空日志 - 删除所有日志文件
     */
    public function clear_log() {
        $deleted_count = 0;

        if (file_exists($this->logs_dir)) {
            $files = glob($this->logs_dir . '/*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $deleted_count++;
                    }
                }
            }
        }

        return $deleted_count > 0;
    }
    
    /**
     * 获取最近的日志条目
     * 
     * @param int $limit 要获取的条目数
     * @return array 日志条目数组
     */
    public function get_recent_logs($limit = 100) {
        $logs = array();
        
        if (file_exists($this->logs_dir)) {
            // 获取所有日志文件，按日期排序
            $files = glob($this->logs_dir . '/*.log');
            rsort($files); // 最新的文件在前
            
            $count = 0;
            foreach ($files as $file) {
                if ($count >= $limit) {
                    break;
                }
                
                $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
                foreach ($lines as $line) {
                    if ($count >= $limit) {
                        break 2; // 跳出两个循环
                    }
                    
                    // 解析日志行
                    $log_entry = $this->parse_log_line($line, $file);
                    if ($log_entry) {
                        $logs[] = $log_entry;
                        $count++;
                    }
                }
            }
        }
        
        return $logs;
    }
    
    /**
     * 解析日志行
     * 
     * @param string $line 日志行
     * @param string $file 文件名
     * @return array|null 解析后的日志条目
     */
    private function parse_log_line($line, $file) {
        // 匹配日志格式: [timestamp] [level] message
        if (preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', $line, $matches)) {
            $timestamp = $matches[1];
            $level = $matches[2];
            $message = $matches[3];
            
            // 分离消息和上下文
            $context = '';
            $context_start = strpos($message, "\nContext: ");
            if ($context_start !== false) {
                $context = substr($message, $context_start + 10);
                $message = substr($message, 0, $context_start);
                
                // 尝试解析JSON上下文
                $context_data = json_decode($context, true);
                if (!$context_data) {
                    $context_data = $context; // 如果不是JSON，保持原样
                }
            } else {
                $context_data = '';
            }
            
            return array(
                'log_id' => 0, // 简化ID
                'log_time' => $timestamp,
                'log_level' => $level,
                'log_message' => trim($message),
                'log_context' => $context_data,
                'log_file' => basename($file)
            );
        }
        
        return null;
    }
}
