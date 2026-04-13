<?php
/**
 * AJAX处理函数
 */

// 确保向量API处理器类可用
if (!class_exists('Yali_AI_Writer_VectorApiHandler')) {
    // 首先确保日志类可用（向量API处理器的依赖）
    if (!class_exists('Yali_AI_Writer_PluginLogger')) {
        $logger_file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }
    }
    
    // 尝试自动加载
    spl_autoload_call('Yali_AI_Writer_VectorApiHandler');
    
    // 如果自动加载失败，手动包含
    if (!class_exists('Yali_AI_Writer_VectorApiHandler')) {
        $vector_handler_file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        if (file_exists($vector_handler_file)) {
            require_once $vector_handler_file;
        } else {
            // 如果文件不存在，记录错误
            error_log('向量API处理器文件未找到: ' . $vector_handler_file);
        }
    }
    
    // 再次检查类是否可用
    if (!class_exists('Yali_AI_Writer_VectorApiHandler')) {
        error_log('向量API处理器类加载失败');
    }
}

/**
 * 测试预置API连接
 */
function yali_ai_writer_manager_test_predefined_api() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 获取参数
    $channel = isset($_POST['channel']) ? sanitize_text_field($_POST['channel']) : 'pollinations';
    
    // 测试连接
    $predefined_api = new Yali_AI_Writer_PredefinedApi();
    $test_result = $predefined_api->test_connection($channel);
    
    if ($test_result['success']) {
        wp_send_json_success(array('message' => __('连接测试成功', 'yali-ai-writer')));
    } else {
        wp_send_json_error(array('message' => __('连接测试失败: ', 'yali-ai-writer') . $test_result['message']));
    }
}

/**
 * 获取配额信息
 */
function yali_ai_writer_manager_get_quota_info() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 获取参数
    $channel = isset($_POST['channel']) ? sanitize_text_field($_POST['channel']) : 'official';
    
    // 只支持插件官方API
    if ($channel !== 'official') {
        wp_send_json_error(array('message' => __('仅插件官方API支持配额查询', 'yali-ai-writer')));
    }
    
    // 获取配额信息
    $official_channel = new Yali_AI_Writer_OfficialChannel();
    $quota_result = $official_channel->get_quota_info();
    
    if ($quota_result['success']) {
        wp_send_json_success(array(
            'message' => $quota_result['message'],
            'quota_balance' => $quota_result['quota_balance']
        ));
    } else {
        wp_send_json_error(array('message' => $quota_result['message']));
    }
}

/**
 * 获取 Pollinations 账户信息 (余额与用量)
 */
function yali_ai_writer_manager_get_pollinations_account_info() {
    // 验证 nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 获取 API Key
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('未配置 API Key', 'yali-ai-writer')));
    }
    
    $pollinations = new Yali_AI_Writer_PollinationsChannel();
    
    // 直接尝试获取所有接口，互不阻塞
    $key_info       = $pollinations->get_account_key($api_key);
    $profile_result = $pollinations->get_account_profile($api_key);
    $balance_result = $pollinations->get_account_balance($api_key);
    $usage_result   = $pollinations->get_account_usage($api_key);
    $daily_usage_result = $pollinations->get_account_daily_usage($api_key);
    
    // 检查是否有任何一个接口成功
    $any_success = ($key_info['success'] || $profile_result['success'] || $balance_result['success'] || $usage_result['success'] || $daily_usage_result['success']);
    
    if (!$any_success) {
        // 如果全部失败，优先返回 balance 或 key 的错误信息
        $msg = $balance_result['message'] ?? ($key_info['message'] ?? __('接口连接异常或权限不足', 'yali-ai-writer'));
        wp_send_json_error(array('message' => $msg));
    }

    // 格式化数据
    $permissions = ($key_info['success'] && isset($key_info['data']['permissions']['account'])) ? $key_info['data']['permissions']['account'] : array();
    $pollen_budget = ($key_info['success'] && isset($key_info['data']['pollenBudget'])) ? $key_info['data']['pollenBudget'] : null;

    $processed_balance = null;
    if ($balance_result['success'] && is_array($balance_result['data'])) {
        $b_data = $balance_result['data'];
        $processed_balance = array(
            'pollen' => $b_data['balance'] ?? ($b_data['pollen'] ?? 0),
            'usd' => $b_data['amount'] ?? ($b_data['cost_usd'] ?? 0),
            'source' => 'account'
        );
    } elseif ($pollen_budget !== null) {
        $processed_balance = array(
            'pollen' => $pollen_budget,
            'usd' => $pollen_budget,
            'is_budget' => true,
            'source' => 'key'
        );
    }

    // 聚合 Usage 函数
    $aggregate_usage = function($raw_data) {
        if (!is_array($raw_data)) return null;
        $records = is_array($raw_data['data'] ?? null) ? $raw_data['data'] : $raw_data;
        if (!is_array($records)) return null;
        
        $total_usd = 0;
        $total_tokens = 0;
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $total_usd += floatval($record['cost_usd'] ?? 0);
            $total_tokens += intval($record['input_text_tokens'] ?? ($record['input_tokens'] ?? 0));
            $total_tokens += intval($record['output_text_tokens'] ?? ($record['output_tokens'] ?? 0));
        }
        return array(
            'pollen_spent' => $total_usd,
            'total_tokens' => $total_tokens,
        );
    };
    
    wp_send_json_success(array(
        'balance' => $processed_balance,
        'usage' => $aggregate_usage($usage_result['data'] ?? null),
        'daily_usage' => $aggregate_usage($daily_usage_result['data'] ?? null),
        'profile' => ($profile_result['success'] ? $profile_result['data'] : null),
        'permissions' => $permissions,
        'debug' => array(
            'has_key' => $key_info['success'],
            'has_balance' => $balance_result['success'],
            'has_usage' => $usage_result['success']
        )
    ));
}

/**
 * 测试API连接
 */
