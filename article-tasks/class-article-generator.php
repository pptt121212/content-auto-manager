<?php
/**
 * 文章生成器（改造后）
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/class-xml-template-processor.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/content-processing/class-content-filter.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/content-processing/class-markdown-converter.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-pinyin-converter.php';

class ContentAuto_ArticleGenerator {
    
    private $database;
    private $article_task_manager;
    private $markdown_converter;
    private $content_filter;

    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->article_task_manager = new ContentAuto_ArticleTaskManager();
        
        // 初始化转换器（避免每次生成文章时重复创建）
        $this->markdown_converter = new ContentAuto_MarkdownConverter();
        $this->content_filter = new ContentAuto_ContentFilter();
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
     * @return array 生成结果
     */
    public function generate_article_for_topic($topic) {
        // 获取发布规则
        $publish_rules = $this->database->get_row('content_auto_publish_rules', array('id' => 1));
        
        // 如果发布规则不存在，使用默认配置
        if (!$publish_rules) {
            $publish_rules = $this->get_default_publish_rules();
        }
        
        // 获取相关内容
        $related_content = (new ContentAuto_RuleManager())->get_content_by_rule($topic['rule_id'], 5);
        
        // 如果启用了文章内链功能，获取相似文章
        $similar_articles = array();
        if (isset($publish_rules['enable_internal_linking']) && $publish_rules['enable_internal_linking'] == 1) {
            $similar_articles = $this->get_similar_published_articles($topic['title']);
        }
        
        // 生成文章内容
        $article_content = $this->generate_article($topic, $related_content, $publish_rules, $similar_articles);
        
        if ($article_content) {
            // 根据是否启用自动配图决定创建策略
            if (isset($publish_rules['auto_image_insertion']) && $publish_rules['auto_image_insertion'] == 1) {
                // 启用自动配图：先创建草稿，处理图片后再设置正确状态
                $post_id = $this->create_wordpress_post_with_images($topic['title'], $article_content, $publish_rules, $topic);
            } else {
                // 未启用自动配图：直接创建并发布
                $post_id = $this->create_wordpress_post($topic['title'], $article_content, $publish_rules, $topic);
            }
            
            if ($post_id) {
                // 保存文章记录到数据库
                $this->save_article_record($topic, $post_id, $article_content, time());
                // 验证主题状态，只有从queued状态才能更新为used
                if ($topic['status'] === CONTENT_AUTO_TOPIC_QUEUED) {
                    $this->database->update('content_auto_topics', array('status' => CONTENT_AUTO_TOPIC_USED), array('id' => $topic['id']));
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
    
    private function generate_article($topic, $related_content, $publish_rules, $similar_articles = array()) {
        error_log('ContentAuto: generate_article - Publish Rules: ' . print_r($publish_rules, true));
        $xml_processor = new ContentAuto_XmlTemplateProcessor();
        $prompt = $xml_processor->generate_prompt($topic, $publish_rules, $related_content, $similar_articles);
        
        $unified_api_handler = new ContentAuto_UnifiedApiHandler();
        $raw_content = $unified_api_handler->generate_content($prompt, 'article', [
            'rule_id' => $topic['rule_id'],
            'topic_id' => $topic['id']
        ]);
        
        if (is_array($raw_content) && isset($raw_content['error'])) {
            return false;
        }
        
        // 过滤外部包装标记
        $filtered_content = $this->content_filter->filter_content($raw_content);
        
        // 转换Markdown为HTML
        $html_content = $this->markdown_converter->markdown_to_html($filtered_content);
        
        // Debug: Log publish rules using the plugin's logger
        if (!class_exists('ContentAuto_PluginLogger')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new ContentAuto_PluginLogger();
        $logger->info('generate_article - Publish Rules', [
            'topic_id' => $topic['id'],
            'publish_rules' => $publish_rules
        ]);

        return $this->insert_brand_profile($html_content, $topic, $publish_rules);
    }

    private function insert_brand_profile($html_content, $topic, $publish_rules) {
        if (!class_exists('ContentAuto_PluginLogger')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new ContentAuto_PluginLogger();

        if (!isset($publish_rules['enable_brand_profile_insertion']) || !$publish_rules['enable_brand_profile_insertion']) {
            $logger->info('insert_brand_profile - Brand profile insertion disabled in publish rules.', ['topic_id' => $topic['id']]);
            return $html_content;
        }

        // Condition 2: Check if topic has a vector embedding
        if (empty($topic['vector_embedding'])) {
            $logger->warning('insert_brand_profile - Topic vector embedding is empty.', ['topic_id' => $topic['id']]);
            return $html_content;
        }

        // Condition 3: Check if any brand profiles with vectors exist
        global $wpdb;
        $brand_profiles_table = $wpdb->prefix . 'content_auto_brand_profiles';
        $brand_profiles = $wpdb->get_results("SELECT * FROM {$brand_profiles_table} WHERE vector IS NOT NULL", ARRAY_A);

        if (empty($brand_profiles)) {
            $logger->warning('ContentAuto: insert_brand_profile - No brand profiles with vectors found.');
            return $html_content;
        }
        $logger->info('ContentAuto: insert_brand_profile - Found ' . count($brand_profiles) . ' brand profiles with vectors.');

        // Decode topic vector
        $topic_vector_decoded = base64_decode($topic['vector_embedding']);
        if (!$topic_vector_decoded) {
            $logger->error('ContentAuto: insert_brand_profile - Failed to decode topic vector for topic ID: ' . $topic['id']);
            return $html_content;
        }
        $topic_vector = unpack('f*', $topic_vector_decoded);
        $logger->info('ContentAuto: insert_brand_profile - Topic vector (first 5 elements): ' . implode(', ', array_slice($topic_vector, 0, 5)));

        $best_match = null;
        $highest_similarity = -1;

        // Ensure the cosine similarity function is available
        if (!function_exists('content_auto_calculate_cosine_similarity')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/functions.php';
        }

        foreach ($brand_profiles as $profile) {
            $profile_vector_decoded = base64_decode($profile['vector']);
            if (!$profile_vector_decoded) {
                error_log('ContentAuto: insert_brand_profile - Failed to decode brand profile vector for ID: ' . $profile['id']);
                continue;
            }
            $profile_vector = unpack('f*', $profile_vector_decoded);

            if (count($topic_vector) !== count($profile_vector)) {
                error_log('ContentAuto: insert_brand_profile - Vector dimension mismatch for brand profile ID: ' . $profile['id']);
                continue;
            }

            $similarity = content_auto_calculate_cosine_similarity($topic_vector, $profile_vector);
            error_log('ContentAuto: insert_brand_profile - Comparing topic ' . $topic['id'] . ' with brand profile ' . $profile['id'] . ' (\'' . $profile['title'] . '\'). Similarity: ' . $similarity);

            if ($similarity > $highest_similarity) {
                $highest_similarity = $similarity;
                $best_match = $profile;
            }
        }

        error_log('ContentAuto: insert_brand_profile - Best match found: ' . ($best_match ? $best_match['title'] : 'None') . ', Highest Similarity: ' . $highest_similarity);

        // Threshold check
        if ($best_match && $highest_similarity > 0.3) {
            $brand_html_block = '' . '<div class="brand-profile-block" style="text-align: center; margin: 20px 0; padding: 15px; border: 1px solid #eee; border-radius: 5px; background-color: #f9f9f9;">' . 
                '<img src="' . esc_url($best_match['image_url']) . '" alt="' . esc_attr($best_match['title']) . '" style="max-width: 150px; height: auto; margin-bottom: 10px;">' . 
                '<h4 style="margin: 0 0 5px 0; font-size: 1.1em;">' . esc_html($best_match['title']) . '</h4>' . 
                ($best_match['description'] ? '<p style="margin: 0 0 10px 0; font-size: 0.9em; color: #555;">' . esc_html($best_match['description']) . '</p>' : '') . 
                ($best_match['link'] ? '<a href="' . esc_url($best_match['link']) . '" target="_blank" rel="noopener noreferrer" style="font-size: 0.9em;">了解更多</a>' : '') . 
            '</div>' . '';

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

            if ($target_paragraph) {
                $fragment = $dom->createDocumentFragment();
                $fragment->appendXML(mb_convert_encoding($brand_html_block, 'HTML-ENTITIES', 'UTF-8'));
                $target_paragraph->parentNode->insertBefore($fragment, $target_paragraph->nextSibling);
                $html_content = $dom->saveHTML();
            } else {
                // If no paragraphs found, append to the body (or end of content)
                $html_content .= $brand_html_block;
            }
        }

        return $html_content;
    }

    private function create_wordpress_post($title, $content, $publish_rules, $topic_data) {
        // 使用拼音转换器将标题转换为拼音
        $pinyin_converter = new ContentAuto_PinyinConverter();
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
        $pinyin_converter = new ContentAuto_PinyinConverter();
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
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            $image_generator = new ContentAuto_AutoImageGenerator();

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
                if (class_exists('ContentAuto_Category_Filter')) {
                    $categories = ContentAuto_Category_Filter::get_filtered_categories(array('hide_empty' => false));
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

    private function save_article_record($topic, $post_id, $article_content, $start_time) {
        $article_data = [
            'topic_id' => $topic['id'],
            'post_id' => $post_id,
            'title' => $topic['title'],
            'content' => $article_content,
            'status' => CONTENT_AUTO_ARTICLE_SUCCESS,
            'processing_time' => time() - $start_time,
            'word_count' => content_auto_manager_word_count($article_content),
            'api_config_id' => $topic['api_config_id'],
            'api_config_name' => $topic['api_config_name']
        ];
        $this->database->insert('content_auto_articles', $article_data);
    }
    
    /**
     * 获取默认发布规则配置
     * 当数据库中不存在发布规则时使用
     */
    private function get_default_publish_rules() {
        // 获取默认分类（通常是"未分类"）
        $default_category = get_category_by_slug('uncategorized');
        
        // 检查默认分类是否在允许的分类中
        if ($default_category && class_exists('ContentAuto_Category_Filter')) {
            if (!ContentAuto_Category_Filter::is_category_allowed($default_category->term_id)) {
                $default_category = null; // 如果不在允许列表中，重置为null
            }
        }
        
        if (!$default_category) {
            // 如果没有"未分类"或不在允许列表中，获取第一个允许的分类
            if (class_exists('ContentAuto_Category_Filter')) {
                $categories = ContentAuto_Category_Filter::get_filtered_categories(array('number' => 1));
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
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // 查找当前标题对应的主题
        $topic = $wpdb->get_row($wpdb->prepare("SELECT id, vector_embedding FROM {$topics_table} WHERE title = %s AND vector_embedding IS NOT NULL AND vector_embedding != ''", $title));
        
        if (!$topic || empty($topic->vector_embedding)) {
            return array();
        }
        
        // 使用现有的相似标题查找函数，获取更多候选主题
        $similar_titles = content_auto_find_similar_titles($topic->id, 10); // 获取前10个相似标题
        
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
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            
            $auto_image_generator = new ContentAuto_AutoImageGenerator();
            
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
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-tasks/class-auto-image-generator.php';
            
            $auto_image_generator = new ContentAuto_AutoImageGenerator();
            
            // 异步处理图片生成，避免阻塞文章生成流程
            $auto_image_generator->schedule_image_generation($post_id, $content);
            
        } catch (Exception $e) {
            // 记录错误但不阻塞文章生成流程
            error_log('ContentAuto: 自动配图处理失败 - Post ID: ' . $post_id . ', Error: ' . $e->getMessage());
        }
    }
}
?>