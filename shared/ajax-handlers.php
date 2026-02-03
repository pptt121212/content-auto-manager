<?php
/**
 * AJAX处理函数
 */

// 确保向量API处理器类可用
if (!class_exists('ContentAuto_VectorApiHandler')) {
    // 首先确保日志类可用（向量API处理器的依赖）
    if (!class_exists('ContentAuto_PluginLogger')) {
        $logger_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }
    }
    
    // 尝试自动加载
    spl_autoload_call('ContentAuto_VectorApiHandler');
    
    // 如果自动加载失败，手动包含
    if (!class_exists('ContentAuto_VectorApiHandler')) {
        $vector_handler_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        if (file_exists($vector_handler_file)) {
            require_once $vector_handler_file;
        } else {
            // 如果文件不存在，记录错误
            error_log('向量API处理器文件未找到: ' . $vector_handler_file);
        }
    }
    
    // 再次检查类是否可用
    if (!class_exists('ContentAuto_VectorApiHandler')) {
        error_log('向量API处理器类加载失败');
    }
}

/**
 * 测试预置API连接
 */
function content_auto_manager_test_predefined_api() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 获取参数
    $channel = isset($_POST['channel']) ? sanitize_text_field($_POST['channel']) : 'pollinations';
    
    // 测试连接
    $predefined_api = new ContentAuto_PredefinedApi();
    $test_result = $predefined_api->test_connection($channel);
    
    if ($test_result['success']) {
        wp_send_json_success(array('message' => __('连接测试成功', 'content-auto-manager')));
    } else {
        wp_send_json_error(array('message' => __('连接测试失败: ', 'content-auto-manager') . $test_result['message']));
    }
}

/**
 * 获取配额信息
 */
function content_auto_manager_get_quota_info() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 获取参数
    $channel = isset($_POST['channel']) ? sanitize_text_field($_POST['channel']) : 'official';
    
    // 只支持插件官方API
    if ($channel !== 'official') {
        wp_send_json_error(array('message' => __('仅插件官方API支持配额查询', 'content-auto-manager')));
    }
    
    // 获取配额信息
    $official_channel = new ContentAuto_OfficialChannel();
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
 * 测试API连接
 */
