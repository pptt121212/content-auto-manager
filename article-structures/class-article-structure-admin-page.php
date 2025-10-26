<?php
/**
 * Admin page for managing article structures.
 * REFACTORED TO BE A SELF-CONTAINED SINGLE FILE TO AVOID WP SCRIPT ENQUEUEING ISSUES.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ArticleStructureAdminPage {

    public function __construct() {
        // 菜单注册已移至 ContentAuto_AdminMenu 类统一管理
        // Script enqueueing is now handled internally by render_page()
        // AJAX actions are still needed.
        add_action('wp_ajax_get_article_structures', [$this, 'ajax_get_article_structures']);
        add_action('wp_ajax_generate_article_structures', [$this, 'ajax_generate_article_structures']);
        add_action('wp_ajax_delete_article_structure', [$this, 'ajax_delete_article_structure']);
        add_action('wp_ajax_get_content_angles', [$this, 'ajax_get_content_angles']);
        add_action('wp_ajax_get_associated_articles', [$this, 'ajax_get_associated_articles']);
        add_action('wp_ajax_get_structure_popularity_stats', [$this, 'ajax_get_structure_popularity_stats']);
        add_action('wp_ajax_delete_dynamic_angle', [$this, 'ajax_delete_dynamic_angle']);
    }

    public function ajax_get_associated_articles() {
        check_ajax_referer('article_structures_nonce', 'nonce');

        if (!isset($_POST['structure_id']) || !is_numeric($_POST['structure_id'])) {
            wp_send_json_error(['message' => '无效的结构ID']);
            return;
        }
        $structure_id = intval($_POST['structure_id']);

        global $wpdb;
        
        // 获取关联的文章及其外部访问统计
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_date, pm2.meta_value as external_visits
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm1 ON (p.ID = pm1.post_id AND pm1.meta_key = '_article_structure_id' AND pm1.meta_value = %s)
            LEFT JOIN {$wpdb->postmeta} pm2 ON (p.ID = pm2.post_id AND pm2.meta_key = '_external_visit_count')
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
            ORDER BY CAST(COALESCE(pm2.meta_value, '0') AS UNSIGNED) DESC
        ", $structure_id), ARRAY_A);
        
        $posts_found = [];
        $total_visits = 0;
        
        foreach ($results as $post) {
            $external_visits = (int)($post['external_visits'] ?? 0);
            $total_visits += $external_visits;
            
            $posts_found[] = [
                'title' => $post['post_title'],
                'url' => get_permalink($post['ID']),
                'external_visits' => $external_visits,
                'date' => mysql2date('Y-m-d', $post['post_date'])
            ];
        }
        
        // 计算平均访问量和受欢迎度指数
        $article_count = count($posts_found);
        $avg_visits = $article_count > 0 ? round($total_visits / $article_count, 1) : 0;
        $popularity_index = $this->calculate_structure_popularity_index($structure_id);
        
        wp_send_json_success([
            'articles' => $posts_found,
            'stats' => [
                'total_articles' => $article_count,
                'total_visits' => $total_visits,
                'avg_visits' => $avg_visits,
                'popularity_index' => $popularity_index
            ]
        ]);
    }
    
    /**
     * 计算文章结构的受欢迎度指数（第二次优化版 - 更强的资源效率考量）
     */
    private function calculate_structure_popularity_index($structure_id) {
        global $wpdb;
        
        // 1. 获取使用该结构的文章的外部访问统计
        $structure_stats = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(p.ID) as article_count,
                SUM(CAST(COALESCE(pm_visits.meta_value, '0') AS UNSIGNED)) as total_visits,
                AVG(CAST(COALESCE(pm_visits.meta_value, '0') AS UNSIGNED)) as avg_visits
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_structure ON (p.ID = pm_structure.post_id AND pm_structure.meta_key = '_article_structure_id' AND pm_structure.meta_value = %s)
            LEFT JOIN {$wpdb->postmeta} pm_visits ON (p.ID = pm_visits.post_id AND pm_visits.meta_key = '_external_visit_count')
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
        ", $structure_id), ARRAY_A);
        
        if (!$structure_stats || $structure_stats['article_count'] == 0) {
            return 0;
        }
        
        // 2. 获取全部文章结构的平均表现作为基准
        $global_stats = $wpdb->get_row("
            SELECT 
                AVG(CAST(COALESCE(pm_visits.meta_value, '0') AS UNSIGNED)) as global_avg_visits
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_structure ON (p.ID = pm_structure.post_id AND pm_structure.meta_key = '_article_structure_id')
            LEFT JOIN {$wpdb->postmeta} pm_visits ON (p.ID = pm_visits.post_id AND pm_visits.meta_key = '_external_visit_count')
            WHERE p.post_type = 'post' AND p.post_status = 'publish'
        ", ARRAY_A);
        
        $global_avg = (float)($global_stats['global_avg_visits'] ?? 0);
        
        if ($global_avg == 0) {
            return 0;
        }
        
        // 3. 计算效率调整后的受欢迎度指数
        $structure_avg = (float)$structure_stats['avg_visits'];
        $article_count = (int)$structure_stats['article_count'];
        
        // 使用平均访问量作为主要指标，并添加效率惩罚
        $efficiency_penalty = pow($article_count, 0.3); // 文章数量越多，效率惩罚越大，但增长较慢
        $efficiency_adjusted_avg = $structure_avg / $efficiency_penalty;
        
        $popularity_index = ($efficiency_adjusted_avg / $global_avg) * 100;

        // 4. 添加文章数量加权，但使用更平缓的增长
        $confidence_factor = min(log(max($article_count, 1) + 1) / log(11), 1); // 使用对数函数，增长非常平缓
        $weighted_index = $popularity_index * (0.85 + 0.15 * $confidence_factor); // 大幅降低信心因子权重
        
        return round($weighted_index, 1);
    }
    
    /**
     * Ajax获取文章结构受欢迎度统计
     */
    public function ajax_get_structure_popularity_stats() {
        check_ajax_referer('article_structures_nonce', 'nonce');
        
        global $wpdb;
        
        // 获取所有文章结构的受欢迎度指数
        $structures = $wpdb->get_results("
            SELECT id, title, content_angle, usage_count
            FROM {$wpdb->prefix}content_auto_article_structures
            ORDER BY content_angle, title
        ", ARRAY_A);
        
        $popularity_stats = [];
        
        foreach ($structures as $structure) {
            $structure_id = $structure['id'];
            $popularity_index = $this->calculate_structure_popularity_index($structure_id);
            
            // 获取该结构的文章统计
            $article_stats = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    COUNT(p.ID) as article_count,
                    SUM(CAST(COALESCE(pm_visits.meta_value, '0') AS UNSIGNED)) as total_visits,
                    AVG(CAST(COALESCE(pm_visits.meta_value, '0') AS UNSIGNED)) as avg_visits
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_structure ON (p.ID = pm_structure.post_id AND pm_structure.meta_key = '_article_structure_id' AND pm_structure.meta_value = %s)
                LEFT JOIN {$wpdb->postmeta} pm_visits ON (p.ID = pm_visits.post_id AND pm_visits.meta_key = '_external_visit_count')
                WHERE p.post_type = 'post' AND p.post_status = 'publish'
            ", $structure_id), ARRAY_A);
            
            $popularity_stats[$structure_id] = [
                'popularity_index' => $popularity_index,
                'article_count' => (int)($article_stats['article_count'] ?? 0),
                'total_visits' => (int)($article_stats['total_visits'] ?? 0),
                'avg_visits' => round((float)($article_stats['avg_visits'] ?? 0), 1),
                'usage_count' => $structure['usage_count']
            ];
        }
        
        wp_send_json_success($popularity_stats);
    }

    public function add_admin_menu() {
        // 菜单注册已移至 ContentAuto_AdminMenu 类统一管理
        // 此方法保留以兼容可能的现有调用
    }

    /**
     * 清理JSON字符串中的控制字符
     * 修复包含换行符等控制字符的JSON解析问题
     * 使用状态机正确处理JSON字符串边界
     */
    private function clean_json_string($json_string) {
        // 首先尝试直接解析
        $data = json_decode($json_string, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json_string; // 已经是有效的JSON
        }
        
        // 移除BOM头
        $json_string = ltrim($json_string, "\xEF\xBB\xBF");
        
        // 第一步：将字面意思的转义序列转换为实际的字符
        // 这是为了处理像日志中显示的 \n 和 \/ 这样的字面转义序列
        $json_string = str_replace(['\\n', '\\r', '\\t', '\\b', '\\f', '\\/', '\\"'], ["\n", "\r", "\t", "\b", "\f", '/', '"'], $json_string);
        
        // 第二步：使用状态机正确处理JSON字符串
        $result = '';
        $in_string = false;
        $escape_next = false;
        $length = strlen($json_string);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $json_string[$i];
            
            if ($escape_next) {
                $result .= $char;
                $escape_next = false;
                continue;
            }
            
            if ($char === '\\') {
                $result .= $char;
                $escape_next = true;
                continue;
            }
            
            if ($char === '"') {
                $result .= $char;
                $in_string = !$in_string;
                continue;
            }
            
            // 只在字符串内部处理控制字符
            if ($in_string) {
                switch ($char) {
                    case "\n":
                        $result .= "\\n";
                        break;
                    case "\r":
                        $result .= "\\r";
                        break;
                    case "\t":
                        $result .= "\\t";
                        break;
                    case "\b":
                        $result .= "\\b";
                        break;
                    case "\f":
                        $result .= "\\f";
                        break;
                    case "\x08": // 退格符
                    case "\x0C": // 换页符
                    case "\x0B": // 垂直制表符
                        // 跳过其他控制字符
                        break;
                    default:
                        // 保留其他字符，包括中文字符
                        $result .= $char;
                        break;
                }
            } else {
                $result .= $char;
            }
        }
        
        return $result;
    }

    /**
     * 标准化structure格式，适配多种API响应格式
     * 支持字符串、数组、对象数组等格式
     */
    private function normalize_structure_format($structure) {
        // 如果已经是数组，直接返回
        if (is_array($structure)) {
            // 检查是否是对象数组格式
            if (!empty($structure) && is_array($structure[0]) && isset($structure[0]['section'])) {
                // 转换对象数组为简单字符串数组
                return array_map(function($item) {
                    return $item['section'] . (isset($item['content']) ? '：' . $item['content'] : '');
                }, $structure);
            }
            return $structure;
        }

        // 如果是字符串，尝试转换为数组
        if (is_string($structure)) {
            // 格式1: 包含<section>标签的字符串
            if (strpos($structure, '<section>') !== false) {
                // 提取<section>标签内容
                preg_match_all('/<section>([^<]*)<\/section>/s', $structure, $matches);
                if (!empty($matches[1])) {
                    return $matches[1];
                }
            }

            // 格式2: 换行分隔的字符串
            if (strpos($structure, "\n") !== false) {
                $lines = explode("\n", trim($structure));
                $lines = array_filter($lines, function($line) {
                    return !empty(trim($line));
                });
                if (!empty($lines)) {
                    return array_map('trim', $lines);
                }
            }

            // 格式3: 单个字符串，包装为数组
            if (!empty(trim($structure))) {
                return [trim($structure)];
            }
        }

        // 其他情况，返回空数组
        return [];
    }

    /**
     * 将数组转换为纯文本格式存储
     */
    private function convert_array_to_text($array) {
        if (!is_array($array) || empty($array)) {
            return '';
        }
        
        // 将数组元素用换行符连接
        $text_lines = array_map(function($item) {
            return trim($item);
        }, $array);
        
        // 过滤空行并连接
        $text_lines = array_filter($text_lines, function($line) {
            return !empty($line);
        });
        
        return implode("\n", $text_lines);
    }

    public function ajax_get_content_angles() {
        check_ajax_referer('article_structures_nonce', 'nonce');
        
        // 定义10个固定的内容角度（与提示词模板中的source_angles保持一致）
        $fixed_angles = [
            '知识科普',
            '实操指导', 
            '问题解决',
            '案例与场景',
            '对比分析',
            '资源工具',
            '趋势洞察',
            '观点评论',
            '情感共鸣',
            '创新启发'
        ];
        
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $db_angles = $wpdb->get_col("SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle IS NOT NULL AND source_angle != '' ORDER BY source_angle ASC");
        
        if (is_wp_error($db_angles)) {
            wp_send_json_error(['message' => '获取内容角度列表失败: ' . $db_angles->get_error_message()]);
            return;
        }
        
        // 找出数据库中存在但不在固定列表中的角度（动态角度）
        $dynamic_angles = array_diff($db_angles, $fixed_angles);
        
        // 返回结构化数据
        wp_send_json_success([
            'fixed_angles' => $fixed_angles,
            'dynamic_angles' => array_values($dynamic_angles) // 重新索引数组
        ]);
    }

    public function render_page() {
        // Read the contents of the view, css, and js files
        $view_html = file_get_contents(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/views/article-structure-management.php');
        $style_css = file_get_contents(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/assets/css/article-structure-management.css');
        $script_js_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/assets/js/article-structure-management.js';
        $script_js = file_exists($script_js_path) ? file_get_contents($script_js_path) : 'console.error("JS file not found!");';

        // Create the localized data for JS
        $localized_data = [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('article_structures_nonce')
        ];

        // Output the self-contained HTML page
        ?>
        <div class="wrap">
            <style type="text/css">
                <?php echo $style_css; ?>
            </style>
            
            <?php echo $view_html; ?>

            <script type="text/javascript">
                const articleStructures = <?php echo json_encode($localized_data); ?>;
            </script>
            <script type="text/javascript">
                <?php echo $script_js; ?>
            </script>
        </div>
        <?php
    }

    public function ajax_get_article_structures() {
        check_ajax_referer('article_structures_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'content_auto_article_structures';

        $structures = $wpdb->get_results("SELECT id, content_angle, title, structure, usage_count FROM {$table_name} ORDER BY content_angle, created_at DESC", ARRAY_A);

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => '数据库查询失败: ' . $wpdb->last_error]);
            return;
        }

        $grouped_structures = [];
        $angle_usage_totals = [];
        
        foreach ($structures as $structure) {
            $angle = $structure['content_angle'];
            $grouped_structures[$angle][] = $structure;
            
            // 累计每个内容角度的使用次数
            if (!isset($angle_usage_totals[$angle])) {
                $angle_usage_totals[$angle] = 0;
            }
            $angle_usage_totals[$angle] += $structure['usage_count'];
        }

        wp_send_json_success([
            'structures' => $grouped_structures,
            'usage_totals' => $angle_usage_totals
        ]);
    }

    public function ajax_generate_article_structures() {
        check_ajax_referer('article_structures_nonce', 'nonce');
        set_time_limit(120); // 2分钟超时限制

        if (!isset($_POST['angle']) || empty($_POST['angle'])) {
            wp_send_json_error(['message' => '未提供内容角度。']);
            return;
        }
        $angle = sanitize_text_field($_POST['angle']);
        $num_to_generate = 1; // 改为单个生成，避免超时

        $logger = new ContentAuto_PluginLogger();
        $logger->info("AJAX call received: Generate {$num_to_generate} structures for angle: {$angle}");

        global $wpdb;
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';

        $prompt_template_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/article-structure-generation-prompt.xml';
        if (!file_exists($prompt_template_path)) {
            wp_send_json_error(['message' => '文章结构生成提示词模板文件不存在。']);
            return;
        }
        $prompt_template = file_get_contents($prompt_template_path);

        $api_handler = new ContentAuto_UnifiedApiHandler();
        $newly_created = []; // To store ids and titles

        // 获取发布语言设置
        $database = new ContentAuto_Database();
        $publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));
        $publish_language = isset($publish_rule['publish_language']) ? $publish_rule['publish_language'] : 'zh-CN';
        
        // 引入语言映射文件
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/language-mappings.php';
        $validated_language = content_auto_validate_language_code($publish_language);
        $language_instruction = content_auto_get_language_instructions($validated_language);

        // Part 1: Generate structures without vectors
        $successful_creations = 0; // 跟踪成功创建的数量
        
        for ($i = 0; $i < $num_to_generate; $i++) {
            $prompt = str_replace('{{CONTENT_ANGLE}}', $angle, $prompt_template);
            $prompt = str_replace('{{CURRENT_DATE}}', date('Y年m月d日'), $prompt);
            $prompt = str_replace('{{LANGUAGE_INSTRUCTION}}', $language_instruction, $prompt);
            $prompt = str_replace('{{LANGUAGE_NAME}}', content_auto_get_language_ai_name($validated_language), $prompt);
            
            // 记录文章结构生成提示词到日志文件
            $this->log_structure_prompt_to_file($prompt, $angle, $i + 1);
            
            $raw_response = $api_handler->generate_content($prompt, 'structure_generation');

            // 增强的响应解析逻辑
            $structure_data = $this->robust_parse_api_response($raw_response, $logger, $angle, $i + 1);
            
            if ($structure_data !== false) {
                $json_error = JSON_ERROR_NONE;
                $json_error_msg = '无';

                if ($json_error === JSON_ERROR_NONE && isset($structure_data['title']) && isset($structure_data['structure'])) {
                    // 标准化structure格式
                    $normalized_structure = $this->normalize_structure_format($structure_data['structure']);
                    
                    // 将数组转换为纯文本存储
                    $structure_text = $this->convert_array_to_text($normalized_structure);
                    
                    $wpdb->insert(
                        $structures_table,
                        [
                            'content_angle' => $angle,
                            'title'         => $structure_data['title'],
                            'structure'     => $structure_text,
                        ],
                        ['%s', '%s', '%s']
                    );
                    $new_id = $wpdb->insert_id;
                    if ($new_id) {
                        $newly_created[] = [
                            'id' => $new_id, 
                            'title' => $structure_data['title'],
                            'content_angle' => $angle,
                            'structure' => $structure_text
                        ];
                        $successful_creations++;
                        $logger->info("Structure generation loop: Successfully created structure for angle {$angle}", [
                            'structure_id' => $new_id,
                            'title' => $structure_data['title'],
                            'structure_format' => gettype($normalized_structure),
                            'structure_count' => is_array($normalized_structure) ? count($normalized_structure) : 0,
                            'storage_format' => 'text',
                            'text_length' => strlen($structure_text),
                            'progress' => "({$successful_creations}/{$num_to_generate})"
                        ]);
                    } else {
                        $logger->error("Structure generation loop: DB insert failed for angle {$angle}", [
                            'db_error' => $wpdb->last_error,
                            'json_string' => $json_string,
                            'attempt' => $i + 1,
                            'progress' => "({$successful_creations}/{$num_to_generate})"
                        ]);
                    }
                } else {
                    $logger->error("Structure generation loop: 数据解析成功但缺少必需字段", [
                        'angle' => $angle,
                        'has_title' => isset($structure_data['title']),
                        'has_structure' => isset($structure_data['structure']),
                        'available_keys' => $structure_data ? array_keys($structure_data) : 'null',
                        'attempt' => $i + 1
                    ]);
                }
            } else {
                $logger->error("Structure generation loop: API响应解析完全失败", [
                    'angle' => $angle,
                    'raw_response_type' => gettype($raw_response),
                    'raw_response_preview' => is_string($raw_response) ? substr($raw_response, 0, 200) : $raw_response,
                    'attempt' => $i + 1
                ]);
            }
            
            
            sleep(1); // Small delay between LLM calls
        }

        if (empty($newly_created)) {
            wp_send_json_error(['message' => '生成失败，请检查API配置或稍后重试']);
            return;
        }

        $logger->info("Successfully generated " . count($newly_created) . " structures. Now generating vectors in batch.");

        // Part 2: Batch generate vectors
        $texts_to_vectorize = [];
        foreach ($newly_created as $item) {
            $combined_text = $item['content_angle'] . ' ' . $item['title'] . ' ' . $item['structure'];
            $texts_to_vectorize[] = $combined_text;
        }

        $vector_handler = new ContentAuto_VectorApiHandler($logger);
        $vector_result = $vector_handler->generate_embeddings_batch($texts_to_vectorize);

        $vectors_generated = 0;
        if ($vector_result !== false && !empty($vector_result['embeddings'])) {
            foreach ($vector_result['embeddings'] as $embedding_data) {
                $index = $embedding_data['index'];
                $embedding = $embedding_data['embedding'];
                if (isset($newly_created[$index])) {
                    $structure_id = $newly_created[$index]['id'];
                    $wpdb->update(
                        $structures_table,
                        ['title_vector' => $embedding],
                        ['id' => $structure_id],
                        ['%s'],
                        ['%d']
                    );
                    $vectors_generated++;
                }
            }
        }

        // 构建详细的结果消息
        $total_attempts = $num_to_generate;
        $structures_created = count($newly_created);
        $success_rate = $total_attempts > 0 ? round(($structures_created / $total_attempts) * 100, 1) : 0;
        
        if ($structures_created > 0) {
            $message = $vectors_generated > 0 ? 
                "✅ 成功生成1个新结构并生成了向量" : 
                "✅ 成功生成1个新结构";
            wp_send_json_success(['message' => $message]);
        } else {
            wp_send_json_error(['message' => '生成失败，请检查API配置']);
        }
    }

    public function ajax_delete_article_structure() {
        check_ajax_referer('article_structures_nonce', 'nonce');

        if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
            wp_send_json_error(['message' => '无效的ID']);
            return;
        }

        $id = intval($_POST['id']);

        global $wpdb;
        
        // 开始事务，确保数据一致性
        $wpdb->query('START TRANSACTION');
        
        try {
            $table_name = $wpdb->prefix . 'content_auto_article_structures';
            
            // 1. 首先删除文章结构记录
            $result = $wpdb->delete($table_name, ['id' => $id], ['%d']);
            
            if ($result === false) {
                throw new Exception('删除文章结构失败: ' . $wpdb->last_error);
            } else if ($result === 0) {
                throw new Exception('要删除的结构不存在');
            }
            
            // 2. 清理所有关联此结构ID的文章meta数据
            $cleaned_meta_count = $wpdb->delete(
                $wpdb->postmeta,
                ['meta_key' => '_article_structure_id', 'meta_value' => $id],
                ['%s', '%s']
            );
            
            // 3. 提交事务
            $wpdb->query('COMMIT');
            
            // 记录清理信息到日志
            if (class_exists('ContentAuto_PluginLogger')) {
                $logger = new ContentAuto_PluginLogger();
                $logger->info('文章结构删除完成', [
                    'structure_id' => $id,
                    'cleaned_article_associations' => $cleaned_meta_count,
                    'action' => 'delete_article_structure'
                ]);
            }
            
            $message = "文章结构已删除";
            if ($cleaned_meta_count > 0) {
                $message .= "，同时清理了 {$cleaned_meta_count} 个文章关联";
            }
            
            wp_send_json_success(['message' => $message]);
            
        } catch (Exception $e) {
            // 回滚事务
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * 记录文章结构生成提示词到统一日志系统
     */
    private function log_structure_prompt_to_file($prompt_content, $content_angle, $sequence_number) {
        try {
            // 仅在调试模式下记录完整提示词
            $debug_mode = get_option('content_auto_debug_mode', false);
            if (!$debug_mode) {
                return; // 调试模式未启用，不记录完整提示词
            }
            
            // 引入日志系统
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
            $logger = new ContentAuto_LoggingSystem();
            
            // 使用统一的日志系统记录完整提示词
            $context = array(
                'type' => 'STRUCTURE_PROMPT',
                'content_angle' => $content_angle,
                'sequence_number' => $sequence_number,
                'prompt_length' => strlen($prompt_content),
                'prompt_content' => $prompt_content
            );
            
            $logger->log_info('COMPLETE_PROMPT', '文章结构生成完整提示词', $context);
            
        } catch (Exception $e) {
            // 静默处理错误，避免影响主流程
            error_log('文章结构提示词日志记录失败: ' . $e->getMessage());
        }
    }

    /**
     * AJAX删除动态角度，将对应主题重新分配到固定角度
     */
    public function ajax_delete_dynamic_angle() {
        check_ajax_referer('article_structures_nonce', 'nonce');

        if (!isset($_POST['angle']) || empty($_POST['angle'])) {
            wp_send_json_error(['message' => '未提供角度名称']);
            return;
        }

        $angle = sanitize_text_field($_POST['angle']);

        // 定义固定角度，防止误删
        $fixed_angles = [
            '知识科普', '实操指导', '问题解决', '案例与场景', '对比分析',
            '资源工具', '趋势洞察', '观点评论', '情感共鸣', '创新启发'
        ];

        if (in_array($angle, $fixed_angles)) {
            wp_send_json_error(['message' => '不能删除固定的内容角度']);
            return;
        }

        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';

        // 开始事务
        $wpdb->query('START TRANSACTION');

        try {
            // 1. 查找使用该角度的所有主题
            $affected_topics = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title FROM {$topics_table} WHERE source_angle = %s",
                $angle
            ), ARRAY_A);

            $updated_topics = 0;
            $deleted_structures = 0;

            // 2. 为每个主题随机分配固定角度
            foreach ($affected_topics as $topic) {
                $random_angle = $fixed_angles[array_rand($fixed_angles)];
                
                $result = $wpdb->update(
                    $topics_table,
                    ['source_angle' => $random_angle],
                    ['id' => $topic['id']],
                    ['%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $updated_topics++;
                    
                    // 记录日志
                    if (class_exists('ContentAuto_PluginLogger')) {
                        $logger = new ContentAuto_PluginLogger();
                        $logger->log('主题角度已重新分配', 'INFO', [
                            'topic_id' => $topic['id'],
                            'topic_title' => $topic['title'],
                            'old_angle' => $angle,
                            'new_angle' => $random_angle
                        ]);
                    }
                }
            }

            // 3. 删除该角度下的所有文章结构（清理关联数据）
            // 3.1 首先获取要删除的结构ID列表
            $structure_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$structures_table} WHERE content_angle = %s",
                $angle
            ));
            
            // 3.2 删除文章结构记录
            $deleted_structures = $wpdb->delete(
                $structures_table,
                ['content_angle' => $angle],
                ['%s']
            );

            if ($deleted_structures === false) {
                throw new Exception('删除文章结构失败: ' . $wpdb->last_error);
            }
            
            // 3.3 清理所有相关的文章关联数据
            $total_cleaned_meta = 0;
            if (!empty($structure_ids)) {
                foreach ($structure_ids as $structure_id) {
                    $cleaned_count = $wpdb->delete(
                        $wpdb->postmeta,
                        ['meta_key' => '_article_structure_id', 'meta_value' => $structure_id],
                        ['%s', '%s']
                    );
                    $total_cleaned_meta += $cleaned_count;
                }
            }

            // 提交事务
            $wpdb->query('COMMIT');

            wp_send_json_success([
                'message' => sprintf(
                    '成功删除动态角度"%s"：%d个主题已重新分配到固定角度，%d个相关结构已删除，%d个文章关联已清理',
                    $angle,
                    $updated_topics,
                    $deleted_structures,
                    $total_cleaned_meta
                ),
                'updated_topics_count' => $updated_topics,
                'deleted_structures_count' => $deleted_structures,
                'cleaned_associations_count' => $total_cleaned_meta,
                'affected_topics' => $affected_topics
            ]);

        } catch (Exception $e) {
            // 回滚事务
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => '删除失败: ' . $e->getMessage()]);
        }
    }
    
    /**
     * 健壮的API响应解析方法
     * 支持多种响应格式和容错处理
     */
    private function robust_parse_api_response($raw_response, $logger, $angle, $attempt) {
        // 记录原始响应用于调试
        $logger->info("开始解析API响应", [
            'angle' => $angle,
            'attempt' => $attempt,
            'response_type' => gettype($raw_response),
            'response_length' => is_string($raw_response) ? strlen($raw_response) : 0
        ]);
        
        // 1. 检查响应是否为错误
        if (is_array($raw_response) && isset($raw_response['error'])) {
            $logger->error("API返回错误响应", [
                'error' => $raw_response['error'],
                'angle' => $angle,
                'attempt' => $attempt
            ]);
            return false;
        }
        
        // 2. 确保响应是字符串
        if (!is_string($raw_response)) {
            $logger->error("API响应不是字符串", [
                'response_type' => gettype($raw_response),
                'response' => $raw_response,
                'angle' => $angle
            ]);
            return false;
        }
        
        // 3. 多种JSON提取策略
        $json_candidates = [];
        
        // 预处理：先清理明显的Markdown标记
        $clean_response = preg_replace('/\*\*•\*\*\s*/', '', $raw_response);
        $clean_response = preg_replace('/```json\s*/', '', $clean_response);
        $clean_response = preg_replace('/```\s*/', '', $clean_response);
        
        // 策略1: 手动重构JSON（处理被Markdown破坏的结构）
        if (preg_match('/"title":\s*"([^"]*)"/', $clean_response, $title_match)) {
            preg_match_all('/"([^"]{10,})"/', $clean_response, $all_strings);
            if (!empty($all_strings[1])) {
                $title = $title_match[1];
                $structure_items = array_filter($all_strings[1], function($item) use ($title) {
                    return $item !== $title && strlen($item) > 8 && 
                           !in_array($item, ['title', 'structure']);
                });
                
                if (!empty($structure_items)) {
                    $reconstructed = [
                        'title' => $title,
                        'structure' => array_values(array_slice($structure_items, 0, 7))
                    ];
                    $json_candidates[] = json_encode($reconstructed);
                }
            }
        }
        
        // 策略2: 查找包含title和structure的JSON
        if (preg_match('/\{[^{}]*"title"[^{}]*"structure"[^{}]*\}/s', $clean_response, $matches)) {
            array_unshift($json_candidates, $matches[0]);
        }
        
        // 策略3: 提取最后一个完整的JSON对象
        if (preg_match_all('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $clean_response, $matches)) {
            $json_candidates = array_merge($json_candidates, $matches[0]);
        }
        
        // 策略4: 简单的大括号匹配
        if (preg_match('/\{.*\}/s', $clean_response, $matches)) {
            $json_candidates[] = $matches[0];
        }
        
        // 4. 尝试解析每个候选JSON
        foreach ($json_candidates as $json_string) {
            $structure_data = $this->try_parse_json($json_string, $logger, $angle, $attempt);
            if ($structure_data !== false) {
                return $structure_data;
            }
        }
        
        // 5. 最后尝试：文本格式解析
        return $this->try_parse_text_format($raw_response, $logger, $angle, $attempt);
    }
    
    /**
     * 尝试解析JSON字符串
     */
    private function try_parse_json($json_string, $logger, $angle, $attempt) {
        // 多层清理
        $original_json = $json_string;
        
        // 清理1: 移除前后空白和非JSON字符
        $json_string = trim($json_string);
        $json_string = preg_replace('/^[^{]*/', '', $json_string);
        $json_string = preg_replace('/[^}]*$/', '', $json_string);
        
        // 清理2: 使用现有的clean_json_string方法
        $json_string = $this->clean_json_string($json_string);
        
        // 清理3: 修复常见的JSON问题
        $json_string = $this->fix_common_json_issues($json_string);
        
        // 尝试解析
        $data = json_decode($json_string, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            // 验证必需字段
            if (isset($data['title']) && isset($data['structure'])) {
                $logger->info("JSON解析成功", [
                    'angle' => $angle,
                    'attempt' => $attempt,
                    'title' => $data['title'],
                    'structure_type' => gettype($data['structure'])
                ]);
                return $data;
            } else {
                $logger->warning("JSON解析成功但缺少必需字段", [
                    'angle' => $angle,
                    'available_keys' => array_keys($data),
                    'original_json' => substr($original_json, 0, 200)
                ]);
            }
        } else {
            $logger->warning("JSON解析失败", [
                'angle' => $angle,
                'error' => json_last_error_msg(),
                'original_length' => strlen($original_json),
                'cleaned_length' => strlen($json_string)
            ]);
        }
        
        return false;
    }
    
    /**
     * 修复常见的JSON格式问题
     */
    private function fix_common_json_issues($json_string) {
        // 修复1: 移除各种代码块标记
        $json_string = preg_replace('/```json\s*/', '', $json_string);
        $json_string = preg_replace('/```\s*json\s*/', '', $json_string);
        $json_string = preg_replace('/\s*```/', '', $json_string);
        
        // 修复2: 移除Markdown格式
        $json_string = preg_replace('/\*\*•\*\*\s*/', '', $json_string); // 移除 **•**
        $json_string = preg_replace('/\*\*([^*]+)\*\*/', '$1', $json_string); // 移除粗体
        $json_string = preg_replace('/\*([^*]+)\*/', '$1', $json_string); // 移除斜体
        
        // 修复3: 清理列表符号和缩进
        $json_string = preg_replace('/^\s*[-*•]\s*/m', '', $json_string); // 移除列表符号
        $json_string = preg_replace('/^\s+/m', '', $json_string); // 移除行首空白
        
        // 修复4: 处理多余的逗号
        $json_string = preg_replace('/,(\s*[}\]])/', '$1', $json_string);
        
        // 修复5: 修复未转义的引号
        $json_string = preg_replace('/"([^"]*)"([^":,}\]]*)"/', '"$1\"$2"', $json_string);
        
        // 修复6: 重建JSON结构（如果被破坏）
        if (!preg_match('/^\s*\{/', $json_string)) {
            // 如果不以{开头，尝试重建
            $lines = explode("\n", $json_string);
            $title_line = '';
            $structure_lines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                if (preg_match('/"title":\s*"([^"]*)"/', $line, $matches)) {
                    $title_line = '"title": "' . $matches[1] . '"';
                } elseif (preg_match('/"([^"]*)"[,\s]*$/', $line, $matches)) {
                    $structure_lines[] = '"' . $matches[1] . '"';
                }
            }
            
            if ($title_line && !empty($structure_lines)) {
                $json_string = '{' . $title_line . ', "structure": [' . implode(', ', $structure_lines) . ']}';
            }
        }
        
        return $json_string;
    }
    
    /**
     * 尝试解析文本格式（当JSON完全失败时的后备方案）
     */
    private function try_parse_text_format($raw_response, $logger, $angle, $attempt) {
        $logger->info("尝试文本格式解析", ['angle' => $angle]);
        
        // 预处理：清理Markdown和代码块标记
        $clean_response = $raw_response;
        $clean_response = preg_replace('/```json\s*/', '', $clean_response);
        $clean_response = preg_replace('/```\s*/', '', $clean_response);
        $clean_response = preg_replace('/\*\*•\*\*\s*/', '', $clean_response);
        $clean_response = preg_replace('/\*\*([^*]+)\*\*/', '$1', $clean_response);
        
        // 查找标题模式
        $title = null;
        if (preg_match('/"title":\s*"([^"]*)"/', $clean_response, $matches)) {
            $title = trim($matches[1]);
        } elseif (preg_match('/(?:标题|title)[：:]\s*"?([^\n\r"]+)"?/i', $clean_response, $matches)) {
            $title = trim($matches[1]);
        }
        
        // 查找结构部分
        $structure = [];
        
        // 模式1: JSON数组中的字符串
        if (preg_match_all('/"([^"]{10,})"[,\s]*/', $clean_response, $matches)) {
            $potential_structure = array_map('trim', $matches[1]);
            // 过滤掉标题
            $structure = array_filter($potential_structure, function($item) use ($title) {
                return $item !== $title && strlen($item) > 5;
            });
        }
        
        // 模式2: 中文引号内容
        if (empty($structure) && preg_match_all('/[""]([^"""]{10,})[""][,\s]*/', $clean_response, $matches)) {
            $structure = array_map('trim', $matches[1]);
        }
        
        // 模式3: 数字列表
        if (empty($structure) && preg_match_all('/^\s*\d+[\.\)]\s*(.+)$/m', $clean_response, $matches)) {
            $structure = array_map('trim', $matches[1]);
        }
        
        // 模式4: 破折号或星号列表
        if (empty($structure) && preg_match_all('/^\s*[-*•]\s*(.+)$/m', $clean_response, $matches)) {
            $structure = array_map('trim', $matches[1]);
        }
        
        // 模式5: 【】标记的内容
        if (empty($structure) && preg_match_all('/【[^】]*】[^【\n]*/', $clean_response, $matches)) {
            $structure = array_map('trim', $matches[0]);
        }
        
        // 清理结构项目
        if (!empty($structure)) {
            $structure = array_map(function($item) {
                // 移除引号和多余符号
                $item = trim($item, '"\'""');
                $item = preg_replace('/^[,\s]+/', '', $item);
                $item = preg_replace('/[,\s]+$/', '', $item);
                return $item;
            }, $structure);
            
            // 过滤空项和过短项
            $structure = array_filter($structure, function($item) {
                return !empty($item) && strlen($item) > 8;
            });
        }
        
        if ($title && !empty($structure)) {
            $logger->info("文本格式解析成功", [
                'angle' => $angle,
                'title' => $title,
                'structure_count' => count($structure)
            ]);
            
            return [
                'title' => $title,
                'structure' => array_values(array_slice($structure, 0, 7)) // 重新索引并限制数量
            ];
        }
        
        $logger->error("文本格式解析也失败", [
            'angle' => $angle,
            'title_found' => !empty($title),
            'structure_count' => count($structure),
            'sample_structure' => !empty($structure) ? array_slice($structure, 0, 2) : []
        ]);
        
        return false;
    }
}
