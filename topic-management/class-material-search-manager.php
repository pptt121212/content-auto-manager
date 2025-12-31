<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 自动素材搜索任务管理器
 * 负责扫描并执行后台素材搜索任务
 */
class ContentAuto_MaterialSearchManager {

    public function __construct() {
        // 注册定时任务Hook
        add_action('content_auto_material_search_event', array($this, 'process_batch'));
    }

    /**
     * 触发一次任务调度（通常在保存主题后调用）
     */
    public function schedule_process() {
        if (!wp_next_scheduled('content_auto_material_search_event')) {
            // 立即调度（异步执行）
            wp_schedule_single_event(time(), 'content_auto_material_search_event');
        }
    }

    /**
     * 处理一批待搜索任务
     */
    public function process_batch() {
        // 1. 检查总开关
        $db = new ContentAuto_Database();
        $publish_rules = $db->get_row('content_auto_publish_rules', array('id' => 1));
        
        // 严格检查：必须开启 'enable_auto_material_search' 且 'enable_reference_material' 也得开启
        // 用户之前的需求是：启用参考资料功能 -> (可选)启用自动素材搜索
        if (empty($publish_rules['enable_reference_material']) || empty($publish_rules['enable_auto_material_search'])) {
            return;
        }

        // 2. 获取一个待处理任务 (一次只做一个，避免超时和API超限)
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 查找 status=pending 的
        // 并且要再次确认 reference_material 确实是空的（双重检查）
        $topic = $wpdb->get_row("SELECT * FROM {$topics_table} WHERE material_search_status = 'pending' AND (reference_material IS NULL OR reference_material = '') ORDER BY id ASC LIMIT 1", ARRAY_A);

        if (!$topic) {
            return; // 队列空了，停止
        }

        // 3. ✅ 原子性标记为处理中（防止竞态条件）
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$topics_table} 
             SET material_search_status = 'processing' 
             WHERE id = %d AND material_search_status = 'pending'",
            $topic['id']
        ));

        // 如果更新失败（返回 0 行），说明任务已被其他进程抢占
        if ($updated === 0) {
            error_log("ContentAuto Material Search: Topic {$topic['id']} already processing by another worker");
            return; // 跳过此任务，等待下次调度
        }

        // 4. 执行搜索
        // 确保类文件已加载
        if (!class_exists('ContentAuto_SearchMaterialsService')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'search-materials/class-search-materials-service.php';
        }
        $service = new ContentAuto_SearchMaterialsService();
        
        $result = $service->execute_full_auto_search($topic['id']);

        // 5. 更新结果状态
        if (is_wp_error($result)) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'failed',
                'material_search_error' => $result->get_error_message()
            ], ['id' => $topic['id']]);
        } else {
            $wpdb->update($topics_table, [
                'material_search_status' => 'completed',
                'material_search_error' => '' // 清空错误
            ], ['id' => $topic['id']]);
        }

        // 6. 链式调用：继续调度下一次，直到队列为空
        // 必须再次检查是否存在 pending 任务，避免无限空循环（虽然上面查询能防住，但逻辑上严谨点）
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE material_search_status = 'pending'");
        
        if ($remaining > 0) {
            // ✅ 防止过度调度：使用 Transient 限制调度频率
            $throttle_key = 'content_auto_material_search_throttle';
            $last_schedule = get_transient($throttle_key);
            
            // 如果60秒内没有调度过，则允许新调度
            if ($last_schedule === false) {
                set_transient($throttle_key, time(), 60); // 60秒内不重复调度
                wp_schedule_single_event(time() + 2, 'content_auto_material_search_event'); // 延迟2秒
            } else {
                // 日志：调度被限流
                error_log("ContentAuto Material Search: Throttled - Last schedule at " . date('Y-m-d H:i:s', $last_schedule));
            }
        }
    }
}
