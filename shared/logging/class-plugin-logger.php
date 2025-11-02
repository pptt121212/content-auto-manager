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
        $this->logs_dir = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'logs';
        $this->current_log_file = $this->logs_dir . '/' . date('Y-m-d') . '.log';
    }
    
    /**
     * 记录日志信息到文件
     * 
     * @param string $message 日志消息
     * @param string $level 日志级别 (DEBUG, INFO, WARNING, ERROR)
     * @param array $context 上下文信息
     */
    public function log($message, $level = 'INFO', $context = array()) {
        // 确保日志目录存在
        if (!file_exists($this->logs_dir)) {
            wp_mkdir_p($this->logs_dir);
        }
        
        // 构建日志条目
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] [{$level}] {$message}";
        
        // 添加上下文信息
        if (!empty($context)) {
            $context_str = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $log_entry .= "\nContext: " . $context_str;
        }
        
        $log_entry .= "\n";
        
        // 写入日志文件
        file_put_contents($this->current_log_file, $log_entry, FILE_APPEND | LOCK_EX);
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
