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
        
        // 挂载到每分钟的全局心跳，作为兜底机制（防止任务链意外中断）
        add_action('content_auto_manager_process_queue', array($this, 'schedule_process'));
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
        
        // 严格检查：必须开启 'enable_reference_material'
        // 注意：不再检查 'enable_auto_material_search'，因为既然任务已在队列中（可能是手动触发），就应该执行
        if (empty($publish_rules['enable_reference_material'])) {
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
            // 继续调度下一次（延迟10秒，避免过于频繁，且给服务器喘息时间）
            // WP会自动处理10分钟内的重复调度请求，确保不会堆积
            wp_schedule_single_event(time() + 10, 'content_auto_material_search_event');
        }
    }
}