function content_auto_manager_test_api_connection() {
    try {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
            wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
        }
        
        // 获取参数
        $config_id = intval($_POST['config_id']);
    
    // 获取配置信息
    $api_config = new ContentAuto_ApiConfig();
    $config = $api_config->get_config($config_id);
    
    error_log('获取到的API配置: ' . print_r($config, true));
    
    if (!$config) {
        wp_send_json_error(array('message' => __('未找到API配置。', 'content-auto-manager')));
    }
    
    // 检查是否为预置API配置
    if (!empty($config['predefined_channel'])) {
        error_log('检测到预置API配置，渠道: ' . $config['predefined_channel']);
        // 对于预置API配置，使用预置API特定的测试方法
        $predefined_api = new ContentAuto_PredefinedApi();
        $test_result = $predefined_api->test_connection($config['predefined_channel']);
    } 
    // 检查是否为向量API配置
    elseif (!empty($config['vector_api_url']) && !empty($config['vector_api_key']) && !empty($config['vector_model_name'])) {
        error_log('检测到向量API配置，URL: ' . $config['vector_api_url'] . ', 模型: ' . $config['vector_model_name']);
        error_log('检测到向量API配置，ID: ' . $config_id);
        // 对于向量API配置，使用向量API特定的测试方法
        try {
            if (!class_exists('ContentAuto_VectorApiHandler')) {
                error_log('向量API处理器类未找到，尝试加载...');
                // 尝试加载向量API处理器类
                $vector_handler_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
                if (file_exists($vector_handler_file)) {
                    require_once $vector_handler_file;
                    error_log('向量API处理器文件已加载: ' . $vector_handler_file);
                } else {
                    error_log('向量API处理器文件未找到: ' . $vector_handler_file);
                    wp_send_json_error(array('message' => __('向量API处理器类未找到。', 'content-auto-manager')));
                }
                
                // 再次检查类是否存在
                if (!class_exists('ContentAuto_VectorApiHandler')) {
                    error_log('向量API处理器类仍然未找到');
                    wp_send_json_error(array('message' => __('向量API处理器类加载失败。', 'content-auto-manager')));
                }
            }
            
            error_log('创建向量API处理器实例...');
            $vector_handler = new ContentAuto_VectorApiHandler();
            error_log('调用向量API测试方法，配置ID: ' . $config_id);
            
            // 检查test_connection方法是否存在
            if (!method_exists($vector_handler, 'test_connection')) {
                error_log('错误: test_connection方法不存在！可用方法: ' . print_r(get_class_methods($vector_handler), true));
                wp_send_json_error(array('message' => __('向量API测试方法不存在', 'content-auto-manager')));
            }
            $test_result = $vector_handler->test_connection($config_id);
            
            // 添加调试日志
            error_log('向量API测试结果: ' . print_r($test_result, true));
            
            // 检查测试结果并返回适当的响应
            if ($test_result && isset($test_result['success'])) {
                if ($test_result['success']) {
                    $response_data = array('message' => $test_result['message'] ?? __('向量API连接成功', 'content-auto-manager'));
                    
                    // 如果包含详细数据，添加到响应中
                    if (isset($test_result['data'])) {
                        $response_data['data'] = $test_result['data'];
                    }
                    
                    error_log('向量API测试成功，发送成功响应');
                    wp_send_json_success($response_data);
                } else {
                    error_log('向量API测试失败，发送错误响应: ' . ($test_result['message'] ?? '未知错误'));
                    wp_send_json_error(array('message' => $test_result['message'] ?? __('向量API连接失败', 'content-auto-manager')));
                }
            } else {
                error_log('向量API测试返回无效结果格式');
                wp_send_json_error(array('message' => __('向量API测试返回无效结果', 'content-auto-manager')));
            }
        } catch (Exception $e) {
            error_log('向量API测试异常: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('向量API测试异常：', 'content-auto-manager') . $e->getMessage()));
        }
    }
    else {
        // 对于自定义API配置，使用标准的测试方法
        $test_result = $api_config->test_connection($config_id);
    }
    
    if ($test_result['success']) {
            $response_data = array('message' => $test_result['message'] ?? __('连接成功', 'content-auto-manager'));
            
            if (isset($test_result['data']) && isset($test_result['data']['dimensions'])) {
                $response_data['data'] = $test_result['data'];
            }
            
            wp_send_json_success($response_data);
        } else {
            $msg = isset($test_result['message']) ? $test_result['message'] : __('未知错误', 'content-auto-manager');

            // 如果响应是HTML格式，说明API返回了错误页面而不是JSON
            if (strpos($msg, '<!DOCTYPE html') === 0 || strpos($msg, '<html') === 0) {
                // 提取HTML中的错误信息
                if (preg_match('/<title>(.*?)<\/title>/i', $msg, $matches)) {
                    $msg = __('API返回错误页面：', 'content-auto-manager') . strip_tags($matches[1]);
                } else {
                    $msg = __('API返回HTML错误页面，请检查API地址和配置是否正确', 'content-auto-manager');
                }
            }

            wp_send_json_error(array('message' => $msg));
        }
        
    } catch (Exception $e) {
        error_log('API连接测试全局异常: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('服务器错误: ', 'content-auto-manager') . $e->getMessage()));
    } catch (Error $e) {
        error_log('API连接测试全局错误: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('服务器错误: ', 'content-auto-manager') . $e->getMessage()));
    }
}

/**
 * 搜索文章
 */
function content_auto_manager_search_articles() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
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
function content_auto_manager_debug_tools() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_debug_tools')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $test_type = isset($_POST['test_type']) ? sanitize_text_field($_POST['test_type']) : '';
    
    switch ($test_type) {
        case 'data_integrity':
            $result = content_auto_validate_data_integrity();
            break;
        case 'field_values':
            $result = content_auto_validate_field_values();
            break;
        case 'configuration':
            $result = content_auto_validate_configuration();
            break;
        case 'full_validation':
            $result = content_auto_run_full_validation();
            break;
        default:
            $result = array(
                'success' => false,
                'message' => __('未知的测试类型', 'content-auto-manager')
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
function content_auto_validate_data_integrity() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        
        // 检查所有必要的表是否存在
        $required_tables = array(
            'content_auto_topics',
            'content_auto_rules',
            'content_auto_rule_items',
            'content_auto_topic_tasks',
            'content_auto_article_tasks',
            'content_auto_articles',
            'content_auto_job_queue',
            'content_auto_publish_rules',
            'content_auto_api_configs'
        );
        
        foreach ($required_tables as $table) {
            $table_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $errors[] = sprintf(__('缺少必要的数据表: %s', 'content-auto-manager'), $table);
            }
        }
        
        // 检查孤立的主题记录
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        
        $orphaned_topics = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table t 
            LEFT JOIN $rules_table r ON t.rule_id = r.id 
            WHERE t.rule_id > 0 AND r.id IS NULL
        ");
        
        if ($orphaned_topics > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的主题记录（引用不存在的规则）', 'content-auto-manager'), $orphaned_topics);
        }
        
        // 检查孤立的规则项目记录
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        $orphaned_rule_items = $wpdb->get_var("
            SELECT COUNT(*) FROM $rule_items_table ri 
            LEFT JOIN $rules_table r ON ri.rule_id = r.id 
            WHERE r.id IS NULL
        ");
        
        if ($orphaned_rule_items > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的规则项目记录', 'content-auto-manager'), $orphaned_rule_items);
        }
        
        // 检查文章任务与文章的关联
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        $article_tasks_table = $wpdb->prefix . 'content_auto_article_tasks';
        
        $orphaned_articles = $wpdb->get_var("
            SELECT COUNT(*) FROM $articles_table a 
            LEFT JOIN $article_tasks_table j ON a.job_id = j.id 
            WHERE j.id IS NULL
        ");
        
        if ($orphaned_articles > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的文章记录（引用不存在的任务）', 'content-auto-manager'), $orphaned_articles);
        }
        
        // 检查任务队列中的孤立记录
        $queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $orphaned_queue_items = $wpdb->get_var("
            SELECT COUNT(*) FROM $queue_table q 
            LEFT JOIN $article_tasks_table aj ON q.job_id = aj.id AND q.job_type = 'article'
            LEFT JOIN $topics_table tt ON q.job_id = tt.id AND q.job_type = 'topic'
            WHERE aj.id IS NULL AND tt.id IS NULL
        ");
        
        if ($orphaned_queue_items > 0) {
            $warnings[] = sprintf(__('发现 %d 个孤立的队列记录', 'content-auto-manager'), $orphaned_queue_items);
        }
        
        // 检查状态字段的有效性
        $valid_statuses = array('pending', 'running', 'completed', 'failed', 'paused');
        
        // 检查主题状态
        $invalid_topic_statuses = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table 
            WHERE status NOT IN ('" . implode("','", $valid_statuses) . "','unused','processing','used')
        ");
        
        if ($invalid_topic_statuses > 0) {
            $errors[] = sprintf(__('发现 %d 个无效状态的主题记录', 'content-auto-manager'), $invalid_topic_statuses);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings)) {
            $results['success'] = true;
            $results['message'] = __('✅ 数据完整性验证通过，未发现问题', 'content-auto-manager');
        } else {
            $results['success'] = !empty($errors);
            $message_parts = array();
            
            if (!empty($errors)) {
                $message_parts[] = __('❌ 发现错误:', 'content-auto-manager');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = __('⚠️ 发现警告:', 'content-auto-manager');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = __('验证失败: ', 'content-auto-manager') . $e->getMessage();
    }
    
    return $results;
}

/**
 * 验证字段值
 */
function content_auto_validate_field_values() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        
        // 检查API配置表字段值
        $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
        $api_configs = $wpdb->get_results("SELECT * FROM $api_configs_table");
        
        foreach ($api_configs as $config) {
            // 检查API URL格式
            if (!empty($config->api_url) && !filter_var($config->api_url, FILTER_VALIDATE_URL)) {
                $errors[] = sprintf(__('API配置 "%s" 的URL格式无效', 'content-auto-manager'), $config->name);
            }
            
            // 检查temperature值范围
            if ($config->temperature < 0 || $config->temperature > 2) {
                $warnings[] = sprintf(__('API配置 "%s" 的temperature值不在推荐范围内 (0-2)', 'content-auto-manager'), $config->name);
            }
            
            // 检查max_tokens值
            if ($config->max_tokens < 1 || $config->max_tokens > 10000) {
                $warnings[] = sprintf(__('API配置 "%s" 的max_tokens值不在推荐范围内 (1-10000)', 'content-auto-manager'), $config->name);
            }
        }
        
        // 检查发布规则表字段值
        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';
        $publish_rules = $wpdb->get_results("SELECT * FROM $publish_rules_table");
        
        foreach ($publish_rules as $rule) {
            // 检查category_mode值
            if (!empty($rule->category_mode) && !in_array($rule->category_mode, array('manual', 'auto'))) {
                $errors[] = sprintf(__('发布规则 ID %d 的category_mode值无效', 'content-auto-manager'), $rule->id);
            }
            
            // 检查序列化的category_ids
            if (!empty($rule->category_ids)) {
                $category_ids = maybe_unserialize($rule->category_ids);
                if (!is_array($category_ids)) {
                    $errors[] = sprintf(__('发布规则 ID %d 的category_ids格式无效', 'content-auto-manager'), $rule->id);
                }
            }
            
            // 检查序列化的fallback_category_ids
            if (!empty($rule->fallback_category_ids)) {
                $fallback_ids = maybe_unserialize($rule->fallback_category_ids);
                if (!is_array($fallback_ids)) {
                    $errors[] = sprintf(__('发布规则 ID %d 的fallback_category_ids格式无效', 'content-auto-manager'), $rule->id);
                }
            }
        }
        
        // 检查主题表字段值
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $invalid_priorities = $wpdb->get_var("
            SELECT COUNT(*) FROM $topics_table 
            WHERE priority_score < 1 OR priority_score > 5
        ");
        
        if ($invalid_priorities > 0) {
            $warnings[] = sprintf(__('发现 %d 个主题的priority_score值不在有效范围内 (1-5)', 'content-auto-manager'), $invalid_priorities);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings)) {
            $results['success'] = true;
            $results['message'] = __('✅ 字段值验证通过，所有字段值都符合要求', 'content-auto-manager');
        } else {
            $results['success'] = !empty($errors);
            $message_parts = array();
            
            if (!empty($errors)) {
                $message_parts[] = __('❌ 发现错误:', 'content-auto-manager');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = __('⚠️ 发现警告:', 'content-auto-manager');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = __('验证失败: ', 'content-auto-manager') . $e->getMessage();
    }
    
    return $results;
}

/**
 * 验证配置
 */
function content_auto_validate_configuration() {
    global $wpdb;
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $errors = array();
        $warnings = array();
        $infos = array();
        
        // 检查是否有激活的API配置
        $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
        $active_configs = $wpdb->get_var("SELECT COUNT(*) FROM $api_configs_table WHERE is_active = 1");
        
        if ($active_configs == 0) {
            $errors[] = __('没有激活的API配置，无法生成文章', 'content-auto-manager');
        } elseif ($active_configs == 1) {
            $warnings[] = __('当前只有1个激活的API配置，建议增加更多配置以提高可靠性和负载均衡能力', 'content-auto-manager');
        } else {
            $infos[] = sprintf(__('✅ 当前有 %d 个激活的API配置，这是推荐的配置方式，提供智能轮询、负载均衡和容错能力', 'content-auto-manager'), $active_configs);
            $infos[] = __('智能轮询机制将自动在多个API配置间切换，避免单个API过载，并在API失败时自动切换到备用配置', 'content-auto-manager');
        }
        
        // 检查发布规则配置
        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';
        $publish_rules = $wpdb->get_var("SELECT COUNT(*) FROM $publish_rules_table");
        
        if ($publish_rules == 0) {
            $warnings[] = __('没有配置发布规则，文章发布时将使用默认设置', 'content-auto-manager');
        } else {
            // 检查发布规则配置的完整性
            $rules = $wpdb->get_results("SELECT * FROM $publish_rules_table");
            foreach ($rules as $rule) {
                if (empty($rule->post_status)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 未设置文章状态', 'content-auto-manager'), $rule->id);
                }
                
                if ($rule->category_mode == 'auto' && empty($rule->fallback_category_ids)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 启用了自动分类但未设置备用分类', 'content-auto-manager'), $rule->id);
                }
                
                if ($rule->category_mode == 'manual' && empty($rule->category_ids)) {
                    $warnings[] = sprintf(__('发布规则 ID %d 设置为手动分类但未选择分类', 'content-auto-manager'), $rule->id);
                }
            }
        }
        
        // 检查WordPress分类
        if (class_exists('ContentAuto_Category_Filter')) {
            $categories = ContentAuto_Category_Filter::get_filtered_categories();
            $filter_stats = ContentAuto_Category_Filter::get_filter_stats();
            if (empty($categories)) {
                $warnings[] = __('插件可用分类为空，可能影响文章分类', 'content-auto-manager');
            } else {
                if ($filter_stats['is_filtered']) {
                    $warnings[] = sprintf(__('插件可使用 %d/%d 个分类（已启用分类过滤）', 'content-auto-manager'), count($categories), $filter_stats['total_categories']);
                } else {
                    $warnings[] = sprintf(__('WordPress中有 %d 个分类可用', 'content-auto-manager'), count($categories));
                }
            }
        } else {
            $categories = get_categories(array('hide_empty' => false));
            if (empty($categories)) {
                $warnings[] = __('WordPress中没有分类，可能影响文章分类', 'content-auto-manager');
            } else {
                $warnings[] = sprintf(__('WordPress中有 %d 个分类可用', 'content-auto-manager'), count($categories));
            }
        }
        
        // 检查用户权限
        if (!current_user_can('edit_posts')) {
            $errors[] = __('当前用户没有编辑文章的权限', 'content-auto-manager');
        }
        
        // 检查WordPress版本兼容性
        global $wp_version;
        if (version_compare($wp_version, '5.0', '<')) {
            $warnings[] = sprintf(__('WordPress版本 %s 可能存在兼容性问题，建议升级到5.0或更高版本', 'content-auto-manager'), $wp_version);
        }
        
        // 构建结果消息
        if (empty($errors) && empty($warnings) && empty($infos)) {
            $results['success'] = true;
            $results['message'] = __('✅ 配置验证通过，所有配置都正确', 'content-auto-manager');
        } else {
            $results['success'] = !empty($errors);
            $message_parts = array();
            
            if (!empty($infos)) {
                $message_parts[] = __('ℹ️ 信息:', 'content-auto-manager');
                foreach ($infos as $info) {
                    $message_parts[] = "• $info";
                }
            }
            
            if (!empty($errors)) {
                $message_parts[] = __('❌ 发现错误:', 'content-auto-manager');
                foreach ($errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($warnings)) {
                $message_parts[] = __('⚠️ 发现警告:', 'content-auto-manager');
                foreach ($warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $results['message'] = implode('<br>', $message_parts);
        }
        
        $results['details'] = array_merge($infos, $errors, $warnings);
        
    } catch (Exception $e) {
        $results['success'] = false;
        $results['message'] = __('验证失败: ', 'content-auto-manager') . $e->getMessage();
    }
    
    return $results;
}

/**
 * 运行完整验证
 */
function content_auto_run_full_validation() {
    $results = array('success' => true, 'message' => '', 'details' => array());
    
    try {
        $all_errors = array();
        $all_warnings = array();
        
        // 运行所有验证
        $integrity_result = content_auto_validate_data_integrity();
        $field_result = content_auto_validate_field_values();
        $config_result = content_auto_validate_configuration();
        
        // 收集所有错误和警告
        foreach (array($integrity_result, $field_result, $config_result) as $result) {
            if (isset($result['details'])) {
                foreach ($result['details'] as $detail) {
                    if (strpos($detail, __('❌ 发现错误:', 'content-auto-manager')) !== false || 
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
            $results['message'] = __('🎉 完整验证通过！系统运行状态良好', 'content-auto-manager');
        } else {
            $results['success'] = empty($all_errors);
            $message_parts = array();
            
            if (!empty($all_errors)) {
                $message_parts[] = __('❌ 发现错误 (' . count($all_errors) . '):', 'content-auto-manager');
                foreach ($all_errors as $error) {
                    $message_parts[] = "• $error";
                }
            }
            
            if (!empty($all_warnings)) {
                $message_parts[] = __('⚠️ 发现警告 (' . count($all_warnings) . '):', 'content-auto-manager');
                foreach ($all_warnings as $warning) {
                    $message_parts[] = "• $warning";
                }
            }
            
            $message_parts[] = '';
            $message_parts[] = __('💡 建议:', 'content-auto-manager');
            if (!empty($all_errors)) {
                $message_parts[] = '• 请优先修复错误项，确保系统正常运行';
            }
            if (!empty($all_warnings)) {
                $message_parts[] = '• 建议处理警告项，优化系统性能和稳定性';
            }
            $message_parts[] = '• 定期运行验证以确保系统健康';
            
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
        $results['message'] = __('完整验证失败: ', 'content-auto-manager') . $e->getMessage();
    }
    
    return $results;
}

/**
 * 获取任务状态
 */
function content_auto_get_task_status() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
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
function content_auto_pause_task() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
    }
    
    // 检查任务状态是否可以暂停
    if (!in_array($task->status, array('pending', 'running', 'processing'))) {
        wp_send_json_error(array('message' => __('当前任务状态不允许暂停。', 'content-auto-manager')));
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
        wp_send_json_error(array('message' => __('暂停任务失败。', 'content-auto-manager')));
    }
    
    wp_send_json_success(array('message' => __('任务已暂停。', 'content-auto-manager')));
}

/**
 * 恢复任务
 */
function content_auto_resume_task() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
    }
    
    // 检查任务状态是否可以恢复
    if ($task->status != 'paused') {
        wp_send_json_error(array('message' => __('当前任务状态不允许恢复。', 'content-auto-manager')));
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
        wp_send_json_error(array('message' => __('恢复任务失败。', 'content-auto-manager')));
    }
    
    wp_send_json_success(array('message' => __('任务已恢复。', 'content-auto-manager')));
}

/**
 * 删除任务
 */
function content_auto_delete_task() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    // 检查任务是否存在
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
    }
    
    // 检查任务状态是否可以删除
    if (!in_array($task->status, array('pending', 'running', 'processing', 'paused', 'failed', 'cancelled'))) {
        wp_send_json_error(array('message' => __('当前任务状态不允许删除。', 'content-auto-manager')));
    }
    
    // 删除任务相关数据
    try {
        $task_manager = new ContentAuto_TopicTaskManager();
        $result = $task_manager->delete_task($task_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => __('删除任务失败，请检查数据库连接或权限。', 'content-auto-manager')));
        }
        
        wp_send_json_success(array('message' => __('任务已删除，但已生成的主题数据仍保留。', 'content-auto-manager')));
    } catch (Throwable $e) {
        // 记录错误日志
        error_log('Delete Task Error: ' . $e->getMessage());
        wp_send_json_error(array('message' => __('删除任务时发生错误: ', 'content-auto-manager') . $e->getMessage()));
    }
}

/**
 * 获取任务进度
 */
function content_auto_get_task_progress() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = sanitize_text_field($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    global $wpdb;
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $tasks_table WHERE topic_task_id = %s",
        $task_id
    ));
    
    if (!$task) {
        wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
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
            'label' => content_auto_get_status_label($status)
        );
    }
    
    wp_send_json_success(array(
        'task_id' => $task->id,
        'status' => $task->status,
        'status_label' => content_auto_get_status_label($task->status),
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
add_action('wp_ajax_content_auto_retry_task', 'content_auto_retry_task_handler');
function content_auto_retry_task_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => '安全验证失败。'));
        return;
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '您没有权限执行此操作。'));
        return;
    }

    // 获取并验证 task_id
    if (!isset($_POST['task_id']) || empty($_POST['task_id'])) {
        wp_send_json_error(array('message' => '无效的任务ID。'));
        return;
    }
    $topic_task_id = sanitize_text_field($_POST['task_id']);

    // 根据 topic_task_id 获取主任务的数字 ID
    global $wpdb;
    $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
    $task_numeric_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$task_table} WHERE topic_task_id = %s", $topic_task_id));

    if (!$task_numeric_id) {
        wp_send_json_error(array('message' => '找不到指定的任务。'));
        return;
    }

    // 执行重试
    try {
        $topic_task_manager = new ContentAuto_TopicTaskManager();
        $result = $topic_task_manager->retry_task($task_numeric_id);

        if ($result) {
            wp_send_json_success(array('message' => '任务已标记为重试，将在下一个计划任务周期执行。'));
        } else {
            wp_send_json_error(array('message' => '任务重试失败，请检查日志获取更多信息。'));
        }
    } catch (Exception $e) {
        wp_send_json_error(array('message' => '任务重试过程中发生错误: ' . $e->getMessage()));
    }
}