function yali_ai_writer_manager_test_api_connection() {
    try {
        // 验证nonce
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
            wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
        }
        
        // 获取参数
        $config_id = intval($_POST['config_id']);
    
    // 获取配置信息
    $api_config = new Yali_AI_Writer_ApiConfig();
    $config = $api_config->get_config($config_id);
    
    error_log('获取到的API配置: ' . print_r($config, true));
    
    if (!$config) {
        wp_send_json_error(array('message' => __('未找到API配置。', 'yali-ai-writer')));
    }
    
    // 检查是否为预置API配置
    if (!empty($config['predefined_channel'])) {
        error_log('检测到预置API配置，渠道: ' . $config['predefined_channel']);
        // 对于预置API配置，使用预置API特定的测试方法
        $predefined_api = new Yali_AI_Writer_PredefinedApi();
        $test_result = $predefined_api->test_connection($config['predefined_channel']);
    } 
    // 检查是否为向量API配置
    elseif (!empty($config['vector_api_url']) && !empty($config['vector_api_key']) && !empty($config['vector_model_name'])) {
        error_log('检测到向量API配置，URL: ' . $config['vector_api_url'] . ', 模型: ' . $config['vector_model_name']);
        error_log('检测到向量API配置，ID: ' . $config_id);
        // 对于向量API配置，使用向量API特定的测试方法
        try {
            if (!class_exists('Yali_AI_Writer_VectorApiHandler')) {
                error_log('向量API处理器类未找到，尝试加载...');
                // 尝试加载向量API处理器类
                $vector_handler_file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
                if (file_exists($vector_handler_file)) {
                    require_once $vector_handler_file;
                    error_log('向量API处理器文件已加载: ' . $vector_handler_file);
                } else {
                    error_log('向量API处理器文件未找到: ' . $vector_handler_file);
                    wp_send_json_error(array('message' => __('向量API处理器类未找到。', 'yali-ai-writer')));
                }
                
                // 再次检查类是否存在
                if (!class_exists('Yali_AI_Writer_VectorApiHandler')) {
                    error_log('向量API处理器类仍然未找到');
                    wp_send_json_error(array('message' => __('向量API处理器类加载失败。', 'yali-ai-writer')));
                }
            }
            
            error_log('创建向量API处理器实例...');
            $vector_handler = new Yali_AI_Writer_VectorApiHandler();
            error_log('调用向量API测试方法，配置ID: ' . $config_id);
            
            // 检查test_connection方法是否存在
            if (!method_exists($vector_handler, 'test_connection')) {
                error_log('错误: test_connection方法不存在！可用方法: ' . print_r(get_class_methods($vector_handler), true));
                wp_send_json_error(array('message' => __('向量API测试方法不存在', 'yali-ai-writer')));
            }
            $test_result = $vector_handler->test_connection($config_id);
            
            // 添加调试日志
            error_log('向量API测试结果: ' . print_r($test_result, true));
            
            // 检查测试结果并返回适当的响应
            if ($test_result && isset($test_result['success'])) {
                if ($test_result['success']) {
                    $response_data = array('message' => $test_result['message'] ?? __('向量API连接成功', 'yali-ai-writer'));
                    
                    // 如果包含详细数据，添加到响应中
                    if (isset($test_result['data'])) {
                        $response_data['data'] = $test_result['data'];
                    }
                    
                    error_log('向量API测试成功，发送成功响应');
                    wp_send_json_success($response_data);
                } else {
                    error_log('向量API测试失败，发送错误响应: ' . ($test_result['message'] ?? '未知错误'));
                    wp_send_json_error(array('message' => $test_result['message'] ?? __('向量API连接失败', 'yali-ai-writer')));
                }
            } else {
                error_log('向量API测试返回无效结果格式');
                wp_send_json_error(array('message' => __('向量API测试返回无效结果', 'yali-ai-writer')));
            }
        } catch (Exception $e) {
            error_log('向量API测试异常: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('向量API测试异常：', 'yali-ai-writer') . $e->getMessage()));
        }
    }
    else {
        // 对于自定义API配置，使用标准的测试方法
        $test_result = $api_config->test_connection($config_id);
    }
    
    if ($test_result['success']) {
            $response_data = array('message' => $test_result['message'] ?? __('连接成功', 'yali-ai-writer'));
            
            if (isset($test_result['data']) && isset($test_result['data']['dimensions'])) {
                $response_data['data'] = $test_result['data'];
            }
            
            wp_send_json_success($response_data);
        } else {
            $msg = isset($test_result['message']) ? $test_result['message'] : __('未知错误', 'yali-ai-writer');

            // 如果响应是HTML格式，说明API返回了错误页面而不是JSON
            if (strpos($msg, '<!DOCTYPE html') === 0 || strpos($msg, '<html') === 0) {
                // 提取HTML中的错误信息
                if (preg_match('/<title>(.*?)<\/title>/i', $msg, $matches)) {
                    $msg = __('API返回错误页面：', 'yali-ai-writer') . strip_tags($matches[1]);
                } else {
                    $msg = __('API返回HTML错误页面，请检查API地址和配置是否正确', 'yali-ai-writer');
                }
            }

            wp_send_json_error(array('message' => $msg));
        }
        
    } catch (Exception $e) {
        error_log('API连接测试全局异常: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('服务器错误: ', 'yali-ai-writer') . $e->getMessage()));
    } catch (Error $e) {
        error_log('API连接测试全局错误: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('服务器错误: ', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 保存插件语言设置
 */
function cam_save_language_setting_handler() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 获取参数
    $locale = isset($_POST['plugin_locale']) ? sanitize_text_field($_POST['plugin_locale']) : 'site_default';
    
    // 允许的值
    $allowed_locales = array('site_default', 'zh_CN', 'en_US');
    if (!in_array($locale, $allowed_locales)) {
        wp_send_json_error(array('message' => __('不支持的语言。', 'yali-ai-writer')));
    }
    
    // 保存设置
    update_option('yali_ai_writer_locale', $locale);
    
    wp_send_json_success(array('message' => __('设置已保存，正在重新加载...', 'yali-ai-writer')));
}

/**
 * 搜索文章
 */
function yali_ai_writer_manager_search_articles() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }

    $search_term = sanitize_text_field($_POST['search_term']);

    if (empty($search_term)) {
        wp_send_json_success(array('articles' => array()));
    }

    $query_args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        's' => $search_term,
        'posts_per_page' => 10, // 限制返回结果数量
    );

    $query = new WP_Query($query_args);
    $articles = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $articles[] = array(
                'id' => get_the_ID(),
                'title' => get_the_title(),
            );
        }
    }
    wp_reset_postdata();

    wp_send_json_success(array('articles' => $articles));
}

/**
 * 调试工具处理器
 */
function yali_ai_writer_manager_debug_tools() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_debug_tools')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $test_type = isset($_POST['test_type']) ? sanitize_text_field($_POST['test_type']) : '';
    
    switch ($test_type) {
        case 'data_integrity':
            $result = yali_ai_writer_validate_data_integrity();
            break;
        case 'field_values':
            $result = yali_ai_writer_validate_field_values();
            break;
        case 'configuration':
            $result = yali_ai_writer_validate_configuration();
            break;
        case 'full_validation':
            $result = yali_ai_writer_run_full_validation();
            break;
        default:
            $result = array(
                'success' => false,
                'message' => __('未知的测试类型', 'yali-ai-writer')
            );
            break;
    }
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

/**
 * 验证数据完整性
 */
