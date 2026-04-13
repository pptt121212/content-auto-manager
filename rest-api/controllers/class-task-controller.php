<?php
namespace ContentAutoManager\RestApi\Controllers;

use WP_REST_Request;
use WP_REST_Response;

require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-extension-task-state-ledger.php';

/**
 * Controller for Extension Task Management
 * Implements a polling mechanism for the browser extension to fetch and execute tasks.
 */
class Task_Controller extends Base_Controller {

    const OPTION_KEY_QUEUE = 'cam_extension_task_queue';
    const OPTION_KEY_RESULTS = 'cam_extension_task_results';
    const CLAIM_TTL_SECONDS = 300;

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
        // 使用统一清理器清理 Option 队列中的终态任务，并回收过期 claim
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/queue/class-task-queue-cleaner.php';
        $cleaner = new \Yali_AI_Writer_TaskQueueCleaner();
        $cleaner->cleanup_option_queue_non_pending();
        
        // 获取清理后的 Option 队列
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        $queue = $this->reclaim_expired_option_queue_claims( $queue );
        update_option( self::OPTION_KEY_QUEUE, $queue );
        
        // 返回可投递状态的 Option 队列任务
        $pending = array();
        $allowed_option_queue_types = array('knowledge_search', 'connection_verify', 'deep_writing', 'content_fetch');
        foreach ( $queue as $task_id => $task ) {
            \Yali_AI_Writer_ExtensionTaskStateLedger::sync_queue_task( $task_id, $task );
            if ( isset($task['status']) && in_array($task['status'], array('pending', 'notified'), true) ) {
                if ( isset($task['type']) && in_array($task['type'], $allowed_option_queue_types, true) ) {
                    $pending[] = $task;
                }
            }
        }

        // [自愈机制] 使用统一清理器清理孤儿任务
        // 包括 DB 队列和 Option 队列中的孤儿任务
        $cleaner->cleanup_orphaned_tasks();
        
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
        $claimant_id = sanitize_text_field( $params['claimant_id'] ?? '' );
        $claim_token = sanitize_text_field( $params['claim_token'] ?? '' );

        if ( ! $task_id ) {
            return new WP_REST_Response( array( 'error' => 'Missing task_id' ), 400 );
        }

        // Update Task Queue Status
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        if ( isset( $queue[$task_id] ) ) {
            $task = $queue[$task_id];
            $status = $task['status'] ?? '';
            $claimed_by = $task['claimed_by'] ?? '';
            $stored_claim_token = $task['claim_token'] ?? '';

            if ( in_array( $status, array('completed', 'failed', 'cancelled'), true ) ) {
                \Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal(
                    $task_id,
                    $status,
                    $result,
                    sanitize_text_field( $result['error'] ?? '' ),
                    array( 'task_type' => $task['type'] ?? 'unknown', 'payload' => $task['payload'] ?? array() )
                );
                return new WP_REST_Response( array(
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'already_terminal',
                    'message' => '任务已处于终态，忽略重复回调'
                ), 200 );
            }

            if ( $status !== 'claimed' || empty($claimant_id) || empty($claim_token) || $claimed_by !== $claimant_id || $stored_claim_token !== $claim_token ) {
                return new WP_REST_Response( array(
                    'success' => true,
                    'skipped' => true,
                    'reason' => 'claim_mismatch',
                    'message' => '任务未被当前扩展实例认领，跳过处理'
                ), 200 );
            }

            $final_queue_status = $this->resolve_terminal_status( $task, $result, $params );
            $queue[$task_id]['status'] = $final_queue_status;
            $queue[$task_id]['completed_at'] = time();
            $queue[$task_id]['claim_expires_at'] = 0;
            update_option( self::OPTION_KEY_QUEUE, $queue );

            \Yali_AI_Writer_ExtensionTaskStateLedger::mark_terminal(
                $task_id,
                $final_queue_status,
                $result,
                sanitize_text_field( $result['error'] ?? '' ),
                array( 'task_type' => $task['type'] ?? 'unknown', 'payload' => $task['payload'] ?? array() )
            );
        }

