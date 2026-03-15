<?php
/**
 * 智能文章结构优化系统 - 定时任务调度器
 * 
 * 负责管理智能结构优化功能的定时任务，包括：
 * - 每日文章分析任务
 * - 每周受欢迎度更新任务
 * - 缓存清理任务
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_StructureOptimizationScheduler {
    
    /**
     * 日志记录器
     */
    private $logger;
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * Cron 钩子名称
     */
    const CRON_HOOK_DAILY_ANALYSIS = 'content_auto_structure_daily_analysis';
    const CRON_HOOK_WEEKLY_POPULARITY = 'content_auto_structure_weekly_popularity';
    const CRON_HOOK_CACHE_CLEANUP = 'content_auto_structure_cache_cleanup';
    
    /**
     * 失败计数选项名称
     */
    const OPTION_FAILURE_COUNT = 'cam_structure_scheduler_failures';
    const OPTION_LAST_FAILURE_TIME = 'cam_structure_scheduler_last_failure';
    
    /**
     * 最大连续失败次数（发送通知前）
     */
    const MAX_CONSECUTIVE_FAILURES = 3;
    
    /**
     * 重试延迟（秒）- 1小时
     */
    const RETRY_DELAY = 3600;
    
    /**
     * 构造函数
     * 
     * @param ContentAuto_PluginLogger|null $logger 日志记录器
     */
    public function __construct($logger = null) {
        // 加载日志记录器
        if ($logger === null) {
            require_once dirname(__FILE__) . '/../logging/class-plugin-logger.php';
            $this->logger = new ContentAuto_PluginLogger();
        } else {
            $this->logger = $logger;
        }
        
        require_once dirname(__FILE__) . '/class-optimization-config.php';
        $this->config = new ContentAuto_OptimizationConfig();
    }
    
    /**
     * 注册所有定时任务
     * 在插件激活时调用
     */
    public function register_cron_events() {
        // 获取配置的分析时间（默认3:00 AM）
        $analysis_hour = $this->config->get_int('analysis_schedule_hour', 3);
        
        // 计算下一次运行时间
        $next_daily_run = $this->get_next_scheduled_time($analysis_hour);
        
        // 注册每日分析任务
        if (!wp_next_scheduled(self::CRON_HOOK_DAILY_ANALYSIS)) {
            wp_schedule_event($next_daily_run, 'daily', self::CRON_HOOK_DAILY_ANALYSIS);
            $this->log_info('CRON_REGISTERED', '每日分析任务已注册', array(
                'next_run' => date('Y-m-d H:i:s', $next_daily_run)
            ));
        }
        
        // 注册每周受欢迎度更新任务（每周一凌晨4点）
        $next_weekly_run = $this->get_next_weekly_time(1, 4); // 周一4点
        if (!wp_next_scheduled(self::CRON_HOOK_WEEKLY_POPULARITY)) {
            wp_schedule_event($next_weekly_run, 'weekly', self::CRON_HOOK_WEEKLY_POPULARITY);
            $this->log_info('CRON_REGISTERED', '每周受欢迎度更新任务已注册', array(
                'next_run' => date('Y-m-d H:i:s', $next_weekly_run)
            ));
        }
        
        // 注册缓存清理任务（每日凌晨5点）
        $next_cleanup_run = $this->get_next_scheduled_time(5);
        if (!wp_next_scheduled(self::CRON_HOOK_CACHE_CLEANUP)) {
            wp_schedule_event($next_cleanup_run, 'daily', self::CRON_HOOK_CACHE_CLEANUP);
            $this->log_info('CRON_REGISTERED', '缓存清理任务已注册', array(
                'next_run' => date('Y-m-d H:i:s', $next_cleanup_run)
            ));
        }
    }
    
    /**
     * 注销所有定时任务
     * 在插件停用时调用
     */
    public function unregister_cron_events() {
        // 清除每日分析任务
        $timestamp = wp_next_scheduled(self::CRON_HOOK_DAILY_ANALYSIS);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_DAILY_ANALYSIS);
            $this->log_info('CRON_UNREGISTERED', '每日分析任务已注销');
        }
        
        // 清除每周受欢迎度更新任务
        $timestamp = wp_next_scheduled(self::CRON_HOOK_WEEKLY_POPULARITY);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_WEEKLY_POPULARITY);
            $this->log_info('CRON_UNREGISTERED', '每周受欢迎度更新任务已注销');
        }
        
        // 清除缓存清理任务
        $timestamp = wp_next_scheduled(self::CRON_HOOK_CACHE_CLEANUP);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_CACHE_CLEANUP);
            $this->log_info('CRON_UNREGISTERED', '缓存清理任务已注销');
        }
        
        // 清除失败计数
        delete_option(self::OPTION_FAILURE_COUNT);
        delete_option(self::OPTION_LAST_FAILURE_TIME);
    }
    
    /**
     * 执行每日文章分析任务
     * 每次只处理一篇文章，避免超时
     * 
     * @return array 执行结果
     */
    public function run_daily_analysis() {
        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'angles_processed' => 0,
            'articles_extracted' => 0,
            'structures_created' => 0,
            'errors' => array(),
            'details' => array()
        );
        
        $this->log_info('DAILY_ANALYSIS_START', '开始执行文章分析任务（单篇模式）');
        
        try {
            // 检查功能是否启用
            if (!$this->config->is_optimization_enabled()) {
                $this->log_info('DAILY_ANALYSIS_SKIP', '智能优化功能未启用，跳过分析');
                $results['skipped'] = true;
                $results['reason'] = '功能未启用';
                return $results;
            }
            
            // 加载依赖类
            require_once dirname(__FILE__) . '/class-article-analyzer.php';
            require_once dirname(__FILE__) . '/class-structure-extractor.php';
            
            $analyzer = new ContentAuto_ArticleAnalyzer($this->logger);
            $extractor = new ContentAuto_StructureExtractor($this->logger);
            
            // 获取所有 content_angle
            $angles = $this->get_all_content_angles();
            
            if (empty($angles)) {
                $this->log_info('DAILY_ANALYSIS_SKIP', '没有找到任何内容角度');
                $results['reason'] = '没有内容角度';
                return $results;
            }
            
            // 查找第一篇待处理的高表现文章
            $article_to_process = null;
            $target_angle = null;
            
            foreach ($angles as $angle) {
                // 每个角度只获取1篇待处理文章
                $high_performers = $analyzer->get_unprocessed_high_performers($angle, 1);
                
                if (!empty($high_performers)) {
                    $article_to_process = $high_performers[0];
                    $target_angle = $angle;
                    break;
                }
            }
            
            // 没有待处理的文章
            if (!$article_to_process) {
                $this->log_info('DAILY_ANALYSIS_SKIP', '所有内容角度都没有待处理的高表现文章');
                $results['reason'] = '没有待处理的高表现文章';
                return $results;
            }
            
            // 处理这一篇文章
            $post_id = $article_to_process['post_id'];
            $this->log_info('PROCESSING_ARTICLE', "开始处理文章", array(
                'post_id' => $post_id,
                'content_angle' => $target_angle,
                'post_title' => $article_to_process['post_title'] ?? ''
            ));
            
            try {
                // 提取并创建结构
                $structure_result = $extractor->extract_and_create_structure($post_id);
                
                if ($structure_result) {
                    $results['articles_extracted'] = 1;
                    $results['structures_created'] = 1;
                    $results['angles_processed'] = 1;
                    
                    $results['details'][] = array(
                        'content_angle' => $target_angle,
                        'post_id' => $post_id,
                        'structure_id' => $structure_result['structure_id'],
                        'title' => $structure_result['title']
                    );
                    
                    $this->log_info('STRUCTURE_CREATED', "成功从文章 {$post_id} 创建结构", array(
                        'structure_id' => $structure_result['structure_id'],
                        'content_angle' => $target_angle
                    ));
                } else {
                    $results['errors'][] = "文章 {$post_id} 结构提取失败";
                }
                
            } catch (Exception $e) {
                $error_msg = "处理文章 {$post_id} 时出错: " . $e->getMessage();
                $results['errors'][] = $error_msg;
                $this->log_error('ARTICLE_PROCESS_ERROR', $error_msg);
            }
            
            // 重置失败计数
            $this->reset_failure_count();
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $this->log_error('DAILY_ANALYSIS_ERROR', '分析任务执行失败: ' . $e->getMessage());
            
            // 处理失败
            $this->handle_task_failure('daily_analysis', $e->getMessage());
        }
        
        $elapsed_time = round(microtime(true) - $start_time, 2);
        $results['elapsed_time'] = $elapsed_time;
        
        $this->log_info('DAILY_ANALYSIS_COMPLETE', '分析任务执行完成', array(
            'articles_extracted' => $results['articles_extracted'],
            'structures_created' => $results['structures_created'],
            'elapsed_time' => $elapsed_time . 's',
            'errors_count' => count($results['errors'])
        ));
        
        return $results;
    }

    
    /**
     * 执行每周受欢迎度更新任务
     * 
     * @return array 执行结果
     */
    public function run_weekly_popularity_update() {
        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'structures_updated' => 0,
            'errors' => array()
        );
        
        $this->log_info('WEEKLY_POPULARITY_START', '开始执行每周受欢迎度更新任务');
        
        try {
            // 检查功能是否启用
            if (!$this->config->is_optimization_enabled()) {
                $this->log_info('WEEKLY_POPULARITY_SKIP', '智能优化功能未启用，跳过更新');
                $results['skipped'] = true;
                $results['reason'] = '功能未启用';
                return $results;
            }
            
            // 加载受欢迎度计算器
            require_once dirname(__FILE__) . '/class-popularity-calculator.php';
            $calculator = new ContentAuto_PopularityCalculator($this->logger);
            
            // 批量更新所有结构的受欢迎度指数
            $updated_count = $calculator->update_all_indices();
            $results['structures_updated'] = $updated_count;
            
            // 重置失败计数
            $this->reset_failure_count();
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $this->log_error('WEEKLY_POPULARITY_ERROR', '每周受欢迎度更新任务执行失败: ' . $e->getMessage());
            
            // 处理失败
            $this->handle_task_failure('weekly_popularity', $e->getMessage());
        }
        
        $elapsed_time = round(microtime(true) - $start_time, 2);
        $results['elapsed_time'] = $elapsed_time;
        
        $this->log_info('WEEKLY_POPULARITY_COMPLETE', '每周受欢迎度更新任务执行完成', array(
            'structures_updated' => $results['structures_updated'],
            'elapsed_time' => $elapsed_time . 's'
        ));
        
        return $results;
    }
    
    /**
     * 执行缓存清理任务
     * 清理30天以上的旧数据，并执行结构淘汰
     * 
     * @return array 执行结果
     */
    public function run_cache_cleanup() {
        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'analytics_deleted' => 0,
            'transients_deleted' => 0,
            'options_cleaned' => 0,
            'structures_retired' => 0,
            'errors' => array()
        );
        
        $this->log_info('CACHE_CLEANUP_START', '开始执行缓存清理任务');
        
        try {
            global $wpdb;
            
            // 1. 清理30天以上的 structure_analytics 数据
            $analytics_table = $wpdb->prefix . 'content_auto_structure_analytics';
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$analytics_table'") == $analytics_table;
            
            if ($table_exists) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$analytics_table} WHERE date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                    30
                ));
                
                if ($deleted !== false) {
                    $results['analytics_deleted'] = $deleted;
                }
            }
            
            // 2. 清理过期的 transients（受欢迎度缓存）
            $transients_deleted = $this->cleanup_popularity_transients();
            $results['transients_deleted'] = $transients_deleted;
            
            // 3. 清理旧的分析历史选项
            $options_cleaned = $this->cleanup_analysis_history_options();
            $results['options_cleaned'] = $options_cleaned;
            
            // 4. 执行结构淘汰
            $structures_retired = $this->retire_low_performing_structures();
            $results['structures_retired'] = $structures_retired;
            
            // 重置失败计数
            $this->reset_failure_count();
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
            $this->log_error('CACHE_CLEANUP_ERROR', '缓存清理任务执行失败: ' . $e->getMessage());
            
            // 处理失败
            $this->handle_task_failure('cache_cleanup', $e->getMessage());
        }
        
        $elapsed_time = round(microtime(true) - $start_time, 2);
        $results['elapsed_time'] = $elapsed_time;
        
        $this->log_info('CACHE_CLEANUP_COMPLETE', '缓存清理任务执行完成', array(
            'analytics_deleted' => $results['analytics_deleted'],
            'transients_deleted' => $results['transients_deleted'],
            'options_cleaned' => $results['options_cleaned'],
            'structures_retired' => $results['structures_retired'],
            'elapsed_time' => $elapsed_time . 's'
        ));
        
        return $results;
    }
    
    /**
     * 淘汰低表现结构
     * 当某个 content_angle 的数据驱动结构超过上限时，淘汰表现最差的
     * 
     * @return int 淘汰的结构数量
     */
    private function retire_low_performing_structures() {
        // 检查是否启用淘汰机制
        if (!$this->config->get_bool('structure_retire_enabled', true)) {
            $this->log_info('RETIRE_SKIP', '结构淘汰机制未启用');
            return 0;
        }
        
        global $wpdb;
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        $max_per_angle = $this->config->get_int('max_structures_per_angle', 20);
        $min_age_days = $this->config->get_int('structure_min_age_days', 30);
        
        // 获取所有 content_angle 及其数据驱动结构数量
        $angle_counts = $wpdb->get_results("
            SELECT content_angle, COUNT(*) as count
            FROM {$structures_table}
            WHERE source_type = 'data_driven'
            GROUP BY content_angle
            HAVING count > {$max_per_angle}
        ", ARRAY_A);
        
        if (empty($angle_counts)) {
            return 0;
        }
        
        $total_retired = 0;
        
        foreach ($angle_counts as $row) {
            $angle = $row['content_angle'];
            $current_count = (int) $row['count'];
            $to_retire = $current_count - $max_per_angle;
            
            if ($to_retire <= 0) {
                continue;
            }
            
            // 获取该角度下表现最差的结构（排除新结构保护期内的）
            // 排序规则：usage_count 升序，然后按 extracted_at 升序（越早越容易被淘汰）
            $structures_to_retire = $wpdb->get_col($wpdb->prepare("
                SELECT id
                FROM {$structures_table}
                WHERE content_angle = %s
                AND source_type = 'data_driven'
                AND extracted_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                ORDER BY usage_count ASC, extracted_at ASC
                LIMIT %d
            ", $angle, $min_age_days, $to_retire));
            
            if (empty($structures_to_retire)) {
                $this->log_info('RETIRE_SKIP_ANGLE', "内容角度 {$angle} 没有可淘汰的结构（都在保护期内）");
                continue;
            }
            
            // 删除这些结构
            $ids_placeholder = implode(',', array_map('intval', $structures_to_retire));
            $deleted = $wpdb->query("DELETE FROM {$structures_table} WHERE id IN ({$ids_placeholder})");
            
            if ($deleted !== false && $deleted > 0) {
                $total_retired += $deleted;
                $this->log_info('STRUCTURES_RETIRED', "淘汰了 {$deleted} 个低表现结构", array(
                    'content_angle' => $angle,
                    'retired_ids' => $structures_to_retire
                ));
            }
        }
        
        return $total_retired;
    }
    
    /**
     * 清理受欢迎度相关的 transients
     * 
     * @return int 删除的数量
     */
    private function cleanup_popularity_transients() {
        global $wpdb;
        
        // 删除过期的 cam_popularity_ 前缀的 transients
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_cam_popularity_%' 
            OR option_name LIKE '_transient_timeout_cam_popularity_%'"
        );
        
        return $deleted !== false ? $deleted : 0;
    }
    
    /**
     * 清理旧的分析历史选项
     * 
     * @return int 清理的数量
     */
    private function cleanup_analysis_history_options() {
        global $wpdb;
        
        // 获取所有分析历史选项
        $options = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
            WHERE option_name LIKE 'cam_article_analysis_%'",
            ARRAY_A
        );
        
        $cleaned = 0;
        // 使用 current_time('timestamp') 保持与写入时间的一致性
        $cutoff_time = current_time('timestamp') - (30 * DAY_IN_SECONDS);
        
        foreach ($options as $option) {
            $history = maybe_unserialize($option['option_value']);
            
            if (!is_array($history)) {
                continue;
            }
            
            // 过滤30天内的记录
            $filtered = array_filter($history, function($item) use ($cutoff_time) {
                return isset($item['timestamp']) && strtotime($item['timestamp']) > $cutoff_time;
            });
            
            // 如果有记录被过滤掉，更新选项
            if (count($filtered) < count($history)) {
                update_option($option['option_name'], array_values($filtered));
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * 处理任务失败
     * 
     * @param string $task_name 任务名称
     * @param string $error_message 错误信息
     */
    private function handle_task_failure($task_name, $error_message) {
        // 获取当前失败计数
        $failures = get_option(self::OPTION_FAILURE_COUNT, array());
        
        if (!isset($failures[$task_name])) {
            $failures[$task_name] = 0;
        }
        
        $failures[$task_name]++;
        update_option(self::OPTION_FAILURE_COUNT, $failures);
        update_option(self::OPTION_LAST_FAILURE_TIME, current_time('mysql'));
        
        $this->log_warning('TASK_FAILURE', "任务 {$task_name} 失败，当前连续失败次数: {$failures[$task_name]}", array(
            'error' => $error_message
        ));
        
        // 检查是否需要发送通知
        if ($failures[$task_name] >= self::MAX_CONSECUTIVE_FAILURES) {
            $this->send_admin_notification($task_name, $failures[$task_name], $error_message);
        }
        
        // 安排重试
        $this->schedule_retry($task_name);
    }
    
    /**
     * 安排任务重试
     * 
     * @param string $task_name 任务名称
     */
    private function schedule_retry($task_name) {
        $retry_time = time() + self::RETRY_DELAY;
        
        switch ($task_name) {
            case 'daily_analysis':
                if (!wp_next_scheduled('content_auto_structure_retry_daily_analysis')) {
                    wp_schedule_single_event($retry_time, 'content_auto_structure_retry_daily_analysis');
                    $this->log_info('RETRY_SCHEDULED', '每日分析任务重试已安排', array(
                        'retry_time' => date('Y-m-d H:i:s', $retry_time)
                    ));
                }
                break;
                
            case 'weekly_popularity':
                if (!wp_next_scheduled('content_auto_structure_retry_weekly_popularity')) {
                    wp_schedule_single_event($retry_time, 'content_auto_structure_retry_weekly_popularity');
                    $this->log_info('RETRY_SCHEDULED', '每周受欢迎度更新任务重试已安排', array(
                        'retry_time' => date('Y-m-d H:i:s', $retry_time)
                    ));
                }
                break;
                
            case 'cache_cleanup':
                if (!wp_next_scheduled('content_auto_structure_retry_cache_cleanup')) {
                    wp_schedule_single_event($retry_time, 'content_auto_structure_retry_cache_cleanup');
                    $this->log_info('RETRY_SCHEDULED', '缓存清理任务重试已安排', array(
                        'retry_time' => date('Y-m-d H:i:s', $retry_time)
                    ));
                }
                break;
        }
    }
    
    /**
     * 发送管理员通知
     * 
     * @param string $task_name 任务名称
     * @param int $failure_count 失败次数
     * @param string $error_message 错误信息
     */
    private function send_admin_notification($task_name, $failure_count, $error_message) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $task_names = array(
            'daily_analysis' => '每日文章分析',
            'weekly_popularity' => '每周受欢迎度更新',
            'cache_cleanup' => '缓存清理'
        );
        
        $task_display_name = isset($task_names[$task_name]) ? $task_names[$task_name] : $task_name;
        
        $subject = "[{$site_name}] 智能结构优化任务失败通知";
        
        $message = "您好，\n\n";
        $message .= "智能文章结构优化系统的定时任务连续失败，需要您的关注。\n\n";
        $message .= "任务名称：{$task_display_name}\n";
        $message .= "连续失败次数：{$failure_count}\n";
        $message .= "最后错误信息：{$error_message}\n";
        $message .= "失败时间：" . current_time('mysql') . "\n\n";
        $message .= "请登录后台检查系统状态并排查问题。\n\n";
        $message .= "此邮件由系统自动发送，请勿回复。\n";
        
        $sent = wp_mail($admin_email, $subject, $message);
        
        if ($sent) {
            $this->log_info('ADMIN_NOTIFIED', '已发送管理员通知邮件', array(
                'task' => $task_name,
                'email' => $admin_email
            ));
        } else {
            $this->log_error('NOTIFICATION_FAILED', '发送管理员通知邮件失败', array(
                'task' => $task_name,
                'email' => $admin_email
            ));
        }
    }
    
    /**
     * 重置失败计数
     */
    private function reset_failure_count() {
        delete_option(self::OPTION_FAILURE_COUNT);
        delete_option(self::OPTION_LAST_FAILURE_TIME);
    }

    
    /**
     * 获取所有 content_angle
     * 
     * @return array content_angle 列表
     */
    private function get_all_content_angles() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        $angles = $wpdb->get_col("
            SELECT DISTINCT source_angle 
            FROM {$topics_table} 
            WHERE source_angle IS NOT NULL 
            AND source_angle != ''
        ");
        
        return $angles ?: array();
    }
    
    /**
     * 计算下一次计划运行时间
     * 
     * @param int $hour 小时（0-23）
     * @return int Unix 时间戳
     */
    private function get_next_scheduled_time($hour) {
        $now = current_time('timestamp');
        $today_scheduled = strtotime(date('Y-m-d', $now) . " {$hour}:00:00");
        
        // 如果今天的计划时间已过，安排到明天
        if ($now >= $today_scheduled) {
            return $today_scheduled + DAY_IN_SECONDS;
        }
        
        return $today_scheduled;
    }
    
    /**
     * 计算下一次每周运行时间
     * 
     * @param int $day_of_week 星期几（1=周一，7=周日）
     * @param int $hour 小时（0-23）
     * @return int Unix 时间戳
     */
    private function get_next_weekly_time($day_of_week, $hour) {
        $now = current_time('timestamp');
        $current_day = (int) date('N', $now);
        $current_hour = (int) date('G', $now);
        
        // 计算到目标日期的天数差
        $days_until = $day_of_week - $current_day;
        
        if ($days_until < 0 || ($days_until === 0 && $current_hour >= $hour)) {
            // 如果目标日期已过或今天已过目标时间，安排到下周
            $days_until += 7;
        }
        
        $target_date = strtotime("+{$days_until} days", strtotime(date('Y-m-d', $now)));
        return strtotime(date('Y-m-d', $target_date) . " {$hour}:00:00");
    }
    
    /**
     * 获取任务状态
     * 
     * @return array 任务状态信息
     */
    public function get_task_status() {
        $status = array(
            'daily_analysis' => array(
                'name' => '每日文章分析',
                'next_run' => null,
                'enabled' => false
            ),
            'weekly_popularity' => array(
                'name' => '每周受欢迎度更新',
                'next_run' => null,
                'enabled' => false
            ),
            'cache_cleanup' => array(
                'name' => '缓存清理',
                'next_run' => null,
                'enabled' => false
            ),
            'failures' => get_option(self::OPTION_FAILURE_COUNT, array()),
            'last_failure_time' => get_option(self::OPTION_LAST_FAILURE_TIME, null)
        );
        
        // 检查每日分析任务
        $next_daily = wp_next_scheduled(self::CRON_HOOK_DAILY_ANALYSIS);
        if ($next_daily) {
            $status['daily_analysis']['next_run'] = date('Y-m-d H:i:s', $next_daily);
            $status['daily_analysis']['enabled'] = true;
        }
        
        // 检查每周受欢迎度更新任务
        $next_weekly = wp_next_scheduled(self::CRON_HOOK_WEEKLY_POPULARITY);
        if ($next_weekly) {
            $status['weekly_popularity']['next_run'] = date('Y-m-d H:i:s', $next_weekly);
            $status['weekly_popularity']['enabled'] = true;
        }
        
        // 检查缓存清理任务
        $next_cleanup = wp_next_scheduled(self::CRON_HOOK_CACHE_CLEANUP);
        if ($next_cleanup) {
            $status['cache_cleanup']['next_run'] = date('Y-m-d H:i:s', $next_cleanup);
            $status['cache_cleanup']['enabled'] = true;
        }
        
        return $status;
    }
    
    /**
     * 手动触发每日分析任务
     * 
     * @return array 执行结果
     */
    public function trigger_daily_analysis() {
        return $this->run_daily_analysis();
    }
    
    /**
     * 手动触发每周受欢迎度更新任务
     * 
     * @return array 执行结果
     */
    public function trigger_weekly_popularity_update() {
        return $this->run_weekly_popularity_update();
    }
    
    /**
     * 手动触发缓存清理任务
     * 
     * @return array 执行结果
     */
    public function trigger_cache_cleanup() {
        return $this->run_cache_cleanup();
    }
    
    /**
     * 更新分析计划时间
     * 
     * @param int $hour 新的小时（0-23）
     * @return bool 是否成功
     */
    public function update_analysis_schedule($hour) {
        if ($hour < 0 || $hour > 23) {
            return false;
        }
        
        // 更新配置
        $this->config->set_config('analysis_schedule_hour', $hour);
        
        // 重新安排任务
        $timestamp = wp_next_scheduled(self::CRON_HOOK_DAILY_ANALYSIS);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK_DAILY_ANALYSIS);
        }
        
        $next_run = $this->get_next_scheduled_time($hour);
        wp_schedule_event($next_run, 'daily', self::CRON_HOOK_DAILY_ANALYSIS);
        
        $this->log_info('SCHEDULE_UPDATED', '分析计划时间已更新', array(
            'new_hour' => $hour,
            'next_run' => date('Y-m-d H:i:s', $next_run)
        ));
        
        return true;
    }
    
    /**
     * 记录信息日志
     */
    private function log_info($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->info("[StructureScheduler] [{$code}] {$message}", $context);
        }
    }
    
    /**
     * 记录警告日志
     */
    private function log_warning($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->warning("[StructureScheduler] [{$code}] {$message}", $context);
        }
    }
    
    /**
     * 记录错误日志
     */
    private function log_error($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->error("[StructureScheduler] [{$code}] {$message}", $context);
        }
    }
    
    /**
     * 初始化 WordPress 钩子
     * 在插件加载时调用
     */
    public static function init_hooks() {
        // 注册 cron 动作钩子
        add_action(self::CRON_HOOK_DAILY_ANALYSIS, array(__CLASS__, 'handle_daily_analysis'));
        add_action(self::CRON_HOOK_WEEKLY_POPULARITY, array(__CLASS__, 'handle_weekly_popularity'));
        add_action(self::CRON_HOOK_CACHE_CLEANUP, array(__CLASS__, 'handle_cache_cleanup'));
        
        // 注册重试钩子
        add_action('content_auto_structure_retry_daily_analysis', array(__CLASS__, 'handle_daily_analysis'));
        add_action('content_auto_structure_retry_weekly_popularity', array(__CLASS__, 'handle_weekly_popularity'));
        add_action('content_auto_structure_retry_cache_cleanup', array(__CLASS__, 'handle_cache_cleanup'));
    }
    
    /**
     * 处理每日分析 cron 事件
     */
    public static function handle_daily_analysis() {
        $scheduler = new self();
        $scheduler->run_daily_analysis();
    }
    
    /**
     * 处理每周受欢迎度更新 cron 事件
     */
    public static function handle_weekly_popularity() {
        $scheduler = new self();
        $scheduler->run_weekly_popularity_update();
    }
    
    /**
     * 处理缓存清理 cron 事件
     */
    public static function handle_cache_cleanup() {
        $scheduler = new self();
        $scheduler->run_cache_cleanup();
    }
}