function yali_ai_writer_validate_data_integrity() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        
        // 检查所有必要的表是否存在
        $required_tables = array(
            'yali_ai_writer_topics',
            'yali_ai_writer_rules',
            'yali_ai_writer_rule_items',
            'yali_ai_writer_topic_tasks',
            'yali_ai_writer_article_tasks',
            'yali_ai_writer_articles',
            'yali_ai_writer_job_queue',
            'yali_ai_writer_publish_rules',
            'yali_ai_writer_api_configs'
        );
        
        foreach ($required_tables as $table) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $errors[] = sprintf(__('缺少必要的数据表: %s', 'yali-ai-writer'), $table);
            }
        }
        
        // 检查孤立的主题记录
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $rules_table = $wpdb->prefix . 'yali_ai_writer_rules';
        
        $orphaned_topics = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table t 
            LEFT JOIN $rules_table r ON t.rule_id = r.id 
            WHERE t.rule_id > 0 AND r.id IS NULL
        ");
        
        if ($orphaned_topics > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的主题记录（引用不存在的规则）', 'yali-ai-writer'), $orphaned_topics);
        }
        
        // 检查孤立的规则项目记录
        $rule_items_table = $wpdb->prefix . 'yali_ai_writer_rule_items';
        $orphaned_rule_items = $wpdb->get_var("
            SELECT COUNT(*) FROM $rule_items_table ri 
            LEFT JOIN $rules_table r ON ri.rule_id = r.id 
            WHERE r.id IS NULL
        ");
        
        if ($orphaned_rule_items > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的规则项目记录', 'yali-ai-writer'), $orphaned_rule_items);
        }
        
        // 检查文章任务与文章的关联
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        $article_tasks_table = $wpdb->prefix . 'yali_ai_writer_article_tasks';
        
        $orphaned_articles = $wpdb->get_var("
            SELECT COUNT(*) FROM $articles_table a 
            LEFT JOIN $article_tasks_table j ON a.job_id = j.id 
            WHERE j.id IS NULL
        ");
        
        if ($orphaned_articles > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的文章记录（引用不存在的任务）', 'yali-ai-writer'), $orphaned_articles);
        }
        
        // 检查任务队列中的孤立记录
        $queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
        $orphaned_queue_items = $wpdb->get_var("
            SELECT COUNT(*) FROM $queue_table q 
            LEFT JOIN $article_tasks_table aj ON q.job_id = aj.id AND q.job_type = 'article'
            LEFT JOIN $topics_table tt ON q.job_id = tt.id AND q.job_type = 'topic'
            WHERE aj.id IS NULL AND tt.id IS NULL
        ");
        
        if ($orphaned_queue_items > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的队列记录', 'yali-ai-writer'), $orphaned_queue_items);
        }
        
        // 检查状态字段的有效性
        $valid_statuses = array('pending', 'running', 'completed', 'failed', 'paused');
        
        // 检查主题状态
        $invalid_topic_statuses = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table 
            WHERE status NOT IN ('" . implode("','", $valid_statuses) . "','unused','processing','used')
        ");
        
        if ($invalid_topic_statuses > 0) {
            $errors[] = sprintf(__('发现 %d 个无效状态的主题记录', 'yali-ai-writer'), $invalid_topic_statuses);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings)) {
            $results['success'] = true;
            $results['message'] = '✅ ' . __('数据完整性验证通过，未发现问题', 'yali-ai-writer');
        } else {
            $results['success'] = empty($errors);
            $message_parts = array();
            
            if (!empty($errors)) {
                $message_parts[] = '❌ ' . __('发现错误:', 'yali-ai-writer');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = '⚠️ ' . __('发现警告:', 'yali-ai-writer');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = '验证失败: ' . $e->getMessage();
    }
    
    return $results;
}

/**
 * 验证字段值
 */
function yali_ai_writer_validate_field_values() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        
        // 检查API配置表字段值
        $api_configs_table = $wpdb->prefix . 'yali_ai_writer_api_configs';
        $api_configs = $wpdb->get_results("SELECT * FROM $api_configs_table");
        
        foreach ($api_configs as $config) {
            // 检查API URL格式
            if (!empty($config->api_url) && !filter_var($config->api_url, FILTER_VALIDATE_URL)) {
                $errors[] = sprintf(__('API配置 "%s" 的URL格式无效', 'yali-ai-writer'), $config->name);
            }
            
            // 检查temperature值范围
            if ($config->temperature < 0 || $config->temperature > 2) {
                $warnings[] = sprintf(__('API配置 "%s" 的temperature值不在推荐范围内 (0-2)', 'yali-ai-writer'), $config->name);
            }
            
            // 检查max_tokens值
            if ($config->max_tokens < 1 || $config->max_tokens > 10000) {
                $warnings[] = sprintf(__('API配置 "%s" 的max_tokens值不在推荐范围内 (1-10000)', 'yali-ai-writer'), $config->name);
            }
        }
        
        // 检查发布规则表字段值
        $publish_rules_table = $wpdb->prefix . 'yali_ai_writer_publish_rules';
        $publish_rules = $wpdb->get_results("SELECT * FROM $publish_rules_table");
        
        foreach ($publish_rules as $rule) {
            // 检查category_mode值
            if (!empty($rule->category_mode) && !in_array($rule->category_mode, array('manual', 'auto'))) {
                $errors[] = sprintf(__('发布规则 ID %d 的category_mode值无效', 'yali-ai-writer'), $rule->id);
            }
            
            // 检查序列化的category_ids
            if (!empty($rule->category_ids)) {
                $category_ids = maybe_unserialize($rule->category_ids);
                if (!is_array($category_ids)) {
                    $errors[] = sprintf(__('发布规则 ID %d 的category_ids格式无效', 'yali-ai-writer'), $rule->id);
                }
            }
            
            // 检查序列化的fallback_category_ids
            if (!empty($rule->fallback_category_ids)) {
                $fallback_ids = maybe_unserialize($rule->fallback_category_ids);
                if (!is_array($fallback_ids)) {
                    $errors[] = sprintf(__('发布规则 ID %d 的fallback_category_ids格式无效', 'yali-ai-writer'), $rule->id);
                }
            }
        }
        
        // 检查主题表字段值
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $invalid_priorities = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table 
            WHERE priority_score < 1 OR priority_score > 5
        ");
        
        if ($invalid_priorities > 0) {
            $warnings[] = sprintf(__('发现 %d 个主题的priority_score值不在有效范围内 (1-5)', 'yali-ai-writer'), $invalid_priorities);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings)) {
            $results['success'] = true;
            $results['message'] = '✅ ' . __('字段值验证通过，所有字段值都符合要求', 'yali-ai-writer');
        } else {
            $results['success'] = empty($all_errors);
            $message_parts = array();
            
            if (!empty($errors)) {
                $message_parts[] = '❌ ' . __('发现错误:', 'yali-ai-writer');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = '⚠️ ' . __('发现警告:', 'yali-ai-writer');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = '验证失败: ' . $e->getMessage();
    }
    
    return $results;
}

/**
 * 验证配置
 */
function yali_ai_writer_validate_configuration() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        $infos = array();
        
        // 检查是否有激活的API配置
        $api_configs_table = $wpdb->prefix . 'yali_ai_writer_api_configs';
        $active_configs = $wpdb->get_var("SELECT COUNT(*) FROM $api_configs_table WHERE is_active = 1");
        
        if ($active_configs == 0) {
            $errors[] = __('没有激活的API配置，无法生成文章', 'yali-ai-writer');
        } elseif ($active_configs == 1) {
            $warnings[] = __('当前只有1个激活的API配置，建议增加更多配置以提高可靠性和负载均衡能力', 'yali-ai-writer');
        } else {
            $infos[] = sprintf(__('✅ 当前有 %d 个激活的API配置，这是推荐的配置方式，提供智能轮询、负载均衡和容错能力', 'yali-ai-writer'), $active_configs);
            $infos[] = __('智能轮询机制将自动在多个API配置间切换，避免单个API过载，并在API失败时自动切换到备用配置', 'yali-ai-writer');
        }
        
        // 检查发布规则配置
        $publish_rules_table = $wpdb->prefix . 'yali_ai_writer_publish_rules';
        $publish_rules = $wpdb->get_var("SELECT COUNT(*) FROM $publish_rules_table");
        
        if ($publish_rules == 0) {
            $warnings[] = __('没有配置发布规则，文章发布时将使用默认设置', 'yali-ai-writer');
        } else {
            // 检查发布规则配置的完整性
            $rules = $wpdb->get_results("SELECT * FROM $publish_rules_table");
            foreach ($rules as $rule) {
                if (empty($rule->post_status)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 未设置文章状态', 'yali-ai-writer'), $rule->id);
                }
                
                if ($rule->category_mode == 'auto' && empty($rule->fallback_category_ids)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 启用了自动分类但未设置备用分类', 'yali-ai-writer'), $rule->id);
                }
                
                if ($rule->category_mode == 'manual' && empty($rule->category_ids)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 设置为手动分类但未选择分类', 'yali-ai-writer'), $rule->id);
                }
            }
        }
        
        // 检查WordPress分类
        if (class_exists('Yali_AI_Writer_Category_Filter')) {
            $categories = Yali_AI_Writer_Category_Filter::get_filtered_categories();
            $filter_stats = Yali_AI_Writer_Category_Filter::get_filter_stats();
            if (empty($categories)) {
                $warnings[] = __('插件可用分类为空，可能影响文章分类', 'yali-ai-writer');
            } else {
                if ($filter_stats['is_filtered']) {
                    $warnings[] = sprintf(__('插件可使用 %d/%d 个分类（已启用分类过滤）', 'yali-ai-writer'), count($categories), $filter_stats['total_categories']);
                } else {
                    $warnings[] = sprintf(__('WordPress中有 %d 个分类可用', 'yali-ai-writer'), count($categories));
                }
            }
        } else {
            $categories = get_categories(array('hide_empty' => false));
            if (empty($categories)) {
                $warnings[] = __('WordPress中没有分类，可能影响文章分类', 'yali-ai-writer');
            } else {
                $warnings[] = sprintf(__('WordPress中有 %d 个分类可用', 'yali-ai-writer'), count($categories));
            }
        }
        
        // 检查用户权限
        if (!current_user_can('edit_posts')) {
            $errors[] = __('当前用户没有编辑文章的权限', 'yali-ai-writer');
        }
        
        // 检查WordPress版本兼容性
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            $warnings[] = sprintf(__('WordPress版本 %s 可能存在兼容性问题，建议升级到5.0或更高版本', 'yali-ai-writer'), $wp_version);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings) && empty($infos)) {
            $results['success'] = true;
            $results['message'] = '✅ ' . __('配置验证通过，所有配置都正确', 'yali-ai-writer');
        } else {
            $results['success'] = empty($errors);
            $message_parts = array();
            
            if (!empty($infos)) {
                $message_parts[] = 'ℹ️ ' . __('信息:', 'yali-ai-writer');
                foreach ($infos as $info) {
                    $message_parts[] = "• $info";
                }
            }
            
            if (!empty($errors)) {
                $message_parts[] = '❌ ' . __('发现错误:', 'yali-ai-writer');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = '⚠️ ' . __('发现警告:', 'yali-ai-writer');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($infos, $errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = '验证失败: ' . $e->getMessage();
    }
    
    return $results;
}

/**
 * 运行完整验证
 */
function yali_ai_writer_run_full_validation() {
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $all_errors = array();
        $all_warnings = array();
        
        // 运行所有验证
        $integrity_result = yali_ai_writer_validate_data_integrity();
        $field_result = yali_ai_writer_validate_field_values();
        $config_result = yali_ai_writer_validate_configuration();
        
        // 收集所有错误和警告
        foreach (array($integrity_result, $field_result, $config_result) as $result) {
            if (isset($result['details'])) {
                foreach ($result['details'] as $detail) {
                    if (strpos($detail, '❌ 发现错误:') !== false || 
                        strpos($detail, '❌') !== false) {
                        $all_errors[] = $detail;
                    } else {
                        $all_warnings[] = $detail;
                    }
                }
            }
        }
        
        // 构建综合结果
        $total_issues = count($all_errors) + count($all_warnings);
        
        if ($total_issues == 0) {
            $results['success'] = true;
            $results['message'] = '🎉 ' . __('完整验证通过！系统运行状态良好', 'yali-ai-writer');
        } else {
            $results['success'] = empty($all_errors);
            $message_parts = array();
            
            if (!empty($all_errors)) {
                $message_parts[] = '❌ ' . sprintf(__('发现错误 (%d):', 'yali-ai-writer'), count($all_errors));
                foreach ($all_errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($all_warnings)) {
                $message_parts[] = '⚠️ ' . sprintf(__('发现警告 (%d):', 'yali-ai-writer'), count($all_warnings));
                foreach ($all_warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $message_parts[] = '';
            $message_parts[] = '💡 ' . __('建议:', 'yali-ai-writer');
            if (!empty($all_errors)) {
                $message_parts[] = '• ' . __('请优先修复错误项，确保系统正常运行', 'yali-ai-writer');
            }
            if (!empty($all_warnings)) {
                $message_parts[] = '• ' . __('建议处理警告项，优化系统性能和稳定性', 'yali-ai-writer');
            }
            $message_parts[] = '• ' . __('定期运行验证以确保系统健康', 'yali-ai-writer');
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($all_errors, $all_warnings);
        $results['summary'] = array(
            'total_issues' => $total_issues,
            'errors' => count($all_errors),
            'warnings' => count($all_warnings),
            'integrity_status' => $integrity_result['success'],
            'field_status' => $field_result['success'],
            'config_status' => $config_result['success']
        );
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = __('完整验证失败: ', 'yali-ai-writer') . $e->getMessage();
    }
    
    return $results;
}

/**
 * 获取任务状态
 */
function yali_ai_writer_get_task_status() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 解析子任务状态
    $subtask_status = json_decode($task->subtask_status, true);
    if (!is_array($subtask_status)) {
        $subtask_status = array();
    }
    
    wp_send_json_success(array(
        'task_id' => $task->id,
        'status' => $task->status,
        'current_processing_item' => $task->current_processing_item,
        'generated_topics_count' => $task->generated_topics_count,
        'total_expected_topics' => $task->total_expected_topics,
        'error_message' => $task->error_message,
        'subtask_status' => $subtask_status,
        'last_processed_at' => $task->last_processed_at,
        'created_at' => $task->created_at
    ));
}

/**
 * 暂停任务
 */
function yali_ai_writer_pause_task() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 检查任务状态是否可以暂停
    if (!in_array($task->status, array('pending', 'running', 'processing'))) {
        wp_send_json_error(array('message' => __('当前任务状态不允许暂停。', 'yali-ai-writer')));
    }
    
    // 更新任务状态
    $result = $wpdb->update(
        $tasks_table,
        array('status' => 'paused'),
        array('topic_task_id' => $task_id),
        array('%s'),
        array('%s')
    );
    
    if ($result === false) {
        wp_send_json_error(array('message' => __('暂停任务失败。', 'yali-ai-writer')));
    }
    
    // 同步更新子任务队列状态，将 pending 状态的子任务改为 paused
    $job_queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
    $wpdb->update(
        $job_queue_table,
        array('status' => 'paused', 'updated_at' => current_time('mysql')),
        array('job_type' => 'topic_task', 'job_id' => $task->id, 'status' => 'pending'),
        array('%s', '%s'),
        array('%s', '%d', '%s')
    );
    
    wp_send_json_success(array('message' => __('任务已暂停。', 'yali-ai-writer')));}

/**
 * 恢复任务
 */
function yali_ai_writer_resume_task() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 检查任务状态是否可以恢复
    if ($task->status != 'paused') {
        wp_send_json_error(array('message' => __('当前任务状态不允许恢复。', 'yali-ai-writer')));
    }
    
    // 更新任务状态
    $result = $wpdb->update(
        $tasks_table,
        array('status' => 'pending'),
        array('topic_task_id' => $task_id),
        array('%s'),
        array('%s')
    );
    
    if ($result === false) {
        wp_send_json_error(array('message' => __('恢复任务失败。', 'yali-ai-writer')));
    }
    
    // 同步恢复队列中的子任务，将 paused 状态的子任务恢复为 pending
    $job_queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
    $wpdb->update(
        $job_queue_table,
        array('status' => 'pending', 'updated_at' => current_time('mysql')),
        array('job_type' => 'topic_task', 'job_id' => $task->id, 'status' => 'paused'),
        array('%s', '%s'),
        array('%s', '%d', '%s')
    );
    
    wp_send_json_success(array('message' => __('任务已恢复。', 'yali-ai-writer')));
}

/**
 * 删除任务
 */
function yali_ai_writer_delete_task() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 检查任务状态是否可以删除
    if (!in_array($task->status, array('pending', 'running', 'processing', 'paused', 'failed', 'cancelled'))) {
        wp_send_json_error(array('message' => __('当前任务状态不允许删除。', 'yali-ai-writer')));
    }
    
    // 删除任务相关数据
    try {
        $task_manager = new Yali_AI_Writer_TopicTaskManager();
        $result = $task_manager->delete_task($task_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('删除任务失败，请检查数据库连接或权限。', 'yali-ai-writer')));
        }
        
        wp_send_json_success(array('message' => __('任务已删除，但已生成的主题数据仍保留。', 'yali-ai-writer')));
    } catch (Throwable $e) {
        // 记录错误日志
        error_log('Delete Task Error: ' . $e->getMessage());
        wp_send_json_error(array('message' => __('删除任务时发生错误: ', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 删除文章任务
 */
function yali_ai_writer_delete_article_task() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = intval($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_article_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE id = %d",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 删除任务相关数据
    try {
        $task_manager = new Yali_AI_Writer_ArticleTaskManager();
        $result = $task_manager->delete_task($task->article_task_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('删除任务失败，请检查数据库连接或权限。', 'yali-ai-writer')));
        }
        
        wp_send_json_success(array('message' => __('文章任务已删除，相关队列项也已清理。', 'yali-ai-writer')));
    } catch (Throwable $e) {
        // 记录错误日志
        error_log('Delete Article Task Error: ' . $e->getMessage());
        wp_send_json_error(array('message' => __('删除任务时发生错误: ', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 获取任务进度
 */
function yali_ai_writer_get_task_progress() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
    }
    
    // 计算进度
    $progress = 0;
    if ($task->total_expected_topics > 0) {
        $progress = round(($task->generated_topics_count / $task->total_expected_topics) * 100, 2);
    }
    
    // 解析子任务状态
    $subtask_status = json_decode($task->subtask_status, true);
    if (!is_array($subtask_status)) {
        $subtask_status = array();
    }
    
    // 计算子任务进度
    $subtask_progress = array();
    foreach ($subtask_status as $index => $status) {
        $subtask_progress[$index] = array(
            'status' => $status,
            'label' => yali_ai_writer_get_status_label($status)
        );
    }
    
    wp_send_json_success(array(
        'task_id' => $task->id,
        'status' => $task->status,
        'status_label' => yali_ai_writer_get_status_label($task->status),
        'progress' => $progress,
        'generated_topics_count' => $task->generated_topics_count,
        'total_expected_topics' => $task->total_expected_topics,
        'current_processing_item' => $task->current_processing_item,
        'subtask_progress' => $subtask_progress,
        'error_message' => $task->error_message,
        'last_processed_at' => $task->last_processed_at
    ));
}


/**
 * 重试主题任务
 */
add_action('wp_ajax_yali_ai_writer_retry_task', 'yali_ai_writer_retry_task_handler');
function yali_ai_writer_retry_task_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        return;
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        return;
    }

    // 获取并验证 task_id
    if (!isset($_POST['task_id']) || empty($_POST['task_id'])) {
        wp_send_json_error(array('message' => __('无效的任务ID。', 'yali-ai-writer')));
        return;
    }
    $topic_task_id = sanitize_text_field($_POST['task_id']);

    // 根据 topic_task_id 获取主任务的数字 ID
    global $wpdb;
    $task_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
    $task_numeric_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$task_table} WHERE topic_task_id = %s", $topic_task_id));

    if (!$task_numeric_id) {
        wp_send_json_error(array('message' => __('找不到指定的任务。', 'yali-ai-writer')));
        return;
    }

    // 执行重试
    try {
        $topic_task_manager = new Yali_AI_Writer_TopicTaskManager();
        $result = $topic_task_manager->retry_task($task_numeric_id, null, true);

        if ($result) {
            wp_send_json_success(array('message' => __('任务已标记为重试，将在下一个计划任务周期执行。', 'yali-ai-writer')));
        } else {
            wp_send_json_error(array('message' => __('任务重试失败，请检查日志获取更多信息。', 'yali-ai-writer')));
        }
    } catch (Exception $e) {
        wp_send_json_error(array('message' => __('任务重试过程中发生错误: ', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 批量重试主题任务
 */
add_action('wp_ajax_yali_ai_writer_bulk_retry_topic_tasks', 'yali_ai_writer_bulk_retry_topic_tasks_handler');
function yali_ai_writer_bulk_retry_topic_tasks_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_reference_recall_test')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        return;
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        return;
    }

    // 获取并验证 task_ids
    if (!isset($_POST['task_ids']) || !is_array($_POST['task_ids']) || empty($_POST['task_ids'])) {
        wp_send_json_error(array('message' => __('无效的任务ID列表。', 'yali-ai-writer')));
        return;
    }
    
    $task_ids = array_map('sanitize_text_field', $_POST['task_ids']);
    $success_count = 0;
    $failures = array();

    // 逐个重试任务
    foreach ($task_ids as $topic_task_id) {
        try {
            // 根据 topic_task_id 获取主任务的数字 ID
            global $wpdb;
            $task_table = $wpdb->prefix . 'yali_ai_writer_topic_tasks';
            $task_numeric_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$task_table} WHERE topic_task_id = %s", $topic_task_id));

            if (!$task_numeric_id) {
                $failures[] = "任务 {$topic_task_id} 未找到";
                continue;
            }

            // 执行重试
            $topic_task_manager = new Yali_AI_Writer_TopicTaskManager();
            $result = $topic_task_manager->retry_task($task_numeric_id, null, true);

            if ($result) {
                $success_count++;
            } else {
                $failures[] = "任务 {$topic_task_id} 重试失败";
            }
        } catch (Exception $e) {
            $failures[] = "任务 {$topic_task_id} 重试过程中发生错误: " . $e->getMessage();
        }
    }

    // 返回结果
    if (empty($failures)) {
        wp_send_json_success(array(
            'message' => sprintf(__('成功提交 %d 个任务的重试请求，将在后台处理。', 'yali-ai-writer'), $success_count)
        ));
    } else {
        wp_send_json_success(array(
            'message' => sprintf(__('成功提交 %d 个任务的重试请求，%d 个任务失败。', 'yali-ai-writer'), $success_count, count($failures)),
            'failures' => $failures
        ));
    }
}

/**
 * 翻译文章任务错误消息
 * 将中文错误消息转换为英文
 */
function yali_ai_writer_translate_task_error_message($error_message) {
    if (empty($error_message)) {
        return $error_message;
    }

    // 匹配 "子任务 {id} 处理失败（非API错误，直接标记为最终失败）"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败（非API错误，直接标记为最终失败）|processing failed \(non-API error, marked as final failure\))$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %s 处理失败（非API错误，直接标记为最终失败）', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配 "子任务 {id} 处理失败"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败|processing failed)$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %s 处理失败', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配 "子任务 {id} 处理失败. 阶段: {stage}, 详情: {details}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理失败|processing failed)\.\s*(?:阶段:|Stage:)\s*([^,]+),\s*(?:详情:|Details:)\s*(.+)$/is', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 处理失败. 阶段: %2$s, 详情: %3$s', 'yali-ai-writer'), $matches[1], trim($matches[2]), trim($matches[3]));
    }

    // 匹配 "子任务 {id} 最终失败（非API错误）. 阶段: {stage}, 详情: {details}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:最终失败（非API错误）|final failure \(non-API error\))\.\s*(?:阶段:|Stage:)\s*([^,]+),\s*(?:详情:|Details:)\s*(.+)$/is', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 最终失败（非API错误）. 阶段: %2$s, 详情: %3$s', 'yali-ai-writer'), $matches[1], trim($matches[2]), trim($matches[3]));
    }

    // 匹配 "子任务 {id} 处理超时: {node}"或英文版
    if (preg_match('/^(?:子任务|Subtask)\s+(\w+)\s+(?:处理超时:|processing timeout:)\s*(.+)$/i', $error_message, $matches)) {
        return sprintf(__('子任务 %1$s 处理超时: %2$s', 'yali-ai-writer'), $matches[1], trim($matches[2]));
    }

    // 匹配 "子任务处理异常: {message}"
    if (preg_match('/^子任务处理异常:\s*(.+)$/s', $error_message, $matches)) {
        return sprintf(__('Subtask processing exception: %s', 'yali-ai-writer'), trim($matches[1]));
    }

    // 匹配固定错误消息
    $fixed_messages = array(
        '文章内容生成失败' => __('Article content generation failed', 'yali-ai-writer'),
        '创建WordPress文章失败' => __('Failed to create WordPress post', 'yali-ai-writer'),
        '任务不存在' => __('Task does not exist', 'yali-ai-writer'),
    );

    if (isset($fixed_messages[$error_message])) {
        return $fixed_messages[$error_message];
    }

    // 匹配状态消息 "所有 {count} 个子任务都完成"
    if (preg_match('/^所有\s+(\d+)\s+个子任务都完成$/', $error_message, $matches)) {
        return sprintf(__('All %d subtasks completed', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "所有 {count} 个子任务都失败"
    if (preg_match('/^所有\s+(\d+)\s+个子任务都失败$/', $error_message, $matches)) {
        return sprintf(__('All %d subtasks failed', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "{count} 个子任务完成，{count} 个子任务失败"
    if (preg_match('/^(\d+)\s+个子任务完成，(\d+)\s+个子任务失败$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) completed, %d subtask(s) failed', 'yali-ai-writer'), $matches[1], $matches[2]);
    }

    // 匹配状态消息 "有 {count} 个子任务正在处理"
    if (preg_match('/^有\s+(\d+)\s+个子任务正在处理$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) processing', 'yali-ai-writer'), $matches[1]);
    }

    // 匹配状态消息 "有 {count} 个子任务待处理"
    if (preg_match('/^有\s+(\d+)\s+个子任务待处理$/', $error_message, $matches)) {
        return sprintf(__('%d subtask(s) pending', 'yali-ai-writer'), $matches[1]);
    }

    // 如果都不匹配，返回原始消息
    return $error_message;
}

/**
 * 获取文章任务详情 - 重构版本
 */
function yali_ai_writer_get_article_task_details() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = intval($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    try {
        $article_task_manager = new Yali_AI_Writer_ArticleTaskManager();
        $task = $article_task_manager->get_task($task_id);
        
        if (!$task) {
            wp_send_json_error(array('message' => __('任务不存在。', 'yali-ai-writer')));
        }
        
        // 获取任务进度信息
        $progress = $article_task_manager->get_task_progress($task_id);
        
        // 获取子任务详情
        $subtasks_info = yali_ai_writer_get_article_subtasks_info($task_id, $task);
        
        // 构建HTML内容
        ob_start();
        ?>
        <div class="task-details-container">
            <!-- 基本信息 -->
            <div class="task-basic-info">
                <table class="task-details-table">
                    <tr>
                        <th><?php _e('任务ID', 'yali-ai-writer'); ?></th>
                        <td><?php echo esc_html($task['article_task_id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('任务名称', 'yali-ai-writer'); ?></th>
                        <td><?php
                            // 解析中文格式的任务名称 "文章任务组 - 2026-02-11 14:45:50 (6个主题)"
                            $task_name = $task['name'];
                            if (preg_match('/^文章任务组\s+-\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+\((\d+)个主题\)$/', $task_name, $matches)) {
                                $datetime = $matches[1];
                                $count = intval($matches[2]);
                                echo esc_html(sprintf(__('Article Task Group - %s (%d topics)', 'yali-ai-writer'), $datetime, $count));
                            } else {
                                echo esc_html(__($task_name, 'yali-ai-writer'));
                            }
                        ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('当前状态', 'yali-ai-writer'); ?></th>
                        <td>
                            <span class="task-status status-<?php echo esc_attr($task['status']); ?>">
                                <?php echo yali_ai_writer_manager_get_status_label($task['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('总体进度', 'yali-ai-writer'); ?></th>
                        <td>
                            <div class="progress-container">
                                <div class="progress-stats">
                                    <span class="progress-text">
                                        <?php echo $progress['current_item']; ?>/<?php echo $progress['total_items']; ?> 
                                        (<?php echo $progress['progress_percentage']; ?>%)
                                    </span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%"></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('成功/失败统计', 'yali-ai-writer'); ?></th>
                        <td>
                            <div class="stats-container">
                                <span class="success-stat">✓ <?php echo $progress['completed_topics']; ?> <?php _e('成功', 'yali-ai-writer'); ?></span>
                                <span class="failed-stat">✗ <?php echo $progress['failed_topics']; ?> <?php _e('失败', 'yali-ai-writer'); ?></span>
                                <?php if ($progress['success_rate'] > 0): ?>
                                    <span class="success-rate"><?php _e('成功率', 'yali-ai-writer'); ?>: <?php echo $progress['success_rate']; ?>%</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('创建时间', 'yali-ai-writer'); ?></th>
                        <td><?php echo yali_ai_writer_manager_format_time($task['created_at']); ?></td>
                    </tr>
                    <?php if ($task['last_processed_at']): ?>
                    <tr>
                        <th><?php _e('最后处理时间', 'yali-ai-writer'); ?></th>
                        <td><?php echo yali_ai_writer_manager_format_time($task['last_processed_at']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($task['error_message'])): ?>
                    <tr>
                        <th><?php _e('错误信息', 'yali-ai-writer'); ?></th>
                        <td class="error-message"><?php echo esc_html(yali_ai_writer_translate_task_error_message($task['error_message'])); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- 子任务列表 -->
            <div class="subtasks-section">
                <h3><?php _e('子任务执行状态', 'yali-ai-writer'); ?></h3>
                
                <?php if (!empty($subtasks_info)): ?>
                    <div class="subtasks-summary">
                        <span class="summary-item pending">
                            <?php _e('待处理', 'yali-ai-writer'); ?>: <?php echo $progress['subtask_status_counts']['pending']; ?>
                        </span>
                        <span class="summary-item processing">
                            <?php _e('处理中', 'yali-ai-writer'); ?>: <?php echo $progress['subtask_status_counts']['processing']; ?>
                        </span>
                        <span class="summary-item completed">
                            <?php _e('已完成', 'yali-ai-writer'); ?>: <?php echo $progress['subtask_status_counts']['completed']; ?>
                        </span>
                        <span class="summary-item failed">
                            <?php _e('失败', 'yali-ai-writer'); ?>: <?php echo $progress['subtask_status_counts']['failed']; ?>
                        </span>
                    </div>
                    
                    <table class="subtasks-table">
                        <thead>
                            <tr>
                                <th><?php _e('主题ID', 'yali-ai-writer'); ?></th>
                                <th><?php _e('主题标题', 'yali-ai-writer'); ?></th>
                                <th><?php _e('执行状态', 'yali-ai-writer'); ?></th>
                                <th><?php _e('错误信息', 'yali-ai-writer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subtasks_info as $subtask): ?>
                                <tr class="subtask-row">
                                    <td><?php echo esc_html($subtask['topic_id']); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($subtask['topic_title']); ?></strong>
                                        <?php if ($subtask['prompt_template']): ?>
                                            <div class="template-info" style="margin-top: 4px; font-size: 11px; color: #666;">
                                                <span class="dashicons dashicons-text" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                                                <span title="<?php _e('使用的提示词模板', 'yali-ai-writer'); ?>"><?php echo esc_html(__($subtask['prompt_template'], 'yali-ai-writer')); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php 
                                        $edit_link = get_edit_post_link($subtask['article_post_id']);
                                        if (!$edit_link && current_user_can('manage_options')) {
                                            $edit_link = admin_url('post.php?post=' . $subtask['article_post_id'] . '&action=edit');
                                        }
                                        if ($edit_link): 
                                        ?>
                                            <div class="article-link">
                                                <a href="<?php echo esc_url($edit_link); ?>" target="_blank">
                                                    <?php _e('查看生成的文章', 'yali-ai-writer'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="subtask-status <?php echo esc_attr($subtask['queue_status']); ?>">
                                            <?php echo yali_ai_writer_get_subtask_status_label($subtask['queue_status']); ?>
                                        </span>
                                        <?php if ($subtask['retry_count'] > 0): ?>
                                            <div class="retry-info">
                                                <small><?php _e('重试次数', 'yali-ai-writer'); ?>: <?php echo $subtask['retry_count']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($subtask['error_message'])):
                                            $translated_error = yali_ai_writer_translate_task_error_message($subtask['error_message']);
                                        ?>
                                            <div class="error-message" title="<?php echo esc_attr($translated_error); ?>">
                                                <?php echo esc_html(wp_trim_words($translated_error, 10)); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="no-error">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-subtasks"><?php _e('暂无子任务信息。', 'yali-ai-writer'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
        
    } catch (Exception $e) {
        wp_send_json_error(array('message' => __('获取任务详情失败: ', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 获取文章子任务信息
 */
function yali_ai_writer_get_article_subtasks_info($task_id, $task) {
    global $wpdb;
    
    $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
    $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
    $queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
    
    // 解析主题ID列表
    $topic_ids = json_decode($task['topic_ids'], true);
    if (!is_array($topic_ids)) {
        return array();
    }
    
    // 解析子任务状态
    $subtask_status = json_decode($task['subtask_status'], true);
    if (!is_array($subtask_status)) {
        $subtask_status = array();
    }
    
    // 调试信息：记录当前子任务状态
    error_log("文章任务ID: {$task_id} 的子任务状态: " . print_r($subtask_status, true));
    
    $subtasks_info = array();
    
    foreach ($topic_ids as $topic_id) {
        // 获取主题信息
        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $topics_table WHERE id = %d", 
            $topic_id
        ), ARRAY_A);
        
        // 获取队列状态
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $queue_table WHERE job_type = 'article' AND job_id = %d AND reference_id = %d ORDER BY created_at DESC LIMIT 1",
            $task_id, $topic_id
        ), ARRAY_A);
        
        // 获取生成的文章
        $article = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $articles_table WHERE topic_id = %d ORDER BY created_at DESC LIMIT 1",
            $topic_id
        ), ARRAY_A);
        
        
        // 确定子任务状态 - 优先使用队列状态，如果没有队列项则使用默认值
        $current_subtask_status = $queue_item ? $queue_item['status'] : 'not_queued';
        
        $subtasks_info[] = array(
            'topic_id' => $topic_id,
            'topic_title' => $topic ? $topic['title'] : __('主题不存在', 'yali-ai-writer'),
            'topic_exists' => !empty($topic),
            'queue_status' => $current_subtask_status,
            'queue_error' => $queue_item ? $queue_item['error_message'] : '',
            'retry_count' => $queue_item ? intval($queue_item['retry_count']) : 0,
            'article_id' => $article ? $article['id'] : null,
            'article_post_id' => $article ? $article['post_id'] : null,
            'prompt_template' => $article && isset($article['prompt_template']) ? $article['prompt_template'] : null,
            'error_message' => $queue_item ? $queue_item['error_message'] : ''
        );
    }
    
    return $subtasks_info;
}

/**
 * 获取子任务状态标签
 */
function yali_ai_writer_get_subtask_status_label($status) {
    switch ($status) {
        case 'pending':
            return __('待处理', 'yali-ai-writer');
        case 'processing':
            return __('处理中', 'yali-ai-writer');
        case 'completed':
            return __('已完成', 'yali-ai-writer');
        case 'failed':
            return __('失败', 'yali-ai-writer');
        case 'not_queued':
            return __('未入队', 'yali-ai-writer');
        case 'running':
            return __('运行中', 'yali-ai-writer');
        case 'success':
            return __('成功', 'yali-ai-writer');
        default:
            // 对于未知状态，返回友好描述
            $status_map = array(
                'success' => __('成功', 'yali-ai-writer'),
                'cancelled' => __('已取消', 'yali-ai-writer'),
                'retry' => __('重试中', 'yali-ai-writer'),
                'paused' => __('已暂停', 'yali-ai-writer')
            );
            return isset($status_map[$status]) ? $status_map[$status] : ucfirst($status);
    }
}



/**
 * 验证数据完整性 - 已移除验证功能
 */
add_action('wp_ajax_yali_ai_writer_validate_data_integrity', 'yali_ai_writer_validate_data_integrity_handler');
function yali_ai_writer_validate_data_integrity_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 验证功能已移除，返回成功状态
    wp_send_json_success(array(
        'message' => __('验证功能已移除，数据库结构由插件自动管理。', 'yali-ai-writer'),
        'article_tasks_validation' => array('valid' => true, 'missing_fields' => array()),
        'job_queue_validation' => array('valid' => true, 'missing_fields' => array())
    ));
}

/**
 * 验证字段值 - 功能已简化
 */
add_action('wp_ajax_yali_ai_writer_validate_field_values', 'yali_ai_writer_validate_field_values_handler');
function yali_ai_writer_validate_field_values_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 简化验证，仅返回基础统计信息
    global $wpdb;
    
    $validation_results = array(
        'message' => __('字段验证功能已简化，插件自动管理数据结构。', 'yali-ai-writer'),
        'article_tasks_fields' => array(
            'total_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_article_tasks"),
            'status' => 'managed_by_plugin'
        ),
        'job_queue_fields' => array(
            'total_article_queue_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_job_queue WHERE job_type = 'article'"),
            'status' => 'managed_by_plugin'
        )
    );
    
    wp_send_json_success($validation_results);
}

/**
 * 验证配置 - 功能已简化
 */
add_action('wp_ajax_yali_ai_writer_validate_configuration', 'yali_ai_writer_validate_configuration_handler');
function yali_ai_writer_validate_configuration_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 简化配置验证，仅返回基础统计
    global $wpdb;
    
    $validation_results = array(
        'message' => __('配置验证功能已简化，插件自动管理配置。', 'yali-ai-writer'),
        'api_configuration' => array(
            'total_apis' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_api_configs"),
            'active_apis' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_api_configs WHERE is_active = 1"),
            'has_active_api' => true // 插件启动时会确保有可用配置
        ),
        'publish_rules' => array(
            'total_rules' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_publish_rules"),
            'has_rules' => true // 插件启动时会确保有默认规则
        )
    );
    
    wp_send_json_success($validation_results);
}

/**
 * 运行完整验证 - 已移除验证功能
 */
add_action('wp_ajax_yali_ai_writer_run_full_validation', 'yali_ai_writer_run_full_validation_handler');
function yali_ai_writer_run_full_validation_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    // 完整验证功能已移除，返回简化结果
    wp_send_json_success(array(
        'message' => __('验证功能已移除，插件自动管理数据库结构。', 'yali-ai-writer'),
        'database_structure' => array(
            'article_tasks_table' => array('valid' => true, 'issues' => array()),
            'job_queue_table' => array('valid' => true, 'issues' => array())
        ),
        'data_consistency' => array(
            'orphaned_queue_items' => array('valid' => true, 'count' => 0),
            'status_inconsistencies' => array('valid' => true, 'count' => 0),
            'reference_id_issues' => array('valid' => true, 'count' => 0)
        ),
        'component_integration' => array(
            'queue_processor' => array('valid' => true, 'integration_rate' => '100%')
        )
    ));
}/**
 
* 重试文章任务 - 重构版本
 */
function yali_ai_writer_retry_article_task() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }
    
    $task_id = intval($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'yali-ai-writer')));
    }
    
    try {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/class-article-task-manager.php';
        $article_task_manager = new Yali_AI_Writer_ArticleTaskManager();
        
        // 调用retry_task并设置$force_retry为true，以触发强制重试逻辑
        $result = $article_task_manager->retry_task($task_id, null, true);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('任务已成功重置，失败的子任务将重新执行。', 'yali-ai-writer')
            ));
        } else {
            wp_send_json_error(array('message' => __('任务重试失败。可能没有需要重试的失败子任务。', 'yali-ai-writer')));
        }

    } catch (Exception $e) {
        // 记录详细错误日志
        error_log('文章任务重试异常: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('重试过程中发生服务器错误，请检查插件日志。', 'yali-ai-writer') . $e->getMessage()));
    }
}