        // Store Result (Separate storage or callback logic)
        // In a real scenario, you might trigger a hook here to notify the system that data is ready.
        $results = get_option( self::OPTION_KEY_RESULTS, array() );
        $results[$task_id] = $result;
        update_option( self::OPTION_KEY_RESULTS, $results );

        // Trigger an internal action so other WP plugins can listen
        do_action( 'cam_extension_task_completed', $task_id, $result );

        if (class_exists('Yali_AI_Writer_JobQueue')) {
             $job_queue = new \Yali_AI_Writer_JobQueue();
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
            $rule_items_table = $wpdb->prefix . 'yali_ai_writer_rule_items';
            $job_queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
            
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
                if ($job_item->status !== 'waiting_browser' && $job_item->status !== 'claimed_browser') {
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
                    // [升级] 存储为数组，包含标题和内容，以便后续流程使用
                    $fetched_title = isset($result['title']) ? $result['title'] : (isset($result['page_title']) ? $result['page_title'] : '');
                    
                    $data_to_store = array(
                        'title' => $fetched_title,
                        'content' => $fetched_content
                    );
                    
                    set_transient('cam_fetched_content_' . $job_item->reference_id, $data_to_store, DAY_IN_SECONDS);
                    
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

                    // 【关键修复】清理 extension 队列中的旧任务，避免重试时冲突
                    $queue_key = 'cam_extension_task_queue';
                    $ext_queue = get_option($queue_key, array());
                    if (isset($ext_queue[$task_id])) {
                        unset($ext_queue[$task_id]);
                        update_option($queue_key, $ext_queue);
                        error_log("[CAM] Cleaned up failed content_fetch task {$task_id} from extension queue");
                    }
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
            'created_at' => time(),
            'claim_token' => '',
            'claimed_by' => '',
            'claim_expires_at' => 0,
            'notification_count' => 0,
            'last_notified_at' => 0,
        );

        update_option( self::OPTION_KEY_QUEUE, $queue );
        \Yali_AI_Writer_ExtensionTaskStateLedger::ensure_task( $task_id, $type, $payload, 'pending' );
        
        // Use Plugin Logger
        if (defined('YALI_AI_WRITER_PLUGIN_DIR')) {
            if (!class_exists('Yali_AI_Writer_LoggingSystem')) {
                include_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
            }
            if (class_exists('Yali_AI_Writer_LoggingSystem')) {
                $logger = new \Yali_AI_Writer_LoggingSystem();
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
        $claimant_id = sanitize_text_field( $request->get_param('claimant_id') ?: '' );

        if ( empty($task_id) ) {
            return new WP_REST_Response( array( 'valid' => false, 'reason' => 'missing_task_id' ), 400 );
        }

        if ( empty($claimant_id) ) {
            return new WP_REST_Response( array( 'valid' => false, 'reason' => 'missing_claimant_id' ), 400 );
        }

        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        $queue = $this->reclaim_expired_option_queue_claims( $queue );
        if ( isset($queue[$task_id]) ) {
            $task = $queue[$task_id];
            $status = $task['status'] ?? '';

            if ( in_array($status, array('pending', 'notified'), true) ) {
                $task['status'] = 'claimed';
                $task['claimed_by'] = $claimant_id;
                $task['claim_token'] = wp_generate_uuid4();
                $task['claim_expires_at'] = time() + self::CLAIM_TTL_SECONDS;
                $queue[$task_id] = $task;
                update_option( self::OPTION_KEY_QUEUE, $queue );
                \Yali_AI_Writer_ExtensionTaskStateLedger::mark_claimed(
                    $task_id,
                    $task['type'] ?? 'unknown',
                    $task['payload'] ?? array(),
                    $claimant_id,
                    $task['claim_token'],
                    $task['claim_expires_at']
                );
                return new WP_REST_Response( array(
                    'valid' => true,
                    'claim_token' => $task['claim_token'],
                    'claim_expires_at' => $task['claim_expires_at'],
                ), 200 );
            }

            if ( $status === 'claimed' ) {
                if ( ($task['claimed_by'] ?? '') === $claimant_id && !empty($task['claim_token']) ) {
                    return new WP_REST_Response( array(
                        'valid' => true,
                        'claim_token' => $task['claim_token'],
                        'claim_expires_at' => intval($task['claim_expires_at'] ?? 0),
                    ), 200 );
                }

                return new WP_REST_Response( array(
                    'valid' => false,
                    'reason' => 'already_claimed',
                    'message' => '任务已被其他扩展实例接管',
                ), 200 );
            }

            return new WP_REST_Response( array(
                'valid' => false,
                'reason' => 'task_status_changed',
                'current_status' => $status,
                'message' => '任务状态已变更',
            ), 200 );
        }

        // 检查是否是 content_fetch 任务（兼容没有镜像 option queue 的老数据）
        if ( strpos($task_id, 'fetch_') === 0 ) {
            global $wpdb;
            $job_queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';

            $queue_id = str_replace('fetch_', '', $task_id);
            $queue_id = intval($queue_id);

            if ($queue_id > 0) {
                $job_item = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$job_queue_table} WHERE id = %d",
                    $queue_id
                ));

                if (!$job_item) {
                    return new WP_REST_Response( array(
                        'valid' => false,
                        'reason' => 'task_deleted',
                        'message' => '任务已被删除'
                    ), 200 );
                }

                if ($job_item->status !== 'waiting_browser') {
                    return new WP_REST_Response( array(
                        'valid' => false,
                        'reason' => 'task_status_changed',
                        'current_status' => $job_item->status,
                        'message' => '任务状态已变更'
                    ), 200 );
                }

                $claim_token = wp_generate_uuid4();
                $wpdb->update(
                    $job_queue_table,
                    array('status' => 'claimed_browser', 'updated_at' => current_time('mysql')),
                    array('id' => $queue_id)
                );
                \Yali_AI_Writer_ExtensionTaskStateLedger::mark_claimed(
                    $task_id,
                    'content_fetch',
                    array( 'queue_id' => $queue_id ),
                    $claimant_id,
                    $claim_token,
                    time() + self::CLAIM_TTL_SECONDS
                );
                return new WP_REST_Response( array( 'valid' => true, 'claim_token' => $claim_token, 'claim_expires_at' => time() + self::CLAIM_TTL_SECONDS ), 200 );
            }
        }

