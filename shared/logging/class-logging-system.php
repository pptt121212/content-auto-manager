<?php
/**
 * Logging System 适配器类
 * 包装 ContentAuto_PluginLogger 并提供所有需要的额外方法
 * 确保向后兼容性
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入底层的 PluginLogger
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';

class ContentAuto_LoggingSystem {
    
    private $plugin_logger;
    
    public function __construct() {
        $this->plugin_logger = new ContentAuto_PluginLogger();
    }
    
    /**
     * 构建上下文信息
     * 与其他类中的 build_context 方法保持一致
     */
    public function build_context($rule_id = null, $rule_item_index = null, $additional_info = array()) {
        $context = array();
        
        if ($rule_id !== null) {
            $context['规则ID'] = $rule_id;
        }
        
        if ($rule_item_index !== null) {
            $context['规则项目索引'] = $rule_item_index;
        }
        
        return array_merge($context, $additional_info);
    }
    
    /**
     * 记录成功日志
     */
    public function log_success($code, $message, $context = array()) {
        $this->plugin_logger->info("[SUCCESS] {$code}: {$message}", $context);
    }
    
    /**
     * 记录错误日志
     */
    public function log_error($code, $message, $context = array(), $suggestions = array(), $performance_data = array()) {
        $log_context = $context;
        if (!empty($suggestions)) {
            $log_context['建议'] = $suggestions;
        }
        if (!empty($performance_data)) {
            $log_context['性能数据'] = $performance_data;
        }
        $this->plugin_logger->error("[ERROR] {$code}: {$message}", $log_context);
    }
    
    /**
     * 记录警告日志
     */
    public function log_warning($code, $message, $context = array()) {
        $this->plugin_logger->warning("[WARNING] {$code}: {$message}", $context);
    }
    
    /**
     * 记录信息日志
     */
    public function log_info($code, $message, $context = array()) {
        $this->plugin_logger->info("[INFO] {$code}: {$message}", $context);
    }
    
    /**
     * 记录调试日志
     */
    public function log_debug($code, $message, $context = array()) {
        $this->plugin_logger->debug("[DEBUG] {$code}: {$message}", $context);
    }
    
    /**
     * 转发调用到 plugin logger 的其他方法
     */
    public function __call($method, $args) {
        if (method_exists($this->plugin_logger, $method)) {
            return call_user_func_array(array($this->plugin_logger, $method), $args);
        }
        throw new Exception("Method {$method} not found in ContentAuto_LoggingSystem");
    }
}
?>