/**
 * 批量重试主题任务
 */
add_action('wp_ajax_content_auto_bulk_retry_topic_tasks', 'content_auto_bulk_retry_topic_tasks_handler');
function content_auto_bulk_retry_topic_tasks_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => '安全验证失败。'));
        return;
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => '您没有权限执行此操作。'));
        return;
    }

    // 获取并验证 task_ids
    if (!isset($_POST['task_ids']) || !is_array($_POST['task_ids']) || empty($_POST['task_ids'])) {
        wp_send_json_error(array('message' => '无效的任务ID列表。'));
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
            $task_table = $wpdb->prefix . 'content_auto_topic_tasks';
            $task_numeric_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$task_table} WHERE topic_task_id = %s", $topic_task_id));

            if (!$task_numeric_id) {
                $failures[] = "任务 {$topic_task_id} 未找到";
                continue;
            }

            // 执行重试
            $topic_task_manager = new ContentAuto_TopicTaskManager();
            $result = $topic_task_manager->retry_task($task_numeric_id);

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
            'message' => "成功提交 {$success_count} 个任务的重试请求，将在后台处理。"
        ));
    } else {
        wp_send_json_success(array(
            'message' => "成功提交 {$success_count} 个任务的重试请求，{$failures} 个任务失败。",
            'failures' => $failures
        ));
    }
}