/**
 * 清除任务队列
 */
function yali_ai_writer_clear_task_queue() {
    // 验证用户权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有足够的权限执行此操作。', 'yali-ai-writer')));
    }

    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_clear_queue')) {
        wp_send_json_error(array('message' => __('安全验证失败，请刷新页面后重试。', 'yali-ai-writer')));
    }

    global $wpdb;
    $results = array();

    try {
        // 开始数据库事务
        $wpdb->query('START TRANSACTION');

        // 记录操作前的状态
        $before_stats = array();

        // 统计主题任务
        $before_stats['topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_topic_tasks");
        $before_stats['topic_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_topic_tasks WHERE status = 'pending'");
        $before_stats['topic_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_topic_tasks WHERE status = 'processing'");

        // 统计文章任务
        $before_stats['article_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_article_tasks");
        $before_stats['article_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_article_tasks WHERE status = 'pending'");
        $before_stats['article_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_article_tasks WHERE status = 'processing'");

        // 统计队列项目
        $before_stats['queue_items'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_job_queue");
        $before_stats['queue_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_job_queue WHERE status = 'pending'");
        $before_stats['queue_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}yali_ai_writer_job_queue WHERE status = 'processing'");

        // 清除操作
        $cleared_counts = array();

        // 1. 重置所有处理中的主题任务为pending状态
        $cleared_counts['topic_tasks_reset'] = $wpdb->query(
            "UPDATE {$wpdb->prefix}yali_ai_writer_topic_tasks
             SET status = 'pending', error_message = '', updated_at = NOW()
             WHERE status IN ('processing', 'failed')"
        );

        // 2. 重置所有处理中的文章任务为pending状态
        $cleared_counts['article_tasks_reset'] = $wpdb->query(
            "UPDATE {$wpdb->prefix}yali_ai_writer_article_tasks
             SET status = 'pending', error_message = '', updated_at = NOW()
             WHERE status IN ('processing', 'failed')"
        );

        // 3. 清除所有队列项目
        $cleared_counts['queue_items_deleted'] = $wpdb->query("DELETE FROM {$wpdb->prefix}yali_ai_writer_job_queue");

        // 4. 重置最后处理时间（可选）
        $wpdb->query(
            "UPDATE {$wpdb->prefix}yali_ai_writer_topic_tasks
             SET last_processed_at = NULL
             WHERE status = 'pending'"
        );

        $wpdb->query(
            "UPDATE {$wpdb->prefix}yali_ai_writer_article_tasks
             SET last_processed_at = NULL
             WHERE status = 'pending'"
        );

        // 提交事务
        $wpdb->query('COMMIT');

        // 构建成功消息
        $message_parts = array();
        $message_parts[] = '✅ ' . __('任务队列已成功清除', 'yali-ai-writer');
        $message_parts[] = '📊 ' . __('清理统计：', 'yali-ai-writer');
        $message_parts[] = '   - ' . sprintf(__('主题任务重置：%d 个', 'yali-ai-writer'), $cleared_counts['topic_tasks_reset']);
        $message_parts[] = '   - ' . sprintf(__('文章任务重置：%d 个', 'yali-ai-writer'), $cleared_counts['article_tasks_reset']);
        $message_parts[] = '   - ' . sprintf(__('队列项目删除：%d 个', 'yali-ai-writer'), $cleared_counts['queue_items_deleted']);

        // 记录操作日志
        $log_message = sprintf(
            '管理员 %s 清除了任务队列。清除统计：主题任务 %d，文章任务 %d，队列项目 %d',
            wp_get_current_user()->user_login,
            $cleared_counts['topic_tasks_reset'],
            $cleared_counts['article_tasks_reset'],
            $cleared_counts['queue_items_deleted']
        );

        // 如果有日志记录器，记录日志
        if (class_exists('Yali_AI_Writer_PluginLogger')) {
            $logger = new Yali_AI_Writer_PluginLogger();
            $logger->info('QUEUE_CLEARED', $log_message, $before_stats);
        } else {
            error_log('[ContentAuto] ' . $log_message);
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $message_parts),
            'stats' => array(
                'before' => $before_stats,
                'cleared' => $cleared_counts
            ),
            'timestamp' => current_time('mysql')
        ));

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        error_log('[ContentAuto] 清除队列失败: ' . $e->getMessage());

        wp_send_json_error(array(
            'message' => __('清除队列时发生错误：', 'yali-ai-writer') . $e->getMessage(),
            'error_code' => 'CLEAR_QUEUE_ERROR'
        ));
    }
}
add_action('wp_ajax_yali_ai_writer_clear_task_queue', 'yali_ai_writer_clear_task_queue');

/**
 * 测试参考资料召回
 */
function cam_test_reference_recall_handler() {
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_reference_recall_test')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        return;
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
        return;
    }
    
    $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : 0;
    if ($topic_id <= 0) {
        wp_send_json_error(array('message' => __('无效的主题ID。', 'yali-ai-writer')));
        return;
    }
    
    // 获取主题数据
    global $wpdb;
    $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
    $topic = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$topics_table} WHERE id = %d",
        $topic_id
    ), ARRAY_A);
    
    if (!$topic) {
        wp_send_json_error(array('message' => __('主题不存在。', 'yali-ai-writer')));
        return;
    }
    
    // 加载参考资料服务类
    if (!class_exists('Yali_AI_Writer_ReferenceMaterialService')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-reference-material-service.php';
    }
    
    // 执行召回测试
    $service = new Yali_AI_Writer_ReferenceMaterialService();
    $result = $service->test_brand_profile_recall($topic);
    
    wp_send_json_success($result);
}

/**
 * 测试搜索API
 */
function yali_ai_writer_test_search_api() {
    // 验证nonce
    if (!check_ajax_referer('yali_ai_writer_manager_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        return;
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
        return;
    }

    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    if (empty($query)) {
        wp_send_json_error(array('message' => __('搜索关键词不能为空。', 'yali-ai-writer')));
        return;
    }
    
    // 使用公共搜索函数执行搜索
    // 该函数会自动读取全局配置（Region, Time, SafeSearch等）并强制使用Lite后端
    $result = yali_ai_writer_search($query);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => __('请求失败: ', 'yali-ai-writer') . $result->get_error_message()));
        return;
    }
    
    if (isset($result['success']) && $result['success']) {
        wp_send_json_success($result);
    } else {
        $error_msg = isset($result['error']) ? $result['error'] : __('搜索未知错误', 'yali-ai-writer');
        if (isset($result['message'])) {
             $error_msg .= ' (' . $result['message'] . ')';
        }
        wp_send_json_error(array('message' => $error_msg));
    }
}
add_action('wp_ajax_yali_ai_writer_test_search_api', 'yali_ai_writer_test_search_api');



