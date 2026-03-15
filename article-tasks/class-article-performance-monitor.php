<?php
/**
 * 文章任务性能监控类
 * 负责收集和记录文章任务执行过程中的性能指标和统计数据
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ArticlePerformanceMonitor {
    
    private $logger;
    private $start_times;
    private $performance_data;
    private $error_stats;
    
    public function __construct() {
        $this->logger = new ContentAuto_LoggingSystem();
        $this->start_times = array();
        $this->performance_data = array();
        $this->error_stats = array();
    }
    
    /**
     * 开始性能计时
     * 
     * @param string $operation_name 操作名称
     * @param array $context 上下文信息
     */
    public function start_timing($operation_name, $context = array()) {
        $this->start_times[$operation_name] = array(
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'context' => $context
        );
        
        // 仅在调试模式下记录性能开始日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->logger->log_debug('PERF_START', "开始性能监控: {$operation_name}", $context);
        }
    }
    
    /**
     * 结束性能计时并记录
     * 
     * @param string $operation_name 操作名称
     * @param bool $success 操作是否成功
     * @param array $additional_data 额外数据
     */
    public function end_timing($operation_name, $success = true, $additional_data = array()) {
        if (!isset($this->start_times[$operation_name])) {
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->logger->log_warning('PERF_WARNING', "未找到操作的开始时间: {$operation_name}");
            }
            return;
        }
        
        $start_data = $this->start_times[$operation_name];
        $end_time = microtime(true);
        $memory_end = memory_get_usage(true);
        
        $performance_metrics = array(
            'operation' => $operation_name,
            'duration_ms' => round(($end_time - $start_data['start_time']) * 1000, 2),
            'memory_used_mb' => round(($memory_end - $start_data['memory_start']) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'success' => $success,
            'timestamp' => current_time('mysql'),
            'context' => $start_data['context']
        );
        
        // 合并额外数据
        $performance_metrics = array_merge($performance_metrics, $additional_data);
        
        // 存储性能数据
        $this->performance_data[] = $performance_metrics;
        
        // 仅在调试模式下记录性能完成日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $log_message = sprintf(
                "性能监控完成: %s - 耗时: %sms, 内存使用: %sMB, 状态: %s",
                $operation_name,
                $performance_metrics['duration_ms'],
                $performance_metrics['memory_used_mb'],
                $success ? '成功' : '失败'
            );
            
            $this->logger->log_info('PERF_END', $log_message, $performance_metrics);
        }
        
        // 清理开始时间记录
        unset($this->start_times[$operation_name]);
        
        // 检查性能阈值并发出警告
        $this->check_performance_thresholds($performance_metrics);
    }
    
    /**
     * 记录错误统计
     * 
     * @param string $error_type 错误类型
     * @param string $error_code 错误代码
     * @param array $context 上下文信息
     */
    public function record_error($error_type, $error_code, $context = array()) {
        $error_key = $error_type . '::' . $error_code;
        
        if (!isset($this->error_stats[$error_key])) {
            $this->error_stats[$error_key] = array(
                'error_type' => $error_type,
                'error_code' => $error_code,
                'count' => 0,
                'first_occurrence' => current_time('mysql'),
                'last_occurrence' => current_time('mysql'),
                'contexts' => array()
            );
        }
        
        $this->error_stats[$error_key]['count']++;
        $this->error_stats[$error_key]['last_occurrence'] = current_time('mysql');
        $this->error_stats[$error_key]['contexts'][] = array(
            'timestamp' => current_time('mysql'),
            'context' => $context
        );
        
        // 只保留最近10次的上下文信息
        if (count($this->error_stats[$error_key]['contexts']) > 10) {
            $this->error_stats[$error_key]['contexts'] = array_slice(
                $this->error_stats[$error_key]['contexts'], -10
            );
        }
        
        // 仅在调试模式下记录错误统计日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->logger->log_info('ERROR_STATS', "错误统计更新: {$error_key}, 总计: {$this->error_stats[$error_key]['count']} 次", $context);
        }
    }
    
    /**
     * 记录API请求统计
     * 
     * @param string $api_name API名称
     * @param float $response_time 响应时间(秒)
     * @param bool $success 是否成功
     * @param array $context 上下文信息
     */
    public function record_api_stats($api_name, $response_time, $success, $context = array()) {
        $api_stats = array(
            'api_name' => $api_name,
            'response_time_ms' => round($response_time * 1000, 2),
            'success' => $success,
            'timestamp' => current_time('mysql'),
            'context' => $context
        );
        
        $this->performance_data[] = $api_stats;
        
        // 仅在调试模式下记录API统计日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $status = $success ? '成功' : '失败';
            $this->logger->log_info('API_STATS', 
                "API统计: {$api_name} - 响应时间: {$api_stats['response_time_ms']}ms, 状态: {$status}", 
                $api_stats
            );
        }
        
        // 检查API响应时间阈值
        if ($api_stats['response_time_ms'] > 30000) { // 30秒
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->logger->log_warning('API_SLOW_RESPONSE', 
                    "API响应时间过长: {$api_name} - {$api_stats['response_time_ms']}ms", 
                    $api_stats
                );
            }
        }
    }
    
    /**
     * 获取性能统计摘要
     * 
     * @return array 性能统计数据
     */
    public function get_performance_summary() {
        $summary = array(
            'total_operations' => count($this->performance_data),
            'successful_operations' => 0,
            'failed_operations' => 0,
            'average_duration_ms' => 0,
            'total_memory_mb' => 0,
            'error_summary' => array(),
            'slowest_operations' => array(),
            'api_performance' => array()
        );
        
        if (empty($this->performance_data)) {
            return $summary;
        }
        
        $total_duration = 0;
        $api_stats = array();
        
        foreach ($this->performance_data as $data) {
            if (isset($data['success'])) {
                if ($data['success']) {
                    $summary['successful_operations']++;
                } else {
                    $summary['failed_operations']++;
                }
            }
            
            if (isset($data['duration_ms'])) {
                $total_duration += $data['duration_ms'];
            }
            
            if (isset($data['memory_used_mb'])) {
                $summary['total_memory_mb'] += $data['memory_used_mb'];
            }
            
            // 收集API统计
            if (isset($data['api_name'])) {
                $api_name = $data['api_name'];
                if (!isset($api_stats[$api_name])) {
                    $api_stats[$api_name] = array(
                        'total_requests' => 0,
                        'successful_requests' => 0,
                        'total_response_time' => 0,
                        'max_response_time' => 0
                    );
                }
                
                $api_stats[$api_name]['total_requests']++;
                if ($data['success']) {
                    $api_stats[$api_name]['successful_requests']++;
                }
                $api_stats[$api_name]['total_response_time'] += $data['response_time_ms'];
                $api_stats[$api_name]['max_response_time'] = max(
                    $api_stats[$api_name]['max_response_time'], 
                    $data['response_time_ms']
                );
            }
        }
        
        // 计算平均值
        if ($summary['total_operations'] > 0) {
            $summary['average_duration_ms'] = round($total_duration / $summary['total_operations'], 2);
        }
        
        // 处理API性能统计
        foreach ($api_stats as $api_name => $stats) {
            $summary['api_performance'][$api_name] = array(
                'total_requests' => $stats['total_requests'],
                'success_rate' => round(($stats['successful_requests'] / $stats['total_requests']) * 100, 2),
                'average_response_time_ms' => round($stats['total_response_time'] / $stats['total_requests'], 2),
                'max_response_time_ms' => $stats['max_response_time']
            );
        }
        
        // 错误统计摘要
        foreach ($this->error_stats as $error_key => $error_data) {
            $summary['error_summary'][$error_key] = array(
                'count' => $error_data['count'],
                'first_occurrence' => $error_data['first_occurrence'],
                'last_occurrence' => $error_data['last_occurrence']
            );
        }
        
        // 找出最慢的操作
        $operations_with_duration = array_filter($this->performance_data, function($data) {
            return isset($data['duration_ms']) && isset($data['operation']);
        });
        
        usort($operations_with_duration, function($a, $b) {
            return $b['duration_ms'] <=> $a['duration_ms'];
        });
        
        $summary['slowest_operations'] = array_slice($operations_with_duration, 0, 5);
        
        return $summary;
    }
    
    /**
     * 检查性能阈值并发出警告
     * 
     * @param array $metrics 性能指标
     */
    private function check_performance_thresholds($metrics) {
        // 检查执行时间阈值
        if ($metrics['duration_ms'] > 60000) { // 60秒
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->logger->log_warning('PERF_SLOW_OPERATION', 
                    "操作执行时间过长: {$metrics['operation']} - {$metrics['duration_ms']}ms", 
                    $metrics
                );
            }
        }
        
        // 检查内存使用阈值
        if ($metrics['memory_used_mb'] > 100) { // 100MB
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->logger->log_warning('PERF_HIGH_MEMORY', 
                    "操作内存使用过高: {$metrics['operation']} - {$metrics['memory_used_mb']}MB", 
                    $metrics
                );
            }
        }
        
        // 检查峰值内存阈值
        if ($metrics['peak_memory_mb'] > 256) { // 256MB
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $this->logger->log_warning('PERF_HIGH_PEAK_MEMORY', 
                    "系统峰值内存使用过高: {$metrics['peak_memory_mb']}MB", 
                    $metrics
                );
            }
        }
    }
    
    /**
     * 清理性能数据
     * 
     * @param int $keep_hours 保留多少小时的数据
     */
    public function cleanup_performance_data($keep_hours = 24) {
        $cutoff_time = time() - ($keep_hours * 3600);
        
        $this->performance_data = array_filter($this->performance_data, function($data) use ($cutoff_time) {
            if (isset($data['timestamp'])) {
                return strtotime($data['timestamp']) > $cutoff_time;
            }
            return true;
        });
        
        // 清理错误统计中的旧上下文
        foreach ($this->error_stats as $error_key => &$error_data) {
            $error_data['contexts'] = array_filter($error_data['contexts'], function($context) use ($cutoff_time) {
                return strtotime($context['timestamp']) > $cutoff_time;
            });
        }
        
        $this->logger->log_info('PERF_CLEANUP', "性能数据清理完成，保留 {$keep_hours} 小时内的数据");
    }
    
    /**
     * 生成性能报告
     * 
     * @return string 性能报告
     */
    public function generate_performance_report() {
        $summary = $this->get_performance_summary();
        
        $report = "=== 文章任务性能报告 ===\n";
        $report .= "生成时间: " . current_time('mysql') . "\n\n";
        
        $report .= "总体统计:\n";
        $report .= "- 总操作数: {$summary['total_operations']}\n";
        $report .= "- 成功操作: {$summary['successful_operations']}\n";
        $report .= "- 失败操作: {$summary['failed_operations']}\n";
        $report .= "- 平均执行时间: {$summary['average_duration_ms']}ms\n";
        $report .= "- 总内存使用: {$summary['total_memory_mb']}MB\n\n";
        
        if (!empty($summary['api_performance'])) {
            $report .= "API性能统计:\n";
            foreach ($summary['api_performance'] as $api_name => $stats) {
                $report .= "- {$api_name}:\n";
                $report .= "  * 总请求数: {$stats['total_requests']}\n";
                $report .= "  * 成功率: {$stats['success_rate']}%\n";
                $report .= "  * 平均响应时间: {$stats['average_response_time_ms']}ms\n";
                $report .= "  * 最大响应时间: {$stats['max_response_time_ms']}ms\n";
            }
            $report .= "\n";
        }
        
        if (!empty($summary['error_summary'])) {
            $report .= "错误统计:\n";
            foreach ($summary['error_summary'] as $error_key => $error_info) {
                $report .= "- {$error_key}: {$error_info['count']} 次\n";
                $report .= "  * 首次发生: {$error_info['first_occurrence']}\n";
                $report .= "  * 最后发生: {$error_info['last_occurrence']}\n";
            }
            $report .= "\n";
        }
        
        if (!empty($summary['slowest_operations'])) {
            $report .= "最慢操作 (前5名):\n";
            foreach ($summary['slowest_operations'] as $i => $op) {
                $report .= ($i + 1) . ". {$op['operation']}: {$op['duration_ms']}ms\n";
            }
        }
        
        return $report;
    }
}
?>