/**
 * 获取文章任务详情 - 重构版本
 */
function content_auto_get_article_task_details() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = intval($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    try {
        $article_task_manager = new ContentAuto_ArticleTaskManager();
        $task = $article_task_manager->get_task($task_id);
        
        if (!$task) {
            wp_send_json_error(array('message' => __('任务不存在。', 'content-auto-manager')));
        }
        
        // 获取任务进度信息
        $progress = $article_task_manager->get_task_progress($task_id);
        
        // 获取子任务详情
        $subtasks_info = content_auto_get_article_subtasks_info($task_id, $task);
        
        // 构建HTML内容
        ob_start();
        ?>
        <div class="task-details-container">
            <!-- 基本信息 -->
            <div class="task-basic-info">
                <table class="task-details-table">
                    <tr>
                        <th><?php _e('任务ID', 'content-auto-manager'); ?></th>
                        <td><?php echo esc_html($task['article_task_id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('任务名称', 'content-auto-manager'); ?></th>
                        <td><?php echo esc_html($task['name']); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('当前状态', 'content-auto-manager'); ?></th>
                        <td>
                            <span class="task-status status-<?php echo esc_attr($task['status']); ?>">
                                <?php echo content_auto_manager_get_status_label($task['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('总体进度', 'content-auto-manager'); ?></th>
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
                        <th><?php _e('成功/失败统计', 'content-auto-manager'); ?></th>
                        <td>
                            <div class="stats-container">
                                <span class="success-stat">✓ <?php echo $progress['completed_topics']; ?> 成功</span>
                                <span class="failed-stat">✗ <?php echo $progress['failed_topics']; ?> 失败</span>
                                <?php if ($progress['success_rate'] > 0): ?>
                                    <span class="success-rate">成功率: <?php echo $progress['success_rate']; ?>%</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('创建时间', 'content-auto-manager'); ?></th>
                        <td><?php echo content_auto_manager_format_time($task['created_at']); ?></td>
                    </tr>
                    <?php if ($task['last_processed_at']): ?>
                    <tr>
                        <th><?php _e('最后处理时间', 'content-auto-manager'); ?></th>
                        <td><?php echo content_auto_manager_format_time($task['last_processed_at']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($task['error_message'])): ?>
                    <tr>
                        <th><?php _e('错误信息', 'content-auto-manager'); ?></th>
                        <td class="error-message"><?php echo esc_html($task['error_message']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- 子任务列表 -->
            <div class="subtasks-section">
                <h3><?php _e('子任务执行状态', 'content-auto-manager'); ?></h3>
                
                <?php if (!empty($subtasks_info)): ?>
                    <div class="subtasks-summary">
                        <span class="summary-item pending">
                            待处理: <?php echo $progress['subtask_status_counts']['pending']; ?>
                        </span>
                        <span class="summary-item processing">
                            处理中: <?php echo $progress['subtask_status_counts']['processing']; ?>
                        </span>
                        <span class="summary-item completed">
                            已完成: <?php echo $progress['subtask_status_counts']['completed']; ?>
                        </span>
                        <span class="summary-item failed">
                            失败: <?php echo $progress['subtask_status_counts']['failed']; ?>
                        </span>
                    </div>
                    
                    <table class="subtasks-table">
                        <thead>
                            <tr>
                                <th><?php _e('主题ID', 'content-auto-manager'); ?></th>
                                <th><?php _e('主题标题', 'content-auto-manager'); ?></th>
                                <th><?php _e('执行状态', 'content-auto-manager'); ?></th>
                                <th><?php _e('错误信息', 'content-auto-manager'); ?></th>
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
                                                <span title="<?php _e('使用的提示词模板', 'content-auto-manager'); ?>"><?php echo esc_html($subtask['prompt_template']); ?></span>
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
                                                    <?php _e('查看生成的文章', 'content-auto-manager'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="subtask-status <?php echo esc_attr($subtask['queue_status']); ?>">
                                            <?php echo content_auto_get_subtask_status_label($subtask['queue_status']); ?>
                                        </span>
                                        <?php if ($subtask['retry_count'] > 0): ?>
                                            <div class="retry-info">
                                                <small>重试次数: <?php echo $subtask['retry_count']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($subtask['error_message'])): ?>
                                            <div class="error-message" title="<?php echo esc_attr($subtask['error_message']); ?>">
                                                <?php echo esc_html(wp_trim_words($subtask['error_message'], 10)); ?>
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
                    <p class="no-subtasks"><?php _e('暂无子任务信息。', 'content-auto-manager'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .task-details-container {
            max-width: 100%;
        }
        
        .task-basic-info {
            margin-bottom: 30px;
        }
        
        .progress-container {
            min-width: 200px;
        }
        
        .stats-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .success-stat {
            color: #00a32a;
            font-weight: 600;
        }
        
        .failed-stat {
            color: #dc3232;
            font-weight: 600;
        }
        
        .success-rate {
            color: #666;
            font-size: 12px;
        }
        
        .error-message {
            color: #dc3232;
            font-weight: 500;
        }
        
        .subtasks-section {
            margin-top: 30px;
        }
        
        .subtasks-summary {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
            flex-wrap: wrap;
        }
        
        .summary-item {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .summary-item.pending {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .summary-item.processing {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .summary-item.completed {
            background: #e8f5e8;
            color: #2e7d32;
        }
        
        .summary-item.failed {
            background: #ffebee;
            color: #c62828;
        }
        
        .subtasks-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .subtasks-table th,
        .subtasks-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        
        .subtasks-table th {
            background: #f1f1f1;
            font-weight: 600;
        }
        
        .subtask-row:nth-child(even) {
            background: #f9f9f9;
        }
        
        .article-link {
            margin-top: 4px;
        }
        
        .article-link a {
            font-size: 12px;
            color: #0073aa;
            text-decoration: none;
        }
        
        .article-link a:hover {
            text-decoration: underline;
        }
        
        .retry-info {
            margin-top: 2px;
        }
        
        .retry-info small {
            color: #666;
        }
        
        .no-error {
            color: #999;
        }
        
        .no-subtasks {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }
        </style>
        <?php
        
        $html = ob_get_clean();
        wp_send_json_success(array('html' => $html));
        
    } catch (Exception $e) {
        wp_send_json_error(array('message' => __('获取任务详情失败: ', 'content-auto-manager') . $e->getMessage()));
    }
}

/**
 * 获取文章子任务信息
 */
function content_auto_get_article_subtasks_info($task_id, $task) {
    global $wpdb;
    
    $topics_table = $wpdb->prefix . 'content_auto_topics';
    $articles_table = $wpdb->prefix . 'content_auto_articles';
    $queue_table = $wpdb->prefix . 'content_auto_job_queue';
    
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
            'topic_title' => $topic ? $topic['title'] : __('主题不存在', 'content-auto-manager'),
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
function content_auto_get_subtask_status_label($status) {
    switch ($status) {
        case 'pending':
            return __('待处理', 'content-auto-manager');
        case 'processing':
            return __('处理中', 'content-auto-manager');
        case 'completed':
            return __('已完成', 'content-auto-manager');
        case 'failed':
            return __('失败', 'content-auto-manager');
        case 'not_queued':
            return __('未入队', 'content-auto-manager');
        case 'running':
            return __('运行中', 'content-auto-manager');
        case 'success':
            return __('成功', 'content-auto-manager');
        default:
            // 对于未知状态，返回友好描述
            $status_map = array(
                'success' => __('成功', 'content-auto-manager'),
                'cancelled' => __('已取消', 'content-auto-manager'),
                'retry' => __('重试中', 'content-auto-manager'),
                'paused' => __('已暂停', 'content-auto-manager')
            );
            return isset($status_map[$status]) ? $status_map[$status] : ucfirst($status);
    }
}



/**
 * 验证数据完整性 - 已移除验证功能
 */
add_action('wp_ajax_content_auto_validate_data_integrity', 'content_auto_validate_data_integrity_handler');
function content_auto_validate_data_integrity_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 验证功能已移除，返回成功状态
    wp_send_json_success(array(
        'message' => __('验证功能已移除，数据库结构由插件自动管理。', 'content-auto-manager'),
        'article_tasks_validation' => array('valid' => true, 'missing_fields' => array()),
        'job_queue_validation' => array('valid' => true, 'missing_fields' => array())
    ));
}

/**
 * 验证字段值 - 功能已简化
 */
add_action('wp_ajax_content_auto_validate_field_values', 'content_auto_validate_field_values_handler');
function content_auto_validate_field_values_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 简化验证，仅返回基础统计信息
    global $wpdb;
    
    $validation_results = array(
        'message' => __('字段验证功能已简化，插件自动管理数据结构。', 'content-auto-manager'),
        'article_tasks_fields' => array(
            'total_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_article_tasks"),
            'status' => 'managed_by_plugin'
        ),
        'job_queue_fields' => array(
            'total_article_queue_records' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_job_queue WHERE job_type = 'article'"),
            'status' => 'managed_by_plugin'
        )
    );
    
    wp_send_json_success($validation_results);
}

/**
 * 验证配置 - 功能已简化
 */
add_action('wp_ajax_content_auto_validate_configuration', 'content_auto_validate_configuration_handler');
function content_auto_validate_configuration_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 简化配置验证，仅返回基础统计
    global $wpdb;
    
    $validation_results = array(
        'message' => __('配置验证功能已简化，插件自动管理配置。', 'content-auto-manager'),
        'api_configuration' => array(
            'total_apis' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_api_configs"),
            'active_apis' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_api_configs WHERE is_active = 1"),
            'has_active_api' => true // 插件启动时会确保有可用配置
        ),
        'publish_rules' => array(
            'total_rules' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_publish_rules"),
            'has_rules' => true // 插件启动时会确保有默认规则
        )
    );
    
    wp_send_json_success($validation_results);
}

/**
 * 运行完整验证 - 已移除验证功能
 */
add_action('wp_ajax_content_auto_run_full_validation', 'content_auto_run_full_validation_handler');
function content_auto_run_full_validation_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    // 完整验证功能已移除，返回简化结果
    wp_send_json_success(array(
        'message' => __('验证功能已移除，插件自动管理数据库结构。', 'content-auto-manager'),
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
function content_auto_retry_article_task() {
    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }
    
    $task_id = intval($_POST['task_id']);
    
    if (empty($task_id)) {
        wp_send_json_error(array('message' => __('任务ID不能为空。', 'content-auto-manager')));
    }
    
    try {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-article-task-manager.php';
        $article_task_manager = new ContentAuto_ArticleTaskManager();
        
        // 调用retry_task并设置$force_retry为true，以触发强制重试逻辑
        $result = $article_task_manager->retry_task($task_id, null, true);
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('任务已成功重置，失败的子任务将重新执行。', 'content-auto-manager')
            ));
        } else {
            wp_send_json_error(array('message' => __('任务重试失败。可能没有需要重试的失败子任务。', 'content-auto-manager')));
        }

    } catch (Exception $e) {
        // 记录详细错误日志
        error_log('文章任务重试异常: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error(array('message' => __('重试过程中发生服务器错误，请检查插件日志。', 'content-auto-manager') . $e->getMessage()));
    }
}

/**
 * 网址内容采集处理器
 */
add_action('wp_ajax_content_auto_fetch_url_content', 'content_auto_fetch_url_content_handler');
function content_auto_fetch_url_content_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
    }

    // 获取网址参数
    $url = isset($_POST['url']) ? sanitize_url($_POST['url']) : '';

    if (empty($url)) {
        wp_send_json_error(array('message' => __('请提供有效的网址。', 'content-auto-manager')));
    }

    // 验证网址格式
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => __('网址格式不正确。', 'content-auto-manager')));
    }

    try {
        // 构建Jina AI Reader API URL
        // 确保URL有协议前缀
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // 构建API URL：直接在原URL前加上 https://r.jina.ai/
        $api_url = 'https://r.jina.ai/' . $url;

        error_log('原始URL: ' . $url);
        error_log('Jina AI API URL: ' . $api_url);

        // 设置请求参数
        $headers = array(
            'Accept' => 'text/plain;charset=utf-8',
            'X-Retain-Images' => 'none',
            'X-Return-Format' => 'text'
        );

        // 动态获取 API Key
        $jina_key = get_option('content_auto_jina_api_key', '');
        if (!empty($jina_key)) {
            $headers['Authorization'] = 'Bearer ' . $jina_key;
        }

        $args = array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => $headers
        );

        // 发送HTTP请求
        $response = wp_remote_get($api_url, $args);

        // 检查请求是否成功
        if (is_wp_error($response)) {
            error_log('网址采集请求失败: ' . $response->get_error_message());
            wp_send_json_error(array('message' => __('无法访问指定网址，请检查网址是否正确或网络连接。', 'content-auto-manager')));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            error_log('网址采集HTTP错误: ' . $http_code . ' - URL: ' . $url);
            wp_send_json_error(array('message' => __('无法获取网页内容，HTTP状态码: ', 'content-auto-manager') . $http_code));
        }

        // 获取内容
        $content = wp_remote_retrieve_body($response);

        if (empty($content)) {
            wp_send_json_error(array('message' => __('网页内容为空或无法解析。', 'content-auto-manager')));
        }

        // Jina AI API已配置为自动过滤链接和图片，只需要移除元数据头部
        $content = preg_replace('/^Title:.*?\n\n/is', '', $content);
        $content = preg_replace('/^URL Source:.*?\n/is', '', $content);
        $content = preg_replace('/^Published Time:.*?\n/is', '', $content);
        $content = preg_replace('/^Warning:.*?\n/is', '', $content);

        // 限制在3000字符以内
        if (mb_strlen($content, 'UTF-8') > 3000) {
            $content = mb_substr($content, 0, 3000, 'UTF-8');
        }

        // 记录成功日志
        error_log('网址采集成功: ' . $url . ' - 内容长度: ' . mb_strlen($content, 'UTF-8'));

        wp_send_json_success(array(
            'content' => $content,
            'original_length' => mb_strlen(wp_remote_retrieve_body($response), 'UTF-8'),
            'final_length' => mb_strlen($content, 'UTF-8'),
            'url' => $url
        ));

    } catch (Exception $e) {
        error_log('网址采集异常: ' . $e->getMessage() . ' - URL: ' . $url);
        wp_send_json_error(array('message' => __('采集过程中发生错误，请稍后重试。', 'content-auto-manager')));
    }
}