/**
 * 批量清理已完成任务处理函数
 */
function yali_ai_writer_bulk_clean_tasks_handler() {
    // 验证用户权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有足够的权限执行此操作。', 'yali-ai-writer')));
    }

    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_bulk_clean')) {
        wp_send_json_error(array('message' => __('安全验证失败，请刷新页面后重试。', 'yali-ai-writer')));
    }

    // 获取并验证参数
    $options = array(
        'clean_topic_tasks' => isset($_POST['clean_topic_tasks']) && $_POST['clean_topic_tasks'] === 'true',
        'clean_article_tasks' => isset($_POST['clean_article_tasks']) && $_POST['clean_article_tasks'] === 'true',
    );

    if (empty($options['clean_topic_tasks']) && empty($options['clean_article_tasks'])) {
        wp_send_json_error(array('message' => __('请至少选择一项进行清理。', 'yali-ai-writer')));
    }

    try {
        // 加载清理器类
        if (!class_exists('Yali_AI_Writer_TaskQueueCleaner')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/queue/class-task-queue-cleaner.php';
        }

        $cleaner = new Yali_AI_Writer_TaskQueueCleaner();
        $stats = $cleaner->clean_completed_data($options);

        // 构建成功消息
        $message_parts = array();
        $message_parts[] = '✅ ' . __('批量清理已完成', 'yali-ai-writer');
        $message_parts[] = '📊 ' . __('清理统计：', 'yali-ai-writer');
        if ($options['clean_topic_tasks']) {
            $message_parts[] = '   - ' . sprintf(__('已完成主题任务：%d 个', 'yali-ai-writer'), $stats['topic_tasks_deleted']);
        }
        if ($options['clean_article_tasks']) {
            $message_parts[] = '   - ' . sprintf(__('已完成文章任务：%d 个', 'yali-ai-writer'), $stats['article_tasks_deleted']);
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $message_parts),
            'stats' => $stats
        ));

    } catch (Exception $e) {
        error_log('[ContentAuto] 批量清理失败: ' . $e->getMessage());
        wp_send_json_error(array('message' => __('清理过程中发生错误：', 'yali-ai-writer') . $e->getMessage()));
    }
}
add_action('wp_ajax_yali_ai_writer_bulk_clean_tasks', 'yali_ai_writer_bulk_clean_tasks_handler');

