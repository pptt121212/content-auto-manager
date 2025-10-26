<?php
/**
 * 文章任务超时处理器
 * 用于检测和处理卡在"处理中"状态超过2.5分钟的文章任务子项
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ArticleTaskTimeoutHandler {
    
    private $database;
    private $logger;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->logger = new ContentAuto_PluginLogger();
    }
    
    /**
     * 检测和处理超时的文章任务子项
     * 
     * @return array 处理结果统计
     */
    public function handle_timeout_tasks() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 获取处理中超过2分钟的文章任务子项
        // 通过直接计算时间差来判断是否超时，避免时区问题
        $hanging_subtasks = $wpdb->get_results(
            "SELECT q.*, at.article_task_id, at.name as task_name,
             TIMESTAMPDIFF(SECOND, q.updated_at, NOW()) as processing_seconds
             FROM {$queue_table} q
             LEFT JOIN {$article_tasks_table} at ON q.job_id = at.id
             WHERE q.job_type = 'article' 
             AND q.status = 'processing' 
             AND TIMESTAMPDIFF(SECOND, q.updated_at, NOW()) > 120", // 超过120秒(2分钟)
            ARRAY_A
        );
        
        // 过滤掉可能正在进行API重试的任务
        $filtered_subtasks = array();
        foreach ($hanging_subtasks as $subtask) {
            // 检查是否为API相关的任务，且retry_count > 0
            // 如果retry_count > 0，说明可能正在进行API重试，不标记为超时
            $business_node = $this->analyze_business_node($subtask);
            $is_api_related = in_array($business_node['node'], ['API请求阶段', '预定义API请求阶段', '自定义API请求阶段']);
            
            // 如果是API相关任务且重试次数小于最大重试次数，跳过超时检测
            $max_retries = get_option('content_auto_max_retries', 2) + 1; // +1 因为初始尝试也算一次
            if ($is_api_related && $subtask['retry_count'] < $max_retries) {
                $this->logger->info("跳过API重试任务超时检测: subtask_id={$subtask['subtask_id']}, retry_count={$subtask['retry_count']}, max_retries={$max_retries}");
                continue;
            }
            
            $filtered_subtasks[] = $subtask;
        }
        
        $hanging_subtasks = $filtered_subtasks;
        
        $processed_count = 0;
        $failed_count = 0;
        
        foreach ($hanging_subtasks as $subtask) {
            // 处理超时的子项
            $result = $this->handle_timeout_subtask($subtask);
            
            if ($result) {
                $processed_count++;
                
                // 记录日志
                $this->logger->info(
                    "文章任务子项超时处理", 
                    array(
                        'subtask_id' => $subtask['subtask_id'],
                        'article_task_id' => $subtask['article_task_id'],
                        'task_name' => $subtask['task_name'],
                        'processing_time' => $subtask['processing_seconds'],
                        'reference_id' => $subtask['reference_id'],
                        'job_id' => $subtask['job_id']
                    )
                );
            } else {
                $failed_count++;
            }
        }
        
        return array(
            'processed' => $processed_count,
            'failed' => $failed_count,
            'total_found' => count($hanging_subtasks)
        );
    }
    
    /**
     * 处理单个超时的子项
     * 
     * @param array $subtask 超时的子项数据
     * @return bool 处理是否成功
     */
    private function handle_timeout_subtask($subtask) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        try {
            // 分析任务执行到的详细业务节点
            $business_node_info = $this->analyze_business_node($subtask);
            
            // 检查是否为API相关的超时
            $is_api_timeout = in_array($business_node_info['node'], ['API请求阶段', '预定义API请求阶段', '自定义API请求阶段']);
            
            // 检查是否已有重试次数信息
            $existing_retry_count = isset($subtask['retry_count']) ? intval($subtask['retry_count']) : 0;
            
            // 构建详细的错误信息
            if ($is_api_timeout) {
                // 如果已有重试次数，使用实际重试次数；否则使用默认的3次
                $display_retry_count = $existing_retry_count > 0 ? $existing_retry_count : 3;
                
                $error_message = sprintf(
                    'API请求失败，重试%d次后超时: 子任务ID=%s, 任务ID=%d, 处理时长=%d秒, 业务节点=%s, 详细信息=%s',
                    $display_retry_count,
                    $subtask['subtask_id'],
                    $subtask['job_id'],
                    $subtask['processing_seconds'],
                    $business_node_info['node'],
                    json_encode($business_node_info['details'], JSON_UNESCAPED_UNICODE)
                );
                
                // 设置重试次数，优先使用现有值
                $retry_count = $existing_retry_count > 0 ? $existing_retry_count : 3;
            } else {
                $error_message = sprintf(
                    '子任务处理超时 (超过2.5分钟): 子任务ID=%s, 任务ID=%d, 开始处理时间=%s, 处理时长=%d秒, 业务节点=%s, 详细信息=%s',
                    $subtask['subtask_id'],
                    $subtask['job_id'],
                    $subtask['updated_at'],
                    $subtask['processing_seconds'],
                    $business_node_info['node'],
                    json_encode($business_node_info['details'], JSON_UNESCAPED_UNICODE)
                );
                
                $retry_count = 0;
            }
            
            // 更新队列项状态为失败，包含retry_count
            $result = $wpdb->update(
                $queue_table,
                array(
                    'status' => 'failed',
                    'error_message' => $error_message,
                    'retry_count' => $retry_count,
                    'updated_at' => current_time('mysql')
                ),
                array('subtask_id' => $subtask['subtask_id'])
            );
            
            if ($result === false) {
                return false;
            }
            
            // 更新主任务状态和错误信息
            $task_result = $wpdb->update(
                $article_tasks_table,
                array(
                    'status' => 'pending', // 重置为待处理，允许继续处理其他子项
                    'error_message' => "子任务 {$subtask['subtask_id']} 处理超时: {$business_node_info['node']}",
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $subtask['job_id'])
            );
            
            // 检查是否所有子任务都已完成，并设置最终状态
            $this->finalize_task_status_if_completed($subtask['job_id']);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error(
                "处理超时子项时发生异常", 
                array(
                    'subtask_id' => $subtask['subtask_id'],
                    'error' => $e->getMessage()
                )
            );
            return false;
        }
    }
    
    /**
     * 分析任务执行到的业务节点
     * 
     * @param array $subtask 子任务数据
     * @return array 业务节点信息
     */
    public function analyze_business_node($subtask) {
        global $wpdb;
        
        $details = array();
        $node = '未知节点';
        
        try {
            // 获取主任务信息
            $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
            $main_task = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$article_tasks_table} WHERE id = %d",
                $subtask['job_id']
            ), ARRAY_A);
            
            if ($main_task) {
                $details['main_task_status'] = $main_task['status'];
                $details['main_task_error'] = $main_task['error_message'];
                $details['topic_ids'] = $main_task['topic_ids'];
                
                // 获取主题信息
                if ($subtask['reference_id']) {
                    $topics_table = $wpdb->prefix . 'content_auto_topics';
                    $topic = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$topics_table} WHERE id = %d",
                        $subtask['reference_id']
                    ), ARRAY_A);
                    
                    if ($topic) {
                        $details['topic_title'] = $topic['title'];
                        $details['topic_status'] = $topic['status'];
                        $details['topic_rule_id'] = $topic['rule_id'];
                        $details['api_config_id'] = $topic['api_config_id'];
                        $details['api_config_name'] = $topic['api_config_name'];
                    }
                    
                    // 分析API回退机制相关节点
                    $node = $this->analyze_api_fallback_node($subtask, $main_task, $topic);
                } else {
                    // 任务初始化阶段
                    $node = '任务初始化阶段';
                    $details['stage'] = '队列任务创建和初始化';
                }
            } else {
                $node = '任务数据异常';
                $details['error'] = '无法获取主任务信息';
            }
            
        } catch (Exception $e) {
            $node = '分析异常';
            $details['analysis_error'] = $e->getMessage();
        }
        
        return array(
            'node' => $node,
            'details' => $details
        );
    }
    
    /**
     * 分析API回退机制相关节点
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array $topic 主题数据
     * @return string 业务节点
     */
    private function analyze_api_fallback_node($subtask, $main_task, $topic) {
        $details = array();
        $node = '文章生成阶段';
        
        try {
            // 检查API配置状态
            if ($topic && $topic['api_config_id']) {
                global $wpdb;
                $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
                
                $api_config = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$api_configs_table} WHERE id = %d",
                    $topic['api_config_id']
                ), ARRAY_A);
                
                if ($api_config) {
                    $details['current_api_config'] = array(
                        'id' => $api_config['id'],
                        'name' => $api_config['name'],
                        'is_active' => $api_config['is_active'],
                        'api_url' => $api_config['api_url'],
                        'predefined_channel' => $api_config['predefined_channel']
                    );
                    
                    // 检查是否为预定义API
                    if (!empty($api_config['predefined_channel'])) {
                        $node = '预定义API请求阶段';
                        $details['api_type'] = '预定义API';
                        $details['channel'] = $api_config['predefined_channel'];
                    } else {
                        $node = '自定义API请求阶段';
                        $details['api_type'] = '自定义API';
                    }
                }
            }
            
            // 分析API轮询和回退机制执行节点
            $node = $this->analyze_api_polling_fallback_node($subtask, $main_task, $topic, $details);
            
        } catch (Exception $e) {
            $details['analysis_error'] = $e->getMessage();
        }
        
        $details['stage'] = '文章内容生成和WordPress文章创建';
        return $node;
    }
    
    /**
     * 分析API轮询和回退机制执行节点
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array $topic 主题数据
     * @param array $details 详细信息数组（引用传递）
     * @return string 业务节点
     */
    private function analyze_api_polling_fallback_node($subtask, $main_task, $topic, &$details) {
        $node = 'API请求阶段';
        
        try {
            global $wpdb;
            $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
            
            // 获取所有激活的API配置
            $active_apis = $wpdb->get_results("SELECT * FROM {$api_configs_table} WHERE is_active = 1 ORDER BY id", ARRAY_A);
            $details['active_api_count'] = count($active_apis);
            
            // 获取当前轮询索引
            $current_api_index = get_option('content_auto_current_api_index', 0);
            $details['current_api_index'] = $current_api_index;
            
            // 获取最后API请求时间
            $last_request_time = get_option('content_auto_last_api_request', 0);
            $details['last_api_request_time'] = $last_request_time;
            
            if ($last_request_time > 0) {
                $time_since_last_request = time() - $last_request_time;
                $details['seconds_since_last_request'] = $time_since_last_request;
                
                // 如果距离上次请求时间很长，可能卡在请求阶段
                if ($time_since_last_request > 300) { // 5分钟以上
                    $node = 'API请求等待响应阶段';
                    $details['issue'] = '长时间未收到API响应，可能网络连接问题或API服务器无响应';
                }
            }
            
            // 检查是否有正在进行的API请求标记
            $api_request_in_progress = get_transient('content_auto_api_request_in_progress');
            if ($api_request_in_progress) {
                $details['api_request_in_progress'] = $api_request_in_progress;
                $node = 'API请求处理中阶段';
                $details['issue'] = '检测到有API请求正在进行中，可能卡在请求处理';
            }
            
            // 检查API失败记录
            $failed_apis = get_option('content_auto_failed_apis', array());
            if (!empty($failed_apis)) {
                $details['recent_failed_apis'] = array();
                $current_time = time();
                $failure_timeout = 31 * 60; // 31分钟超时
                
                foreach ($failed_apis as $api_id => $failure_time) {
                    // 只显示未过期的失败记录
                    if ($current_time - $failure_time < $failure_timeout) {
                        $details['recent_failed_apis'][$api_id] = date('Y-m-d H:i:s', $failure_time);
                    }
                }
                
                $details['failed_apis_count'] = count($details['recent_failed_apis']);
                
                // 检查是否因为API全部失败导致卡住
                if (count($details['recent_failed_apis']) >= count($active_apis) && count($active_apis) > 0) {
                    $node = 'API回退机制完成阶段';
                    $details['issue'] = '所有激活的API配置都已标记为失败，回退机制已完成但未成功';
                }
            }
            
            // 检查UnifiedApiHandler相关状态
            $last_api_error = get_option('content_auto_last_api_error', '');
            if (!empty($last_api_error)) {
                $details['last_api_error'] = $last_api_error;
                $details['last_api_error_time'] = get_option('content_auto_last_api_error_time', '');
            }
            
            // 检查重试次数
            if ($subtask['retry_count'] > 0) {
                $details['retry_count'] = $subtask['retry_count'];
                $node = 'API重试阶段';
                
                // 检查是否达到最大重试次数
                $max_retries = get_option('content_auto_max_retries', 2);
                if ($subtask['retry_count'] >= $max_retries) {
                    $node = '重试次数耗尽阶段';
                    $details['issue'] = '已达到最大重试次数，但仍未成功';
                }
            }
            
            // 检查您怀疑的具体问题节点
            $this->check_suspected_issue_nodes($subtask, $main_task, $details, $node);
            
        } catch (Exception $e) {
            $details['analysis_error'] = $e->getMessage();
        }
        
        return $node;
    }
    
    /**
     * 检查您怀疑的具体问题节点
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array &$details 详细信息数组（引用传递）
     * @param string &$node 业务节点（引用传递）
     */
    private function check_suspected_issue_nodes($subtask, $main_task, &$details, &$node) {
        global $wpdb;
        
        // 1. 检查文章任务回退机制是否使用了错误的数据读取方法
        $this->check_data_reading_issues($subtask, $main_task, $details, $node);
        
        // 2. 检查Recovery Handler是否正确处理文章任务数据结构
        $this->check_recovery_handler_issues($subtask, $main_task, $details, $node);
        
        // 3. 检查add_task_to_queue方法对文章任务的支持
        $this->check_queue_adding_issues($subtask, $main_task, $details, $node);
        
        // 4. 检查任务队列表字段定位问题
        $this->check_queue_field_issues($subtask, $main_task, $details, $node);
    }
    
    /**
     * 检查文章任务回退机制是否使用了错误的数据读取方法
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array &$details 详细信息数组（引用传递）
     * @param string &$node 业务节点（引用传递）
     */
    private function check_data_reading_issues($subtask, $main_task, &$details, &$node) {
        global $wpdb;
        
        $details['data_reading_check'] = array();
        
        try {
            // 检查是否错误地读取了主题任务的字段
            $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
            
            // 检查是否存在同ID的主题任务（这会导致数据混淆）
            $conflicting_topic_task = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$topic_tasks_table} WHERE id = %d",
                $main_task['id']
            ), ARRAY_A);
            
            if ($conflicting_topic_task) {
                $details['data_reading_check']['conflicting_topic_task'] = true;
                $details['data_reading_check']['conflict_details'] = array(
                    'topic_task_id' => $conflicting_topic_task['topic_task_id'],
                    'topic_task_status' => $conflicting_topic_task['status']
                );
                $details['data_reading_check']['issue'] = '发现ID冲突：文章任务和主题任务使用了相同的ID，可能导致数据读取错误';
                $node = '数据读取冲突阶段';
            } else {
                $details['data_reading_check']['conflicting_topic_task'] = false;
            }
            
            // 检查字段结构差异
            $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
            $article_task_fields = $wpdb->get_results("SHOW COLUMNS FROM {$article_tasks_table}", ARRAY_A);
            $topic_tasks_fields = $wpdb->get_results("SHOW COLUMNS FROM {$topic_tasks_table}", ARRAY_A);
            
            $article_field_names = array_column($article_task_fields, 'Field');
            $topic_field_names = array_column($topic_tasks_fields, 'Field');
            
            $missing_in_article = array_diff($topic_field_names, $article_field_names);
            $missing_in_topic = array_diff($article_field_names, $topic_field_names);
            
            if (!empty($missing_in_article) || !empty($missing_in_topic)) {
                $details['data_reading_check']['field_structure_mismatch'] = true;
                $details['data_reading_check']['missing_in_article'] = array_values($missing_in_article);
                $details['data_reading_check']['missing_in_topic'] = array_values($missing_in_topic);
                $details['data_reading_check']['warning'] = '文章任务和主题任务表结构存在差异，可能导致回退机制数据读取问题';
            } else {
                $details['data_reading_check']['field_structure_mismatch'] = false;
            }
            
        } catch (Exception $e) {
            $details['data_reading_check']['error'] = $e->getMessage();
        }
    }
    
    /**
     * 检查Recovery Handler是否正确处理文章任务数据结构
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array &$details 详细信息数组（引用传递）
     * @param string &$node 业务节点（引用传递）
     */
    private function check_recovery_handler_issues($subtask, $main_task, &$details, &$node) {
        $details['recovery_handler_check'] = array();
        
        try {
            // 检查Recovery Handler相关选项和状态
            $recovery_in_progress = get_option('content_auto_recovery_in_progress', false);
            $last_recovery_time = get_option('content_auto_last_recovery_time', 0);
            
            $details['recovery_handler_check']['recovery_in_progress'] = $recovery_in_progress;
            $details['recovery_handler_check']['last_recovery_time'] = $last_recovery_time;
            
            if ($recovery_in_progress) {
                $details['recovery_handler_check']['issue'] = '检测到恢复处理正在进行中，可能卡在恢复处理阶段';
                $node = '任务恢复处理阶段';
            }
            
            // 检查Recovery Handler是否正确识别任务类型
            $recovery_task_type = get_option('content_auto_recovery_task_type', '');
            if (!empty($recovery_task_type) && $recovery_task_type !== 'article') {
                $details['recovery_handler_check']['task_type_mismatch'] = true;
                $details['recovery_handler_check']['detected_type'] = $recovery_task_type;
                $details['recovery_handler_check']['actual_type'] = 'article';
                $details['recovery_handler_check']['issue'] = 'Recovery Handler识别的任务类型与实际类型不匹配';
                $node = '任务类型识别错误阶段';
            }
            
            // 检查Recovery Handler处理的文章任务特定字段
            $required_article_fields = array('topic_ids', 'total_topics', 'completed_topics', 'failed_topics');
            $missing_fields = array();
            
            foreach ($required_article_fields as $field) {
                if (!isset($main_task[$field])) {
                    $missing_fields[] = $field;
                }
            }
            
            if (!empty($missing_fields)) {
                $details['recovery_handler_check']['missing_article_fields'] = $missing_fields;
                $details['recovery_handler_check']['issue'] = '主任务缺少文章任务必需的字段，Recovery Handler可能无法正确处理';
            }
            
        } catch (Exception $e) {
            $details['recovery_handler_check']['error'] = $e->getMessage();
        }
    }
    
    /**
     * 检查add_task_to_queue方法对文章任务的支持
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array &$details 详细信息数组（引用传递）
     * @param string &$node 业务节点（引用传递）
     */
    private function check_queue_adding_issues($subtask, $main_task, &$details, &$node) {
        global $wpdb;
        
        $details['queue_adding_check'] = array();
        
        try {
            // 检查队列项的字段完整性
            $queue_table = $wpdb->prefix . 'content_auto_job_queue';
            
            // 检查当前队列项是否包含文章任务必需的字段
            $queue_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$queue_table} WHERE subtask_id = %s",
                $subtask['subtask_id']
            ), ARRAY_A);
            
            if ($queue_item) {
                $details['queue_adding_check']['queue_item_exists'] = true;
                
                // 检查文章任务必需的字段
                $required_queue_fields = array('job_type', 'job_id', 'subtask_id', 'reference_id');
                $missing_queue_fields = array();
                
                foreach ($required_queue_fields as $field) {
                    if (!isset($queue_item[$field]) || ($field === 'reference_id' && empty($queue_item[$field]))) {
                        $missing_queue_fields[] = $field;
                    }
                }
                
                if (!empty($missing_queue_fields)) {
                    $details['queue_adding_check']['missing_queue_fields'] = $missing_queue_fields;
                    $details['queue_adding_check']['issue'] = '队列项缺少文章任务必需的字段';
                    $node = '队列字段缺失阶段';
                }
                
                // 特别检查reference_id字段（文章任务特有）
                if (empty($queue_item['reference_id'])) {
                    $details['queue_adding_check']['reference_id_missing'] = true;
                    $details['queue_adding_check']['warning'] = '队列项缺少reference_id字段，文章任务可能无法正确关联主题';
                }
                
                // 检查job_type是否正确
                if ($queue_item['job_type'] !== 'article') {
                    $details['queue_adding_check']['job_type_incorrect'] = true;
                    $details['queue_adding_check']['expected'] = 'article';
                    $details['queue_adding_check']['actual'] = $queue_item['job_type'];
                    $details['queue_adding_check']['issue'] = '队列项job_type字段不正确';
                    $node = '队列任务类型错误阶段';
                }
                
            } else {
                $details['queue_adding_check']['queue_item_exists'] = false;
                $details['queue_adding_check']['issue'] = '无法找到对应的队列项';
                $node = '队列项缺失阶段';
            }
            
            // 检查主题ID的提取和关联
            if (isset($main_task['topic_ids'])) {
                $topic_ids = json_decode($main_task['topic_ids'], true);
                if (is_array($topic_ids)) {
                    $details['queue_adding_check']['topic_ids_count'] = count($topic_ids);
                    $details['queue_adding_check']['topic_ids_sample'] = array_slice($topic_ids, 0, 3);
                    
                    // 检查是否所有主题ID都已创建队列项
                    $created_queue_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d",
                        $main_task['id']
                    ));
                    
                    if ($created_queue_count < count($topic_ids)) {
                        $details['queue_adding_check']['queue_items_incomplete'] = true;
                        $details['queue_adding_check']['created_count'] = $created_queue_count;
                        $details['queue_adding_check']['expected_count'] = count($topic_ids);
                        $details['queue_adding_check']['issue'] = '创建的队列项数量少于主题数量';
                        $node = '队列项创建不完整阶段';
                    }
                } else {
                    $details['queue_adding_check']['topic_ids_invalid'] = true;
                    $details['queue_adding_check']['issue'] = 'topic_ids字段格式无效';
                    $node = '主题ID格式错误阶段';
                }
            }
            
        } catch (Exception $e) {
            $details['queue_adding_check']['error'] = $e->getMessage();
        }
    }
    
    /**
     * 检查任务队列表字段定位问题
     * 
     * @param array $subtask 子任务数据
     * @param array $main_task 主任务数据
     * @param array &$details 详细信息数组（引用传递）
     * @param string &$node 业务节点（引用传递）
     */
    private function check_queue_field_issues($subtask, $main_task, &$details, &$node) {
        global $wpdb;
        
        $details['queue_field_check'] = array();
        
        try {
            $queue_table = $wpdb->prefix . 'content_auto_job_queue';
            
            // 检查队列表是否包含必需的字段
            $queue_columns = $wpdb->get_results("SHOW COLUMNS FROM {$queue_table}", ARRAY_A);
            $queue_field_names = array_column($queue_columns, 'Field');
            
            $required_queue_fields = array('job_type', 'job_id', 'subtask_id', 'reference_id');
            $missing_queue_fields = array_diff($required_queue_fields, $queue_field_names);
            
            if (!empty($missing_queue_fields)) {
                $details['queue_field_check']['missing_fields'] = array_values($missing_queue_fields);
                $details['queue_field_check']['issue'] = '任务队列表缺少必需字段';
                $node = '队列表结构缺失阶段';
            }
            
            // 检查是否因为缺少job_type字段无法准确定位到文章任务
            if (!in_array('job_type', $queue_field_names)) {
                $details['queue_field_check']['job_type_field_missing'] = true;
                $details['queue_field_check']['critical_issue'] = '任务队列表缺少job_type字段，无法准确区分文章任务和主题任务';
                $node = '关键字段缺失阶段';
            }
            
            // 检查当前查询是否能准确定位到文章任务
            $accurate_query_result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$queue_table} WHERE job_type = 'article' AND subtask_id = %s",
                $subtask['subtask_id']
            ), ARRAY_A);
            
            $inaccurate_query_result = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$queue_table} WHERE subtask_id = %s",
                $subtask['subtask_id']
            ), ARRAY_A);
            
            if ($accurate_query_result && $inaccurate_query_result) {
                $details['queue_field_check']['accurate_query_works'] = true;
                
                // 检查两种查询结果是否一致
                if ($accurate_query_result['job_type'] !== $inaccurate_query_result['job_type']) {
                    $details['queue_field_check']['query_conflict'] = true;
                    $details['queue_field_check']['accurate_result'] = $accurate_query_result['job_type'];
                    $details['queue_field_check']['inaccurate_result'] = $inaccurate_query_result['job_type'];
                    $details['queue_field_check']['issue'] = '使用不同查询条件得到不同的任务类型结果，可能存在ID冲突';
                    $node = '查询结果冲突阶段';
                }
            } else if (!$accurate_query_result) {
                $details['queue_field_check']['accurate_query_fails'] = true;
                $details['queue_field_check']['issue'] = '使用准确查询条件无法找到队列项';
                $node = '准确查询失败阶段';
            }
            
            // 检查job_id字段的关联准确性
            if ($accurate_query_result && $accurate_query_result['job_id'] != $main_task['id']) {
                $details['queue_field_check']['job_id_mismatch'] = true;
                $details['queue_field_check']['queue_job_id'] = $accurate_query_result['job_id'];
                $details['queue_field_check']['main_task_id'] = $main_task['id'];
                $details['queue_field_check']['issue'] = '队列项job_id与主任务ID不匹配';
                $node = '任务ID关联错误阶段';
            }
            
        } catch (Exception $e) {
            $details['queue_field_check']['error'] = $e->getMessage();
        }
    }
    
    /**
     * 检查任务是否完成并设置最终状态
     * 
     * @param int $task_id 任务ID
     */
    private function finalize_task_status_if_completed($task_id) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 获取任务信息
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$article_tasks_table} WHERE id = %d",
            $task_id
        ), ARRAY_A);
        
        if (!$task) {
            return;
        }
        
        // 使用 >= 是为了防止在某些边缘情况下计数不精确
        $processed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status IN ('completed', 'failed')",
            $task_id
        ));
        
        if ($processed_count >= $task['total_topics']) {
            $failed_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND status = 'failed'",
                $task_id
            ));
            
            // 如果有任何失败的子任务，则整个任务失败；否则，任务完成
            $final_status = ($failed_count > 0) ? CONTENT_AUTO_STATUS_FAILED : CONTENT_AUTO_STATUS_COMPLETED;
            
            $wpdb->update(
                $article_tasks_table,
                array('status' => $final_status),
                array('id' => $task_id)
            );
        }
    }
    
    /**
     * 清理孤立的队列项
     * 
     * @return int 清理的队列项数量
     */
    public function cleanup_orphaned_queues() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        // 检查 orphaned 队列项（没有对应任务的队列项）
        $orphaned_queues = $wpdb->get_results(
            "SELECT q.* FROM {$queue_table} q
            LEFT JOIN {$article_tasks_table} at ON q.job_id = at.id
            WHERE q.job_type = 'article' 
            AND at.id IS NULL
            AND q.status IN ('pending', 'processing')",
            ARRAY_A
        );
        
        $cleaned_count = 0;
        foreach ($orphaned_queues as $queue) {
            $result = $wpdb->delete($queue_table, array('subtask_id' => $queue['subtask_id']));
            if ($result !== false) {
                $cleaned_count++;
            }
        }
        
        return $cleaned_count;
    }
}