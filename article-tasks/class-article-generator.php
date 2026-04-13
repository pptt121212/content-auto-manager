<?php
/**
 * 文章生成器（改造后）
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/class-xml-template-processor.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-content-filter.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/content-processing/class-markdown-converter.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-pinyin-converter.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/class-rule-manager.php';

class Yali_AI_Writer_ArticleGenerator {
    
    private $database;
    private $article_task_manager;
    private $markdown_converter;
    private $content_filter;

    public function __construct() {
        $this->database = new Yali_AI_Writer_Database();
        $this->article_task_manager = new Yali_AI_Writer_ArticleTaskManager();
        
        // 初始化转换器（避免每次生成文章时重复创建）
        $this->markdown_converter = new Yali_AI_Writer_MarkdownConverter();
        $this->content_filter = new Yali_AI_Writer_ContentFilter();
    }

    /**
     * 为指定主题生成文章
     * 
     * 【重要说明】此类主要用于文章内容生成，但实际的文章创建调用路径在：
     * class-article-queue-processor.php 的 create_wordpress_post_with_images() 方法
     * 
     * 如需修复发布逻辑问题，请在 article-tasks/class-article-queue-processor.php 中修改！
     * 
     * @param array $topic 主题数据
     * @param int|null $task_id 任务ID（用于批量多样性追踪）
     * @return array 生成结果
     */
    public function generate_article_for_topic($topic, $task_id = null) {
        // 获取发布规则
        $publish_rules = $this->database->get_row('yali_ai_writer_publish_rules', array('id' => 1));
        
        // 如果发布规则不存在，使用默认配置
        if (!$publish_rules) {
            $publish_rules = $this->get_default_publish_rules();
        }
        
        // 获取相关内容
        $rule_manager = new Yali_AI_Writer_RuleManager();
        $related_content = array();
        
        // 检查是否为仿写规则
        $rule = $rule_manager->get_rule($topic['rule_id']);
        $rule_type = is_object($rule) ? ($rule->rule_type ?? '') : ($rule['rule_type'] ?? '');
        $is_rewrite = ($rule_type === 'collect_url_rewrite');
        $topic['is_rewrite'] = $is_rewrite; // 传递标志到下游
        
        if ($is_rewrite && isset($topic['rule_item_index'])) {
            // 对于仿写任务，获取特定的采集内容
            // 注意：此时 rule_item_index 实际上存储的是 item_id
            $item_content = $rule_manager->get_content_by_rule_item_id($topic['rule_item_index']);
            if ($item_content) {
                // 如果是采集内容，通常存储在 upload_text (或 url) 中，将其映射到 content 供后续处理
                if (empty($item_content['content']) && !empty($item_content['upload_text'])) {
                     $item_content['content'] = $item_content['upload_text'];
                }
                // 如果 get_content_by_rule_item_id 返回的是数组(被包装过)，则可能需要解包，
                // 但根据 class-rule-manager.php 的实现，它返回的是 array('id'=>..., 'content'=>..., 'result'=>array(...)) 
                // Wait, get_content_by_rule_item_id 返回的是 array('url'=>...) 的数组.
                // 修正：get_content_by_rule_item_id 在 class-rule-manager.php 中返回的是 array(array(...)) ?
                // 再次确认：现有的 get_content_by_rule_item_id (lines 504+) Returns: array($data) —— 一个包含单个元素的数组。
                // 所以我们需要取 [0]。
                
                if (isset($item_content[0])) {
                     $single_item = $item_content[0];
                     
                     // -------------------------------------------------------------
                     // 深度风险修复：Transient 缓存过期保护
                     // 如果 RuleManager 返回的内容看起来像 URL (缓存丢失)，
                     // 但主题表(reference_material)里存有备份内容，则强制使用备份内容。
                     // -------------------------------------------------------------
                     $has_valid_content = !empty($single_item['content']) && mb_strlen($single_item['content']) > 200;
                     if (!$has_valid_content && !empty($topic['reference_material']) && mb_strlen($topic['reference_material']) > 200) {
                         $single_item['content'] = $topic['reference_material'];
                         $has_valid_content = true;
                     }
                     
                     // 常规映射 (Fallback)
                     if (!$has_valid_content) {
                        if (empty($single_item['content']) && !empty($single_item['url'])) {
                            // 即使是 URL，也赋值给 content，让 XML 处理器去决定如何处理(或报错)
                            $single_item['content'] = $single_item['url'];
                        } elseif (empty($single_item['content']) && !empty($single_item['upload_text'])) {
                            $single_item['content'] = $single_item['upload_text'];
                        }
                     }
                     
                     $related_content = array($single_item);
                }
            }
        } else {
            $related_content = $rule_manager->get_content_by_rule($topic['rule_id'], 5);
        }
        
        // --- AI Metadata Completion for URL Rewrite ---
        // 如果是仿写任务，且缺失关键元数据（分类或关键词），尝试AI补全
        if ($is_rewrite && (empty($topic['matched_category']) || empty($topic['seo_keywords']))) {
            // 将原始内容暂时放入 original_content 供分析 (如果 related_content 有提取到内容)
            if (!empty($related_content) && isset($related_content[0]['content'])) {
                 $topic['original_content'] = $related_content[0]['content'];
                 // 尝试补全
                 $metadata_result = $this->auto_complete_topic_metadata($topic);
                 if ($metadata_result) {
                     if (!empty($metadata_result['matched_category'])) {
                         $topic['matched_category'] = $metadata_result['matched_category'];
                     }
                     if (!empty($metadata_result['seo_keywords'])) {
                         $topic['seo_keywords'] = $metadata_result['seo_keywords'];
                     }
                 }
            }
        }
        // ----------------------------------------------
        
        // 如果启用了文章内链功能，获取相似文章
        $similar_articles = array();
        if (isset($publish_rules['enable_internal_linking']) && $publish_rules['enable_internal_linking'] == 1) {
            $similar_articles = $this->get_similar_published_articles($topic['title']);
        }
        
        // 验证：如果启用了自动配图，但未配置图像API，则强制关闭自动配图
        if (isset($publish_rules['auto_image_insertion']) && $publish_rules['auto_image_insertion'] == 1) {
            if (!$this->is_image_api_configured()) {
                $publish_rules['auto_image_insertion'] = 0;
                error_log('ContentAuto: 检测到图像API未配置或密钥为空，已强制关闭自动配图功能 (Topic: ' . $topic['title'] . ')');
            }
        }
        
        // 生成文章内容（传递task_id用于批量追踪）
        $article_content = $this->generate_article($topic, $related_content, $publish_rules, $similar_articles, $task_id);
        
        if ($article_content) {
            // 根据是否启用自动配图决定创建策略
            if (isset($publish_rules['auto_image_insertion']) && $publish_rules['auto_image_insertion'] == 1) {
                // 启用自动配图：先创建草稿，处理图片后再设置正确状态
                $content_text = is_array($article_content) ? $article_content['content'] : $article_content;
                $post_id = $this->create_wordpress_post_with_images($topic['title'], $content_text, $publish_rules, $topic);
            } else {
                // 未启用自动配图：直接创建并发布
                $content_text = is_array($article_content) ? $article_content['content'] : $article_content;
                $post_id = $this->create_wordpress_post($topic['title'], $content_text, $publish_rules, $topic);
            }
            
            if ($post_id) {
                // 保存文章记录到数据库
                // 从 prompt_data 中获取 template_name，这里无法直接获取，需要重构 generate_article 返回值
                // 暂时方案：从 generate_article 返回结果中由上层获取不到，因为 generate_article 只返回 content
                // 修正：generate_article 生成的 content 是纯文本，元数据丢失。
                // 必须修改 generate_article 返回值，包含 template_name
                if (is_array($article_content)) {
                    $content_text = $article_content['content'];
                    $template_name = $article_content['template_name'] ?? null;
                    $usage = $article_content['usage'] ?? array();
                } else {
                    $content_text = $article_content;
                    $template_name = null;
                    $usage = array();
                }

                $this->save_article_record($topic, $post_id, $content_text, time(), $template_name, $usage);

                // 验证主题状态，只有从queued状态才能更新为used
                if ($topic['status'] === YALI_AI_WRITER_TOPIC_QUEUED) {
                    $this->database->update('yali_ai_writer_topics', array('status' => YALI_AI_WRITER_TOPIC_USED), array('id' => $topic['id']));
                } else {
                    // 如果主题不是queued状态，记录警告但继续执行
                    error_log('Warning: Topic ' . $topic['id'] . ' status is not queued before article generation, current status: ' . $topic['status']);
                }
                return ['success' => true, 'post_id' => $post_id];
            } else {
                return ['success' => false, 'message' => '创建WordPress文章失败'];
            }
        } else {
            return ['success' => false, 'message' => '生成文章内容失败'];
        }
    }
    
    private function generate_article($topic, $related_content, $publish_rules, $similar_articles = array(), $task_id = null) {
        // 构建基础上下文信息
        $base_context = array(
            'topic_id' => $topic['id'],
            'topic_title' => $topic['title'],
            'rule_id' => $topic['rule_id'],
            'task_id' => $task_id
        );
        
        // 初始化日志记录器
        if (!class_exists('Yali_AI_Writer_PluginLogger')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new Yali_AI_Writer_PluginLogger();
        
        $logger->info('ARTICLE_GENERATION_START', '开始生成文章', $base_context);
        
        error_log('ContentAuto: generate_article - Publish Rules: ' . print_r($publish_rules, true));
        $xml_processor = new Yali_AI_Writer_XmlTemplateProcessor();
        
        // 设置任务ID用于批量多样性追踪
        if ($task_id !== null) {
            $xml_processor->set_task_id($task_id);
        }
        
        $prompt_data = $xml_processor->generate_prompt($topic, $publish_rules, $related_content, $similar_articles);
        
        // 解析提示词数据
        if (is_array($prompt_data)) {
            $prompt = $prompt_data['prompt'];
            $template_name = $prompt_data['template_name'] ?? 'unknown';
        } else {
            $prompt = $prompt_data;
            $template_name = 'unknown'; // 旧版本兼容
        }
        
        // 记录生成的提示词（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('GENERATED_PROMPT', '生成的文章提示词', array_merge($base_context, array(
                'prompt_length' => strlen($prompt),
                'template_name' => $template_name,
                'prompt_content' => $prompt,
                'publish_rules' => $publish_rules
            )));
        }
        
        $unified_api_handler = new Yali_AI_Writer_UnifiedApiHandler();
        $raw_content_data = $unified_api_handler->generate_content($prompt, 'article', [
            'rule_id' => $topic['rule_id'],
            'topic_id' => $topic['id'],
            'return_usage' => true
        ]);
        
        $raw_content = is_array($raw_content_data) ? ($raw_content_data['content'] ?? '') : $raw_content_data;
        $usage = is_array($raw_content_data) ? ($raw_content_data['usage'] ?? array()) : array();
        
        if (is_array($raw_content_data) && isset($raw_content_data['error'])) {
            $logger->error('API_CONTENT_GENERATION_FAILED', 'API内容生成失败', array_merge($base_context, array(
                'error_message' => $raw_content_data['error']
            )));
            return false;
        }

        // 记录API返回的原始内容（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('API_RAW_CONTENT', 'API返回的原始内容', array_merge($base_context, array(
                'content_length' => strlen($raw_content),
                'raw_content' => $raw_content
            )));
        }
        
        $logger->info('CONTENT_FILTER_START', '开始过滤内容', array_merge($base_context, array(
            'content_before_filter_length' => strlen($raw_content)
        )));
        
        // 过滤外部包装标记
        $filtered_content = $this->content_filter->filter_content($raw_content);
        
        // 记录过滤后的内容变化（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('CONTENT_FILTERED', '内容过滤完成', array_merge($base_context, array(
                'content_before_filter' => $raw_content,
                'content_after_filter' => $filtered_content,
                'content_before_length' => strlen($raw_content),
                'content_after_length' => strlen($filtered_content),
                'content_reduced' => strlen($raw_content) - strlen($filtered_content)
            )));
        }
        
        $logger->info('MARKDOWN_CONVERSION_START', '开始Markdown转换', array_merge($base_context, array(
            'markdown_length' => strlen($filtered_content)
        )));
        
        // 转换Markdown为HTML
        $html_content = $this->markdown_converter->markdown_to_html($filtered_content);
        
        // 记录Markdown转换结果（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('MARKDOWN_CONVERTED', 'Markdown转换为HTML', array_merge($base_context, array(
                'markdown_content' => $filtered_content,
                'html_content' => $html_content,
                'markdown_length' => strlen($filtered_content),
                'html_length' => strlen($html_content)
            )));
        }
        
        $logger->info('BRAND_PROFILE_INSERTION_START', '开始插入品牌资料', $base_context);
        
        $final_content = $this->insert_brand_profile($html_content, $topic, $publish_rules);
        
        // 记录品牌资料插入的结果（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('BRAND_PROFILE_INSERTED', '品牌资料插入完成', array_merge($base_context, array(
                'content_before_brand' => $html_content,
                'content_after_brand' => $final_content,
                'content_before_brand_length' => strlen($html_content),
                'content_after_brand_length' => strlen($final_content),
                'brand_content_added' => strlen($final_content) - strlen($html_content)
            )));
        }
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('ARTICLE_GENERATION_SUCCESS', '文章生成完成', array_merge($base_context, array(
                'final_content_length' => strlen($final_content)
            )));

            // Debug: Log publish rules using the plugin's logger
            $logger->debug('PUBLISH_RULES_APPLIED', '应用发布规则', array(
                'topic_id' => $topic['id'],
                'publish_rules' => $publish_rules
            ));
        }

        return array(
            'content' => $final_content,
            'template_name' => $template_name,
            'usage' => $usage
        );
    }

    private function insert_brand_profile($html_content, $topic, $publish_rules) {
        if (!class_exists('Yali_AI_Writer_PluginLogger')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new Yali_AI_Writer_PluginLogger();

        $base_context = array(
            'topic_id' => $topic['id'],
            'topic_title' => $topic['title'],
            'content_length' => strlen($html_content)
        );

        $logger->info('BRAND_INSERTION_START', '开始品牌资料插入处理', $base_context);

        // Condition 1: Check if brand profile insertion is enabled
        if (!isset($publish_rules['enable_brand_profile_insertion']) || !$publish_rules['enable_brand_profile_insertion']) {
            $logger->info('BRAND_INSERTION_DISABLED', '品牌资料插入功能未启用', array_merge($base_context, array(
                'enable_brand_profile_insertion' => isset($publish_rules['enable_brand_profile_insertion']) ? $publish_rules['enable_brand_profile_insertion'] : 'not_set'
            )));
            return $html_content;
        }

        // Condition 2: Check if topic has a vector embedding
        if (empty($topic['vector_embedding'])) {
            $logger->warning('BRAND_INSERTION_NO_VECTOR', '主题向量嵌入为空', $base_context);
            return $html_content;
        }

        // Condition 3: Check if any brand profiles with vectors exist
        global $wpdb;
        $brand_profiles_table = $wpdb->prefix . 'yali_ai_writer_brand_profiles';
        $brand_profiles = $wpdb->get_results("SELECT * FROM {$brand_profiles_table} WHERE vector IS NOT NULL AND type IN ('standard', 'custom_html')", ARRAY_A);

        if (empty($brand_profiles)) {
            $logger->warning('BRAND_INSERTION_NO_PROFILES', '未找到带向量的品牌资料', $base_context);
            return $html_content;
        }
        
        $logger->info('BRAND_INSERTION_PROFILES_FOUND', '找到带向量的品牌资料', array_merge($base_context, array(
            'profiles_count' => count($brand_profiles)
        )));

        // Decode topic vector
        $topic_vector_decoded = base64_decode($topic['vector_embedding']);
        if (!$topic_vector_decoded) {
            $logger->error('BRAND_INSERTION_VECTOR_DECODE_FAILED', '主题向量解码失败', $base_context);
            return $html_content;
        }
        
        $topic_vector = unpack('f*', $topic_vector_decoded);
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('BRAND_INSERTION_TOPIC_VECTOR', '主题向量信息', array_merge($base_context, array(
                'vector_dimensions' => count($topic_vector),
                'vector_first_5' => array_slice($topic_vector, 0, 5),
                'vector_encoded_length' => strlen($topic['vector_embedding'])
            )));
        }
        
        $best_match = null;
        $highest_similarity = -1;
        $similarity_results = array();

        // Ensure the cosine similarity function is available
        if (!function_exists('yali_ai_writer_calculate_cosine_similarity')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/functions.php';
        }

        foreach ($brand_profiles as $profile) {
            $profile_vector_decoded = base64_decode($profile['vector']);
            if (!$profile_vector_decoded) {
                $logger->warning('BRAND_INSERTION_PROFILE_VECTOR_DECODE_FAILED', '品牌资料向量解码失败', array_merge($base_context, array(
                    'profile_id' => $profile['id'],
                    'profile_title' => $profile['title']
                )));
                continue;
            }
            
            $profile_vector = unpack('f*', $profile_vector_decoded);

            if (count($topic_vector) !== count($profile_vector)) {
                $logger->warning('BRAND_INSERTION_VECTOR_DIMENSION_MISMATCH', '向量维度不匹配', array_merge($base_context, array(
                    'profile_id' => $profile['id'],
                    'profile_title' => $profile['title'],
                    'topic_vector_dimensions' => count($topic_vector),
                    'profile_vector_dimensions' => count($profile_vector)
                )));
                continue;
            }

            $similarity = yali_ai_writer_calculate_cosine_similarity($topic_vector, $profile_vector);
            
            $similarity_results[] = array(
                'profile_id' => $profile['id'],
                'profile_title' => $profile['title'],
                'similarity' => $similarity
            );
            
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $logger->debug('BRAND_INSERTION_SIMILARITY_CALCULATION', '品牌资料相似度计算', array_merge($base_context, array(
                    'profile_id' => $profile['id'],
                    'profile_title' => $profile['title'],
                    'similarity_score' => $similarity
                )));
            }

            if ($similarity > $highest_similarity) {
                $highest_similarity = $similarity;
                $best_match = $profile;
            }
        }

        $logger->info('BRAND_INSERTION_BEST_MATCH', '找到最佳匹配品牌资料', array_merge($base_context, array(
            'best_match_id' => $best_match ? $best_match['id'] : null,
            'best_match_title' => $best_match ? $best_match['title'] : null,
            'highest_similarity' => $highest_similarity,
            'threshold_met' => $highest_similarity > 0.3,
            'all_similarities' => $similarity_results
        )));

        // Threshold check
        if ($best_match && $highest_similarity > 0.3) {
            $brand_html_block = '' . '<div class="brand-profile-block" style="text-align: center; margin: 20px 0; padding: 15px; border: 1px solid #eee; border-radius: 5px; background-color: #f9f9f9;">' . 
                '<img src="' . esc_url($best_match['image_url']) . '" alt="' . esc_attr($best_match['title']) . '" style="max-width: 150px; height: auto; margin-bottom: 10px;">' . 
                '<h4 style="margin: 0 0 5px 0; font-size: 1.1em;">' . esc_html($best_match['title']) . '</h4>' . 
                ($best_match['description'] ? '<p style="margin: 0 0 10px 0; font-size: 0.9em; color: #555;">' . esc_html($best_match['description']) . '</p>' : '') . 
                ($best_match['link'] ? '<a href="' . esc_url($best_match['link']) . '" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em;">了解更多</a>' : '') . 
            '</div>' . '';

            // 根据发布规则中的设置选择插入位置
            $position = isset($publish_rules['brand_profile_position']) ? $publish_rules['brand_profile_position'] : 'before_second_paragraph';

            if ($position === 'article_end') {
                // 在文章结尾插入品牌资料
                $html_content .= '<br><br>' . $brand_html_block . '<br><br>';
                $insertion_position = 'article_end';
            } else {
                // 默认在第二段落前插入（原有逻辑）
                // Use DOMDocument for more robust insertion
                $dom = new DOMDocument();
                // Suppress warnings for malformed HTML
                libxml_use_internal_errors(true);
                $dom->loadHTML(mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();

                $paragraphs = $dom->getElementsByTagName('p');
                $target_paragraph = null;

                if ($paragraphs->length >= 2) {
                    $target_paragraph = $paragraphs->item(1); // Second paragraph
                } elseif ($paragraphs->length >= 1) {
                    $target_paragraph = $paragraphs->item(0); // First paragraph
                }

                $insertion_position = 'unknown';
                if ($target_paragraph) {
                    $fragment = $dom->createDocumentFragment();
                    $fragment->appendXML(mb_convert_encoding($brand_html_block, 'HTML-ENTITIES', 'UTF-8'));
                    $target_paragraph->parentNode->insertBefore($fragment, $target_paragraph->nextSibling);
                    $html_content = $dom->saveHTML();
                    $insertion_position = $paragraphs->length >= 2 ? 'before_second_paragraph' : 'before_first_paragraph';
                } else {
                    // If no paragraphs found, append to the body (or end of content)
                    $html_content .= $brand_html_block;
                    $insertion_position = 'content_end';
                }
            }

            $final_content_length = strlen($html_content);
            $brand_content_length = strlen($brand_html_block);

            $logger->info('BRAND_INSERTION_SUCCESS', '品牌资料插入成功', array_merge($base_context, array(
                'brand_profile_id' => $best_match['id'],
                'brand_profile_title' => $best_match['title'],
                'similarity_score' => $highest_similarity,
                'insertion_position' => $insertion_position,
                'brand_content_length' => $brand_content_length,
                'final_content_length' => $final_content_length,
                'content_increase' => $final_content_length - $base_context['content_length']
            )));

            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $logger->debug('BRAND_INSERTION_DETAILS', '品牌资料插入详情', array_merge($base_context, array(
                    'html_before_insertion' => $base_context['content_length'] . ' chars',
                    'html_after_insertion' => $final_content_length . ' chars',
                    'brand_html_block' => $brand_html_block,
                    'best_match_profile' => $best_match
                )));
            }
        } else {
            $logger->info('BRAND_INSERTION_SKIPPED', '品牌资料插入跳过（未达到阈值）', array_merge($base_context, array(
                'best_match_title' => $best_match ? $best_match['title'] : null,
                'highest_similarity' => $highest_similarity,
                'threshold' => 0.3,
                'reason' => $highest_similarity <= 0.3 ? 'similarity_below_threshold' : 'no_match_found'
            )));
        }

        return $html_content;
    }

    private function create_wordpress_post($title, $content, $publish_rules, $topic_data) {
        // 使用拼音转换器将标题转换为拼音
        $pinyin_converter = new Yali_AI_Writer_PinyinConverter();
        $pinyin_slug = $pinyin_converter->convert_to_pinyin($title);

        $post_status = $publish_rules['post_status'] ?? 'draft';

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

        if ($post_id && !is_wp_error($post_id)) {
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
        return false;
    }

    /**
     * 创建带图片处理的WordPress文章（新的一步到位方案）
     * 先处理图片占位符，然后直接创建最终状态的文章
     * 
     * 【注意】这个方法不是实际执行路径！
     * 实际的文章创建调用在：article-tasks/class-article-queue-processor.php
     * 请不要在此处修改发布逻辑，修改无效！
     * 
     * @param string $title 文章标题
     * @param string $content 文章内容
     * @param array $publish_rules 发布规则
     * @param array $topic_data 主题数据
     * @return int|false 文章ID或失败
     */
    private function create_wordpress_post_with_images($title, $content, $publish_rules, $topic_data) {
        // 【新方案】第一步：预先处理图片占位符，获得最终内容
        $processed_content = $this->process_image_placeholders_in_content($content);
        
        // 第二步：使用处理后的内容，采用与不勾选自动配图完全相同的逻辑
        return $this->create_wordpress_post_direct($title, $processed_content, $publish_rules, $topic_data);
    }

    /**
     * 直接创建WordPress文章（与不勾选自动配图的逻辑完全一致）
     * 
     * @param string $title 文章标题
     * @param string $content 文章内容（已处理图片）
     * @param array $publish_rules 发布规则
     * @param array $topic_data 主题数据
     * @return int|false 文章ID或失败
     */
    private function create_wordpress_post_direct($title, $content, $publish_rules, $topic_data) {
        // 使用拼音转换器将标题转换为拼音
        $pinyin_converter = new Yali_AI_Writer_PinyinConverter();
        $pinyin_slug = $pinyin_converter->convert_to_pinyin($title);

        // 获取发布状态
        $post_status = $publish_rules['post_status'] ?? 'draft';

        // 处理时间间隔发布逻辑（与create_wordpress_post方法完全一致）
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
                    'post_date'     => $new_publish_time,
                    'post_date_gmt' => get_gmt_from_date($new_publish_time),
                    'post_author'   => $publish_rules['author_id'] ?? get_current_user_id(),
                    'post_type'     => 'post',
                    'post_name'     => $pinyin_slug,
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

        // 一步到位创建文章
        $post_id = wp_insert_post($post_data);

        if (!$post_id || is_wp_error($post_id)) {
            return false;
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

        return $post_id;
    }

    /**
     * 预处理内容中的图片占位符，返回包含实际图片的内容
     * 
     * @param string $content 原始内容
     * @return string 处理后的内容
     */
    private function process_image_placeholders_in_content($content) {
        try {
            // 检查是否有图片占位符
            $pattern = '/<!--\s*image\s+prompt:\s*(.*?)-->/is';
            if (!preg_match($pattern, $content)) {
                return $content; // 没有占位符，直接返回原内容
            }

            // 加载自动图片生成器
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            $image_generator = new Yali_AI_Writer_AutoImageGenerator();

            // 处理所有图片占位符
            $processed_content = preg_replace_callback($pattern, function($matches) use ($image_generator) {
                $prompt = trim($matches[1]);
                
                try {
                    // 直接生成图片并返回HTML
                    $image_html = $image_generator->generate_single_image($prompt);
                    return $image_html ?: $matches[0]; // 如果生成失败，保留原占位符
                } catch (Exception $e) {
                    error_log('ContentAuto: 图片生成失败 - Prompt: ' . $prompt . ', Error: ' . $e->getMessage());
                    return $matches[0]; // 保留原占位符
                }
            }, $content);

            return $processed_content;

        } catch (Exception $e) {
            error_log('ContentAuto: 预处理图片占位符失败 - Error: ' . $e->getMessage());
            return $content; // 处理失败，返回原内容
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

    private function save_article_record($topic, $post_id, $article_content, $start_time, $template_name = null, $usage = array()) {
        $article_data = [
            'topic_id' => $topic['id'],
            'post_id' => $post_id,
            'title' => $topic['title'],
            'content' => $article_content,
            'status' => YALI_AI_WRITER_ARTICLE_SUCCESS,
            'processing_time' => time() - $start_time,
            'word_count' => yali_ai_writer_manager_word_count($article_content),
            'api_config_id' => $topic['api_config_id'],
            'api_config_name' => $topic['api_config_name'],
            'prompt_template' => $template_name,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0
        ];
        $this->database->insert('yali_ai_writer_articles', $article_data);
    }
    
    /**
     * 获取默认发布规则配置
     * 当数据库中不存在发布规则时使用
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
        
        // 返回默认发布规则配置
        return array(
            'id' => 0,
            'name' => '默认发布规则',
            'category_mode' => 'manual',
            'category_ids' => serialize(array($default_category_id)),
            'fallback_category_ids' => serialize(array($default_category_id)),
            'post_status' => 'publish',
            'post_type' => 'post',
            'author_id' => get_current_user_id(),
            'is_active' => 1,
            'target_length' => '800-1500',        // 目标字数
            'knowledge_depth' => '未设置',        // 内容深度 - 默认未设置
            'reader_role' => '未设置',            // 目标受众 - 默认未设置
            'normalize_output' => 0,              // 文章结构指导（默认关闭）
            'auto_image_insertion' => 0,          // 文章自动配图（默认关闭）
            'enable_internal_linking' => 0        // 文章内链功能（默认关闭）
        );
    }
    
    /**
     * 获取相似的已发布文章
     * 
     * @param string $title 当前文章标题
     * @return array 相似文章列表
     */
    private function get_similar_published_articles($title) {
        // 获取当前文章的向量表示
        global $wpdb;
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        
        // 查找当前标题对应的主题
        $topic = $wpdb->get_row($wpdb->prepare("SELECT id, vector_embedding FROM {$topics_table} WHERE title = %s AND vector_embedding IS NOT NULL AND vector_embedding != ''", $title));
        
        if (!$topic || empty($topic->vector_embedding)) {
            return array();
        }
        
        // 使用现有的相似标题查找函数，获取更多候选主题
        $similar_titles = yali_ai_writer_find_similar_titles($topic->id, 10); // 获取前10个相似标题
        
        // 收集所有有实际文章的相似标题，不限制相似度阈值
        $similar_articles = array();
        foreach ($similar_titles as $similar_title) {
            // 获取文章ID对应的实际文章
            $article = $wpdb->get_row($wpdb->prepare("SELECT post_id FROM {$articles_table} WHERE topic_id = %d AND post_id IS NOT NULL AND post_id > 0", $similar_title['id']));
            if ($article && $article->post_id) {
                $post_url = get_permalink($article->post_id);
                if ($post_url) {
                    $similar_articles[] = array(
                        'title' => $similar_title['title'],
                        'url' => $post_url,
                        'similarity' => $similar_title['similarity']
                    );
                }
            }
        }
        
        // 按相似度排序，相似度高的排前面
        usort($similar_articles, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // 返回相似度最高的前3篇文章
        return array_slice($similar_articles, 0, 3);
    }
    
    /**
     * 同步处理文章的自动配图（推荐方式）
     * 不受文章发布状态影响，确保图片占位符完整替换
     * 
     * @param int $post_id 文章ID
     * @param string $content 文章内容
     */
    private function process_auto_images_sync($post_id, $content) {
        try {
            // 加载自动图片生成器
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            
            $auto_image_generator = new Yali_AI_Writer_AutoImageGenerator();
            
            // 同步处理图片生成，确保在文章发布前完成
            $result = $auto_image_generator->auto_generate_images_for_post($post_id, $content);
            
            if ($result['success'] && $result['generated_count'] > 0) {
                // 图片生成成功，更新文章内容已在auto_generate_images_for_post中完成
                error_log('ContentAuto: 图片生成完成 - Post ID: ' . $post_id . ', 生成数量: ' . $result['generated_count']);
            } elseif (!$result['success']) {
                // 图片生成失败，记录错误但不阻塞文章发布
                error_log('ContentAuto: 图片生成失败 - Post ID: ' . $post_id . ', 错误: ' . ($result['error'] ?? '未知错误'));
            }
            
        } catch (Exception $e) {
            // 记录错误但不阻塞文章生成流程
            error_log('ContentAuto: 自动配图处理异常 - Post ID: ' . $post_id . ', Error: ' . $e->getMessage());
        }
    }
    
    /**
     * 异步处理文章的自动配图（备用方式）
     * 
     * @param int $post_id 文章ID
     * @param string $content 文章内容
     */
    private function process_auto_images_async($post_id, $content) {
        try {
            // 加载自动图片生成器
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            
            $auto_image_generator = new Yali_AI_Writer_AutoImageGenerator();
            
            // 异步处理图片生成，避免阻塞文章生成流程
            $auto_image_generator->schedule_image_generation($post_id, $content);
            
        } catch (Exception $e) {
            // 记录错误但不阻塞文章生成流程
            error_log('ContentAuto: 自动配图处理失败 - Post ID: ' . $post_id . ', Error: ' . $e->getMessage());
        }
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
        if (isset($settings[$provider]) && array_key_exists('api_key', $settings[$provider])) {
             return !empty($settings[$provider]['api_key']);
        }
        
        return true; 
    }
    /**
     * AI 自动补全主题元数据 (分类和SEO关键词)
     * 
     * @param array $topic 主题数据 (需包含 title 和 original_content)
     * @return array|false 包含 matched_category, seo_keywords 的数组，或失败返回 false
     */
    private function auto_complete_topic_metadata($topic) {
        if (empty($topic['original_content'])) {
            return false;
        }

        // 1. 获取站点现有分类
        $categories = get_categories(array('hide_empty' => false));
        $category_list = array();
        foreach ($categories as $cat) {
            $category_list[] = $cat->name;
        }
        
        if (empty($category_list)) {
            return false;
        }
        
        $category_str = implode(', ', $category_list);
        
        // 2. 构建 Prompt
        // 限制内容长度以分析
        $content_snippet = mb_substr(strip_tags($topic['original_content']), 0, 1000);
        
        $prompt = "请分析以下文章内容，完成两个任务：\n";
        $prompt .= "1. 从给定的分类列表中选择最匹配的一个分类。\n";
        $prompt .= "2. 提取 3-5 个最重要的 SEO 关键词。\n\n";
        $prompt .= "文章标题：{$topic['title']}\n";
        $prompt .= "文章内容摘要：" . $content_snippet . "...\n\n";
        $prompt .= "可选分类列表：[{$category_str}]\n\n";
        $prompt .= "请严格按照以下 JSON 格式输出，不要包含任何其他说明：\n";
        $prompt .= "{\n";
        $prompt .= '  "matched_category": "分类名称",' . "\n";
        $prompt .= '  "seo_keywords": "关键词1, 关键词2, 关键词3"' . "\n";
        $prompt .= "}";

        // 3. 调用 AI
        // 实例化一个新的 API Handler 避免状态干扰
        if (!class_exists('Yali_AI_Writer_UnifiedApiHandler')) {
            // 确保类已加载，虽然通常应该已经加载了
             $file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
             if (file_exists($file)) require_once $file;
        }
        
        $unified_api_handler = new Yali_AI_Writer_UnifiedApiHandler(); 
        
        // 使用自定义任务类型 'metadata_completion'
        $response_data = $unified_api_handler->generate_content($prompt, 'metadata_completion', [
            'timeout' => 45,
            'return_usage' => true
        ]);
        
        if (empty($response_data) || (is_array($response_data) && isset($response_data['error']))) {
            error_log('ContentAuto: Metadata completion failed');
            return false;
        }

        $response = is_array($response_data) && isset($response_data['content']) ? $response_data['content'] : $response_data;
        
        // 4. 解析 JSON
        // 尝试提取 JSON 部分（如果 AI 返回了 Markdown 代码块）
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $json_str = $matches[0];
        } else {
            $json_str = $response;
        }
        
        $data = json_decode($json_str, true);
        
        if (empty($data) || !isset($data['matched_category'])) {
            error_log('ContentAuto: Metadata completion JSON parse failed: ' . $response);
            return false;
        }
        
        // 5. 更新数据库
        $update_data = array();
        $updates_needed = false;
        
        // 验证分类是否存在
        $matched_cat_clean = trim($data['matched_category']);
        if (in_array($matched_cat_clean, $category_list)) {
            // 只有当原主题没有分类时，才进行更新（避免覆盖规则中指定的分类）
            if (empty($topic['matched_category'])) {
                $update_data['matched_category'] = $matched_cat_clean;
                $updates_needed = true;
            }
        } else {
             error_log("ContentAuto: AI suggested category '{$matched_cat_clean}' not found in site categories.");
        }
        
        if (!empty($data['seo_keywords'])) {
            $update_data['seo_keywords'] = sanitize_text_field($data['seo_keywords']);
            $updates_needed = true;
        }
        
        if ($updates_needed && !empty($topic['id'])) {
             $this->database->update('yali_ai_writer_topics', $update_data, array('id' => $topic['id']));
             return $update_data;
        }
        
        return false;
    }

}
?>