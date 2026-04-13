<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 自动素材搜索任务管理器（旧版调度器）
 * 
 * 重要说明：
 * - 此调度器现在只处理 "search_engine" 模式（网络搜索）
 * - "extension_rag" 模式（知识库搜索）由 job_queue 队列处理器分发到浏览器插件
 * - 这确保了不同模式走不同的执行路径
 */
class Yali_AI_Writer_MaterialSearchManager {

    public function __construct() {
        // 注册定时任务Hook
        add_action('yali_ai_writer_material_search_event', array($this, 'process_batch'));
        
        // 挂载到每分钟的全局心跳，作为兜底机制（防止任务链意外中断）
        add_action('yali_ai_writer_manager_process_queue', array($this, 'schedule_process'));
    }

    /**
     * 触发一次任务调度（通常在保存主题后调用）
     */
    public function schedule_process() {
        if (!wp_next_scheduled('yali_ai_writer_material_search_event')) {
            // 立即调度（异步执行）
            wp_schedule_single_event(time(), 'yali_ai_writer_material_search_event');
        }
    }

    /**
     * 处理一批待搜索任务
     * 
     * 注意：此方法现在会检查 material_collection_mode 设置
     * - search_engine 模式：直接执行网络搜索
     * - extension_rag 模式：跳过，由 job_queue 处理器分发到浏览器插件
     * - none 模式：跳过
     */
    public function process_batch() {
        // 1. 检查总开关和模式
        $db = new Yali_AI_Writer_Database();
        $publish_rules = $db->get_row('yali_ai_writer_publish_rules', array('id' => 1));
        
        // 严格检查：必须开启 'enable_reference_material'
        if (empty($publish_rules['enable_reference_material'])) {
            return;
        }
        
        // 获取搜集模式
        $mode = !empty($publish_rules['material_collection_mode']) ? $publish_rules['material_collection_mode'] : 'none';
        // 迁移逻辑：如果字段尚未存在但旧开关开启，默认为 search_engine
        if ($mode === 'none' && !empty($publish_rules['enable_auto_material_search'])) {
            $mode = 'search_engine';
        }
        
        // 如果模式是 extension_rag 或 none，此调度器不处理
        // extension_rag 模式由 job_queue 中的 process_material_search 方法处理
        if ($mode !== 'search_engine') {
            return;
        }

        // 2. 获取一个待处理任务 (一次只做一个，避免超时和API超限)
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
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

        // 4. 执行网络搜索（仅 search_engine 模式会到达这里）
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
        $logger = new Yali_AI_Writer_LoggingSystem();
        $logger->log_success('MATERIAL_MANAGER_EXECUTE', "MaterialSearchManager 执行网络搜索", array(
            'topic_id' => $topic['id'],
            'topic_title' => $topic['title'],
            'mode' => 'search_engine'
        ));
        
        // 确保类文件已加载
        if (!class_exists('Yali_AI_Writer_SearchMaterialsService')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'search-materials/class-search-materials-service.php';
        }
        $service = new Yali_AI_Writer_SearchMaterialsService();
        
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
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE material_search_status = 'pending'");
        
        if ($remaining > 0) {
            // 继续调度下一次（延迟10秒）
            wp_schedule_single_event(time() + 10, 'yali_ai_writer_material_search_event');
        }
    }
}