        return new WP_REST_Response( array(
            'valid' => false,
            'reason' => 'task_not_found',
            'message' => '任务未找到'
        ), 200 );
    }

    private function reclaim_expired_option_queue_claims( $queue ) {
        $now = time();
        foreach ( $queue as $task_id => $task ) {
            if ( ($task['status'] ?? '') === 'claimed' ) {
                $expires_at = intval($task['claim_expires_at'] ?? 0);
                if ( $expires_at <= 0 || $expires_at < $now ) {
                    $queue[$task_id]['status'] = 'pending';
                    $queue[$task_id]['claimed_by'] = '';
                    $queue[$task_id]['claim_token'] = '';
                    $queue[$task_id]['claim_expires_at'] = 0;
                    \Yali_AI_Writer_ExtensionTaskStateLedger::mark_reclaimed_pending( $task_id );
                }
            }
        }

        return $queue;
    }

    private function resolve_terminal_status( $task, $result, $params ) {
        $task_type = $task['type'] ?? '';
        $result_type = isset($params['type']) ? $params['type'] : '';
        if (empty($result_type) && isset($result['type'])) {
            $result_type = $result['type'];
        }

        if ( $task_type === 'content_fetch' || $result_type === 'content_fetch_result' ) {
            if ( !empty($result['error']) || (($result['status'] ?? '') === 'failed') ) {
                return 'failed';
            }
            return 'completed';
        }

        if ( !empty($result['error']) ) {
            return 'failed';
        }

        return 'completed';
    }
}