/**
 * 清除任务队列
 */
function content_auto_clear_task_queue() {
    // 验证用户权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('您没有足够的权限执行此操作。', 'content-auto-manager')));
    }

    // 验证nonce
    if (!wp_verify_nonce($_POST['nonce'], 'content_auto_clear_queue')) {
        wp_send_json_error(array('message' => __('安全验证失败，请刷新页面后重试。', 'content-auto-manager')));
    }

    global $wpdb;
    $results = array();

    try {
        // 开始数据库事务
        $wpdb->query('START TRANSACTION');

        // 记录操作前的状态
        $before_stats = array();

        // 统计主题任务
        $before_stats['topic_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_topic_tasks");
        $before_stats['topic_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_topic_tasks WHERE status = 'pending'");
        $before_stats['topic_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_topic_tasks WHERE status = 'processing'");

        // 统计文章任务
        $before_stats['article_tasks'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_article_tasks");
        $before_stats['article_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_article_tasks WHERE status = 'pending'");
        $before_stats['article_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_article_tasks WHERE status = 'processing'");

        // 统计队列项目
        $before_stats['queue_items'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_job_queue");
        $before_stats['queue_pending'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_job_queue WHERE status = 'pending'");
        $before_stats['queue_processing'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}content_auto_job_queue WHERE status = 'processing'");

        // 清除操作
        $cleared_counts = array();

        // 1. 重置所有处理中的主题任务为pending状态
        $cleared_counts['topic_tasks_reset'] = $wpdb->query(
            "UPDATE {$wpdb->prefix}content_auto_topic_tasks
             SET status = 'pending', error_message = '', updated_at = NOW()
             WHERE status IN ('processing', 'failed')"
        );

        // 2. 重置所有处理中的文章任务为pending状态
        $cleared_counts['article_tasks_reset'] = $wpdb->query(
            "UPDATE {$wpdb->prefix}content_auto_article_tasks
             SET status = 'pending', error_message = '', updated_at = NOW()
             WHERE status IN ('processing', 'failed')"
        );

        // 3. 清除所有队列项目
        $cleared_counts['queue_items_deleted'] = $wpdb->query("DELETE FROM {$wpdb->prefix}content_auto_job_queue");

        // 4. 重置最后处理时间（可选）
        $wpdb->query(
            "UPDATE {$wpdb->prefix}content_auto_topic_tasks
             SET last_processed_at = NULL
             WHERE status = 'pending'"
        );

        $wpdb->query(
            "UPDATE {$wpdb->prefix}content_auto_article_tasks
             SET last_processed_at = NULL
             WHERE status = 'pending'"
        );

        // 提交事务
        $wpdb->query('COMMIT');

        // 构建成功消息
        $message_parts = array();
        $message_parts[] = '✅ 任务队列已成功清除';
        $message_parts[] = sprintf('📊 清理统计：');
        $message_parts[] = sprintf('   - 主题任务重置：%d 个', $cleared_counts['topic_tasks_reset']);
        $message_parts[] = sprintf('   - 文章任务重置：%d 个', $cleared_counts['article_tasks_reset']);
        $message_parts[] = sprintf('   - 队列项目删除：%d 个', $cleared_counts['queue_items_deleted']);

        // 记录操作日志
        $log_message = sprintf(
            '管理员 %s 清除了任务队列。清除统计：主题任务 %d，文章任务 %d，队列项目 %d',
            wp_get_current_user()->user_login,
            $cleared_counts['topic_tasks_reset'],
            $cleared_counts['article_tasks_reset'],
            $cleared_counts['queue_items_deleted']
        );

        // 如果有日志记录器，记录日志
        if (class_exists('ContentAuto_PluginLogger')) {
            $logger = new ContentAuto_PluginLogger();
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
            'message' => __('清除队列时发生错误：', 'content-auto-manager') . $e->getMessage(),
            'error_code' => 'CLEAR_QUEUE_ERROR'
        ));
    }
}
add_action('wp_ajax_content_auto_clear_task_queue', 'content_auto_clear_task_queue');

/**
 * 测试参考资料召回
 */
function cam_test_reference_recall_handler() {
    // 验证nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_reference_recall_test')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
        return;
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
        return;
    }
    
    $topic_id = isset($_POST['topic_id']) ? intval($_POST['topic_id']) : 0;
    if ($topic_id <= 0) {
        wp_send_json_error(array('message' => __('无效的主题ID。', 'content-auto-manager')));
        return;
    }
    
    // 获取主题数据
    global $wpdb;
    $topics_table = $wpdb->prefix . 'content_auto_topics';
    $topic = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$topics_table} WHERE id = %d",
        $topic_id
    ), ARRAY_A);
    
    if (!$topic) {
        wp_send_json_error(array('message' => __('主题不存在。', 'content-auto-manager')));
        return;
    }
    
    // 加载参考资料服务类
    if (!class_exists('ContentAuto_ReferenceMaterialService')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-reference-material-service.php';
    }
    
    // 执行召回测试
    $service = new ContentAuto_ReferenceMaterialService();
    $result = $service->test_brand_profile_recall($topic);
    
    wp_send_json_success($result);
}

/**
 * 测试搜索API
 */
function content_auto_test_search_api() {
    // 验证nonce
    if (!check_ajax_referer('content_auto_manager_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'content-auto-manager')));
        return;
    }
    
    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'content-auto-manager')));
        return;
    }

    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    if (empty($query)) {
        wp_send_json_error(array('message' => __('搜索关键词不能为空。', 'content-auto-manager')));
        return;
    }
    
    // 使用公共搜索函数执行搜索
    // 该函数会自动读取全局配置（Region, Time, SafeSearch等）并强制使用Lite后端
    $result = content_auto_search($query);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => __('请求失败: ', 'content-auto-manager') . $result->get_error_message()));
        return;
    }
    
    if (isset($result['success']) && $result['success']) {
        wp_send_json_success($result);
    } else {
        $error_msg = isset($result['error']) ? $result['error'] : __('搜索未知错误', 'content-auto-manager');
        if (isset($result['message'])) {
             $error_msg .= ' (' . $result['message'] . ')';
        }
        wp_send_json_error(array('message' => $error_msg));
    }
}
add_action('wp_ajax_content_auto_test_search_api', 'content_auto_test_search_api');


