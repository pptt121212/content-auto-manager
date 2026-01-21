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
    }

    /**
     * Get Pending Tasks for the Extension
     */
    public function get_pending_tasks( $request ) {
        $queue = get_option( self::OPTION_KEY_QUEUE, array() );
        
        // Filter only pending tasks
        $pending = array();
        foreach ( $queue as $task_id => $task ) {
            if ( $task['status'] === 'pending' ) {
                $pending[] = $task;
                // Optional: Mark as 'dispatched' to prevent double processing if utilizing multiple clients
                // For single user extension, we might keep it pending until ack.
            }
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

        // [FIX] Explicitly handle completion since JobQueue might not be loaded in REST context
        if (!class_exists('ContentAuto_JobQueue')) {
            if (defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/queue/class-job-queue.php';
            }
        }
        
        if (class_exists('ContentAuto_JobQueue')) {
             $job_queue = new \ContentAuto_JobQueue();
             // Ensure method exists before calling
             if (method_exists($job_queue, 'handle_extension_task_completion')) {
                 $job_queue->handle_extension_task_completion($task_id, $result);
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
}
