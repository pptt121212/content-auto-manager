<?php
/**
 * 文章队列处理器
 * 专门处理文章生成子任务，基于主题任务成功模式的队列处理逻辑
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入依赖的组件
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/class-xml-template-processor.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-content-filter.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-markdown-converter.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-config.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/class-rule-manager.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-pinyin-converter.php';
require_once __DIR__ . '/class-article-performance-monitor.php';

class Yali_AI_Writer_ArticleQueueProcessor {
    
    private $database;
    private $logger;
    private $performance_monitor;
    private $xml_processor;
    private $api_handler;
    private $content_filter;
    private $markdown_converter;
    private $api_config;
    
    public function __construct() {
        $this->database = new Yali_AI_Writer_Database();
        $this->logger = new Yali_AI_Writer_LoggingSystem();
        $this->performance_monitor = new Yali_AI_Writer_ArticlePerformanceMonitor();
        $this->xml_processor = new Yali_AI_Writer_XmlTemplateProcessor();
        $this->api_handler = new Yali_AI_Writer_UnifiedApiHandler($this->logger);
        $this->content_filter = new Yali_AI_Writer_ContentFilter();
        $this->markdown_converter = new Yali_AI_Writer_MarkdownConverter();
        $this->api_config = new Yali_AI_Writer_ApiConfig();
    }
    
    /**
     * 处理单个文章生成子任务
     * 基于主题任务成功模式的队列处理逻辑
     */
    public function process_article_subtask($task_id, $subtask_id, $job_queue_id = null) {
        $context = $this->logger->build_context(null, null, array(
            'task_id' => $task_id, 
            'subtask_id' => $subtask_id,
            'job_queue_id' => $job_queue_id
        ));
        
        // 开始性能监控
        $this->performance_monitor->start_timing('process_article_subtask', $context);
        $this->logger->log_success('SUBTASK_START', '开始处理文章生成子任务', $context);
        
        try {
            // 1. 从队列记录中获取主题ID
            $this->log_debug('QUEUE_FETCH', '从队列记录中获取主题ID', $context);
            $topic_id = $this->get_topic_id_from_queue($task_id, $subtask_id);
            if (!$topic_id) {
                $error_message = '无法从队列记录中获取主题ID';
                $this->logger->log_error('QUEUE_FETCH_ERROR', $error_message, $context);
                $this->performance_monitor->record_error('database', 'QUEUE_FETCH_ERROR', $context);
                $this->performance_monitor->end_timing('process_article_subtask', false);
                return ['success' => false, 'message' => $error_message, 'error' => ['stage' => '获取队列信息', 'message' => $error_message]];
            }
            $context['topic_id'] = $topic_id;
            $this->logger->log_info('QUEUE_FETCH_SUCCESS', "成功获取主题ID: {$topic_id}", $context);
            
            // 2. 获取主题信息
            $this->log_debug('TOPIC_FETCH', "获取主题信息: {$topic_id}", $context);
            $topic = $this->database->get_row('yali_ai_writer_topics', array('id' => $topic_id));
            if (!$topic) {
                $error_message = '主题不存在: ' . $topic_id;
                $this->logger->log_error('TOPIC_NOT_FOUND', $error_message, $context);
                $this->performance_monitor->record_error('database', 'TOPIC_NOT_FOUND', $context);
                $this->performance_monitor->end_timing('process_article_subtask', false);
                return ['success' => false, 'message' => $error_message, 'error' => ['stage' => '主题验证', 'message' => $error_message]];
            }
            $this->log_debug('TOPIC_FETCH_SUCCESS', "主题信息获取成功: {$topic['title']}", $context);
            
            // 🚀 二次核心修复：兼容对象/数组返回值并彻底净化素材
            $rule_manager = new Yali_AI_Writer_RuleManager();
            $rule = $rule_manager->get_rule($topic['rule_id']);
            
            // 兼容性判断：rule 有可能是对象也有可能是数组
            $rule_type = '';
            if (is_object($rule)) {
                $rule_type = $rule->rule_type ?? '';
            } elseif (is_array($rule)) {
                $rule_type = $rule['rule_type'] ?? '';
            }
            
            $topic['is_rewrite'] = ($rule_type === 'collect_url_rewrite');
            
            if ($topic['is_rewrite']) {
                $this->logger->log_info('REWRITE_MODE_DETECTED', '确认采集仿写模式，正在净化素材...', $context);
                // 从参考资料中剔除可能存在的图片标签，避免在仿写模式下复制原内容格式
                if (!empty($topic['reference_material'])) {
                    $topic['reference_material'] = preg_replace('/<!--\s*image\s+prompt:.*?-->/is', '', $topic['reference_material']);
                }
            }
            
            // 3. 获取发布规则（如果不存在则使用默认配置）
            $this->log_debug('PUBLISH_RULES_FETCH', '获取发布规则', $context);
            $publish_rules = $this->database->get_row('yali_ai_writer_publish_rules', array('id' => 1));
            if (!$publish_rules) {
                $this->logger->log_info('PUBLISH_RULES_DEFAULT', '发布规则不存在，使用默认配置', $context);
                $publish_rules = $this->get_default_publish_rules();
            }
            $this->logger->log_info('PUBLISH_RULES_FETCH_SUCCESS', '发布规则获取成功', $context);
            
            // 验证：如果启用了自动配图，但未配置图像API，则强制关闭自动配图
            if (isset($publish_rules['auto_image_insertion']) && $publish_rules['auto_image_insertion'] == 1) {
                if (!$this->is_image_api_configured()) {
                    $publish_rules['auto_image_insertion'] = 0;
                    $this->logger->log_warning('AUTO_IMAGE_DISABLED', '检测到图像API未配置或密钥为空，已强制关闭自动配图功能', $context);
                }
            }
            
            // 4. 获取相关内容
            // 🚀 关键修复：仿写模式下，素材在主题表的 reference_material 字段中，而非 rule_items 表
            if ($topic['is_rewrite'] && !empty($topic['reference_material'])) {
                // 仿写模式：直接使用存储在主题中的采集内容
                $related_content = [
                    [
                        'content' => $topic['reference_material'],
                        'title' => $topic['original_title'] ?? '',
                        'url' => $topic['source_url'] ?? ''
                    ]
                ];
                $this->log_debug('REWRITE_CONTENT_SOURCE', '使用主题 reference_material 作为仿写素材', array_merge($context, [
                    'material_length' => strlen($topic['reference_material'])
                ]));
            } else {
                // 标准模式：从规则项目表获取内容
                $related_content = $rule_manager->get_content_by_rule($topic['rule_id'], 5);
            }
            
            // 5. 生成文章内容
            $result = $this->generate_article_content($topic, $related_content, $publish_rules, $task_id, $subtask_id, $job_queue_id);
            if (!$result['success']) {
                // 确保错误信息格式正确，兼容队列表处理逻辑
                $error_message = isset($result['error']['message']) ? $result['error']['message'] : '文章内容生成失败';
                return ['success' => false, 'message' => $error_message, 'error' => $result['error']];
            }
            
            // 获取实际使用的 API 配置并存回 topic 数组，以便 save_article_record 使用
            $used_api_config = $this->api_handler->get_current_api_config();
            if ($used_api_config) {
                $topic['api_config_id'] = $used_api_config['id'];
                $topic['api_config_name'] = $used_api_config['name'];
            }
            
            // 6. 创建WordPress文章（根据是否启用自动配图选择处理方式）
            $this->log_debug('POST_CREATION', '开始创建WordPress文章', $context);
            
            if (isset($publish_rules['auto_image_insertion']) && $publish_rules['auto_image_insertion'] == 1) {
                // 【修复】改为同步处理图片，避免WP-Cron触发问题
                // 原方案：异步调度WP-Cron任务，但WP-Cron依赖网站访问，导致后续任务不执行
                // 新方案：在文章生成流程中直接处理图片，确保每张图片都生成成功
                $post_id = $this->create_wordpress_post_with_sync_images($topic['title'], $result['content'], $publish_rules, $topic);
            } else {
                // 未启用自动配图：使用标准处理模式
                $post_id = $this->create_wordpress_post($topic['title'], $result['content'], $publish_rules, $topic);
            }
            
            if (!$post_id) {
                $error_message = '创建WordPress文章失败';
                $this->logger->log_error('POST_CREATION_FAILED', $error_message, $context);
                $this->performance_monitor->record_error('wordpress', 'POST_CREATION_FAILED', $context);
                $this->performance_monitor->end_timing('process_article_subtask', false);
                return ['success' => false, 'message' => $error_message, 'error' => ['stage' => '文章创建', 'message' => $error_message]];
            }
            $context['post_id'] = $post_id;
            $this->logger->log_success('POST_CREATION_SUCCESS', "WordPress文章创建成功，文章ID: {$post_id}", $context);
            
            // 7. 保存文章记录
            $this->log_debug('ARTICLE_RECORD_SAVE', '保存文章记录', $context);
            $this->save_article_record($topic, $post_id, $result['content'], time());
            $this->logger->log_info('ARTICLE_RECORD_SAVE_SUCCESS', '文章记录保存成功', $context);
            
            // 8. 更新主题状态
            $this->log_debug('TOPIC_STATUS_UPDATE', '更新主题状态为已使用', $context);
            
            // 验证主题状态，只有从queued状态才能更新为used
            if ($topic['status'] === YALI_AI_WRITER_TOPIC_QUEUED) {
                $this->database->update('yali_ai_writer_topics', array('status' => YALI_AI_WRITER_TOPIC_USED), array('id' => $topic_id));
                $this->logger->log_info('TOPIC_STATUS_UPDATE_SUCCESS', '主题状态更新成功', $context);
            } else {
                // 如果主题不是queued状态，记录警告但继续执行
                $this->logger->log_warning('TOPIC_STATUS_INVALID', '主题状态不是queued，但仍更新为used', array_merge($context, array('current_status' => $topic['status'])));
                $this->database->update('yali_ai_writer_topics', array('status' => YALI_AI_WRITER_TOPIC_USED), array('id' => $topic_id));
            }
            
            // 9. 更新子任务状态为成功
            $this->log_debug('SUBTASK_STATUS_UPDATE', '更新子任务状态为完成', $context);
            $this->update_subtask_status($task_id, $subtask_id, 'completed');
            
            $this->logger->log_success('SUBTASK_COMPLETED', '文章生成子任务处理完成', $context);
            $this->performance_monitor->end_timing('process_article_subtask', true, array(
                'post_id' => $post_id,
                'topic_title' => $topic['title']
            ));
            return ['success' => true, 'post_id' => $post_id];
            
        } catch (Exception $e) {
            $error_message = '子任务处理异常: ' . $e->getMessage();
            $exception_context = array_merge($context, array(
                'exception_type' => get_class($e),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ));
            $this->logger->log_error('SUBTASK_EXCEPTION', $error_message, $exception_context);
            $this->performance_monitor->record_error('system', 'SUBTASK_EXCEPTION', $exception_context);
            $this->performance_monitor->end_timing('process_article_subtask', false);
            $this->update_subtask_status($task_id, $subtask_id, 'failed', $error_message);
            return ['success' => false, 'message' => $error_message, 'error' => ['stage' => '系统异常', 'message' => $error_message]];
        }
    }
    
    /**
     * 从队列记录中获取主题ID
     */
    private function get_topic_id_from_queue($task_id, $subtask_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
        
        $queue_record = $wpdb->get_row($wpdb->prepare(
            "SELECT reference_id FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND subtask_id = %s",
            $task_id, $subtask_id
        ));
        
        return $queue_record ? $queue_record->reference_id : null;
    }
    
    /**
     * 更新子任务状态
     */
    private function update_subtask_status($task_id, $subtask_id, $status, $error_message = '') {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
        
        $update_data = array(
            'status' => $status,
            'updated_at' => current_time('mysql')
        );
        
        if (!empty($error_message)) {
            $update_data['error_message'] = $error_message;
        }
        
        return $wpdb->update(
            $queue_table,
            $update_data,
            array('job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id)
        );
    }   
  
   
 /**
     * 插入品牌资料到文章内容中
     * 
     * @param string $html_content 文章HTML内容
     * @param array $topic 主题数据
     * @param array $publish_rules 发布规则
     * @return string 处理后的HTML内容
     */
    private function insert_brand_profile($html_content, &$topic, $publish_rules) {
        if (!class_exists('Yali_AI_Writer_PluginLogger')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new Yali_AI_Writer_PluginLogger();

        if (!isset($publish_rules['enable_brand_profile_insertion']) || !$publish_rules['enable_brand_profile_insertion']) {
            return $html_content;
        }

        // Condition 2: Check if topic has a vector embedding
        if (empty($topic['vector_embedding'])) {
            return $html_content;
        }

        // Condition 3: Check if any brand profiles with vectors exist
        global $wpdb;
        $brand_profiles_table = $wpdb->prefix . 'yali_ai_writer_brand_profiles';
        $brand_profiles = $wpdb->get_results("SELECT * FROM {$brand_profiles_table} WHERE vector IS NOT NULL AND type IN ('standard', 'custom_html')", ARRAY_A);

        if (empty($brand_profiles)) {
            return $html_content;
        }

        // Decode topic vector
        $topic_vector_decoded = base64_decode($topic['vector_embedding']);
        if (!$topic_vector_decoded) {
            return $html_content;
        }
        $topic_vector = unpack('f*', $topic_vector_decoded);

        $best_match = null;
        $highest_similarity = -1;

        // Ensure the cosine similarity function is available
        if (!function_exists('yali_ai_writer_calculate_cosine_similarity')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/functions.php';
        }

        foreach ($brand_profiles as $profile) {
            $profile_vector_decoded = base64_decode($profile['vector']);
            if (!$profile_vector_decoded) {
                continue;
            }
            $profile_vector = unpack('f*', $profile_vector_decoded);

            if (count($topic_vector) !== count($profile_vector)) {
                continue;
            }

            $similarity = yali_ai_writer_calculate_cosine_similarity($topic_vector, $profile_vector);

            if ($similarity > $highest_similarity) {
                $highest_similarity = $similarity;
                $best_match = $profile;
            }
        }

        // Threshold check
        if ($best_match && $highest_similarity > 0.3) {
            // 根据品牌物料类型生成不同的HTML
            $brand_type = isset($best_match['type']) ? $best_match['type'] : 'standard';
            
            if ($brand_type === 'custom_html' && !empty($best_match['custom_html'])) {
                // 自定义HTML类型
                $brand_html_block = '<br>' .
                    '<div style="max-width: 100%; overflow: hidden; word-wrap: break-word; margin: 10px 0;">' .
                        $best_match['custom_html'] .
                    '</div>' .
                '<br>';
            } else {
                // 标准样式类型
                $has_description = !empty($best_match['description']);
                $has_link = !empty($best_match['link']);
                
                $brand_html_block = '<br>' .
                    '<p style="text-align: center; margin: 10px 0;">' .
                        ($has_link ? '<a href="' . esc_url($best_match['link']) . '" target="_blank" rel="noopener noreferrer">' : '') .
                            '<img src="' . esc_url($best_match['image_url']) . '" alt="' . esc_attr($best_match['title']) . '" style="max-width: 600px; height: auto;">' .
                            ($has_description ? '<br>' . esc_html($best_match['description']) : '') .
                        ($has_link ? '</a>' : '') .
                    '</p>' .
                '<br>';
            }

            // 根据发布规则中的设置选择插入位置
            $position = isset($publish_rules['brand_profile_position']) ? $publish_rules['brand_profile_position'] : 'before_second_paragraph';
            
            if ($position === 'article_end') {
                // 在文章结尾插入品牌资料
                $html_content .= '<br><br>' . $brand_html_block . '<br><br>';
            } else {
                // 默认在第二段落前插入（原有逻辑）
                $h2_pattern = '/<h2[^>]*>.*?<\/h2>/i';
                preg_match_all($h2_pattern, $html_content, $h2_matches, PREG_OFFSET_CAPTURE);
                
                if (count($h2_matches[0]) >= 2) {
                    // 找到第二个H2标题的位置，在其前插入
                    $second_h2_pos = $h2_matches[0][1][1];
                    $html_content = substr_replace($html_content, '<br><br>' . $brand_html_block . '<br><br>', $second_h2_pos, 0);
                } elseif (count($h2_matches[0]) >= 1) {
                    // 只有一个H2标题，在其后插入
                    $first_h2_end = strpos($html_content, '</h2>', $h2_matches[0][0][1]) + 5;
                    $html_content = substr_replace($html_content, '<br><br>' . $brand_html_block . '<br><br>', $first_h2_end, 0);
                } else {
                    // 没有H2标题，查找段落
                    $paragraphs = preg_split('/<\/p>/', $html_content);
                    if (count($paragraphs) >= 2) {
                        // 在第二段之后插入
                        $paragraphs[1] .= '<br><br>' . $brand_html_block . '<br><br>';
                        $html_content = implode('</p>', $paragraphs);
                    } elseif (count($paragraphs) >= 1) {
                        // 在第一段之后插入
                        $paragraphs[0] .= '<br><br>' . $brand_html_block . '<br><br>';
                        $html_content = implode('</p>', $paragraphs);
                    } else {
                        // 如果没有段落，直接追加到内容末尾
                        $html_content .= '<br><br>' . $brand_html_block . '<br><br>';
                    }
                }
            }
        }

        return $html_content;
    }

    /**
     * 生成文章内容
     * 复现有的内容生成组件，并实现API轮询和重试机制
     */
    private function generate_article_content(&$topic, $related_content, $publish_rules, $task_id = null, $subtask_id = null, $job_queue_id = null) {
        $context = array('topic_id' => $topic['id'], 'topic_title' => $topic['title']);
        $this->performance_monitor->start_timing('generate_article_content', $context);

        try {
            // --- NEW STEP: 5.5. Generate Dynamic Article Structure ---
            // Only execute when structure_mode is set to 'personalized'
            $structure_mode = isset($publish_rules['structure_mode']) ? $publish_rules['structure_mode'] : 'generic';
            
            if (!empty($publish_rules['normalize_output']) && $structure_mode === 'personalized' && empty($topic['is_rewrite'])) {
                $this->logger->log_info('DYNAMIC_STRUCTURE', 'Starting dynamic article structure generation (personalized mode)', $context);
                $this->performance_monitor->start_timing('structure_generation', $context);
                
                // Generate the structure prompt
                $structure_prompt_data = $this->xml_processor->generate_prompt($topic, $publish_rules, $related_content, [], 'generate-structure-prompt.xml');
                
                $dynamic_structure = null;
                if (!empty($structure_prompt_data)) {
                    $structure_prompt = is_array($structure_prompt_data) ? $structure_prompt_data['prompt'] : $structure_prompt_data;
                    
                    // [调试模式] 记录完整的大纲生成提示词
                    if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                        $this->logger->log_debug('DYNAMIC_STRUCTURE_PROMPT', '大纲生成提示词完整内容', array_merge($context, [
                            'prompt_length' => strlen($structure_prompt),
                            'prompt_content' => $structure_prompt
                        ]));
                    }
                    
                    // Call the API with the specific structure_generation task type
                    $structure_result = $this->api_handler->generate_content($structure_prompt, 'structure_generation', [
                        'rule_id' => $topic['rule_id'],
                        'topic_id' => $topic['id'],
                        'job_queue_id' => $job_queue_id,
                        'return_usage' => true // Required to properly catch 'length' limits
                    ]);

                    if (is_array($structure_result) && !isset($structure_result['error']) && !empty($structure_result['content'])) {
                        $dynamic_structure = trim($structure_result['content']);
                        
                        // [调试模式] 记录完整的大纲生成API返回内容
                        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                            $this->logger->log_debug('DYNAMIC_STRUCTURE_RAW_RESPONSE', 'API返回的完整大纲结构内容', array_merge($context, [
                                'structure_length' => strlen($dynamic_structure),
                                'structure_content' => $dynamic_structure
                            ]));
                        }
                        
                        // Validate basic XML structure
                        if (strpos($dynamic_structure, '<structure') !== false && strpos($dynamic_structure, '</structure>') !== false) {
                            $topic['dynamic_structure_block'] = $dynamic_structure;
                            $this->logger->log_success('DYNAMIC_STRUCTURE_SUCCESS', 'Dynamic structure generated successfully', array_merge($context, ['length' => strlen($dynamic_structure)]));
                        } else {
                            $this->logger->log_warning('DYNAMIC_STRUCTURE_INVALID', 'Generated structure missing <structure> tags, falling back to database structures', $context);
                        }
                    } else {
                        $error_msg = isset($structure_result['error']) ? (is_array($structure_result['error']) ? json_encode($structure_result['error']) : $structure_result['error']) : 'Unknown API error';
                        $this->logger->log_warning('DYNAMIC_STRUCTURE_FAILED', 'Failed to generate dynamic structure, falling back to database structures. Error: ' . $error_msg, $context);
                    }
                } else {
                    $this->logger->log_warning('DYNAMIC_STRUCTURE_PROMPT_FAILED', 'Failed to generate structure prompt, falling back to database structures', $context);
                }
                $this->performance_monitor->end_timing('structure_generation', !empty($topic['dynamic_structure_block']));
            }
            // --- END NEW STEP ---

            // 5. 获取相似文章（用于内链）
            $similar_articles = [];
            if (!empty($publish_rules['enable_internal_linking'])) {
                $this->logger->log_info('SIMILARITY_SEARCH', '内链功能已启用，开始查找相似文章', $context);
                $raw_similar = yali_ai_writer_find_similar_titles($topic['id'], 10);
                if (!empty($raw_similar)) {
                    foreach ($raw_similar as $article) {
                        $similar_articles[] = [
                            'title' => $article['title'],
                            'url'   => get_permalink($article['post_id'])
                        ];
                    }
                    // 只保留前3篇最相似的文章
                    $similar_articles = array_slice($similar_articles, 0, 3);
                    $this->logger->log_info('SIMILARITY_SUCCESS', '查找到 ' . count($similar_articles) . ' 篇相似文章', $context);
                }
            }

            // 6. 生成提示词
            $this->logger->log_info('PROMPT_GENERATION', '开始生成提示词', $context);
            $this->performance_monitor->start_timing('prompt_generation', $context);
            
            // Pass the generated dynamic structure into generate_prompt
            $prompt_data = $this->xml_processor->generate_prompt($topic, $publish_rules, $related_content, $similar_articles);
            $this->performance_monitor->end_timing('prompt_generation', !empty($prompt_data));

            if (empty($prompt_data)) {
                $this->logger->log_error('PROMPT_GENERATION_FAILED', '生成提示词失败', $context);
                $this->performance_monitor->record_error('content_generation', 'PROMPT_GENERATION_FAILED', $context);
                $this->performance_monitor->end_timing('generate_article_content', false);
                return ['success' => false, 'error' => ['stage' => '提示词生成', 'message' => '生成提示词失败']];
            }

            // 解析提示词数据
            if (is_array($prompt_data)) {
                $prompt = $prompt_data['prompt'];
                $template_name = $prompt_data['template_name'] ?? 'unknown';
            } else {
                $prompt = $prompt_data;
                $template_name = 'unknown';
            }
            $topic['prompt_template'] = $template_name;

            $this->logger->log_success('PROMPT_GENERATION_SUCCESS', "提示词生成成功 (模板: {$template_name})", array_merge($context, array('prompt_length' => strlen($prompt))));

            // [调试模式] 记录完整的文章生成提示词
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('API_REQUEST_PROMPT', '文章生成提示词完整内容', array_merge($context, [
                    'prompt_length' => strlen($prompt),
                    'prompt_content' => $prompt
                ]));
            }

            // 2. 使用重试机制调用API生成原始内容
            $this->logger->log_info('API_REQUEST', '开始API请求', $context);
            $result = $this->execute_api_request_with_retry($prompt, $topic, 3, $task_id, $subtask_id, $job_queue_id);
            if (!$result['success']) {
                $this->performance_monitor->end_timing('generate_article_content', false);
                return $result;
            }

            $raw_content = $result['content'];
            $this->logger->log_success('API_REQUEST_SUCCESS', 'API请求成功', array_merge($context, array('content_length' => strlen($raw_content))));
            
            // [优化] API请求可能耗时较长，重置超时时间以确保后续处理（过滤、Markdown转换、配图）有足够时间
            set_time_limit(900); // 15分钟

            // 记录API返回的完整原始内容（仅在调试模式下）
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('API_RAW_CONTENT_LOG', 'API返回的完整原始内容', array_merge($context, array(
                    'raw_content_length' => strlen($raw_content),
                    'raw_content' => $raw_content
                )));
            }

            // 3. 过滤外部包装标记
            $this->logger->log_info('CONTENT_FILTER', '开始内容过滤', $context);
            $this->performance_monitor->start_timing('content_filter', $context);
            
            // 记录过滤前的完整内容（仅在调试模式下）
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('CONTENT_FILTER_BEFORE', '内容过滤前的完整内容', array_merge($context, array(
                    'content_before_filter_length' => strlen($raw_content),
                    'content_before_filter' => $raw_content
                )));
            }
            
            $filtered_content = $this->content_filter->filter_content($raw_content);
            $this->performance_monitor->end_timing('content_filter', true);
            
            // 记录过滤后的完整内容（仅在调试模式下）
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('CONTENT_FILTER_AFTER', '内容过滤后的完整内容', array_merge($context, array(
                    'content_after_filter_length' => strlen($filtered_content),
                    'content_after_filter' => $filtered_content,
                    'content_reduced' => strlen($raw_content) - strlen($filtered_content),
                    'content_reduction_percentage' => strlen($raw_content) > 0 ? round((strlen($raw_content) - strlen($filtered_content)) / strlen($raw_content) * 100, 2) . '%' : '0%'
                )));
            }
            
            $this->logger->log_success('CONTENT_FILTER_SUCCESS', '内容过滤完成', array_merge($context, array('filtered_length' => strlen($filtered_content))));

            // 4. 转换Markdown为HTML
            $this->logger->log_info('MARKDOWN_CONVERSION', '开始Markdown转换', $context);
            $this->performance_monitor->start_timing('markdown_conversion', $context);
            
            // 记录Markdown转换前的内容（仅在调试模式下）
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('MARKDOWN_BEFORE_CONVERSION', 'Markdown转换前的内容', array_merge($context, array(
                    'markdown_length' => strlen($filtered_content),
                    'markdown_content' => $filtered_content
                )));
            }
            
            $html_content = $this->markdown_converter->markdown_to_html($filtered_content);
            $this->performance_monitor->end_timing('markdown_conversion', true);
            
            // 记录Markdown转换后的HTML内容（仅在调试模式下）
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $this->logger->log_debug('MARKDOWN_AFTER_CONVERSION', 'Markdown转换后的HTML内容', array_merge($context, array(
                    'html_length' => strlen($html_content),
                    'html_content' => $html_content,
                    'content_increase' => strlen($html_content) - strlen($filtered_content)
                )));
            }
            
            $this->logger->log_success('MARKDOWN_CONVERSION_SUCCESS', 'Markdown转换完成', array_merge($context, array('html_length' => strlen($html_content))));

            // 5. 插入品牌资料（如果启用）
            $html_content = $this->insert_brand_profile($html_content, $topic, $publish_rules);

            $this->performance_monitor->end_timing('generate_article_content', true, array('final_content_length' => strlen($html_content)));
            return ['success' => true, 'content' => $html_content];

        } catch (Exception $e) {
            return ['success' => false, 'error' => ['stage' => '内容生成异常', 'message' => $e->getMessage()]];
        }
    }
    
    /**
     * 执行API请求（带重试机制）
     * 接入统一 API 处理器，彻底规避 cURL 超时和 SSL 错误
     */
    private function execute_api_request_with_retry($prompt, $topic, $max_retries = 3, $task_id = null, $subtask_id = null, $job_queue_id = null) {
        $context = $this->logger->build_context(null, null, array(
            'topic_id' => $topic['id'],
            'max_retries' => $max_retries
        ));
        
        $this->performance_monitor->start_timing('api_request_with_retry', $context);
        
        // 更新队列中的重试次数（标记为正在进行第1次尝试，实际重试由Handler内部接管）
        if ($task_id && $subtask_id) {
            $this->update_subtask_retry_count($task_id, $subtask_id, 1);
        }
        
        // 🚀 核心优化：完全委托给统一 API 处理器
        // 不再传递 config_id，让 generate_content 自动使用 get_next_active_config 和 try_predefined_api_fallback
        $result_data = $this->api_handler->generate_content($prompt, 'article', [
            'rule_id' => $topic['rule_id'],
            'topic_id' => $topic['id'],
            'job_queue_id' => $job_queue_id,
            'return_usage' => true // 请求返回用量和结束原因
        ]);
        
        // 检查结果（finish_reason == 'length' 已由底层 UnifiedApiHandler 自动触发 API 轮询切换）
        if (!is_array($result_data) || !isset($result_data['error'])) {
            $this->performance_monitor->end_timing('api_request_with_retry', true);
            
            $content = is_array($result_data) && isset($result_data['content']) ? $result_data['content'] : $result_data;
            return ['success' => true, 'content' => $content];
        }
        
        $last_error = [
            'stage' => 'API请求',
            'message' => is_string($result_data['error']) ? $result_data['error'] : json_encode($result_data['error'])
        ];
        
        $this->logger->log_error('API_FAILED', "文章生成API请求失败 (Handler内部重试也失败): " . $last_error['message'], $context);

        $this->performance_monitor->end_timing('api_request_with_retry', false);
        return ['success' => false, 'message' => 'API请求失败: ' . $last_error['message'], 'error' => $last_error];
    }

    /**
     * 更新子任务重试次数
     */
    private function update_subtask_retry_count($task_id, $subtask_id, $retry_count) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
        
        // 先检查当前记录是否存在
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, retry_count FROM {$queue_table} WHERE job_type = 'article' AND job_id = %d AND subtask_id = %s",
            $task_id, $subtask_id
        ));
        
        if (!$existing) {
            $this->logger->log_error('RETRY_COUNT_UPDATE_ERROR', "找不到要更新的队列记录: task_id={$task_id}, subtask_id={$subtask_id}");
            return false;
        }
        
        $result = $wpdb->update(
            $queue_table,
            array(
                'retry_count' => $retry_count,
                'updated_at' => current_time('mysql')
            ),
            array('job_type' => 'article', 'job_id' => $task_id, 'subtask_id' => $subtask_id)
        );
        
        // 记录详细日志
        $this->logger->log_info('RETRY_COUNT_UPDATE', 
            "更新子任务重试次数: task_id={$task_id}, subtask_id={$subtask_id}, " .
            "old_retry_count={$existing->retry_count}, new_retry_count={$retry_count}, " .
            "result=" . ($result !== false ? 'success' : 'failed') . 
            ($result === false ? ', error=' . $wpdb->last_error : '')
        );
        
        return $result;
    }
    
    /**
     * 创建WordPress文章
     * 复用现有的发布规则适配逻辑
     */
    private function create_wordpress_post($title, $content, $publish_rules, $topic_data) {
        // 使用拼音转换器将标题转换为拼音
        $pinyin_converter = new Yali_AI_Writer_PinyinConverter();
        $pinyin_slug = $pinyin_converter->convert_to_pinyin($title);

        // 仅在调试模式下记录拼音转换结果
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $this->logger->log_debug('PINYIN_CONVERSION', '标题拼音转换结果', [
                'original_title' => $title,
                'pinyin_slug' => $pinyin_slug,
                'pinyin_length' => strlen($pinyin_slug)
            ]);
        }

        $post_status = $publish_rules['post_status'] ?? 'publish';

        // 处理时间间隔发布逻辑
        if ($post_status === 'publish' && isset($publish_rules['publish_interval_minutes']) && $publish_rules['publish_interval_minutes'] > 0) {
            $interval_minutes = intval($publish_rules['publish_interval_minutes']);
            $current_timestamp = current_time('timestamp');

            // 获取最新发布文章的时间（包含预发布时间）
            $latest_post_time = $this->get_latest_publish_time();

            // 核心逻辑：预发布时间 = max(最新文章发布时间, 当前系统时间) + 时间间隔
            if ($latest_post_time) {
                $latest_timestamp = strtotime($latest_post_time);
                $base_timestamp = max($latest_timestamp, $current_timestamp);
            } else {
                // 如果没有现有文章，使用当前时间作为基准
                $base_timestamp = $current_timestamp;
            }

            // 计算预发布时间
            $publish_timestamp = $base_timestamp + ($interval_minutes * 60);
            $new_publish_time = date('Y-m-d H:i:s', $publish_timestamp);

            // 判断发布状态
            if ($publish_timestamp > $current_timestamp) {
                // 未来时间：预发布
                $post_status = 'future';
                $post_data = [
                    'post_title'    => $title,
                    'post_content'  => $content,
                    'post_status'   => $post_status,
                    'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
                    'post_type'     => 'post',
                    'post_name'     => $pinyin_slug,
                    'post_date'     => $new_publish_time,
                    'post_date_gmt' => get_gmt_from_date($new_publish_time),
                ];
            } else {
                // 过去或当前时间：立即发布
                $post_status = 'publish';
                $post_data = [
                    'post_title'    => $title,
                    'post_content'  => $content,
                    'post_status'   => $post_status,
                    'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
                    'post_type'     => 'post',
                    'post_name'     => $pinyin_slug,
                ];
            }
        } else {
            // 常规发布逻辑
            $post_data = [
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => $post_status,
                'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
                'post_type'     => 'post',
                'post_name'     => $pinyin_slug,
            ];
        }

        $post_id = wp_insert_post($post_data);

        if (!$post_id || is_wp_error($post_id)) {
            $this->logger->log_error('POST_INSERT_FAILED', 'Failed to insert post.', $this->logger->build_context(null, $topic_data['id']));
            return false; // Exit if the insertion fails.
        }

        // Set categories as before.
        $category_ids = $this->get_post_categories($publish_rules, $topic_data);
        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, $category_ids);
        }

        // Associate the article structure ID with the post
        if (isset($GLOBALS['cam_used_structure_id']) && is_numeric($GLOBALS['cam_used_structure_id'])) {
            update_post_meta($post_id, '_article_structure_id', $GLOBALS['cam_used_structure_id']);
            // Unset the global to prevent it from being accidentally used again
            unset($GLOBALS['cam_used_structure_id']);
        }

        return $post_id;
    }





    /**
     * 创建文章并异步处理图片（用于大量图片的情况）
     */
    private function create_wordpress_post_with_async_images($title, $content, $publish_rules, $topic_data) {
        $pinyin_converter = new Yali_AI_Writer_PinyinConverter();
        $pinyin_slug = $pinyin_converter->convert_to_pinyin($title);

        // 【关键修复】预先计算最终的发布时间和状态
        $final_status = $publish_rules['post_status'] ?? 'draft';
        $final_post_date = null;
        $final_post_date_gmt = null;
        
        // 处理时间间隔发布逻辑（在创建文章前预先计算）
        if ($final_status === 'publish' && isset($publish_rules['publish_interval_minutes']) && $publish_rules['publish_interval_minutes'] > 0) {
            $interval_minutes = intval($publish_rules['publish_interval_minutes']);
            $current_timestamp = current_time('timestamp');
            $latest_post_time = $this->get_latest_publish_time();

            if ($latest_post_time) {
                $latest_timestamp = strtotime($latest_post_time);
                $base_timestamp = max($latest_timestamp, $current_timestamp);
            } else {
                $base_timestamp = $current_timestamp;
            }

            $publish_timestamp = $base_timestamp + ($interval_minutes * 60);
            $new_publish_time = date('Y-m-d H:i:s', $publish_timestamp);

            if ($publish_timestamp > $current_timestamp) {
                $final_status = 'future';
                $final_post_date = $new_publish_time;
                $final_post_date_gmt = get_gmt_from_date($new_publish_time);
            } else {
                $final_status = 'publish';
            }
        }

        // 构建文章数据（使用最终状态，而不是草稿）
        $post_data = [
            'post_title'    => $title,
            'post_content'  => $content,  // 【修复】直接使用包含品牌资料的完整内容
            'post_status'   => $final_status,  // 【修复】直接使用最终状态
            'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
            'post_type'     => 'post',
            'post_name'     => $pinyin_slug,
        ];

        // 如果需要预发布时间，直接设置
        if ($final_post_date) {
            $post_data['post_date'] = $final_post_date;
            $post_data['post_date_gmt'] = $final_post_date_gmt;
        }

        // 一次性创建最终状态的文章
        $post_id = wp_insert_post($post_data);

        if (!$post_id || is_wp_error($post_id)) {
            return false;
        }

        // 设置分类和元数据
        $category_ids = $this->get_post_categories($publish_rules, $topic_data);
        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, $category_ids);
        }

        if (isset($GLOBALS['cam_used_structure_id']) && is_numeric($GLOBALS['cam_used_structure_id'])) {
            update_post_meta($post_id, '_article_structure_id', $GLOBALS['cam_used_structure_id']);
            unset($GLOBALS['cam_used_structure_id']);
        }

        // 异步处理图片（不影响文章发布状态和时间）
        // 【修复】使用包含品牌资料的完整内容来处理图片占位符，并传递发布规则
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
        $image_generator = new Yali_AI_Writer_AutoImageGenerator();
        $image_generator->schedule_image_generation($post_id, $content, $publish_rules);

        return $post_id;
    }

    /**
     * 创建文章并同步处理图片（推荐方案）
     * 【关键修复】在文章生成流程中直接处理图片，避免WP-Cron触发问题
     * 原问题：WP-Cron依赖网站访问，导致只有第一篇文章的图片能生成
     * 新方案：同步执行，确保每张图片都生成成功后再返回
     */
    private function create_wordpress_post_with_sync_images($title, $content, $publish_rules, $topic_data) {
        // 增加PHP执行时间，确保图片生成有足够时间
        $original_time_limit = ini_get('max_execution_time');
        set_time_limit(900); // 15分钟
        
        $this->logger->log_info('SYNC_IMAGE_START', '开始同步处理文章图片', [
            'title' => $title,
            'content_length' => strlen($content)
        ]);
        
        // 先创建文章（草稿状态，等图片处理完成后再发布）
        $pinyin_converter = new Yali_AI_Writer_PinyinConverter();
        $pinyin_slug = $pinyin_converter->convert_to_pinyin($title);
        
        // 计算最终发布状态
        $final_status = $publish_rules['post_status'] ?? 'draft';
        $final_post_date = null;
        
        // 处理时间间隔发布逻辑
        if ($final_status === 'publish' && isset($publish_rules['publish_interval_minutes']) && $publish_rules['publish_interval_minutes'] > 0) {
            $interval_minutes = intval($publish_rules['publish_interval_minutes']);
            $current_timestamp = current_time('timestamp');
            $latest_post_time = $this->get_latest_publish_time();
            
            if ($latest_post_time) {
                $latest_timestamp = strtotime($latest_post_time);
                $base_timestamp = max($latest_timestamp, $current_timestamp);
            } else {
                $base_timestamp = $current_timestamp;
            }
            
            $publish_timestamp = $base_timestamp + ($interval_minutes * 60);
            if ($publish_timestamp > $current_timestamp) {
                $final_status = 'future';
                $final_post_date = date('Y-m-d H:i:s', $publish_timestamp);
            }
        }
        
        // 先创建为草稿（避免图片生成失败导致已发布文章不完整）
        $post_data = [
            'post_title'    => $title,
            'post_content'  => $content,
            'post_status'   => 'draft',  // 先创建为草稿
            'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
            'post_type'     => 'post',
            'post_name'     => $pinyin_slug,
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (!$post_id || is_wp_error($post_id)) {
            $this->logger->log_error('POST_INSERT_FAILED', '创建文章失败', ['title' => $title]);
            set_time_limit($original_time_limit);
            return false;
        }
        
        // 同步处理图片
        try {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            $image_generator = new Yali_AI_Writer_AutoImageGenerator();
            
            $this->logger->log_info('SYNC_IMAGE_PROCESSING', '同步处理图片占位符', ['post_id' => $post_id]);
            
            $options = [];
            if ($publish_rules) {
                $options['publish_rules'] = $publish_rules;
            }
            
            // 同步执行图片生成（会等待所有图片生成完成）
            $result = $image_generator->auto_generate_images_for_post($post_id, $content, $options);
            
            if ($result['success']) {
                $this->logger->log_success('SYNC_IMAGE_COMPLETE', '图片同步处理完成', [
                    'post_id' => $post_id,
                    'generated_count' => $result['generated_count'],
                    'failed_count' => $result['failed_count'] ?? 0
                ]);
            } else {
                $this->logger->log_warning('SYNC_IMAGE_PARTIAL', '图片处理部分失败', [
                    'post_id' => $post_id,
                    'error' => $result['error'] ?? '未知错误'
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->log_error('SYNC_IMAGE_EXCEPTION', '图片处理异常', [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
        }
        
        // 设置分类
        $category_ids = $this->get_post_categories($publish_rules, $topic_data);
        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, $category_ids);
        }
        
        // 设置文章结构ID
        if (isset($GLOBALS['cam_used_structure_id']) && is_numeric($GLOBALS['cam_used_structure_id'])) {
            update_post_meta($post_id, '_article_structure_id', $GLOBALS['cam_used_structure_id']);
            unset($GLOBALS['cam_used_structure_id']);
        }
        
        // 更新文章为最终状态
        $update_data = ['ID' => $post_id];
        
        if ($final_status === 'future' && $final_post_date) {
            $update_data['post_status'] = 'future';
            $update_data['post_date'] = $final_post_date;
            $update_data['post_date_gmt'] = get_gmt_from_date($final_post_date);
        } else {
            $update_data['post_status'] = $final_status;
        }
        
        wp_update_post($update_data);
        
        // 恢复原始执行时间限制
        set_time_limit($original_time_limit);
        
        $this->logger->log_success('POST_CREATION_WITH_SYNC_IMAGES', '文章创建并同步处理图片完成', [
            'post_id' => $post_id,
            'status' => $final_status
        ]);
        
        return $post_id;
    }

    /**
     * 同步处理文章的自动配图
     */
    private function process_auto_images_sync($post_id, $content) {
        try {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            $auto_image_generator = new Yali_AI_Writer_AutoImageGenerator();
            $result = $auto_image_generator->auto_generate_images_for_post($post_id, $content);
            
            if ($result['success'] && $result['generated_count'] > 0) {
                $this->logger->log_success('AUTO_IMAGE_SYNC_SUCCESS', '同步图片生成完成', [
                    'post_id' => $post_id, 'generated_count' => $result['generated_count']
                ]);
            } elseif (!$result['success']) {
                $this->logger->log_error('AUTO_IMAGE_SYNC_FAILED', '同步图片生成失败', [
                    'post_id' => $post_id, 'error' => ($result['error'] ?? '未知错误')
                ]);
            }
        } catch (Exception $e) {
            $this->logger->log_error('AUTO_IMAGE_SYNC_EXCEPTION', '同步图片处理异常', [
                'post_id' => $post_id, 'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取最新发布文章的时间
     *
     * @return string|null 最新发布时间，如果没有找到则返回null
     */
    private function get_latest_publish_time() {
        $args = array(
            'post_type' => 'post',
            'post_status' => array('publish', 'future'),
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC'
        );

        $latest_posts = get_posts($args);

        if (!empty($latest_posts)) {
            $latest_post = $latest_posts[0];
            return $latest_post->post_date;
        }

        return null;
    }

    /**
     * 获取文章分类
     * 复用现有的发布规则适配逻辑
     */
    private function get_post_categories($publish_rules, $topic_data) {
        // 检查发布规则是否存在
        if (empty($publish_rules)) {
            return array();
        }
        
        // 获取分类模式
        $category_mode = isset($publish_rules['category_mode']) ? $publish_rules['category_mode'] : 'manual';
        
        if ($category_mode === 'auto') {
            // 自动模式：使用主题中的matched_category字段
            if (!empty($topic_data['matched_category'])) {
                $matched_category = $topic_data['matched_category'];
                
                // 尝试按名称精确匹配分类（使用过滤后的分类）
                if (class_exists('Yali_AI_Writer_Category_Filter')) {
                    $categories = Yali_AI_Writer_Category_Filter::get_filtered_categories(array('hide_empty' => false));
                } else {
                    $categories = get_categories(array('hide_empty' => false));
                }
                
                foreach ($categories as $category) {
                    if ($category->name === $matched_category || $category->slug === sanitize_title($matched_category)) {
                        return array($category->term_id);
                    }
                }
                
                // 如果精确匹配失败，尝试模糊匹配
                foreach ($categories as $category) {
                    if (stripos($category->name, $matched_category) !== false || stripos($matched_category, $category->name) !== false) {
                        return array($category->term_id);
                    }
                }
            }
            
            // 如果主题中的分类匹配失败，使用备用分类兜底
            if (!empty($publish_rules['fallback_category_ids'])) {
                $fallback_ids = maybe_unserialize($publish_rules['fallback_category_ids']);
                if (is_array($fallback_ids) && !empty($fallback_ids)) {
                    // 验证分类ID是否有效
                    $valid_categories = array();
                    foreach ($fallback_ids as $category_id) {
                        $category = get_category($category_id);
                        if ($category && !is_wp_error($category)) {
                            $valid_categories[] = (int)$category_id;
                        }
                    }
                    return $valid_categories;
                }
            }
        } else {
            // 手动模式：使用预设的分类
            if (!empty($publish_rules['category_ids'])) {
                $category_ids = maybe_unserialize($publish_rules['category_ids']);
                if (is_array($category_ids) && !empty($category_ids)) {
                    // 验证分类ID是否有效
                    $valid_categories = array();
                    foreach ($category_ids as $category_id) {
                        $category = get_category($category_id);
                        if ($category && !is_wp_error($category)) {
                            $valid_categories[] = (int)$category_id;
                        }
                    }
                    return $valid_categories;
                }
            }
        }
        
        // 如果没有找到有效的分类，返回空数组
        return array();
    }
    
    /**
     * 保存文章记录
     */
    private function save_article_record($topic, $post_id, $article_content, $start_time) {
        $article_data = [
            'topic_id' => $topic['id'],
            'post_id' => $post_id,
            'title' => $topic['title'],
            'content' => $article_content,
            'status' => YALI_AI_WRITER_ARTICLE_SUCCESS,
            'processing_time' => time() - $start_time,
            'word_count' => yali_ai_writer_manager_word_count($article_content),
            'api_config_id' => $topic['api_config_id'] ?? null,
            'api_config_name' => $topic['api_config_name'] ?? null,
            'prompt_template' => $topic['prompt_template'] ?? null
        ];
        return $this->database->insert('yali_ai_writer_articles', $article_data);
    }
    
    /**
     * 处理文章的自动配图
     * 
     * @param int $post_id 文章ID
     * @param string $content 文章内容
     */
    private function process_auto_images($post_id, $content) {
        try {
            // 加载自动图片生成器
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            
            $auto_image_generator = new Yali_AI_Writer_AutoImageGenerator();
            
            // 异步处理图片生成，避免阻塞文章生成流程
            $auto_image_generator->schedule_image_generation($post_id, $content);
            
            $this->logger->log_info('AUTO_IMAGE_SCHEDULED', '自动配图任务已调度', [
                'post_id' => $post_id
            ]);
            
        } catch (Exception $e) {
            // 记录错误但不阻塞文章生成流程
            $this->logger->log_error('AUTO_IMAGE_ERROR', '自动配图处理失败', [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 获取默认发布规则
     */
    private function get_default_publish_rules() {
        // 获取默认分类（通常是"未分类"）
        $default_category = get_category_by_slug('uncategorized');
        
        // 检查默认分类是否在允许的分类中
        if ($default_category && class_exists('Yali_AI_Writer_Category_Filter')) {
            if (!Yali_AI_Writer_Category_Filter::is_category_allowed($default_category->term_id)) {
                $default_category = null; // 如果不在允许列表中，重置为null
            }
        }
        
        if (!$default_category) {
            // 如果没有"未分类"或不在允许列表中，获取第一个允许的分类
            if (class_exists('Yali_AI_Writer_Category_Filter')) {
                $categories = Yali_AI_Writer_Category_Filter::get_filtered_categories(array('number' => 1));
            } else {
                $categories = get_categories(array('number' => 1));
            }
            $default_category = !empty($categories) ? $categories[0] : null;
        }
        
        $default_category_id = $default_category ? $default_category->term_id : 1;
        
        // 返回默认发布规则配置（数组格式，兼容XML模板处理器）
        return array(
            'id' => 0,
            'name' => '默认发布规则',
            'category_mode' => 'manual',
            'category_ids' => serialize(array($default_category_id)),
            'fallback_category_ids' => serialize(array($default_category_id)),
            'post_status' => 'publish',
            'post_type' => 'post',
            'author_id' => 1,
            'is_active' => 1,
            'target_length' => '800-1500',        // 目标字数
            'knowledge_depth' => '未设置',        // 内容深度 - 默认未设置
            'reader_role' => '未设置',            // 目标受众 - 默认未设置
            'normalize_output' => 0,              // 文章结构指导（默认关闭）
            'auto_image_insertion' => 0           // 文章自动配图（默认关闭）
        );
    }
    
    /**
     * 验证所有必需的组件是否正确集成
     * 确保内容处理流程保持不变
     */
    public function verify_component_integration() {
        $verification_results = array();
        
        // 1. 验证 Yali_AI_Writer_XmlTemplateProcessor 的 generate_prompt 方法
        try {
            if (method_exists($this->xml_processor, 'generate_prompt')) {
                $verification_results['xml_template_processor'] = 'OK - generate_prompt方法可用';
            } else {
                $verification_results['xml_template_processor'] = 'ERROR - generate_prompt方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['xml_template_processor'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 2. 验证 Yali_AI_Writer_UnifiedApiHandler 的 generate_content 方法
        try {
            if (method_exists($this->api_handler, 'generate_content')) {
                $verification_results['unified_api_handler'] = 'OK - generate_content方法可用';
            } else {
                $verification_results['unified_api_handler'] = 'ERROR - generate_content方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['unified_api_handler'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 3. 验证 Yali_AI_Writer_ContentFilter 的 filter_content 方法
        try {
            if (method_exists($this->content_filter, 'filter_content')) {
                $verification_results['content_filter'] = 'OK - filter_content方法可用';
            } else {
                $verification_results['content_filter'] = 'ERROR - filter_content方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['content_filter'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 4. 验证 Yali_AI_Writer_MarkdownConverter 的 markdown_to_html 方法
        try {
            if (method_exists($this->markdown_converter, 'markdown_to_html')) {
                $verification_results['markdown_converter'] = 'OK - markdown_to_html方法可用';
            } else {
                $verification_results['markdown_converter'] = 'ERROR - markdown_to_html方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['markdown_converter'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 5. 验证 API 配置管理器
        try {
            if (method_exists($this->api_config, 'get_next_active_config')) {
                $verification_results['api_config'] = 'OK - API轮询机制可用';
            } else {
                $verification_results['api_config'] = 'ERROR - get_next_active_config方法不存在';
            }
        } catch (Exception $e) {
            $verification_results['api_config'] = 'ERROR - ' . $e->getMessage();
        }
        
        // 6. 验证发布规则适配功能
        try {
            $test_publish_rules = array('category_mode' => 'manual');
            $test_topic_data = array('matched_category' => 'test');
            $categories = $this->get_post_categories($test_publish_rules, $test_topic_data);
            $verification_results['publish_rules_adapter'] = 'OK - 发布规则适配功能可用';
        } catch (Exception $e) {
            $verification_results['publish_rules_adapter'] = 'ERROR - ' . $e->getMessage();
        }
        
        return $verification_results;
    }
    
    /**
     * 获取组件集成状态摘要
     */
    public function get_integration_summary() {
        $results = $this->verify_component_integration();
        $total_components = count($results);
        $successful_components = 0;
        
        foreach ($results as $component => $status) {
            if (strpos($status, 'OK') === 0) {
                $successful_components++;
            }
        }
        
        return array(
            'total_components' => $total_components,
            'successful_components' => $successful_components,
            'integration_rate' => round(($successful_components / $total_components) * 100, 2) . '%',
            'details' => $results
        );
    }

    /**
     * 检查图像API是否已有效配置
     * 
     * @return bool
     */
    private function is_image_api_configured() {
        if (!class_exists('Yali_AI_Writer_Image_API_Admin_Page')) {
            $admin_page_file = YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
            if (file_exists($admin_page_file)) {
                require_once $admin_page_file;
            } else {
                return false;
            }
        }
        
        if (!class_exists('Yali_AI_Writer_Image_API_Admin_Page')) {
            return false;
        }
        
        $settings = Yali_AI_Writer_Image_API_Admin_Page::get_settings();
        $provider = isset($settings['provider']) ? $settings['provider'] : '';
        
        if (empty($provider)) {
            return false;
        }
        
        // 检查选中的提供商是否有API Key（如果该提供商需要API Key）
        // 默认的 modelscope 以及 openai, siliconflow 都需要 api_key
        if (isset($settings[$provider]) && array_key_exists('api_key', $settings[$provider])) {
             return !empty($settings[$provider]['api_key']);
        }
        
        // 其他提供商（如 pollinations）可能不需要 api_key，如果选中则视为已配置
        return true; 
    }

    /**
     * 仅在调试模式下记录调试日志
     * 
     * @param string $code 日志代码
     * @param string $message 日志消息
     * @param array $context 上下文信息
     */
    private function log_debug($code, $message, $context = []) {
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $this->logger->log_debug($code, $message, $context);
        }
    }
}