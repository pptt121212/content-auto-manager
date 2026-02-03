<?php
namespace ContentAutoManager\RestApi\Controllers;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller for Extension Task Management
 * Implements a polling mechanism for the browser extension to fetch and execute tasks.
 */
class Task_Controller extends Base_Controller {

    const OPTION_KEY_QUEUE = 'cam_extension_task_queue';
    const OPTION_KEY_RESULTS = 'cam_extension_task_results';

    public function register_routes() {
        // 1. 获取待处理任务 (浏览器插件轮询此接口)
        // GET /wp-json/content-auto-manager/v1/tasks/pending
        register_rest_route( $this->namespace, '/tasks/pending', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_pending_tasks' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        // 2. 提交任务结果 (浏览器插件执行完后回调此接口)
        // POST /wp-json/content-auto-manager/v1/tasks/submit
        register_rest_route( $this->namespace, '/tasks/submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'submit_task_result' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        // 3. 发布新任务 (供网站插件内部调用，或者通过 API 测试)
        // POST /wp-json/content-auto-manager/v1/tasks/create
        register_rest_route( $this->namespace, '/tasks/create', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_task_endpoint' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        // 4. 验证任务是否仍然有效 (浏览器插件在执行前调用)
        // GET /wp-json/content-auto-manager/v1/tasks/validate?task_id=xxx
        register_rest_route( $this->namespace, '/tasks/validate', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'validate_task' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    /**
     * Get Pending Tasks for the Extension
     */
    public function get_pending_tasks( $request ) {
        // 使用统一清理器清理 Option 队列中的非 pending 任务
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/queue/class-task-queue-cleaner.php';
        $cleaner = new \ContentAuto_TaskQueueCleaner();
        $cleaner->cleanup_option_queue_non_pending();
        
        // 获取清理后的 Option 队列
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        
        // 仅返回 pending 状态的 knowledge_search 任务
        // content_fetch 任务由数据库队列处理
        $pending = array();
        foreach ( $queue as $task_id => $task ) {
            if ( isset($task['status']) && $task['status'] === 'pending' ) {
                if ( isset($task['type']) && $task['type'] === 'knowledge_search' ) {
                    $pending[] = $task;
                }
            }
        }

        // --- 新增：从 job_queue 表中获取等待采集的任务 ---
        global $wpdb;
        $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        
        // 收集已有的任务 ID，用于去重
        $pending_ids = array();
        if (!empty($pending)) {
            foreach ($pending as $task) {
                if (isset($task['id'])) {
                    $pending_ids[] = $task['id'];
                }
            }
        }

        // 查询状态为 waiting_browser 的任务，同时获取规则的采集选项
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
        
        // 使用 LEFT JOIN 以确保主要采集逻辑的鲁棒性
        // 只有当主任务确实存在且未被强制失败/取消时才返回
        // 允许 'completed' 状态是为了防止异步采集任务在主任务刚刚切换状态时被漏下
        $waiting_tasks = $wpdb->get_results(
            "SELECT q.id, q.id as queue_id, q.job_id, q.job_type, q.subtask_id, q.reference_id, 
                    r.upload_text as url, r.rule_id, ru.rule_conditions
             FROM {$job_queue_table} q 
             JOIN {$rule_items_table} r ON q.reference_id = r.id 
             LEFT JOIN {$rules_table} ru ON r.rule_id = ru.id
             LEFT JOIN {$topic_tasks_table} tt ON (q.job_type = 'topic_task' AND q.job_id = tt.id)
             LEFT JOIN {$article_tasks_table} at ON (q.job_type = 'article' AND q.job_id = at.id)
             WHERE q.status = 'waiting_browser'
             AND (
                (q.job_type = 'topic_task' AND tt.id IS NOT NULL AND tt.status IN ('pending', 'processing', 'running', 'completed'))
                OR
                (q.job_type = 'article' AND at.id IS NOT NULL AND at.status IN ('pending', 'processing', 'running', 'completed'))
             )",
            ARRAY_A
        );
        
        // [自愈机制] 使用统一清理器清理孤儿任务
        // 包括 DB 队列和 Option 队列中的孤儿任务
        $cleaner->cleanup_orphaned_tasks();
        
        if (!empty($waiting_tasks)) {
            foreach ($waiting_tasks as $w_task) {
                $fetch_task_id = 'fetch_' . $w_task['queue_id'];
                
                // 去重检查：如果 Option Queue 中已存在该任务（说明 freshly added），则跳过 DB 结果
                // 避免插件收到重复 ID 的任务
                if (in_array($fetch_task_id, $pending_ids)) {
                    continue;
                }
                
                // 解析规则的采集选项
                $collect_options = array('keepImages' => false, 'keepLinks' => false);
                if (!empty($w_task['rule_conditions'])) {
                    $conditions = maybe_unserialize($w_task['rule_conditions']);
                    if (isset($conditions['collect_options'])) {
                        $collect_options['keepImages'] = !empty($conditions['collect_options']['keep_images']);
                        $collect_options['keepLinks'] = !empty($conditions['collect_options']['keep_links']);
                    }
                }

                // 将这些任务格式化为插件能识别的结构
                $pending[] = array(
                    'id' => $fetch_task_id, // 生成临时任务ID
                    'original_queue_id' => $w_task['queue_id'], // 保留原始队列ID
                    'type' => 'content_fetch', // 任务类型
                    'payload' => array(
                        'url' => trim($w_task['url']), // 确保 URL 干净
                        'rule_item_id' => $w_task['reference_id'],
                        'options' => $collect_options
                    ),
                    'status' => 'pending',
                    'created_at' => time()
                );
            }
        }
        
        // 调试日志
        if (!empty($pending)) {
            error_log('[CAM Tasks Pending] Found ' . count($pending) . ' pending tasks');
        }

        return new WP_REST_Response( array( 'tasks' => $pending ), 200 );
    }

    /**
     * Submit Task Result
     */
    public function submit_task_result( $request ) {
        $params = $request->get_json_params();
        $task_id = isset($params['task_id']) ? $params['task_id'] : null;
        $result = isset($params['result']) ? $params['result'] : null;

        if ( ! $task_id ) {
            return new WP_REST_Response( array( 'error' => 'Missing task_id' ), 400 );
        }

        // Update Task Queue Status
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        if ( isset( $queue[$task_id] ) ) {
            $queue[$task_id]['status'] = 'completed';
            $queue[$task_id]['completed_at'] = time();
            update_option( self::OPTION_KEY_QUEUE, $queue );
        }

        // Store Result (Separate storage or callback logic)
        // In a real scenario, you might trigger a hook here to notify the system that data is ready.
        $results = get_option( self::OPTION_KEY_RESULTS, array() );
        $results[$task_id] = $result;
        update_option( self::OPTION_KEY_RESULTS, $results );

        // Trigger an internal action so other WP plugins can listen
        do_action( 'cam_extension_task_completed', $task_id, $result );

        if (class_exists('ContentAuto_JobQueue')) {
             $job_queue = new \ContentAuto_JobQueue();
             if (method_exists($job_queue, 'handle_extension_task_completion')) {
                 $job_queue->handle_extension_task_completion($task_id, $result);
             }
        }
        
        // --- 新增：处理 content_fetch 任务回传 ---
        // 识别特征：result.type='content_fetch_result' 或从 task_id 前缀判断
        
        // type 可能在 params 顶层（旧版）或 result 内部（新版）
        $result_type = isset($params['type']) ? $params['type'] : '';
        if (empty($result_type) && isset($result['type'])) {
            $result_type = $result['type'];
        }
        if ($result_type === 'content_fetch_result' || strpos($task_id, 'fetch_') === 0) {
            
            global $wpdb;
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
            
            // 解析原始队列ID
            $queue_id = str_replace('fetch_', '', $task_id);
            $queue_id = intval($queue_id);
            
            $fetched_content = isset($result['content']) ? $result['content'] : '';
            $error_msg = isset($result['error']) ? $result['error'] : '';
            
            if ($queue_id > 0) {
                // 获取reference_id (rule_item_id)
                $job_item = $wpdb->get_row($wpdb->prepare("SELECT reference_id, job_id, subtask_id, status FROM {$job_queue_table} WHERE id = %d", $queue_id));
                
                // 【新增】任务有效性验证：如果任务已被删除或状态不是 waiting_browser，则跳过处理
                if (!$job_item) {
                    // 任务已被删除（用户在后台删除了主题/文章任务）
                    error_log("[CAM] content_fetch task {$task_id} skipped: queue item not found (probably deleted)");
                    return new WP_REST_Response( array( 
                        'success' => true, 
                        'skipped' => true,
                        'reason' => 'task_deleted',
                        'message' => '任务已被删除，跳过处理'
                    ), 200 );
                }
                
                // 【新增】检查任务状态：如果已经不是 waiting_browser，说明已被其他进程处理或取消
                if ($job_item->status !== 'waiting_browser') {
                    error_log("[CAM] content_fetch task {$task_id} skipped: status is '{$job_item->status}' instead of 'waiting_browser'");
                    return new WP_REST_Response( array( 
                        'success' => true, 
                        'skipped' => true,
                        'reason' => 'task_status_changed',
                        'message' => '任务状态已变更，跳过处理'
                    ), 200 );
                }
                
                // 1. 验证内容长度
                if (empty($error_msg) && mb_strlen($fetched_content) < 200) {
                    $error_msg = '采集内容过短（小于200字符），判定为无效内容';
                }
                
                if (empty($error_msg)) {
                    // 成功情况
                    // A. 将采集内容临时存储到 Transient 中（有效期24小时）
                    // 不再覆盖 rule_items 表的 upload_text，保留原始 URL，避免数据破坏
                    set_transient('cam_fetched_content_' . $job_item->reference_id, $fetched_content, DAY_IN_SECONDS);
                    
                    // B. 将任务状态重置为 pending，让后端重新扫描处理
                    // 后端处理时会优先检查 Transient 中的内容
                    $wpdb->update(
                        $job_queue_table,
                        array('status' => 'pending', 'updated_at' => current_time('mysql'), 'error_message' => ''),
                        array('id' => $queue_id)
                    );
                
                } else {
                    // 失败情况
                    // 标记任务为 failed
                    $wpdb->update(
                        $job_queue_table,
                        array('status' => 'failed', 'error_message' => "采集失败: " . $error_msg, 'updated_at' => current_time('mysql')),
                        array('id' => $queue_id)
                    );
                    
                    // 同时更新主任务状态（可选，或者让 Task Manager 在后续检查中发现）
                    // 为简化逻辑，我们在 handle_failed_processing 中已经有逻辑，这里只需更新队列状态
                }
            }
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Create Task (Internal/Endpoint Helper)
     */
    public function create_task_endpoint( $request ) {
        $params = $request->get_json_params();
        $type = isset($params['type']) ? $params['type'] : 'unknown';
        $payload = isset($params['payload']) ? $params['payload'] : array();

        $task_id = $this->add_task( $type, $payload );

        return new WP_REST_Response( array( 'task_id' => $task_id ), 200 );
    }

    /**
     * Internal Helper to Add Task
     */
    public function add_task( $type, $payload ) {
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        
        // Auto cleanup old tasks if queue is too big
        if ( count($queue) > 50 ) {
            $queue = array_slice( $queue, -20, null, true );
        }

        $task_id = wp_generate_uuid4();
        $queue[$task_id] = array(
            'id' => $task_id,
            'type' => $type, // e.g., 'knowledge_search'
            'payload' => $payload, // e.g., { 'query': 'AI Marketing' }
            'status' => 'pending',
            'created_at' => time()
        );

        update_option( self::OPTION_KEY_QUEUE, $queue );
        
        // Use Plugin Logger
        if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
            if (!class_exists('ContentAuto_LoggingSystem')) {
                include_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
            }
            if (class_exists('ContentAuto_LoggingSystem')) {
                $logger = new \ContentAuto_LoggingSystem();
                $logger->log_info('EXTENSION_QUEUE_DEBUG', "已添加任务到扩展队列", array(
                    'task_id' => $task_id,
                    'type' => $type,
                    'queue_size' => count($queue)
                ));
            }
        }
        
        return $task_id;
    }

    /**
     * Validate Task - 验证任务是否仍然有效（用于执行前检查）
     * 浏览器插件在开始执行任务前调用此接口，避免执行已删除的任务
     */
    public function validate_task( $request ) {
        $task_id = $request->get_param('task_id');
        
        if ( empty($task_id) ) {
            return new WP_REST_Response( array( 'valid' => false, 'reason' => 'missing_task_id' ), 400 );
        }

        // 检查是否是 content_fetch 任务（以 fetch_ 开头）
        if ( strpos($task_id, 'fetch_') === 0 ) {
            global $wpdb;
            $job_queue_table = $wpdb->prefix . 'content_auto_job_queue';
            
            // 解析原始队列ID
            $queue_id = str_replace('fetch_', '', $task_id);
            $queue_id = intval($queue_id);
            
            if ($queue_id > 0) {
                $job_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$job_queue_table} WHERE id = %d", 
                    $queue_id
                ));
                
                if (!$job_item) {
                    // 任务已被删除
                    return new WP_REST_Response( array( 
                        'valid' => false, 
                        'reason' => 'task_deleted',
                        'message' => '任务已被删除'
                    ), 200 );
                }
                
                if ($job_item->status !== 'waiting_browser') {
                    // 任务状态已变更
                    return new WP_REST_Response( array( 
                        'valid' => false, 
                        'reason' => 'task_status_changed',
                        'current_status' => $job_item->status,
                        'message' => '任务状态已变更'
                    ), 200 );
                }
                
                // 任务有效
                return new WP_REST_Response( array( 'valid' => true ), 200 );
            }
        }

        // 检查 Option Queue 中的任务（如 knowledge_search）
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        if ( isset($queue[$task_id]) && $queue[$task_id]['status'] === 'pending' ) {
            return new WP_REST_Response( array( 'valid' => true ), 200 );
        }

        // 未找到任务
        return new WP_REST_Response( array( 
            'valid' => false, 
            'reason' => 'task_not_found',
            'message' => '任务未找到'
        ), 200 );
    }
}