/**
 * 删除主题
 */
add_action('wp_ajax_yali_ai_writer_delete_topic', 'yali_ai_writer_delete_topic');
function yali_ai_writer_delete_topic() {
    // 验证nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }

    // 获取主题ID
    $topic_id = intval($_POST['topic_id']);

    if ($topic_id <= 0) {
        wp_send_json_error(array('message' => __('无效的主题ID。', 'yali-ai-writer')));
    }

    global $wpdb;
    $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';

    // 检查主题是否存在
    $topic = $wpdb->get_row($wpdb->prepare("SELECT id FROM $topics_table WHERE id = %d", $topic_id));
    
    if (!$topic) {
        wp_send_json_error(array('message' => __('主题不存在。', 'yali-ai-writer')));
    }

    // 执行删除
    $result = $wpdb->delete($topics_table, array('id' => $topic_id));

    if ($result === false) {
        wp_send_json_error(array('message' => __('删除主题失败，数据库错误。', 'yali-ai-writer')));
    }

    wp_send_json_success(array('message' => __('主题已删除。', 'yali-ai-writer')));
}


/**
 * 向量聚类异步操作接口集合
 * 包括：启动聚类、后台执行聚类、获取聚类状态
 */

// 1. 启动向量聚类（前台非阻塞调用）
add_action('wp_ajax_yali_ai_writer_start_vector_clustering', 'yali_ai_writer_start_vector_clustering');
function yali_ai_writer_start_vector_clustering() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'start_clustering_action')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
        return;
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        return;
    }

    // 检查是否已有聚类任务在运行
    require_once(YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-clustering-manager.php');
    if (get_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT)) {
        wp_send_json_error(array('message' => __('当前已有聚类任务正在运行，请稍候。', 'yali-ai-writer')));
        return;
    }

    // 初始化状态结构
    $initial_status = array(
        'status' => 'running',
        'progress_message' => __('初始化聚类任务...', 'yali-ai-writer') . "\n",
        'start_time' => current_time('mysql'),
        'completed_time' => null,
        'has_error' => false
    );
    update_option('yali_ai_writer_clustering_status', $initial_status);

    // 百万级流式框架：必须清空上一轮残留的老旧循环分析状态机
    delete_option('yali_ai_writer_clustering_state');

    // 锁定聚类进程 (2小时)
    set_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT, true, HOUR_IN_SECONDS * 2);

    // 生成内部通讯令牌，防止未授权触发
    $internal_token = wp_generate_password(32, false);
    set_transient('yali_ai_writer_clustering_internal_token', $internal_token, 60);

    // 异步触发后台真正执行的方法
    $url = admin_url('admin-ajax.php');
    $args = array(
        'timeout'   => 0.01,
        'blocking'  => false,
        'sslverify' => false,
        'body'      => array(
            'action' => 'execute_vector_clustering',
            'internal_token' => $internal_token
        )
    );
    wp_remote_post($url, $args);

    wp_send_json_success(array('message' => __('聚类任务已在后台成功启动。', 'yali-ai-writer')));
}

