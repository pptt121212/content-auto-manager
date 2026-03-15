<?php
/**
 * 重构后的主题任务管理器
 * 通过使用抽象出来的功能模块，大大简化了主类的职责
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入依赖的抽象功能模块
require_once __DIR__ . '/class-topic-api-handler.php';
require_once __DIR__ . '/class-json-parser.php';
require_once __DIR__ . '/class-task-status-manager.php';
require_once __DIR__ . '/class-task-recovery-handler.php';
require_once dirname(__DIR__) . '/rule-management/class-rule-manager.php';
require_once dirname(__DIR__) . '/shared/logging/class-logging-system.php';

class ContentAuto_TopicTaskManager {
    
    private $database;
    private $rule_manager;
    private $logger;
    private $api_handler;
    private $json_parser;
    private $status_manager;
    private $recovery_handler;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->rule_manager = new ContentAuto_RuleManager();
        $this->logger = new ContentAuto_LoggingSystem();
        $this->api_handler = new ContentAuto_TopicApiHandler($this->logger);
        $this->json_parser = new ContentAuto_JsonParser($this->logger);
        $this->status_manager = new ContentAuto_TaskStatusManager($this->database, $this->logger);
        $this->recovery_handler = new ContentAuto_TaskRecoveryHandler($this->database, $this->status_manager, $this->logger);
    }
    
    /**
     * 创建主题生成任务
     */
    public function create_topic_task($rule_id, $topic_count_per_item) {
        global $wpdb;
        
        // 验证规则是否存在且已启用
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d AND status = 1", $rule_id));
        if (!$rule) {
            return false;
        }
        
        // 统一使用规则项目表中的实际数据数量
        global $wpdb;
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';

        $total_rule_items = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$rule_items_table} WHERE rule_id = %d",
            $rule_id
        ));
        $total_rule_items = intval($total_rule_items);

        if ($total_rule_items <= 0) {
            return false;
        }
        
        // 智能任务去重检查
        if (!$this->should_create_new_task($rule_id)) {
            return false;
        }
        
        // 计算预期生成主题总数
        $total_expected_topics = $topic_count_per_item * $total_rule_items;
        
        // 生成全局唯一的主题任务ID
        $topic_task_id = 'topic_task_' . uniqid();
        
        // 创建任务数据
        $task_data = array(
            'topic_task_id' => $topic_task_id,
            'rule_id' => $rule_id,
            'topic_count_per_item' => $topic_count_per_item,
            'total_rule_items' => $total_rule_items,
            'total_expected_topics' => $total_expected_topics,
            'current_processing_item' => 0,
            'generated_topics_count' => 0,
            'status' => CONTENT_AUTO_STATUS_PENDING,
            'error_message' => '',
            'subtask_status' => '{}',
            'last_processed_at' => null
        );
        
        // 插入任务记录
        $task_id = $this->database->insert('content_auto_topic_tasks', $task_data);
        
        if ($task_id) {
            // 将任务添加到队列
            $this->add_to_queue($task_id);
        }
        
        return $task_id;
    }
    
    /**
     * 处理主题生成任务
     */
    public function process_topic_task($task_id, $subtask_id = null, $job_queue_id = null) {
        $context = $this->logger->build_context(null, null, array('task_id' => $task_id, 'job_queue_id' => $job_queue_id));
        $this->logger->log_success('TASK_START', __('开始处理主题任务', 'yali-ai-writer'), $context);
        
        // 获取任务信息
        $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
        if (!$task) {
            $error_message = sprintf(__('主题任务不存在: %s', 'yali-ai-writer'), $task_id);
            $this->logger->log_error('TASK_NOT_FOUND', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 检查任务状态
        if (!$this->is_task_processable($task)) {
            $error_message = sprintf(__('任务 (ID: %1$s) 状态不可处理或恢复失败，当前状态: %2$s', 'yali-ai-writer'), $task_id, $task['status']);
            $this->logger->log_warning('TASK_NOT_PROCESSABLE', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 并发控制检查
        if (!$this->check_concurrency_control($task)) {
            $error_message = sprintf(__('发现相同规则 (Rule ID: %1$s) 的并发任务，本任务 (ID: %2$s) 跳过处理', 'yali-ai-writer'), $task['rule_id'], $task_id);
            $this->logger->log_warning('CONCURRENT_TASK_SKIPPED', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        if ($subtask_id === null) {
            $subtask_id = $this->get_current_subtask_id($task_id, $task);
        }
        
        // [FIX] 如果仍未获取到子任务ID，检查是否存在 waiting_browser 状态的子任务
        if ($subtask_id === null) {
             global $wpdb;
             $waiting_count = $wpdb->get_var($wpdb->prepare(
                 "SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_job_queue 
                  WHERE job_type = 'topic_task' AND job_id = %d AND status = 'waiting_browser'",
                  $task_id
             ));
             
             if ($waiting_count > 0) {
                 $this->logger->log_info('TASK_WAITING', sprintf(__('任务 (ID: %1$s) 正在等待浏览器采集 (%2$d 个子任务)', 'yali-ai-writer'), $task_id, $waiting_count), $context);
                 // 保持 status 为 pending 或 processing 都可以，这里直接返回 success 停止本次执行
                 return ['success' => true, 'status' => 'waiting_for_browser', 'message' => __('等待浏览器采集内容', 'yali-ai-writer')];
             }
             
             // 如果既没有 pending 也没有 waiting_browser，尝试完成任务状态逻辑
             $this->finalize_task_status_if_completed($task_id, $task);
             
             // 返回成功但不执行任何操作，避免报错
             return ['success' => true, 'message' => __('未找到待处理的子任务', 'yali-ai-writer')];
        }
        
        // 仅当任务状态不是 'processing' 时，才更新为 'processing'
        if ($task['status'] !== 'processing') {
            if (!$this->status_manager->safe_update_task_status($task_id, 'processing', __('开始处理子任务', 'yali-ai-writer'))) {
                $error_message = sprintf(__('更新任务 (ID: %s) 状态为\'处理中\'失败', 'yali-ai-writer'), $task_id);
                $this->logger->log_error('STATUS_UPDATE_FAILED', $error_message);
                return ['success' => false, 'message' => $error_message];
            }
            // 重新加载任务信息以确保状态更新
            $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
        }
        
        global $wpdb;
        try {
            // 开始数据库事务
            $wpdb->query('START TRANSACTION');
            
            // 处理当前规则项目
            $result = $this->process_current_rule_item($task, $subtask_id, $job_queue_id);

            if ($result['success']) {
                $actual_saved_count = $result['saved_count'] ?? 0;
                $this->handle_successful_processing($task_id, $task, $subtask_id, $actual_saved_count);
                $wpdb->query('COMMIT');
                return ['success' => true];
            } elseif (isset($result['status']) && $result['status'] === 'waiting_for_browser') {
                // 特殊处理：等待浏览器采集，这不算失败，但需要暂时释放 Worker 锁
                $wpdb->query('COMMIT'); 
                
                // 将主任务重置为 pending，以便 Worker 下次能再次扫描它，也为了不阻塞全局队列
                // 注意：由于我们设置了并发限制，这个 pending 任务在子任务未完成前会一直“跳过”逻辑
                $this->status_manager->safe_update_task_status($task_id, CONTENT_AUTO_STATUS_PENDING, __('等待浏览器采集内容', 'yali-ai-writer'));
                
                // 同时更新 job_queue 子任务状态为 waiting_browser (如果之前不是的话)
                $wpdb->update(
                    $wpdb->prefix . 'content_auto_job_queue',
                    array('status' => 'waiting_browser', 'updated_at' => current_time('mysql')),
                    array('job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id)
                );

                return ['success' => true, 'status' => 'waiting_for_browser', 'message' => 'waiting_for_browser'];
            } else {
                // 处理失败状态
                $wpdb->query('ROLLBACK');
                $error_message = $this->handle_failed_processing($task_id, $task, $subtask_id, $result['error']);
                return ['success' => false, 'message' => $error_message];
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $error_message = $this->handle_exception($task_id, $task, $subtask_id, $e);
            return ['success' => false, 'message' => $error_message];
        }
    }
    
    
    
    /**
     * 更新任务心跳，防止长时间运行的任务被误判为挂起
     * 
     * @param int $task_id 主任务ID
     * @param string $subtask_id 子任务ID
     * @param string $stage 当前执行阶段（用于日志）
     */
    private function update_task_heartbeat($task_id, $subtask_id, $stage = '') {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $updated = $wpdb->update(
            $queue_table,
            ['updated_at' => current_time('mysql')],
            [
                'job_type' => 'topic_task',
                'job_id' => $task_id,
                'subtask_id' => $subtask_id
            ]
        );
        
        // 仅在调试模式下记录心跳日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE && !empty($stage)) {
            $this->logger->log_debug('TASK_HEARTBEAT', "任务心跳更新", [
                'task_id' => $task_id,
                'subtask_id' => $subtask_id,
                'stage' => $stage,
                'updated' => $updated !== false ? 'YES' : 'NO'
            ]);
        }
    }

    /**
     * 处理当前规则项目
     */
    private function process_current_rule_item($task, $subtask_id, $job_queue_id = null) {
        // [增强] 设置PHP执行时间限制，确保API调用和后续处理有足够时间
        // 与文章生成任务保持一致（300秒 = 5分钟）
        set_time_limit(300);
        
        // [增强] 初始化任务心跳，通知系统任务正在活跃执行
        $this->update_task_heartbeat($task['id'], $subtask_id, 'INIT');
        
        $error_details = [];

        // 通过reference_id获取规则项目内容 - 确保绝对的准确性
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 获取队列记录中的reference_id（规则项目ID）
        $queue_record = $wpdb->get_row($wpdb->prepare(
            "SELECT reference_id FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND subtask_id = %s",
            $task['id'], $subtask_id
        ));
        
        if (!$queue_record || !$queue_record->reference_id) {
            $error_details['stage'] = __('获取队列信息', 'yali-ai-writer');
            $error_details['message'] = __('无法获取规则项目ID', 'yali-ai-writer');
            $this->logger->log_error('QUEUE_FETCH', $error_details['message']);
            return ['success' => false, 'error' => $error_details];
        }
        
        $rule_item_id = $queue_record->reference_id;
        
        // 使用新的规则项目ID查询方法
        $content = $this->rule_manager->get_content_by_rule_item_id($rule_item_id);
        if (!$content) {
            $error_details['stage'] = __('获取规则数据', 'yali-ai-writer');
            $error_details['message'] = __('无法获取规则项目内容', 'yali-ai-writer');
            $this->logger->log_error('CONTENT_FETCH', $error_details['message']);
            return ['success' => false, 'error' => $error_details];
        }

        // 检查是否是"采集网址仿写"规则，且内容尚未采集
        // 如果 upload_text 以 http:// 或 https:// 开头，说明是待采集的 URL
        if (isset($task['rule_id'])) {
            global $wpdb;
            $rule = $wpdb->get_row($wpdb->prepare("SELECT rule_type FROM {$wpdb->prefix}content_auto_rules WHERE id = %d", $task['rule_id']));
            
            // 调试日志
            $this->log_debug('RULE_TYPE_CHECK', "规则类型检查", [
                'rule_id' => $task['rule_id'],
                'rule_type' => $rule ? $rule->rule_type : 'null',
                'content_count' => count($content),
                'first_item_url' => isset($content[0]['url']) ? $content[0]['url'] : 'not_set',
                'first_item_content' => isset($content[0]['content']) ? mb_substr($content[0]['content'], 0, 100) : 'not_set'
            ]);
            
            if ($rule && $rule->rule_type === 'collect_url_rewrite') {
                $first_item = $content[0] ?? null;
                $url_to_fetch = $first_item['url'] ?? '';
                
                $this->log_debug('COLLECT_URL_REWRITE_CHECK', "采集网址仿写检查", [
                    'url_to_fetch' => $url_to_fetch,
                    'url_empty' => empty($url_to_fetch) ? 'yes' : 'no'
                ]);
                
                // 简单判断：如果 url 字段非空 且 content字段为空，说明是待采集的 URL
                // 注意：由于我们现在保留了原始 URL (upload_text)，所以 url 字段总是有值的
                // 必须检查 content 是否已经从 Transient 中读取到了
                $has_content = !empty($first_item['content']);
                
                if (!empty($url_to_fetch) && !$has_content) {
                     $this->logger->log_info('WAIT_FOR_BROWSER', sprintf(__('规则项 %s 尚未采集内容，转入等待状态', 'yali-ai-writer'), $rule_item_id), ['url' => $url_to_fetch]);
                     
                     // 返回特殊状态，通知调用者挂起任务
                     return ['success' => false, 'status' => 'waiting_for_browser', 'message' => __('等待浏览器插件采集内容', 'yali-ai-writer')];
                }
            }
        }

        // 格式化内容为提示
        $prompt_content = $this->format_content_for_prompt($content, $task);

        // 仅在调试模式下记录完整提示词到日志文件
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_complete_prompt_to_file($prompt_content, $task, $subtask_id);
        }

        // [增强] API调用前更新心跳，标记即将进行长时间操作
        $this->update_task_heartbeat($task['id'], $subtask_id, 'BEFORE_API_CALL');

        // 使用API处理器生成主题
        $result = $this->api_handler->generate_topics($prompt_content, $task['topic_count_per_item'], $task['rule_id'], $subtask_id, $task['id'], $job_queue_id);

        // [增强] API调用后更新心跳，标记长时间操作已完成
        $this->update_task_heartbeat($task['id'], $subtask_id, 'AFTER_API_CALL');

        if (isset($result['error'])) {
            $error_details['stage'] = __('API调用', 'yali-ai-writer');
            $error_details['message'] = $result['error'];
            return ['success' => false, 'error' => $error_details];
        }

        $topics = $result;

        // [调试模式] 记录完整的API返回内容
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->logger->log_debug('TOPIC_API_RESPONSE', '主题生成API返回完整内容', [
                'task_id' => $task['id'],
                'subtask_id' => $subtask_id,
                'topics_count' => is_array($topics) ? count($topics) : 0,
                'response_content' => json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ]);
        }

        if ($topics && is_array($topics)) {
            // [增强] 保存主题前更新心跳，标记即将进行数据库操作
            $this->update_task_heartbeat($task['id'], $subtask_id, 'BEFORE_SAVE_TOPICS');
            
            // 传递 rule_item_id 给保存函数，以便建立正确的数据关联
            // 同时传递 content 的内容，以便保存到 reference_material
            $save_result = $this->save_generated_topics($topics, $task, $subtask_id, $rule_item_id, $content);
            
            // [增强] 保存完成后更新心跳
            $this->update_task_heartbeat($task['id'], $subtask_id, 'AFTER_SAVE_TOPICS');
            if ($save_result['success']) {
                $count = isset($save_result['saved_count']) ? $save_result['saved_count'] : 0;
                return ['success' => true, 'saved_count' => $count];
            } else {
                $error_details['stage'] = __('保存主题', 'yali-ai-writer');
                $error_details['message'] = $save_result['error'];
                return ['success' => false, 'error' => $error_details];
            }
        }

        $error_details['stage'] = __('API响应格式', 'yali-ai-writer');
        $error_details['message'] = __('API未返回有效的主题数组，或返回格式不正确', 'yali-ai-writer');
        return ['success' => false, 'error' => $error_details];
    }
    
    
        /**
     * 保存生成的主题
     */
    private function save_generated_topics($topics, $task, $subtask_id, $rule_item_id = null, $content = null) {
        // --- 自动搜索物料逻辑准备 ---
        $has_pending_search = false;
        $enable_auto_search = false;
        $saved_count = 0; // ✅ 初始化变量，修复 Bug
        
        // 检查是否是"采集网址仿写"规则
        $is_rewrite_rule = false;
        if (!empty($task['rule_id'])) {
             $rule = $this->database->get_row('content_auto_rules', array('id' => $task['rule_id']));
             // 注意：get_row 返回数组，使用数组访问方式
             if ($rule && isset($rule['rule_type']) && $rule['rule_type'] === 'collect_url_rewrite') {
                  $is_rewrite_rule = true;
             }
             
             // 调试日志
             $this->log_debug('SAVE_TOPICS_RULE_CHECK', '规则类型检查', [
                 'rule_id' => $task['rule_id'],
                 'rule_exists' => $rule ? 'YES' : 'NO',
                 'rule_type' => $rule['rule_type'] ?? 'null',
                 'is_rewrite_rule' => $is_rewrite_rule ? 'YES' : 'NO'
             ]);
        }
        
        // 获取发布规则配置
        $publish_rules = $this->database->get_row('content_auto_publish_rules', array('id' => 1));
        
        // 判断是否启用自动素材搜索：兼容旧字段和新字段
        // - 旧字段：enable_auto_material_search (0/1)
        // - 新字段：material_collection_mode (none/search_engine/extension_rag)
        $material_mode = !empty($publish_rules['material_collection_mode']) ? $publish_rules['material_collection_mode'] : 'none';
        // 如果是仿写规则，强制关闭自动搜索（因为已有内容）
        $is_auto_search_enabled = !$is_rewrite_rule && (!empty($publish_rules['enable_auto_material_search']) || $material_mode !== 'none');
        
        // 调试日志：记录自动搜索条件判断
        $this->logger->log_success('MATERIAL_SEARCH_DEBUG', '素材搜索条件检查', array(
            'enable_reference_material' => $publish_rules['enable_reference_material'] ?? 'null',
            'enable_auto_material_search' => $publish_rules['enable_auto_material_search'] ?? 'null',
            'material_collection_mode' => $material_mode,
            'is_auto_search_enabled' => $is_auto_search_enabled ? 'YES' : 'NO',
            'rule_id' => $task['rule_id'] ?? 'null',
            'is_rewrite_rule' => $is_rewrite_rule ? 'YES' : 'NO'
        ));
        
        if ($publish_rules && !empty($publish_rules['enable_reference_material']) && $is_auto_search_enabled) {
            // 检查规则级参考资料
            $rule_has_material = false;
            if (!empty($task['rule_id'])) {
                $rule = $this->database->get_row('content_auto_rules', array('id' => $task['rule_id']));
                if ($rule && !empty($rule['reference_material'])) {
                    $rule_has_material = true;
                    $this->logger->log_warning('MATERIAL_SEARCH_SKIP', '规则已有参考资料，跳过自动搜索', array(
                        'rule_id' => $task['rule_id'],
                        'reference_material_length' => strlen($rule['reference_material'])
                    ));
                }
            }
            
            // 只有当规则没有资料时，才启用自动搜索
            if (!$rule_has_material) {
                $enable_auto_search = true;
                $this->logger->log_success('MATERIAL_SEARCH_ENABLED', __('自动素材搜索已启用，将创建 material_search 任务', 'yali-ai-writer'));
            }
        } else {
            // 记录未启用的原因
            $this->logger->log_warning('MATERIAL_SEARCH_DISABLED', '自动素材搜索未启用', array(
                'publish_rules_exists' => $publish_rules ? 'YES' : 'NO',
                'enable_reference_material' => $publish_rules['enable_reference_material'] ?? 'null',
                'is_auto_search_enabled' => $is_auto_search_enabled ? 'YES' : 'NO'
            ));
        }
        // -----------------------

        // 获取目标分类（如果有）
        $target_cat_name = null;
        if (!empty($task['rule_id'])) {
            $rule = $this->database->get_row('content_auto_rules', array('id' => $task['rule_id']));
            if ($rule) {
                $conditions = maybe_unserialize($rule['rule_conditions']);
                if (!empty($conditions['target_category'])) {
                    $target_cat_name = get_cat_name($conditions['target_category']);
                }
            }
        }

        // 确保角度映射函数可用
        require_once __DIR__ . '/../prompt-templating/language-mappings.php';

        foreach ($topics as $topic) {
            $topic_data = null;

            if (is_string($topic) && !empty(trim($topic))) {
                $topic_data = [
                    'task_id' => $task['topic_task_id'],
                    'rule_id' => $task['rule_id'],
                    'rule_item_index' => $subtask_id,
                    'title' => trim($topic),
                    'status' => CONTENT_AUTO_TOPIC_UNUSED,
                    'material_search_status' => 'none' // ✅ 显式默认值
                ];
            } elseif (is_array($topic) && isset($topic['title'])) {
                if ($this->is_complete_topic_data($topic)) {
                    $topic_data = [
                        'task_id' => $task['topic_task_id'],
                        'rule_id' => $task['rule_id'],
                        'rule_item_index' => $rule_item_id ? intval($rule_item_id) : 0, // 确保是整数，不再回退到 subtask_id (字符串)
                        'title' => trim($topic['title']),
                        'status' => CONTENT_AUTO_TOPIC_UNUSED,
                        'source_angle' => content_auto_normalize_angle($topic['source_angle']),
                        'user_value' => $topic['user_value'],
                        'seo_keywords' => json_encode($topic['seo_keywords']),
                        'matched_category' => $topic['matched_category'],
                        'priority_score' => intval($topic['priority_score']),
                        'material_search_status' => 'none' // ✅ 显式默认值
                    ];
                    
                    // 如果是采集网址仿写，保存源URL和采集内容
                    if ($is_rewrite_rule && !empty($content) && is_array($content)) {
                        $first_content = reset($content); // 获取第一条内容
                        
                        // 保存源 URL 到 source_url 字段（用于去重，即使规则删除也保留）
                        if (!empty($first_content['url'])) {
                            $topic_data['source_url'] = $first_content['url'];
                        }
                        

                        
                        // 保存采集内容到参考资料字段
                        if (!empty($first_content['content'])) {
                             $topic_data['reference_material'] = $first_content['content'];
                        } elseif (!empty($first_content['upload_text']) && strpos($first_content['upload_text'], 'http') !== 0) {
                             // 兼容性处理：如果 content 字段为空 but upload_text 包含内容
                             $topic_data['reference_material'] = $first_content['upload_text'];
                        }
                    }
                    
                } else {
                    $error_message = sprintf(__('主题数据字段不完整: %s', 'yali-ai-writer'), json_encode($topic, JSON_UNESCAPED_UNICODE));
                    $this->logger->log_error('INCOMPLETE_TOPIC', $error_message);
                    return ['success' => false, 'error' => $error_message];
                }
            }

            // 如果存在目标分类，强制覆盖
            if ($topic_data && $target_cat_name) {
                $topic_data['matched_category'] = $target_cat_name;
            }

            if ($topic_data) {
                $this->add_api_config_to_topic($topic_data);
                
                // 如果满足自动搜索条件，标记为 pending
                if ($enable_auto_search) {
                    $topic_data['material_search_status'] = 'pending';
                }
                
                // 插入主题记录
                $inserted_topic_id = $this->database->insert('content_auto_topics', $topic_data);
                
                if ($inserted_topic_id) {
                    $saved_count++; // ✅ 记录实际成功保存的个数
                }
                
                // ✅ 根本性修复：在主题创建的同时，立即创建对应的 job_queue 任务
                // 一个主题 → 一个任务，不再需要后续的批量查询
                if ($inserted_topic_id && $enable_auto_search) {
                    global $wpdb;
                    $queue_table = $wpdb->prefix . 'content_auto_job_queue';
                    
                    // 直接插入，不再需要防重检查（因为这是主题首次创建）
                    // 统一语义：job_id = 父任务ID（无父任务时为 0），reference_id = 业务实体ID（主题ID）
                    // 注意：job_id 使用 0 而非 NULL，因为数据库字段约束为 NOT NULL
                    $queue_data = [
                        'job_type' => 'material_search',
                        'job_id' => 0,  // material_search 没有父任务表，设为 0（数据库不允许 NULL）
                        'subtask_id' => 'material_' . $inserted_topic_id,  // 使用 topic_id 作为唯一标识
                        'reference_id' => $inserted_topic_id,  // 主题 ID
                        'priority' => 20,  // 最低优先级（低于主题生成和文章生成）
                        'retry_count' => 0,
                        'status' => 'pending',
                        'error_message' => '',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ];
                    
                    $insert_result = $wpdb->insert($queue_table, $queue_data);
                    $insert_id = $wpdb->insert_id;
                    
                    // 记录任务创建结果
                    $this->logger->log_success('MATERIAL_SEARCH_QUEUE_INSERT', __('素材搜索任务已插入队列', 'yali-ai-writer'), array(
                        'topic_id' => $inserted_topic_id,
                        'queue_id' => $insert_id,
                        'insert_result' => $insert_result !== false ? 'SUCCESS' : 'FAILED',
                        'wpdb_error' => $wpdb->last_error ?: 'none'
                    ));
                    
                    $has_pending_search = true;
                }
            }
        }

        // 如果本次有创建待搜索任务，触发调度器
        if ($has_pending_search) {
            require_once dirname(__DIR__) . '/topic-management/class-material-search-manager.php';
            $search_manager = new ContentAuto_MaterialSearchManager();
            $search_manager->schedule_process();
        }
        
        // 如果没有成功保存任何主题，返回错误
        if ($saved_count === 0) {
            return ['success' => false, 'error' => __('API返回的主题数据不完整，或没有有效的主题被保存', 'yali-ai-writer')];
        }

        return ['success' => true, 'saved_count' => $saved_count]; // ✅ 返回实际成功的数量
    }
    
    /**
     * 格式化内容为提示
     */
    private function format_content_for_prompt($content, $task) {
        $prompt = '';
        
        // 动态选择模板：如果任务有特定规则类型，优先使用对应模板
        $selected_template = 'topic-generation-prompt.xml';
        $use_generic_db_template = true;
        
        // 检查规则类型
        if (isset($task['rule_id'])) {
            global $wpdb;
            $rule = $wpdb->get_row($wpdb->prepare("SELECT rule_type FROM {$wpdb->prefix}content_auto_rules WHERE id = %d", $task['rule_id']));
            if ($rule && $rule->rule_type === 'collect_url_rewrite') {
                $selected_template = 'topic-generation-prompt-rewrite.xml';
                $use_generic_db_template = false; // 强制使用文件模板，因为这是一个特殊的规则类型，通用DB模板不适用
            }
        }

        // 尝试从数据库获取启用的主题生成模板 (仅当允许使用通用模板时)
        if ($use_generic_db_template && class_exists('ContentAuto_TemplateManager')) {
            $template_manager = new ContentAuto_TemplateManager();
            $db_template_content = $template_manager->get_active_template_content('topic_generation');
            
            if ($db_template_content) {
                $prompt = $db_template_content;
            }
        }

        // 如果没有数据库模板，回退到文件系统
        if (empty($prompt)) {
            // 如果不是强制指定的模板（如仿写规则），则随机选择
            if ($use_generic_db_template) {
                $available_templates = [
                    'topic-generation-prompt.xml',
                    'topic1-generation-prompt.xml'
                ];
                $selected_template = $available_templates[array_rand($available_templates)];
            }
            
            $template_path = __DIR__ . '/../prompt-templating/' . $selected_template;
            
            if (!file_exists($template_path)) {
                // 如果特定模板不存在，回退到默认模板
                $fallback_template = 'topic-generation-prompt.xml';
                $template_path = __DIR__ . '/../prompt-templating/' . $fallback_template;
                
                if (!file_exists($template_path)) {
                     $this->logger->log_error('TEMPLATE_MISSING', '提示词模板文件未找到: ' . $template_path);
                     return "模板加载失败，请检查插件文件完整性。";
                }
            }
            
            $prompt = file_get_contents($template_path);
            
            // 记录使用的模板文件
            $this->logger->log_info('TEMPLATE_SELECTED', '已加载提示词模板', ['path' => $selected_template]);
        }
        
        // 动态生成内容块
        $reference_content_block = $this->build_reference_content_block($content);
        $existing_topics_block = $this->build_existing_topics_block();
        $site_categories_block = $this->build_site_categories_block();
        
        // 获取发布语言设置
        $database = new ContentAuto_Database();
        $publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));
        $publish_language = isset($publish_rule['publish_language']) ? $publish_rule['publish_language'] : 'zh-CN';
        
        // 引入语言映射文件
        require_once __DIR__ . '/../prompt-templating/language-mappings.php';
        $validated_language = content_auto_validate_language_code($publish_language);
        $language_instruction = content_auto_get_language_instructions($validated_language);
        $language_ai_name = content_auto_get_language_ai_name($validated_language);
        
        // 仅在调试模式下添加调试日志
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->logger->log_success('LANGUAGE_DEBUG', '语言设置调试信息', array(
                'publish_language' => $publish_language,
                'validated_language' => $validated_language,
                'language_instruction_preview' => substr($language_instruction, 0, 100) . '...',
                'selected_template' => $selected_template
            ));
        }
        
        // 获取参考资料块
        $reference_material_block = $this->build_reference_material_block($content, $task['rule_id']);
        
        // 获取搜索意图推断块
        $intent_inference_block = $this->build_intent_inference_block($publish_rule);
        
        // 替换占位符
        $replacements = array(
            '{{REFERENCE_CONTENT_BLOCK}}' => $reference_content_block,
            '{{REFERENCE_MATERIAL_BLOCK}}' => $reference_material_block,
            '{{EXISTING_TOPICS_BLOCK}}' => $existing_topics_block,
            '{{SITE_CATEGORIES_BLOCK}}' => $site_categories_block,
            '{{LANGUAGE_INSTRUCTION}}' => $language_instruction,
            '{{LANGUAGE_NAME}}' => $language_ai_name,
            '{{CURRENT_DATE}}' => date('Y年m月d日'),
            '{{INTENT_INFERENCE_BLOCK}}' => $intent_inference_block
        );
        
        $final_prompt = str_replace(array_keys($replacements), array_values($replacements), $prompt);
        
        // 仅在调试模式下记录提示词替换调试信息
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $language_found = strpos($final_prompt, $language_instruction) !== false;
            $placeholder_remaining = strpos($final_prompt, '{{LANGUAGE_INSTRUCTION}}') !== false;
            
            $this->logger->log_success('PROMPT_DEBUG', '提示词替换调试信息', array(
                'language_instruction_found' => $language_found ? 'YES' : 'NO',
                'placeholder_remaining' => $placeholder_remaining ? 'YES' : 'NO',
                'final_prompt_length' => strlen($final_prompt),
                'final_prompt_preview' => substr($final_prompt, 0, 200) . '...'
            ));
        }
        
        return $final_prompt;
    }
    
    /**
     * 检查是否应该创建新任务
     */
    private function should_create_new_task($rule_id) {
        global $wpdb;

        // 1. [新增] 严格检查子任务队列状态
        // 只要该规则关联的任一主任务下，还有未完成的子任务（包括等待浏览器采集的 waiting_browser），严禁创建新任务
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
        
        $active_subtasks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$job_queue_table} tq
             JOIN {$topic_tasks_table} tt ON tq.job_id = tt.id
             WHERE tt.rule_id = %d
             AND tq.job_type = 'topic_task'
             AND tq.status IN ('pending', 'processing', 'running', 'waiting_browser')",
             $rule_id
        ));
        
        if ($active_subtasks > 0) {
             $this->logger->log_warning('DUPLICATE_TASK_SUB', "规则 {$rule_id} 仍有 {$active_subtasks} 个活跃子任务（包含 waiting_browser），拒绝创建新任务");
             return false;
        }
        
        // 2. [原有] 检查主任务状态
        $task_timeout = 30 * 60; // 30分钟超时
        $existing_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}content_auto_topic_tasks 
            WHERE rule_id = %d AND status IN ('pending', 'processing')",
            $rule_id
        ));
        
        if (!empty($existing_tasks)) {
            foreach ($existing_tasks as $existing_task) {
                $updated_time = strtotime($existing_task->updated_at) - get_option('gmt_offset') * 3600;
                $current_time = current_time('timestamp', true);
                $time_diff = $current_time - $updated_time;
                
                if ($time_diff < $task_timeout) {
                    $this->logger->log_warning('DUPLICATE_TASK', "发现相同规则的活跃任务，跳过创建新任务: rule_id={$rule_id}");
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * 检查任务是否可处理
     */
    private function is_task_processable($task) {
        $valid_statuses = array(CONTENT_AUTO_STATUS_PENDING, CONTENT_AUTO_STATUS_PROCESSING);
        
        if (!in_array($task['status'], $valid_statuses)) {
            // 尝试恢复不一致的状态
            if ($this->recovery_handler->recover_inconsistent_task_state($task['id'])) {
                $this->logger->log_success('TASK_RECOVERED', '任务状态已恢复');
                // 重新获取任务信息
                $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task['id']));
                return $task && in_array($task['status'], $valid_statuses);
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查并发控制
     */
    private function check_concurrency_control($task) {
        global $wpdb;
        
        $concurrent_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}content_auto_topic_tasks 
            WHERE rule_id = %d AND status = 'processing' AND id != %d",
            $task['rule_id'], $task['id']
        ));
        
        if (!empty($concurrent_tasks)) {
            $this->logger->log_warning('CONCURRENT_TASK', "发现相同规则的并发任务，跳过处理: task_id={$task['id']}");
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取当前子任务ID
     */
    private function get_current_subtask_id($task_id, $task) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        
        // 首先检查是否有正在处理的子任务
        $processing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT subtask_id FROM {$queue_table} 
            WHERE job_type = 'topic_task' AND job_id = %d AND status = %s 
            ORDER BY created_at ASC LIMIT 1",
            $task_id, CONTENT_AUTO_STATUS_PROCESSING
        ));
        
        if ($processing_record && $processing_record->subtask_id) {
            return $processing_record->subtask_id;
        }
        
        // 然后获取下一个待处理的子任务
        $pending_record = $wpdb->get_row($wpdb->prepare(
            "SELECT subtask_id FROM {$queue_table} 
            WHERE job_type = 'topic_task' AND job_id = %d AND status = %s 
            ORDER BY created_at ASC LIMIT 1",
            $task_id, CONTENT_AUTO_STATUS_PENDING
        ));
        
        return $pending_record && $pending_record->subtask_id ? $pending_record->subtask_id : null;
    }
    
    /**
     * 处理成功的任务处理
     */
    private function handle_successful_processing($task_id, $task, $subtask_id, $actual_saved_count = 0) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 1. 更新队列中的子任务状态为 completed
        $wpdb->update($queue_table,
            ['status' => 'completed', 'updated_at' => current_time('mysql'), 'error_message' => ''],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务进度
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // ✅ 使用实际生成的数量累计，而非任务预期的每项生成数
        $new_generated_count = intval($task['generated_topics_count']) + $actual_saved_count;
        
        $this->database->update('content_auto_topic_tasks', array(
            'current_processing_item' => $processed_count,
            'generated_topics_count' => $new_generated_count,
            'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
            'last_processed_at' => current_time('mysql')
        ), array('id' => $task_id));
        
        $this->logger->log_success('SUBTASK_COMPLETED', sprintf(__('子任务完成 (生成数量: %1$d)，等待下次处理: task_id=%2$s, subtask_id=%3$s', 'yali-ai-writer'), $actual_saved_count, $task_id, $subtask_id));

        // 3. 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, $task);
    }
    
    /**
     * 处理失败的任务处理
     */
    private function handle_failed_processing($task_id, $task, $subtask_id, $error_details) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        // 构建错误对象
        $error_stage = $error_details['stage'] ?? __('未知', 'yali-ai-writer');
        $error_detail_str = is_array($error_details['message']) ? json_encode($error_details['message'], JSON_UNESCAPED_UNICODE) : $error_details['message'];
        
        $error_message = sprintf(
            __('子任务 %1$s 处理失败. 阶段: %2$s, 详情: %3$s', 'yali-ai-writer'), 
            $subtask_id, 
            $error_stage, 
            $error_detail_str
        );
        
        // 1. 更新队列中的子任务状态为 failed
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务的进度和错误信息，但不立即改变主任务状态
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = sprintf(__('子任务 %s 处理失败', 'yali-ai-writer'), $subtask_id);
        
        $this->database->update('content_auto_topic_tasks',
            [
                'error_message' => $main_task_error,
                'current_processing_item' => $processed_count,
                'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
                'last_processed_at' => current_time('mysql')
            ],
            ['id' => $task_id]
        );

        $this->logger->log_error('SUBTASK_FAILED', $error_message, ['task_id' => $task_id, 'subtask_id' => $subtask_id]);

        // 3. 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, $task);

        return $error_message;
    }
    
    /**
     * 处理异常
     */
    private function handle_exception($task_id, $task, $subtask_id, $exception) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        $error_detail_message = sprintf(
            __("在文件 %1\$s 的第 %2\$d 行发生异常: %3\$s", 'yali-ai-writer'),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage()
        );
        $error_message = sprintf(
            __('子任务 %1$s 处理失败. 阶段: 系统异常, 详情: %2$s', 'yali-ai-writer'),
            $subtask_id,
            $error_detail_message
        );

        // 1. 更新队列子任务状态
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = sprintf(__('子任务 %s 处理失败', 'yali-ai-writer'), $subtask_id);
        
        $this->database->update('content_auto_topic_tasks',
            [
                'error_message' => $main_task_error,
                'current_processing_item' => $processed_count,
                'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending
                'last_processed_at' => current_time('mysql')
            ],
            ['id' => $task_id]
        );

        $this->logger->log_error('TASK_EXCEPTION', $error_message, ['task_id' => $task_id, 'subtask_id' => $subtask_id]);

        // 3. 检查是否完成
        $this->finalize_task_status_if_completed($task_id, $task);

        return $error_message;
    }

    private function finalize_task_status_if_completed($task_id, $task) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 使用 >= 是为了防止在某些边缘情况下计数不精确
        $processed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')",
            $task_id
        ));

        if ($processed_count >= $task['total_rule_items']) {
            $failed_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status = 'failed'",
                $task_id
            ));

            // 如果有任何失败的子任务，则整个任务失败；否则，任务完成
            $final_status = ($failed_count > 0) ? CONTENT_AUTO_STATUS_FAILED : CONTENT_AUTO_STATUS_COMPLETED;
            
            $this->database->update('content_auto_topic_tasks',
                ['status' => $final_status],
                ['id' => $task_id]
            );
            $this->logger->log_success('TASK_FINALIZED', sprintf(__('主题任务处理完成，最终状态: %s', 'yali-ai-writer'), $final_status), ['task_id' => $task_id]);
        }
    }
    
    // ==============================================
    // 任务管理接口方法
    // ==============================================
    
    /**
     * 获取任务
     */
    public function get_task($task_id) {
        return $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
    }
    
    /**
     * 获取所有任务（支持状态筛选）
     * 
     * @param string|null $status 状态筛选条件
     * @return array 任务列表
     */
    public function get_tasks($status = null) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_topic_tasks';
        
        if ($status) {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table_name WHERE status = %s ORDER BY updated_at DESC", $status), 
                ARRAY_A
            );
        } else {
            return $wpdb->get_results("SELECT * FROM $table_name ORDER BY updated_at DESC", ARRAY_A);
        }
    }
    
    /**
     * 获取任务状态
     */
    public function get_task_status($task_id) {
        return $this->status_manager->get_task_status($task_id);
    }
    
    /**
     * 获取任务进度信息
     */
    public function get_task_progress($task_id) {
        // 首先尝试按ID查询（数字）
        $task = $this->get_task($task_id);
        
        // 如果按ID查询失败，尝试按topic_task_id查询（字符串）
        if (!$task) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'content_auto_topic_tasks';
            $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE topic_task_id = %s", $task_id), ARRAY_A);
        }
        
        if (!$task) {
            return false;
        }
        
        return array(
            'current_item' => $task['current_processing_item'],
            'total_items' => $task['total_rule_items'],
            'generated_topics' => $task['generated_topics_count'],
            'expected_topics' => $task['total_expected_topics'],
            'progress_percentage' => $task['total_rule_items'] > 0 ? 
                round(($task['current_processing_item'] / $task['total_rule_items']) * 100, 2) : 0
        );
    }
    
    /**
     * 暂停任务
     */
    public function pause_task($task_id) {
        return $this->status_manager->safe_update_task_status($task_id, 'paused', __('用户暂停任务', 'yali-ai-writer'));
    }
    
    /**
     * 重试任务
     */
    public function retry_task($task_id, $subtask_id = null) {
        return $this->recovery_handler->retry_task($task_id, $subtask_id);
    }
    
    /**
     * 删除任务
     * 根据topic_task_id删除父任务及其相关的非成功状态子任务
     * 注意：已生成的主题数据和成功完成的子任务会被保留
     * 
     * @param string $topic_task_id 主题任务的唯一标识符
     * @return bool 删除是否成功
     */
    public function delete_task($topic_task_id) {
        global $wpdb;
        
        try {
            // 1. 首先根据topic_task_id找到父任务信息
            $task = $this->database->get_row('content_auto_topic_tasks', array('topic_task_id' => $topic_task_id));
            if (!$task) {
                // 任务可能已被其他进程删除
                return true; // 返回 true，因为目标（任务不存在）已达成
            }
            
            // 防御性检查：确保 $task 包含 id
            $task_id = isset($task['id']) ? intval($task['id']) : 0;
            if ($task_id <= 0) {
                $this->logger->log_warning('DELETE_TASK_INVALID', '任务记录无效，跳过清理', array(
                    'topic_task_id' => $topic_task_id,
                    'task_data' => $task
                ));
                return false;
            }
            
            // 2. 使用统一清理器清理所有相关队列项
            require_once dirname(__DIR__) . '/shared/queue/class-task-queue-cleaner.php';
            $cleaner = new ContentAuto_TaskQueueCleaner();
            $cleanup_stats = $cleaner->cleanup_by_task_id($task_id, 'topic_task');
            
            $this->logger->log_info('TASK_DELETE_CLEANUP', '删除任务时执行了队列清理', array(
                'task_id' => $task_id,
                'cleanup_stats' => $cleanup_stats
            ));
            
            // 3. 删除任务记录本身
            $result = $this->database->delete('content_auto_topic_tasks', array('topic_task_id' => $topic_task_id));
            
            return $result !== false;
            
        } catch (Exception $e) {
            // 捕获任何异常，记录日志并返回失败
            $this->logger->log_error('DELETE_TASK_ERROR', '删除任务时发生异常', array(
                'topic_task_id' => $topic_task_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            return false;
        }
    }
    
    /**
     * 自动恢复挂起的任务
     */
    public function auto_recover_hanging_tasks() {
        return $this->recovery_handler->auto_recover_hanging_tasks();
    }
    
    /**
     * 智能重试任务
     */
    public function smart_retry_task($task_id) {
        return $this->recovery_handler->smart_retry_task($task_id);
    }
    
    // ==============================================
    // 状态管理委托方法
    // ==============================================
    
    public function validate_status($status) {
        return $this->status_manager->validate_status($status);
    }
    
    public function normalize_status($status) {
        return $this->status_manager->normalize_status($status);
    }
    
    public function get_status_label($status) {
        return $this->status_manager->get_status_label($status);
    }
    
    public function get_all_valid_statuses() {
        return $this->status_manager->get_all_valid_statuses();
    }
    
    public function safe_update_task_status($task_id, $new_status, $reason = '') {
        return $this->status_manager->safe_update_task_status($task_id, $new_status, $reason);
    }
    
    // ==============================================
    // 私有辅助方法
    // ==============================================
    
    /**
     * 检查主题数据完整性
     */
    private function is_complete_topic_data($topic) {
        // 移除 'matched_category'，允许其为空字符串
        $required_fields = array('title', 'source_angle', 'user_value', 'seo_keywords', 'priority_score');
        
        foreach ($required_fields as $field) {
            // 对于必需字段，要求既存在又不为空
            if (!isset($topic[$field]) || (is_string($topic[$field]) && trim($topic[$field]) === '') || (is_array($topic[$field]) && empty($topic[$field]))) {
                return false;
            }
        }
        
        // 单独检查 matched_category 是否存在（允许为空）
        if (!isset($topic['matched_category'])) {
            return false;
        }

        return true;
    }
    
    /**
     * 添加API配置信息到主题数据
     */
    private function add_api_config_to_topic(&$topic_data) {
        $current_api_config = $this->api_handler->get_current_api_config();
        if ($current_api_config) {
            $topic_data['api_config_id'] = $current_api_config['id'];
            $topic_data['api_config_name'] = $current_api_config['name'];
        }
    }
    
    /**
     * 构建引用内容块
     */
    private function build_reference_content_block($content) {
        $reference_content_block = '';
        foreach ($content as $item) {
            if (isset($item['upload_text']) && !empty($item['upload_text'])) {
                $reference_content_block .= "    <reference_content>\n";
                $reference_content_block .= "      <upload_text>" . htmlspecialchars($item['upload_text']) . "</upload_text>\n";
                $reference_content_block .= "    </reference_content>\n";
            } elseif (isset($item['keyword']) && !empty($item['keyword'])) {
                // 关键词类型的内容
                $reference_content_block .= "    <reference_content>\n";
                $reference_content_block .= "      <keyword>" . htmlspecialchars($item['keyword']) . "</keyword>\n";
                if (isset($item['cycle'])) {
                    $reference_content_block .= "      <cycle>第" . ($item['cycle'] + 1) . "轮循环</cycle>\n";
                }
                $reference_content_block .= "    </reference_content>\n";
            } elseif (isset($item['category_name']) && !empty($item['category_name'])) {
                // 随机分类规则的内容
                $reference_content_block .= "    <reference_content>\n";
                $reference_content_block .= "      <category_name>" . htmlspecialchars($item['category_name']) . "</category_name>\n";
                if (!empty($item['category_description'])) {
                    $reference_content_block .= "      <category_description>" . htmlspecialchars($item['category_description']) . "</category_description>\n";
                }
                $reference_content_block .= "    </reference_content>\n";
            } elseif (isset($item['content']) && !empty($item['content'])) {
                // 已采集的任务内容或上传的文本内容 (优先级高于纯URL)
                $reference_content_block .= "    <reference_content>\n";
                if (!empty($item['title'])) {
                    $reference_content_block .= "      <title>" . htmlspecialchars($item['title']) . "</title>\n";
                } elseif (!empty($item['post_title'])) {
                     $reference_content_block .= "      <title>" . htmlspecialchars($item['post_title']) . "</title>\n";
                }
                $reference_content_block .= "      <content>" . htmlspecialchars($item['content']) . "</content>\n";
                $reference_content_block .= "    </reference_content>\n";
            } else {
                // 回退到默认文章/分类逻辑
                $reference_content_block .= "    <current_category>\n";
                if (!empty($item['category_names'])) {
                    $reference_content_block .= "      <name>" . htmlspecialchars($item['category_names']) . "</name>\n";
                }
                if (!empty($item['category_descriptions'])) {
                    $reference_content_block .= "      <description>" . htmlspecialchars($item['category_descriptions']) . "</description>\n";
                }
                $reference_content_block .= "    </current_category>\n    \n";
                $reference_content_block .= "    <reference_content>\n";
                $reference_content_block .= "      <title>" . htmlspecialchars($item['title']) . "</title>\n";
                if (!empty($item['content'])) {
                    $reference_content_block .= "      <content>" . htmlspecialchars($item['content']) . "</content>\n";
                }
                $reference_content_block .= "    </reference_content>\n";
            }
        }
        return $reference_content_block;
    }
    
    /**
     * 构建已存在主题块
     */
    private function build_existing_topics_block() {
        $existing_topics_block = '';
        $existing_topics = $this->get_existing_topics();
        foreach ($existing_topics as $topic) {
            $existing_topics_block .= "      " . htmlspecialchars($topic) . "\n";
        }
        return $existing_topics_block;
    }
    
    /**
     * 构建网站分类块
     */
    private function build_site_categories_block() {
        $site_categories_block = '';
        $site_categories = $this->get_site_categories();
        foreach ($site_categories as $category) {
            $site_categories_block .= "      " . htmlspecialchars($category) . "\n";
        }
        return $site_categories_block;
    }
    
    /**
     * 获取已存在的未使用主题
     */
    private function get_existing_topics($limit = 5) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title FROM {$topics_table} WHERE status IN (%s, %s) ORDER BY created_at DESC LIMIT %d",
                CONTENT_AUTO_TOPIC_UNUSED,
                CONTENT_AUTO_TOPIC_QUEUED,
                $limit * 6  // 增加候选数量，从3倍提高到6倍（5*6=30个候选）
            ),
            ARRAY_A
        );
        
        $topics = array();
        foreach ($results as $row) {
            $topics[] = $row['title'];
        }
        
        return $this->simple_deduplicate($topics, $limit);
    }
    
    /**
     * 简单去重处理
     */
    private function simple_deduplicate($topics, $limit) {
        if (count($topics) <= 1) {
            return $topics;
        }
        
        $unique_topics = array();
        $used_titles = array();
        
        foreach ($topics as $title) {
            $is_duplicate = false;
            
            foreach ($used_titles as $used_title) {
                if ($this->calculate_similarity($title, $used_title) > 0.8) {
                    $is_duplicate = true;
                    break;
                }
            }
            
            if (!$is_duplicate && count($unique_topics) < $limit) {
                $unique_topics[] = $title;
                $used_titles[] = $title;
            }
            
            if (count($unique_topics) >= $limit) {
                break;
            }
        }
        
        return empty($unique_topics) && !empty($topics) ? array($topics[0]) : $unique_topics;
    }
    
    /**
     * 计算字符串相似度 - 使用向量和余弦相似度（如果可用），否则回退到字符相似度
     */
    private function calculate_similarity($str1, $str2) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 尝试使用向量计算余弦相似度
        $topic1 = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vector_embedding FROM {$topics_table} WHERE title = %s AND vector_embedding IS NOT NULL AND vector_embedding != '' LIMIT 1",
            $str1
        ), ARRAY_A);
        
        $topic2 = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vector_embedding FROM {$topics_table} WHERE title = %s AND vector_embedding IS NOT NULL AND vector_embedding != '' LIMIT 1", 
            $str2
        ), ARRAY_A);
        
        // 检查两个主题是否都有有效的向量数据
        if ($topic1 && $topic2 && !empty($topic1['vector_embedding']) && !empty($topic2['vector_embedding'])) {
            // 如果两个标题都有向量数据，使用余弦相似度计算
            require_once dirname(__DIR__) . '/shared/common/functions.php';
            
            $vector1 = content_auto_decompress_vector_from_base64($topic1['vector_embedding']);
            $vector2 = content_auto_decompress_vector_from_base64($topic2['vector_embedding']);
            
            if ($vector1 !== false && $vector2 !== false) {
                $similarity = content_auto_calculate_cosine_similarity($vector1, $vector2);
                return max(0, $similarity); // 确保返回非负值
            }
        }
        
        // 如果向量计算失败或没有向量数据，回退到基于字符的相似度计算
        similar_text($str1, $str2, $percent);
        return $percent / 100;
    }
    
    /**
     * 获取网站分类列表
     */
    private function get_site_categories() {
        // 使用分类过滤器获取允许的分类
        if (class_exists('ContentAuto_Category_Filter')) {
            $categories = ContentAuto_Category_Filter::get_filtered_categories(array(
                'hide_empty' => false,
                'number' => 50
            ));
        } else {
            $categories = get_categories(array(
                'hide_empty' => false,
                'number' => 50
            ));
        }
        
        $category_list = array();
        foreach ($categories as $category) {
            $category_list[] = $category->name;
        }
        
        return $category_list;
    }
    
    /**
     * 将任务添加到队列
     * 为每个规则项目创建独立的队列项，支持唯一ID子任务处理模式
     */
    private function add_to_queue($task_id) {
        // 获取任务信息
        $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
        if (!$task) {
            return false;
        }
        
        // 获取规则信息
        global $wpdb;
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT rule_type, rule_conditions FROM {$rules_table} WHERE id = %d", $task['rule_id']));
        $is_collect_url_rewrite = ($rule && $rule->rule_type === 'collect_url_rewrite');
        
        // 获取采集选项（用于 collect_url_rewrite 规则）
        $collect_options = array('keep_images' => false, 'keep_links' => false);
        if ($is_collect_url_rewrite && $rule->rule_conditions) {
            $conditions = maybe_unserialize($rule->rule_conditions);
            if (isset($conditions['collect_options'])) {
                $collect_options = array_merge($collect_options, $conditions['collect_options']);
            }
        }
        
        // 调试日志
        $this->log_debug('ADD_TO_QUEUE_START', '开始添加任务到队列', [
            'task_id' => $task_id,
            'rule_id' => $task['rule_id'],
            'rule_type' => $rule ? $rule->rule_type : 'null',
            'is_collect_url_rewrite' => $is_collect_url_rewrite ? 'YES' : 'NO'
        ]);
        
        // 获取规则的所有项目
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        $rule_items = $wpdb->get_results($wpdb->prepare(
            "SELECT id, upload_text FROM {$rule_items_table} WHERE rule_id = %d ORDER BY id",
            $task['rule_id']
        ));
        
        if (empty($rule_items)) {
            return false;
        }
        
        // 为每个规则项目创建队列项
        $queue_ids = array();
        foreach ($rule_items as $rule_item) {
            $subtask_id = 'subtask_' . uniqid();
            
            // 预处理文本 (去除首尾空格，防止 http 前有空格导致 fetch 失败)
            $item_text = trim($rule_item->upload_text);
            $upload_text_preview = mb_substr($item_text, 0, 100);
            
            // 智能判断是否为待采集URL：
            // 1. 标准URL格式 (http/https 开头)
            // 2. 也是URL特征 (www. 开头)
            // 3. 仿写规则下的简短无换行文本 (视为省略协议头的URL) - 兼容用户输入不规范
            $is_standard_url = (strpos($item_text, 'http') === 0) || (strpos($item_text, 'www.') === 0);
            $is_short_text = (iconv_strlen($item_text, 'UTF-8') < 500 && strpos($item_text, "\n") === false);
            
            $is_pending_url = $is_collect_url_rewrite && ($is_standard_url || $is_short_text);
            
            // 调试日志
            $this->log_debug('QUEUE_ITEM_CHECK', '检查规则项目', [
                'rule_item_id' => $rule_item->id,
                'upload_text_preview' => $upload_text_preview,
                'upload_text_length' => strlen($item_text),
                'is_standard_url' => $is_standard_url ? 'YES' : 'NO',
                'is_pending_url' => $is_pending_url ? 'YES' : 'NO'
            ]);
            
            // 设置初始状态：如果是待采集 URL
            $initial_status = CONTENT_AUTO_STATUS_PENDING;
            if ($is_pending_url) {
                // [修复] 关键逻辑：先检查是否已经采集到了内容（由插件回传后存入 Transient）
                // 如果已经有内容了，就设为 pending 等待 AI 处理；否则才设为 waiting_browser 等待插件
                $has_collected_content = (bool) get_transient('cam_fetched_content_' . $rule_item->id);
                $initial_status = $has_collected_content ? CONTENT_AUTO_STATUS_PENDING : 'waiting_browser';
            }
            
            $data = array(
                'job_type' => 'topic_task',
                'job_id' => $task_id,
                'subtask_id' => $subtask_id,
                'reference_id' => $rule_item->id,  // reference_id存储规则项目ID
                'priority' => 80, // 主题生成优先级（高于文章生成）
                'retry_count' => 0,
                'status' => $initial_status,
                'error_message' => '',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );

            $queue_id = $this->database->insert('content_auto_job_queue', $data);
            if ($queue_id) {
                $queue_ids[] = $queue_id;
                
                // 如果是待采集 URL，同时写入浏览器插件队列（类似知识库搜索的机制）
                // 此时 status='waiting_browser'，由插件抓取后回调触发下一步
                // 注意：传入 trim 后的 item_text 作为 URL
                if ($is_pending_url) {
                    $this->add_to_extension_queue($queue_id, $item_text, $rule_item->id, $collect_options);
                }
            }
        }
        
        return !empty($queue_ids);
    }
    
    /**
     * 将采集任务写入浏览器插件队列
     * 与知识库搜索使用相同的机制，确保浏览器插件能立即接收到任务
     */
    private function add_to_extension_queue($queue_id, $url, $rule_item_id, $collect_options = array()) {
        $queue_key = 'cam_extension_task_queue';
        $queue = get_option($queue_key, array());
        
        // 清理旧任务（防止膨胀）
        if (count($queue) > 50) {
            $queue = array_slice($queue, -20, null, true);
        }
        
        // 设置默认采集选项
        $options = array_merge(
            array('keep_images' => false, 'keep_links' => false),
            $collect_options
        );
        
        $task_id = 'fetch_' . $queue_id;
        $queue[$task_id] = array(
            'id' => $task_id,
            'type' => 'content_fetch',
            'payload' => array(
                'url' => $url,
                'rule_item_id' => $rule_item_id,
                'queue_id' => $queue_id,
                'options' => array(
                    'keepImages' => (bool) $options['keep_images'],
                    'keepLinks' => (bool) $options['keep_links']
                )
            ),
            'status' => 'pending',
            'created_at' => time()
        );
        
        $saved = update_option($queue_key, $queue);
        
        $this->logger->log_info('EXTENSION_QUEUE_ADD', '已写入浏览器插件采集任务', array(
            'task_id' => $task_id,
            'url' => $url,
            'queue_size' => count($queue),
            'save_success' => $saved ? 'YES' : 'NO'
        ));
        
        return $saved;
    }
    
    /**
     * 构建参考资料块
     * 根据规则类型决定是否插入参考资料
     */
    private function build_reference_material_block($content, $rule_id) {
        // 首先获取规则信息
        if (empty($content) || !isset($content[0])) {
            return '';
        }
        
        $first_item = $content[0];
        
        // 任何规则类型，只要有参考资料，都允许通过
        // 移除旧后的白名单限制 (keyword, category_name)
        // 只要 $first_item 中包含 reference_material 且不为空，就使用它
        if (isset($first_item['reference_material']) && !empty($first_item['reference_material'])) {
            $reference_material = $first_item['reference_material'];
        } else {
            return '';
        }
        
        // 获取规则的参考资料
        global $wpdb;
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        
        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT reference_material FROM {$rules_table} WHERE id = %d",
            $rule_id
        ));
        
        if (!$rule || empty(trim($rule->reference_material))) {
            return '';
        }
        
        // 格式化参考资料块
        $reference_material_block = "    <reference_material>\n";
        $reference_material_block .= "      <content>" . htmlspecialchars(trim($rule->reference_material)) . "</content>\n";
        $reference_material_block .= "    </reference_material>\n";
        
        return $reference_material_block;
    }
    
    /**
     * 构建搜索意图推断块
     * 根据发布规则设置决定是否启用意图推断增强
     */
    private function build_intent_inference_block($publish_rule) {
        // 检查是否启用搜索意图推断
        $enable_intent_inference = isset($publish_rule['enable_intent_inference']) ? intval($publish_rule['enable_intent_inference']) : 0;
        
        if (!$enable_intent_inference) {
            return '';
        }
        
        // 返回意图推断增强提示词块
        $intent_block = <<<'XML'

  <intent_inference_enhancement>
    <instruction>【搜索意图推断】在生成标题前，必须先完成以下意图分析：</instruction>
    
    <analysis_step_1>
      基于源内容，推断用户可能的2-4个不同搜索意图方向：
      - 信息获取型：想了解概念、原理、背景知识
      - 问题解决型：遇到具体问题，寻求解决方案
      - 对比决策型：在多个选项间做选择，需要比较信息
      - 学习提升型：想掌握技能、方法、最佳实践
      - 资源获取型：寻找工具、模板、资源推荐
    </analysis_step_1>
    
    <analysis_step_2>针对每个推断出的意图方向，思考用户会在搜索引擎中输入什么样的查询词或问题</analysis_step_2>
    
    <analysis_step_3>基于这些真实搜索场景构思标题，确保标题使用用户可能搜索的自然语言表达</analysis_step_3>
    
    <requirement>生成的标题必须覆盖不同的意图方向，每个标题对应一个明确的用户搜索意图，优先使用用户可能在搜索引擎中输入的自然语言表达方式</requirement>
  </intent_inference_enhancement>
XML;
        
        return $intent_block;
    }
    
    /**
     * 仅在调试模式下记录调试日志
     * 
     * @param string $code 日志代码
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private function log_debug($code, $message, $context = []) {
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->logger->log_debug($code, $message, $context);
        }
    }

    /**
     * 记录完整提示词到统一日志系统
     */
    private function log_complete_prompt_to_file($prompt_content, $task, $subtask_id) {
        try {
            // 仅在调试模式下记录完整提示词
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                // 使用统一的日志系统记录完整提示词
                $context = array(
                    'type' => 'TOPIC_PROMPT',
                    'task_id' => $task['id'],
                    'topic_task_id' => $task['topic_task_id'],
                    'rule_id' => $task['rule_id'],
                    'subtask_id' => $subtask_id,
                    'prompt_length' => strlen($prompt_content),
                    'prompt_content' => $prompt_content
                );
                
                $this->logger->log_debug('COMPLETE_PROMPT', __('主题生成提示词完整内容', 'yali-ai-writer'), $context);
            }
        } catch (Exception $e) {
            $this->logger->log_error('PROMPT_LOG_FAILED', __('提示词日志记录失败: ', 'yali-ai-writer') . $e->getMessage());
        }
    }
}