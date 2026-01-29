<?php
/**
 * 处理规则表单提交的类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_RuleHandler {

    public function __construct() {
        add_action('admin_init', array($this, 'handle_form_submission'));
        // 注册AJAX处理函数
        add_action('wp_ajax_content_auto_delete_rule', array($this, 'handle_delete_rule'));
        add_action('wp_ajax_content_auto_get_article_titles', array($this, 'handle_get_article_titles'));
    }

    /**
     * 处理规则添加/编辑表单的提交
     */
    public function handle_form_submission() {
        // 检查是否是我们表单的提交
        if (!isset($_POST['cam_save_rule_nonce']) || !wp_verify_nonce($_POST['cam_save_rule_nonce'], 'cam_save_rule_action')) {
            return;
        }

        // 检查用户权限
        if (!current_user_can('manage_options')) {
            wp_die(__('您没有权限执行此操作。', 'content-auto-manager'));
        }

        global $wpdb;
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';

        // 1. 清洗和准备主规则数据
        $rule_name = sanitize_text_field($_POST['rule_name']);
        $rule_type = sanitize_text_field($_POST['rule_type']);
        $item_count = intval($_POST['item_count']);
        $status = isset($_POST['status']) ? 1 : 0;
        // 处理参考资料字段
        $reference_material = isset($_POST['reference_material']) ? sanitize_textarea_field($_POST['reference_material']) : '';
        // 确保不超过800字符限制
        if (mb_strlen($reference_material, 'UTF-8') > 800) {
            $reference_material = mb_substr($reference_material, 0, 800, 'UTF-8');
        }

        // 检查是否是编辑模式
        $is_edit_mode = isset($_POST['rule_id']) && !empty($_POST['rule_id']);
        $rule_id = $is_edit_mode ? intval($_POST['rule_id']) : 0;
        
        // 为新规则生成唯一的任务ID
        $rule_task_id = $is_edit_mode ? null : 'rule_' . uniqid();

        $rule_conditions = array();
        if ($rule_type === 'random_selection') {
            $rule_conditions['categories'] = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        } elseif ($rule_type === 'fixed_articles') {
            $rule_conditions['post_ids'] = array();
            if (!empty($_POST['selected_articles'])) {
                $post_ids_str = sanitize_text_field($_POST['selected_articles']);
                $rule_conditions['post_ids'] = array_map('intval', explode(',', $post_ids_str));
            }
        } elseif ($rule_type === 'upload_text') {
            $rule_conditions['upload_text'] = isset($_POST['upload_text_content']) ? sanitize_textarea_field($_POST['upload_text_content']) : '';
            // 限制文本长度为3000字符（使用mb_strlen确保正确计算多字节字符）
            if (mb_strlen($rule_conditions['upload_text'], 'UTF-8') > 3000) {
                $rule_conditions['upload_text'] = mb_substr($rule_conditions['upload_text'], 0, 3000, 'UTF-8');
            }
        } elseif ($rule_type === 'import_keywords') {
            $keywords_text = isset($_POST['keywords_content']) ? sanitize_textarea_field($_POST['keywords_content']) : '';
            // 分割关键词并过滤
            $keywords_array = array();
            if (!empty($keywords_text)) {
                $raw_keywords = explode("\n", $keywords_text);
                foreach ($raw_keywords as $keyword) {
                    $keyword = trim($keyword);
                    if (!empty($keyword)) {
                        $keywords_array[] = $keyword;
                    }
                }
            }
            // 限制最多200个关键词
            $rule_conditions['keywords'] = array_slice($keywords_array, 0, 200);
        } elseif ($rule_type === 'random_categories') {
            $rule_conditions['categories'] = isset($_POST['categories']) ? array_map('intval', $_POST['categories']) : array();
        } elseif ($rule_type === 'collect_url_rewrite') {
            $url_text = isset($_POST['collect_url_content']) ? sanitize_textarea_field($_POST['collect_url_content']) : '';
            // 分割网址并过滤
            $urls_array = array();
            $duplicate_in_input = array();      // 输入中的重复项
            $duplicate_in_rules = array();      // 其他待处理规则中的重复项
            $duplicate_in_topics = array();     // 已生成主题的重复项
            
            if (!empty($url_text)) {
                $raw_urls = explode("\n", $url_text);
                $seen_urls = array(); // 用于检测输入内重复
                
                foreach ($raw_urls as $url) {
                    $url = trim($url);
                    if (!empty($url)) {
                        // 标准化 URL（移除末尾斜杠和空格，统一小写协议）
                        $normalized_url = rtrim($url, '/');
                        $normalized_url = preg_replace('/^https?:\/\//i', '', $normalized_url);
                        $normalized_key = strtolower($normalized_url);
                        
                        // 检查输入内是否重复
                        if (isset($seen_urls[$normalized_key])) {
                            $duplicate_in_input[] = $url;
                            continue;
                        }
                        $seen_urls[$normalized_key] = true;
                        $urls_array[] = $url;
                    }
                }
                
                // 检查历史重复（两个来源）
                if (!empty($urls_array)) {
                    global $wpdb;
                    $topics_table = $wpdb->prefix . 'content_auto_topics';
                    $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
                    $rules_table = $wpdb->prefix . 'content_auto_rules';
                    
                    // 排除当前正在编辑的规则
                    $exclude_rule_id = $is_edit_mode ? $rule_id : 0;
                    
                    // 来源1：从主题表查询已成功生成主题的 URL
                    $topic_urls = $wpdb->get_col(
                        "SELECT DISTINCT source_url 
                         FROM {$topics_table} 
                         WHERE source_url IS NOT NULL 
                         AND source_url != ''"
                    );
                    
                    // 来源2：从规则项目表查询其他规则中待处理的 URL
                    $rule_urls = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT ri.upload_text 
                         FROM {$rule_items_table} ri
                         INNER JOIN {$rules_table} r ON ri.rule_id = r.id
                         WHERE r.rule_type = 'collect_url_rewrite'
                         AND ri.rule_id != %d
                         AND ri.upload_text != ''
                         AND ri.upload_text LIKE 'http%%'",
                        $exclude_rule_id
                    ));
                    
                    // 标准化主题表 URL
                    $topic_normalized = array();
                    foreach ($topic_urls as $hist_url) {
                        $normalized = strtolower(rtrim(preg_replace('/^https?:\/\//i', '', trim($hist_url)), '/'));
                        $topic_normalized[$normalized] = $hist_url;
                    }
                    
                    // 标准化规则项目表 URL（排除已在主题表中的）
                    $rule_normalized = array();
                    foreach ($rule_urls as $hist_url) {
                        $normalized = strtolower(rtrim(preg_replace('/^https?:\/\//i', '', trim($hist_url)), '/'));
                        // 只记录不在主题表中的（避免重复计数）
                        if (!isset($topic_normalized[$normalized])) {
                            $rule_normalized[$normalized] = $hist_url;
                        }
                    }
                    
                    // 过滤重复 URL 并分类记录
                    $filtered_urls = array();
                    foreach ($urls_array as $url) {
                        $normalized = strtolower(rtrim(preg_replace('/^https?:\/\//i', '', trim($url)), '/'));
                        
                        if (isset($topic_normalized[$normalized])) {
                            // 已生成过主题
                            $duplicate_in_topics[] = $url;
                        } elseif (isset($rule_normalized[$normalized])) {
                            // 在其他待处理规则中
                            $duplicate_in_rules[] = $url;
                        } else {
                            $filtered_urls[] = $url;
                        }
                    }
                    $urls_array = $filtered_urls;
                }
            }
            
            // 限制最多500个网址
            $rule_conditions['collect_url_content'] = array_slice($urls_array, 0, 500);
            
            // 处理采集选项（保留图片、保留链接）
            $rule_conditions['collect_options'] = array(
                'keep_images' => isset($_POST['collect_keep_images']) && $_POST['collect_keep_images'] == '1',
                'keep_links' => isset($_POST['collect_keep_links']) && $_POST['collect_keep_links'] == '1'
            );
            
            // 记录去重信息（用于前端显示详细提示）
            $has_duplicates = !empty($duplicate_in_input) || !empty($duplicate_in_rules) || !empty($duplicate_in_topics);
            if ($has_duplicates) {
                set_transient('cam_url_dedup_info_' . get_current_user_id(), array(
                    // 数量统计
                    'count_in_input' => count($duplicate_in_input),
                    'count_in_rules' => count($duplicate_in_rules),
                    'count_in_topics' => count($duplicate_in_topics),
                    // 具体 URL 列表（用于详细展示）
                    'urls_in_input' => array_slice($duplicate_in_input, 0, 10),    // 最多显示10条
                    'urls_in_rules' => array_slice($duplicate_in_rules, 0, 10),
                    'urls_in_topics' => array_slice($duplicate_in_topics, 0, 10),
                    // 总计
                    'total_filtered' => count($duplicate_in_input) + count($duplicate_in_rules) + count($duplicate_in_topics)
                ), 60); // 60秒有效期
            }
            
            // 强制设置循环次数为1，因为此规则类型没有循环次数配置
            $item_count = 1;
        }

        // 处理所有规则类型通用的目标分类字段
        if (isset($_POST['target_category']) && $_POST['target_category'] !== '') {
            $rule_conditions['target_category'] = intval($_POST['target_category']);
        }

        // 准备数据数组
        $data = array(
            'rule_name' => $rule_name,
            'rule_type' => $rule_type,
            'rule_conditions' => serialize($rule_conditions),
            'item_count' => $item_count,
            'status' => $status,
            'reference_material' => $reference_material,
            'updated_at' => current_time('mysql'),
        );

        
        // 如果是新规则，添加创建时间和任务ID
        if (!$is_edit_mode) {
            $data['rule_task_id'] = $rule_task_id;
            $data['created_at'] = current_time('mysql');
        }

        // 2. 插入或更新主规则到数据库
        if ($is_edit_mode) {
            // 编辑模式：检查规则是否正在被使用
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/class-rule-manager.php';
            $rule_manager = new ContentAuto_RuleManager();

            if ($rule_manager->is_rule_in_use($rule_id)) {
                // 规则正在使用中，不允许修改
                wp_redirect(admin_url('admin.php?page=content-auto-manager-rules&message=6')); // 6表示规则正在使用中
                exit;
            }

            // 更新现有规则
            $result = $wpdb->update(
                $rules_table,
                $data,
                array('id' => $rule_id)
            );
        } else {
            // 插入新规则
            $result = $wpdb->insert(
                $rules_table,
                $data
            );

            $rule_id = $wpdb->insert_id;
        }

        // 添加调试信息
        if ($wpdb->last_error) {
        }

        if ($result === false) {
        }

        if (!$is_edit_mode && !$rule_id) {
            // 新规则插入失败，重定向并附带错误代码
            wp_redirect(admin_url('admin.php?page=content-auto-manager-rules&message=2'));
            exit;
        }

        if (!$is_edit_mode) {
            // 3. 为新规则生成并插入子规则任务
            $this->generate_rule_items($rule_id, $rule_task_id, $rule_type, $rule_conditions, $item_count);
        } else {
            // 3. 对于现有规则，先删除旧的子规则任务，然后重新生成
            $wpdb->delete($rule_items_table, array('rule_id' => $rule_id));

            // 获取规则的任务ID
            $rule = $wpdb->get_row($wpdb->prepare("SELECT rule_task_id FROM {$rules_table} WHERE id = %d", $rule_id));
            if ($rule) {
                $this->generate_rule_items($rule_id, $rule->rule_task_id, $rule_type, $rule_conditions, $item_count);
            }
        }

        // 4. 操作完成后重定向，防止表单重复提交
        $message_code = $is_edit_mode ? 3 : 1; // 3表示更新成功，1表示创建成功
        wp_redirect(admin_url('admin.php?page=content-auto-manager-rules&message=' . $message_code));
        exit;
    }

    /**
     * 生成子规则任务项
     */
    private function generate_rule_items($rule_id, $rule_task_id, $rule_type, $conditions, $count) {
        global $wpdb;
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';

        if ($rule_type === 'random_selection') {
            // 随机选择逻辑 - 完全随机抽取，允许重复
            $category_ids = $conditions['categories'] ?? array();
            if (!empty($category_ids)) {
                // 先获取所有符合条件的文章ID
                $args = array(
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => -1, // 获取所有文章
                    'category__in' => $category_ids,
                    'fields' => 'ids' // 只获取ID
                );
                $all_post_ids = get_posts($args);
                
                // 如果有文章，则进行随机抽取
                if (!empty($all_post_ids)) {
                    $total_posts = count($all_post_ids);
                    for ($i = 0; $i < $count; $i++) {
                        // 随机选择一篇文章ID
                        $random_index = array_rand($all_post_ids);
                        $post_id = $all_post_ids[$random_index];
                        $post = get_post($post_id);
                        if ($post) {
                            // 获取文章的分类和标签信息
                            $category_ids = '';
                            $category_names = '';
                            $category_descriptions = '';
                            $tag_names = '';
                            
                            // 获取分类信息
                            $categories = get_the_category($post->ID);
                            if (!empty($categories)) {
                                $category_ids_array = array();
                                $category_names_array = array();
                                $category_descriptions_array = array();
                                
                                foreach ($categories as $category) {
                                    $category_ids_array[] = $category->term_id;
                                    $category_names_array[] = $category->name;
                                    $category_descriptions_array[] = $category->description;
                                }
                                
                                $category_ids = implode(',', $category_ids_array);
                                $category_names = implode(',', $category_names_array);
                                $category_descriptions = implode(',', $category_descriptions_array);
                            }
                            
                            // 获取标签信息
                            $tags = get_the_tags($post->ID);
                            if (!empty($tags)) {
                                $tag_names_array = array();
                                foreach ($tags as $tag) {
                                    $tag_names_array[] = $tag->name;
                                }
                                $tag_names = implode(',', $tag_names_array);
                            }
                            
                            // 插入子规则
                            $wpdb->insert(
                                $rule_items_table,
                                array(
                                    'rule_id' => $rule_id,
                                    'rule_task_id' => $rule_task_id,
                                    'post_id' => $post->ID,
                                    'post_title' => $post->post_title,
                                    'category_ids' => $category_ids,
                                    'category_names' => $category_names,
                                    'category_descriptions' => $category_descriptions,
                                    'tag_names' => $tag_names,
                                    'upload_text' => '',
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql'),
                                )
                            );
                        }
                    }
                }
            }
        } elseif ($rule_type === 'fixed_articles') {
            // 固定选择逻辑
            $post_ids = $conditions['post_ids'] ?? array();
            if (!empty($post_ids)) {
                $total_selected = count($post_ids);
                for ($i = 0; $i < $count; $i++) {
                    $post_id = $post_ids[$i % $total_selected]; // 循环获取
                    $post = get_post($post_id);
                    if ($post) {
                        // 获取文章的分类和标签信息
                        $category_ids = '';
                        $category_names = '';
                        $category_descriptions = '';
                        $tag_names = '';
                        
                        // 获取分类信息
                        $categories = get_the_category($post->ID);
                        if (!empty($categories)) {
                            $category_ids_array = array();
                            $category_names_array = array();
                            $category_descriptions_array = array();
                            
                            foreach ($categories as $category) {
                                $category_ids_array[] = $category->term_id;
                                $category_names_array[] = $category->name;
                                $category_descriptions_array[] = $category->description;
                            }
                            
                            $category_ids = implode(',', $category_ids_array);
                            $category_names = implode(',', $category_names_array);
                            $category_descriptions = implode(',', $category_descriptions_array);
                        }
                        
                        // 获取标签信息
                        $tags = get_the_tags($post->ID);
                        if (!empty($tags)) {
                            $tag_names_array = array();
                            foreach ($tags as $tag) {
                                $tag_names_array[] = $tag->name;
                            }
                            $tag_names = implode(',', $tag_names_array);
                        }
                        
                        // 插入子规则
                        $wpdb->insert(
                            $rule_items_table,
                            array(
                                'rule_id' => $rule_id,
                                'rule_task_id' => $rule_task_id,
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'category_ids' => $category_ids,
                                'category_names' => $category_names,
                                'category_descriptions' => $category_descriptions,
                                'tag_names' => $tag_names,
                                'upload_text' => '',
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            )
                        );
                    }
                }
            }
        } elseif ($rule_type === 'upload_text') {
            // 上传文本内容逻辑
            $upload_text = $conditions['upload_text'] ?? '';
            // 检查文本内容是否存在（即使为空或只有空格也允许保存）
            if (isset($conditions['upload_text'])) {
                // 为每个循环次数创建一个条目
                for ($i = 0; $i < $count; $i++) {
                    // 插入子规则，不包含post_id和post_title
                    $wpdb->insert(
                        $rule_items_table,
                        array(
                            'rule_id' => $rule_id,
                            'rule_task_id' => $rule_task_id,
                            'post_id' => 0,
                            'post_title' => '',
                            'category_ids' => '',
                            'category_names' => '',
                            'category_descriptions' => '',
                            'tag_names' => '',
                            'upload_text' => $upload_text,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        )
                    );
                }
            }
        } elseif ($rule_type === 'import_keywords') {
            // 导入关键词逻辑 - 直接存储循环后的所有关键词
            $keywords = $conditions['keywords'] ?? array();
            if (!empty($keywords)) {
                $total_keywords = count($keywords);
                if ($total_keywords > 0) {
                    // 按照循环次数展开存储所有关键词
                    for ($i = 0; $i < $count; $i++) {
                        foreach ($keywords as $keyword) {
                            // 计算当前是第几轮循环
                            $cycle = $i + 1;

                            $wpdb->insert(
                                $rule_items_table,
                                array(
                                    'rule_id' => $rule_id,
                                    'rule_task_id' => $rule_task_id,
                                    'post_id' => 0,
                                    'post_title' => '', // 关键词规则不使用标题字段
                                    'category_ids' => '',
                                    'category_names' => '',
                                    'category_descriptions' => '',
                                    'tag_names' => '',
                                    'upload_text' => $keyword, // 关键词只存储在upload_text字段
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql'),
                                )
                            );
                        }
                    }
                }
            }
        } elseif ($rule_type === 'random_categories') {
            // 随机分类逻辑 - 根据循环次数完全随机抽取分类
            $category_ids = $conditions['categories'] ?? array();
            if (!empty($category_ids)) {
                // 获取所有选定分类的详细信息
                $categories_info = array();
                foreach ($category_ids as $cat_id) {
                    $category = get_category($cat_id);
                    if ($category) {
                        $categories_info[] = array(
                            'id' => $cat_id,
                            'name' => $category->name,
                            'description' => $category->description
                        );
                    }
                }

                // 如果有分类信息，则进行随机抽取
                if (!empty($categories_info)) {
                    $total_categories = count($categories_info);
                    for ($i = 0; $i < $count; $i++) {
                        // 随机选择一个分类
                        $random_index = array_rand($categories_info);
                        $selected_category = $categories_info[$random_index];

                        // 插入子规则 - 只保留必要的字段，设置post_id=0用于查询
                        $wpdb->insert(
                            $rule_items_table,
                            array(
                                'rule_id' => $rule_id,
                                'rule_task_id' => $rule_task_id,
                                'post_id' => 0,
                                'category_ids' => $selected_category['id'],
                                'category_names' => $selected_category['name'],
                                'category_descriptions' => $selected_category['description'],
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            )
                        );
                    }
                }
            }
        } elseif ($rule_type === 'collect_url_rewrite') {
            // 采集网址仿写逻辑
            $urls = $conditions['collect_url_content'] ?? array();
            if (!empty($urls)) {
                foreach ($urls as $url) {
                    $wpdb->insert(
                        $rule_items_table,
                        array(
                            'rule_id' => $rule_id,
                            'rule_task_id' => $rule_task_id,
                            'post_id' => 0,
                            'post_title' => '', 
                            'category_ids' => '',
                            'category_names' => '',
                            'category_descriptions' => '',
                            'tag_names' => '',
                            'upload_text' => $url, // 网址存储在upload_text字段
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        )
                    );
                }
            }
        }
    }
    
    /**
     * 处理删除规则的AJAX请求
     */
    public function handle_delete_rule() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败。'));
        }

        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足。'));
        }

        // 获取规则ID
        $rule_id = intval($_POST['rule_id']);

        if ($rule_id <= 0) {
            wp_send_json_error(array('message' => '无效的规则ID。'));
        }

        // 使用规则管理器进行删除（包含使用状态检查）
        $rule_manager = new ContentAuto_RuleManager();
        $result = $rule_manager->delete_rule($rule_id);

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * 处理获取文章标题的AJAX请求
     */
    public function handle_get_article_titles() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'content_auto_manager_nonce')) {
            wp_send_json_error(array('message' => '安全验证失败。'));
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '权限不足。'));
        }
        
        // 获取文章ID列表
        $article_ids_str = sanitize_text_field($_POST['article_ids']);
        $article_ids = array_map('intval', explode(',', $article_ids_str));
        
        if (empty($article_ids)) {
            wp_send_json_success(array('articles' => array()));
        }
        
        // 查询文章标题
        $articles = array();
        foreach ($article_ids as $id) {
            if ($id > 0) {
                $post = get_post($id);
                if ($post) {
                    $articles[] = array(
                        'id' => $post->ID,
                        'title' => $post->post_title
                    );
                }
            }
        }
        
        wp_send_json_success(array('articles' => $articles));
    }
}