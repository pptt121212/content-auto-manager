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
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';

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
    public function process_topic_task($task_id, $subtask_id = null) {
        $context = $this->logger->build_context(null, null, array('task_id' => $task_id));
        $this->logger->log_success('TASK_START', '开始处理主题任务', $context);
        
        // 获取任务信息
        $task = $this->database->get_row('content_auto_topic_tasks', array('id' => $task_id));
        if (!$task) {
            $error_message = '主题任务不存在: ' . $task_id;
            $this->logger->log_error('TASK_NOT_FOUND', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 检查任务状态
        if (!$this->is_task_processable($task)) {
            $error_message = "任务 (ID: {$task_id}) 状态不可处理或恢复失败，当前状态: {$task['status']}";
            $this->logger->log_warning('TASK_NOT_PROCESSABLE', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 并发控制检查
        if (!$this->check_concurrency_control($task)) {
            $error_message = "发现相同规则 (Rule ID: {$task['rule_id']}) 的并发任务，本任务 (ID: {$task_id}) 跳过处理";
            $this->logger->log_warning('CONCURRENT_TASK_SKIPPED', $error_message);
            return ['success' => false, 'message' => $error_message];
        }
        
        // 获取当前处理的子任务ID
        if ($subtask_id === null) {
            $subtask_id = $this->get_current_subtask_id($task_id, $task);
        }
        
        // 仅当任务状态不是 'processing' 时，才更新为 'processing'
        if ($task['status'] !== 'processing') {
            if (!$this->status_manager->safe_update_task_status($task_id, 'processing', '开始处理子任务')) {
                $error_message = "更新任务 (ID: {$task_id}) 状态为'处理中'失败";
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
            $result = $this->process_current_rule_item($task, $subtask_id);

            if ($result['success']) {
                $this->handle_successful_processing($task_id, $task, $subtask_id);
                $wpdb->query('COMMIT');
                return ['success' => true];
            } else {
                // 先回滚事务，然后处理失败状态
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
     * 处理当前规则项目
     */
    private function process_current_rule_item($task, $subtask_id) {
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
            $error_details['stage'] = '获取队列信息';
            $error_details['message'] = '无法获取规则项目ID';
            $this->logger->log_error('QUEUE_FETCH', $error_details['message']);
            return ['success' => false, 'error' => $error_details];
        }
        
        $rule_item_id = $queue_record->reference_id;
        
        // 使用新的规则项目ID查询方法
        $content = $this->rule_manager->get_content_by_rule_item_id($rule_item_id);
        if (!$content) {
            $error_details['stage'] = '获取规则数据';
            $error_details['message'] = '无法获取规则项目内容';
            $this->logger->log_error('CONTENT_FETCH', $error_details['message']);
            return ['success' => false, 'error' => $error_details];
        }

        // 格式化内容为提示
        $prompt_content = $this->format_content_for_prompt($content);

        // 仅在调试模式下记录完整提示词到日志文件
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_complete_prompt_to_file($prompt_content, $task, $subtask_id);
        }

        // 使用API处理器生成主题
        $result = $this->api_handler->generate_topics($prompt_content, $task['topic_count_per_item'], $task['rule_id'], $subtask_id);

        if (isset($result['error'])) {
            $error_details['stage'] = 'API调用';
            $error_details['message'] = $result['error'];
            return ['success' => false, 'error' => $error_details];
        }

        $topics = $result;

        if ($topics && is_array($topics)) {
            $save_result = $this->save_generated_topics($topics, $task, $subtask_id);
            if ($save_result['success']) {
                return ['success' => true];
            } else {
                $error_details['stage'] = '保存主题';
                $error_details['message'] = $save_result['error'];
                return ['success' => false, 'error' => $error_details];
            }
        }

        $error_details['stage'] = 'API响应格式';
        $error_details['message'] = 'API未返回有效的主题数组，或返回格式不正确';
        return ['success' => false, 'error' => $error_details];
    }
    
    
    
    /**
     * 保存生成的主题
     */
    private function save_generated_topics($topics, $task, $subtask_id) {
        foreach ($topics as $topic) {
            if (is_string($topic) && !empty(trim($topic))) {
                $topic_data = [
                    'task_id' => $task['topic_task_id'],
                    'rule_id' => $task['rule_id'],
                    'rule_item_index' => $subtask_id,
                    'title' => trim($topic),
                    'status' => CONTENT_AUTO_TOPIC_UNUSED
                ];
                $this->add_api_config_to_topic($topic_data);
                $this->database->insert('content_auto_topics', $topic_data);
            } elseif (is_array($topic) && isset($topic['title'])) {
                if ($this->is_complete_topic_data($topic)) {
                    $topic_data = [
                        'task_id' => $task['topic_task_id'],
                        'rule_id' => $task['rule_id'],
                        'rule_item_index' => $subtask_id,
                        'title' => trim($topic['title']),
                        'status' => CONTENT_AUTO_TOPIC_UNUSED,
                        'source_angle' => $topic['source_angle'],
                        'user_value' => $topic['user_value'],
                        'seo_keywords' => json_encode($topic['seo_keywords']),
                        'matched_category' => $topic['matched_category'],
                        'priority_score' => intval($topic['priority_score'])
                    ];
                    $this->add_api_config_to_topic($topic_data);
                    $this->database->insert('content_auto_topics', $topic_data);
                } else {
                    $error_message = '主题数据字段不完整: ' . json_encode($topic);
                    $this->logger->log_error('INCOMPLETE_TOPIC', $error_message);
                    return ['success' => false, 'error' => $error_message];
                }
            }
        }
        return ['success' => true];
    }
    
    /**
     * 格式化内容为提示
     */
    private function format_content_for_prompt($content) {
        // 随机选择模板文件
        $template_files = [
            'topic-generation-prompt.xml',
            'topic1-generation-prompt.xml'
        ];
        $selected_template = $template_files[array_rand($template_files)];
        $template_path = __DIR__ . '/../prompt-templating/' . $selected_template;
        
        if (!file_exists($template_path)) {
            $this->logger->log_error('TEMPLATE_MISSING', '提示词模板文件未找到: ' . $template_path);
            return "模板加载失败，请检查插件文件完整性。";
        }
        
        $prompt = file_get_contents($template_path);
        
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
        
        // 替换占位符
        $replacements = array(
            '{{REFERENCE_CONTENT_BLOCK}}' => $reference_content_block,
            '{{EXISTING_TOPICS_BLOCK}}' => $existing_topics_block,
            '{{SITE_CATEGORIES_BLOCK}}' => $site_categories_block,
            '{{LANGUAGE_INSTRUCTION}}' => $language_instruction,
            '{{LANGUAGE_NAME}}' => $language_ai_name,
            '{{CURRENT_DATE}}' => date('Y年m月d日') // 添加当前日期替换
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
    private function handle_successful_processing($task_id, $task, $subtask_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        // 1. 更新队列中的子任务状态为 completed
        $wpdb->update($queue_table,
            ['status' => 'completed', 'updated_at' => current_time('mysql'), 'error_message' => ''],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务进度
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        $new_generated_count = $task['generated_topics_count'] + $task['topic_count_per_item'];
        
        $this->database->update('content_auto_topic_tasks', array(
            'current_processing_item' => $processed_count,
            'generated_topics_count' => $new_generated_count,
            'status' => CONTENT_AUTO_STATUS_PENDING, // 设置回pending，等待下一个子任务
            'last_processed_at' => current_time('mysql')
        ), array('id' => $task_id));
        
        $this->logger->log_success('SUBTASK_COMPLETED', "子任务完成，等待下次处理: task_id={$task_id}, subtask_id={$subtask_id}");

        // 3. 检查是否所有子任务都已完成，并设置最终状态
        $this->finalize_task_status_if_completed($task_id, $task);
    }
    
    /**
     * 处理失败的任务处理
     */
    private function handle_failed_processing($task_id, $task, $subtask_id, $error_details) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';

        $error_message = '子任务 ' . $subtask_id . ' 处理失败. 阶段: ' . ($error_details['stage'] ?? '未知') . ', 详情: ' . (is_array($error_details['message']) ? json_encode($error_details['message'], JSON_UNESCAPED_UNICODE) : $error_details['message']);

        // 1. 更新队列中的子任务状态为 failed
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务的进度和错误信息，但不立即改变主任务状态
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = "子任务 {$subtask_id} 处理失败";
        
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
            "在文件 %s 的第 %d 行发生异常: %s",
            $exception->getFile(),
            $exception->getLine(),
            $exception->getMessage()
        );
        $error_message = '子任务 ' . $subtask_id . ' 处理失败. 阶段: 系统异常, 详情: ' . $error_detail_message;

        // 1. 更新队列子任务状态
        $wpdb->update($queue_table,
            ['status' => 'failed', 'error_message' => $error_message, 'updated_at' => current_time('mysql')],
            ['job_type' => 'topic_task', 'job_id' => $task_id, 'subtask_id' => $subtask_id]
        );

        // 2. 更新主任务
        $processed_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status IN ('completed', 'failed')", $task_id));
        
        // 不累积错误，只显示当前子任务的错误
        $main_task_error = "子任务 {$subtask_id} 处理失败";
        
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
            $this->logger->log_success('TASK_FINALIZED', "主题任务处理完成，最终状态: {$final_status}", ['task_id' => $task_id]);
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
        return $this->status_manager->safe_update_task_status($task_id, 'paused', '用户暂停任务');
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
        
        // 1. 首先根据topic_task_id找到父任务信息
        $task = $this->database->get_row('content_auto_topic_tasks', array('topic_task_id' => $topic_task_id));
        if (!$task) {
            return false;
        }
        
        // 2. 删除非成功状态的子任务队列项
        // 只删除 pending, processing, failed 等非成功状态的子任务
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $deleted_queue_count = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$queue_table} WHERE job_type = 'topic_task' AND job_id = %d AND status != 'completed'",
            $task['id']
        ));
        
        // 3. 删除任务记录本身
        $result = $this->database->delete('content_auto_topic_tasks', array('topic_task_id' => $topic_task_id));
        
        return $result !== false;
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
        $required_fields = array('title', 'source_angle', 'user_value', 'seo_keywords', 'matched_category', 'priority_score');
        
        foreach ($required_fields as $field) {
            if (!isset($topic[$field]) || empty($topic[$field])) {
                return false;
            }
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
            } else {
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
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/functions.php';
            
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
        
        // 获取规则的所有项目
        global $wpdb;
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        $rule_items = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$rule_items_table} WHERE rule_id = %d ORDER BY id",
            $task['rule_id']
        ));
        
        if (empty($rule_items)) {
            return false;
        }
        
        // 为每个规则项目创建队列项
        $queue_ids = array();
        foreach ($rule_items as $rule_item) {
            $data = array(
                'job_type' => 'topic_task',
                'job_id' => $task_id,
                'subtask_id' => 'subtask_' . uniqid(),  // 使用唯一ID
                'reference_id' => $rule_item->id,  // reference_id存储规则项目ID
                'priority' => 5,
                'retry_count' => 0,
                'status' => CONTENT_AUTO_STATUS_PENDING,
                'error_message' => '',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );

            $queue_id = $this->database->insert('content_auto_job_queue', $data);
            if ($queue_id) {
                $queue_ids[] = $queue_id;
            }
        }
        
        return !empty($queue_ids);
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
                    'prompt_length' => strlen($prompt_content)
                    // 不再记录完整的 prompt_content 以节省空间
                );
                
                $this->logger->log_info('COMPLETE_PROMPT', '主题生成完整提示词', $context);
            }
        } catch (Exception $e) {
            $this->logger->log_error('PROMPT_LOG_FAILED', '提示词日志记录失败: ' . $e->getMessage());
        }
    }
}