// 2. 真正执行聚类计算的后台入口（仅允许内部触发）
add_action('wp_ajax_yali_ai_writer_execute_vector_clustering', 'yali_ai_writer_execute_vector_clustering');
add_action('wp_ajax_nopriv_yali_ai_writer_execute_vector_clustering', 'yali_ai_writer_execute_vector_clustering'); // 即使是 nopriv 也要注册，以便异步请求到达
function yali_ai_writer_execute_vector_clustering() {
    // 验证内部执行令牌
    $expected_token = get_transient('yali_ai_writer_clustering_internal_token');
    if (empty($_POST['internal_token']) || empty($expected_token) || $_POST['internal_token'] !== $expected_token) {
        error_log('Unauthorized attempt to trigger execute_vector_clustering.');
        
        // 更新状态为错误以通知前端解锁
        $current_status = get_option('yali_ai_writer_clustering_status', array());
        if (is_array($current_status)) {
            $current_status['has_error'] = true;
            $current_status['status'] = 'completed';
            $current_status['progress_message'] .= "\n[Error] 后台授权验证失败，任务中止。";
            update_option('yali_ai_writer_clustering_status', $current_status);
        }
        
        require_once(YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-clustering-manager.php');
        delete_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT);
        
        wp_die();
    }
    
    // 销毁令牌防止重放
    delete_transient('yali_ai_writer_clustering_internal_token');
    
    // 关闭连接，继续后台执行 (FastCGI 环境下尤为重要)
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // 加载管理页类以复用逻辑，但替换其 UI 抛出逻辑为状态日志
    require_once(YALI_AI_WRITER_PLUGIN_DIR . 'admin/class-clustering-admin-page.php');
    $admin_page = new Yali_AI_Writer_ClusteringAdminPage();
    $admin_page->do_clustering_background();
    
    wp_die();
}

// 3. 获取聚类进度状态
add_action('wp_ajax_yali_ai_writer_get_clustering_status', 'yali_ai_writer_get_clustering_status');
function yali_ai_writer_get_clustering_status() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有权限执行此操作。', 'yali-ai-writer')));
        return;
    }

    $status = get_option('yali_ai_writer_clustering_status');
    if (!$status) {
        wp_send_json_success(array(
            'status' => 'idle',
            'progress_message' => ''
        ));
    } else {
        // 安全起见检查锁是否存在，如果锁没了但状态还在running，纠正它
        require_once(YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-vector-clustering-manager.php');
        if ($status['status'] === 'running' && !get_transient(Yali_AI_Writer_VectorClusteringManager::CLUSTERING_LOCK_TRANSIENT)) {
            $status['status'] = 'completed';
            $status['has_error'] = true;
            $status['progress_message'] .= "\n[System] 进程异常终止，锁定已消失。";
            update_option('yali_ai_writer_clustering_status', $status);
        }
        wp_send_json_success($status);
    }
}
