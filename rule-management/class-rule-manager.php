<?php
/**
 * 规则管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_RuleManager {
    
    private $database;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
    }
    
    /**
     * 创建规则
     */
    public function create_rule($data) {
        // 验证数据
        $validated_data = $this->validate_rule_data($data);
        if (!$validated_data) {
            return false;
        }
        
        // 插入数据
        return $this->database->insert('content_auto_rules', $validated_data);
    }
    
    /**
     * 更新规则
     */
    public function update_rule($id, $data) {
        // 检查规则是否正在被使用
        if ($this->is_rule_in_use($id)) {
            $usage_details = $this->get_rule_usage_details($id);
            return array(
                'success' => false,
                'message' => sprintf(
                    '规则正在被 %d 个主题任务和 %d 个文章任务使用，无法修改。请等待所有相关任务完成后再试。',
                    $usage_details['topic_tasks'],
                    $usage_details['article_tasks']
                ),
                'usage_details' => $usage_details
            );
        }

        // 验证数据
        $validated_data = $this->validate_rule_data($data);
        if (!$validated_data) {
            return array(
                'success' => false,
                'message' => '规则数据验证失败。'
            );
        }

        // 更新数据
        $result = $this->database->update('content_auto_rules', $validated_data, array('id' => $id));

        if ($result === false) {
            return array(
                'success' => false,
                'message' => '规则更新失败。'
            );
        }

        return array(
            'success' => true,
            'message' => '规则更新成功。'
        );
    }
    
    /**
     * 删除规则
     */
    public function delete_rule($id) {
        // 检查规则是否正在被使用
        if ($this->is_rule_in_use($id)) {
            $usage_details = $this->get_rule_usage_details($id);
            return array(
                'success' => false,
                'message' => sprintf(
                    '规则正在被 %d 个主题任务和 %d 个文章任务使用，无法删除。请等待所有相关任务完成后再试。',
                    $usage_details['topic_tasks'],
                    $usage_details['article_tasks']
                ),
                'usage_details' => $usage_details
            );
        }

        // 删除规则项目
        $rule_items_deleted = $this->database->delete('content_auto_rule_items', array('rule_id' => $id));

        // 删除主规则
        $result = $this->database->delete('content_auto_rules', array('id' => $id));

        if ($result === false) {
            return array(
                'success' => false,
                'message' => '规则删除失败。'
            );
        }

        return array(
            'success' => true,
            'message' => '规则已成功删除。'
        );
    }
    
    /**
     * 获取单个规则
     */
    public function get_rule($id) {
        return $this->database->get_row('content_auto_rules', array('id' => $id));
    }
    
    /**
     * 获取所有规则
     */
    public function get_rules() {
        return $this->database->get_results('content_auto_rules');
    }
    
    /**
     * 获取启用的规则
     */
    public function get_active_rules() {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        return $wpdb->get_results("SELECT * FROM {$rules_table} WHERE status = 1 ORDER BY created_at DESC");
    }
    
    /**
     * 验证规则数据
     */
    private function validate_rule_data($data) {
        $validated_data = array();

        // 验证必需字段
        if (empty($data['rule_name'])) {
            return false;
        }
        $validated_data['rule_name'] = sanitize_text_field($data['rule_name']);

        if (empty($data['rule_conditions'])) {
            return false;
        }
        $validated_data['rule_conditions'] = maybe_serialize($data['rule_conditions']);

        if (isset($data['rule_type'])) {
            $validated_data['rule_type'] = sanitize_text_field($data['rule_type']);
        }

        if (isset($data['item_count'])) {
            $validated_data['item_count'] = intval($data['item_count']);
        }

        if (isset($data['status'])) {
            $validated_data['status'] = intval($data['status']);
        }

        // 处理参考资料字段
        if (isset($data['reference_material'])) {
            $validated_data['reference_material'] = sanitize_textarea_field($data['reference_material']);
            // 确保不超过800字符限制
            if (mb_strlen($validated_data['reference_material']) > 800) {
                $validated_data['reference_material'] = mb_substr($validated_data['reference_material'], 0, 800);
            }
        } else {
            $validated_data['reference_material'] = '';
        }

        return $validated_data;
    }
    
    /**
     * 根据规则获取内容
     */
    public function get_content_by_rule($rule_id, $limit = 10) {
        global $wpdb;
        
        // 获取规则
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d", $rule_id));
        
        if (!$rule) {
            return false;
        }
        
        $content = array();
        
        // 根据规则类型获取内容
        if ($rule->rule_type === 'random_selection' || $rule->rule_type === 'fixed_articles') {
            // 从规则项目表中获取文章内容
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND post_id > 0 LIMIT %d", $rule_id, $limit));
            
            foreach ($items as $item) {
                $content[] = array(
                    'id' => $item->post_id,
                    'title' => $item->post_title,
                    'content' => '', // 在这个上下文中不需要完整内容
                    'excerpt' => '', // 在这个上下文中不需要摘要
                    'date' => '' // 在这个上下文中不需要日期
                );
            }
        } elseif ($rule->rule_type === 'upload_text') {
            // 从规则项目表中获取上传文本内容
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND upload_text != '' LIMIT %d", $rule_id, $limit));

            foreach ($items as $item) {
                $content[] = array(
                    'id' => 0, // 上传文本没有post_id
                    'title' => '', // 上传文本没有标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'excerpt' => '', // 在这个上下文中不需要摘要
                    'date' => '', // 在这个上下文中不需要日期
                    'upload_text' => $item->upload_text
                );
            }
        } elseif ($rule->rule_type === 'import_keywords') {
            // 从规则项目表中获取关键词内容
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND upload_text != '' LIMIT %d", $rule_id, $limit));

            foreach ($items as $item) {
                $content[] = array(
                    'id' => 0, // 关键词没有post_id
                    'title' => '', // 关键词没有标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'excerpt' => '', // 在这个上下文中不需要摘要
                    'date' => '', // 在这个上下文中不需要日期
                    'keyword' => $item->upload_text // 关键词内容从upload_text字段获取
                );
            }
        } elseif ($rule->rule_type === 'random_categories') {
            // 从规则项目表中获取随机分类内容
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $items = $wpdb->get_results($wpdb->prepare("SELECT category_ids, category_names, category_descriptions FROM {$rule_items_table} WHERE rule_id = %d AND post_id = 0 LIMIT %d", $rule_id, $limit));

            foreach ($items as $item) {
                $content[] = array(
                    'id' => 0, // 随机分类没有post_id
                    'title' => $item->category_names, // 分类名称作为标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'excerpt' => '', // 在这个上下文中不需要摘要
                    'date' => '', // 在这个上下文中不需要日期
                    'category_name' => $item->category_names, // 分类名称
                    'category_description' => $item->category_descriptions // 分类描述
                );
            }
        }
        
        return $content;
    }
    
    /**
     * 将HTML内容转换为纯文本
     */
    private function convert_to_plain_text($html_content) {
        // 移除WordPress特定的注释标签
        $content = preg_replace('/<!--\s*wp:[^>]+\s*-->/i', '', $html_content);
        $content = preg_replace('/<!--\s*\/wp:[^>]+\s*-->/i', '', $content);
        
        // 解码HTML实体
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        
        // 移除HTML标签
        $content = strip_tags($content);
        
        // 清理多余的空白字符
        $content = preg_replace('/\s+/', ' ', $content);
        
        // 去除首尾空白
        $content = trim($content);
        
        return $content;
    }
    
    public function get_content_by_rule_for_subtask($rule_id, $subtask_index) {
        global $wpdb;
        
        // 获取规则
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d", $rule_id));
        
        if (!$rule) {
            return false;
        }
        
        $content = array();
        
        // 根据规则类型获取内容
        if ($rule->rule_type === 'random_selection' || $rule->rule_type === 'fixed_articles') {
            // 从规则项目表中获取特定子任务的文章内容 - 按ID排序确保顺序一致
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND post_id > 0 ORDER BY id LIMIT %d, 1", $rule_id, $subtask_index));
            
            if ($item) {
                // 获取文章的完整内容
                $post = get_post($item->post_id);
                $post_content = '';
                if ($post) {
                    // 截取前6000字
                    $post_content = mb_substr($post->post_content, 0, 6000);
                    // 过滤为纯文本
                    $post_content = $this->convert_to_plain_text($post_content);
                }
                
                $content[] = array(
                    'id' => $item->post_id,
                    'title' => $item->post_title,
                    'content' => $post_content,
                    'category_ids' => $item->category_ids,
                    'category_names' => $item->category_names,
                    'category_descriptions' => $item->category_descriptions,
                    'tag_names' => $item->tag_names,
                    'excerpt' => '', // 在这个上下文中不需要摘要
                    'date' => '' // 在这个上下文中不需要日期
                );
            }
        } elseif ($rule->rule_type === 'upload_text') {
            // 从规则项目表中获取特定子任务的上传文本内容 - 按ID排序确保顺序一致
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND upload_text != '' ORDER BY id LIMIT %d, 1", $rule_id, $subtask_index));

            if ($item) {
                $content[] = array(
                    'id' => 0, // 上传文本没有post_id
                    'title' => '', // 上传文本没有标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'category_ids' => '',
                    'category_names' => '',
                    'category_descriptions' => '',
                    'tag_names' => '',
                    'upload_text' => $item->upload_text
                );
            }
        } elseif ($rule->rule_type === 'import_keywords') {
            // 从规则项目表中获取特定子任务的关键词内容 - 按ID排序确保顺序一致
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE rule_id = %d AND upload_text != '' ORDER BY id LIMIT %d, 1", $rule_id, $subtask_index));

            if ($item) {
                $content[] = array(
                    'id' => 0, // 关键词没有post_id
                    'title' => '', // 关键词规则没有标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'category_ids' => '',
                    'category_names' => '',
                    'category_descriptions' => '',
                    'tag_names' => '',
                    'keyword' => $item->upload_text // 关键词内容从upload_text字段获取
                );
            }
        } elseif ($rule->rule_type === 'random_categories') {
            // 从规则项目表中获取特定子任务的随机分类内容 - 按ID排序确保顺序一致
            $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
            $item = $wpdb->get_row($wpdb->prepare("SELECT category_ids, category_names, category_descriptions FROM {$rule_items_table} WHERE rule_id = %d AND post_id = 0 ORDER BY id LIMIT %d, 1", $rule_id, $subtask_index));

            if ($item) {
                $content[] = array(
                    'id' => 0, // 随机分类没有post_id
                    'title' => $item->category_names, // 分类名称作为标题
                    'content' => '', // 在这个上下文中不需要完整内容
                    'category_ids' => $item->category_ids,
                    'category_names' => $item->category_names,
                    'category_descriptions' => $item->category_descriptions,
                    'category_name' => $item->category_names, // 分类名称
                    'category_description' => $item->category_descriptions // 分类描述
                );
            }
        }
        
        return $content;
    }

    /**
     * 检查规则是否正在被使用
     * 注意：文章任务只使用已有主题，不受规则变更影响，所以只检查主题任务
     */
    public function is_rule_in_use($rule_id) {
        global $wpdb;

        // 只检查主题任务队列中是否有该规则的子任务
        // 文章任务使用已生成的主题，不受规则变更影响
        $topic_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $topic_tasks_in_use = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$topic_queue_table} tq
            JOIN {$wpdb->prefix}content_auto_topic_tasks tt ON tq.job_id = tt.id
            WHERE tt.rule_id = %d
            AND tq.status IN ('pending', 'processing', 'running')",
            $rule_id
        ));

        return ($topic_tasks_in_use > 0);
    }

    /**
     * 获取规则使用状态详情
     * 注意：文章任务只使用已有主题，不受规则变更影响，所以只检查主题任务
     */
    public function get_rule_usage_details($rule_id) {
        global $wpdb;

        $details = array(
            'in_use' => false,
            'topic_tasks' => 0,
            'task_details' => array()
        );

        // 只检查主题任务
        $topic_queue_table = $wpdb->prefix . 'content_auto_job_queue';
        $topic_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.topic_task_id, tt.status as task_status, COUNT(tq.id) as active_subtasks
            FROM {$wpdb->prefix}content_auto_topic_tasks tt
            JOIN {$topic_queue_table} tq ON tt.id = tq.job_id
            WHERE tt.rule_id = %d
            AND tq.status IN ('pending', 'processing', 'running')
            GROUP BY tt.id",
            $rule_id
        ));

        $details['topic_tasks'] = count($topic_tasks);
        $details['task_details'] = array_map(
            function($t) {
                return array(
                    'type' => '主题任务',
                    'id' => $t->topic_task_id,
                    'status' => $t->task_status,
                    'active_subtasks' => $t->active_subtasks
                );
            },
            $topic_tasks
        );

        $details['in_use'] = ($details['topic_tasks'] > 0);

        return $details;
    }

/**
     * 根据规则项目ID获取内容
     * 通过reference_id直接查询，避免使用索引的潜在问题
     */
    public function get_content_by_rule_item_id($rule_item_id) {
        global $wpdb;
        
        // 直接通过规则项目ID查询，确保绝对的准确性
        $rule_items_table = $wpdb->prefix . 'content_auto_rule_items';
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rule_items_table} WHERE id = %d", $rule_item_id));
        
        if (!$item) {
            return false;
        }
        
        $content = array();
        
        // 根据规则项目类型获取内容
        if ($item->post_id > 0) {
            // 文章类型
            $post = get_post($item->post_id);
            $post_content = '';
            if ($post) {
                // 截取前6000字
                $post_content = mb_substr($post->post_content, 0, 6000);
                // 过滤为纯文本
                $post_content = $this->convert_to_plain_text($post_content);
            }
            
            $content[] = array(
                'id' => $item->post_id,
                'title' => $item->post_title,
                'content' => $post_content,
                'category_ids' => $item->category_ids,
                'category_names' => $item->category_names,
                'category_descriptions' => $item->category_descriptions,
                'tag_names' => $item->tag_names,
                'excerpt' => '',
                'date' => ''
            );
        } elseif (!empty($item->upload_text)) {
            // 检查规则类型来确定是上传文本还是关键词
            $rules_table = $wpdb->prefix . 'content_auto_rules';
            $rule = $wpdb->get_row($wpdb->prepare("SELECT rule_type FROM {$rules_table} WHERE id = %d", $item->rule_id));
            
            if ($rule && $rule->rule_type === 'import_keywords') {
                // 关键词类型
                $content[] = array(
                    'id' => 0,
                    'title' => '',
                    'content' => '',
                    'category_ids' => '',
                    'category_names' => '',
                    'category_descriptions' => '',
                    'tag_names' => '',
                    'keyword' => $item->upload_text
                );
            } else {
                // 上传文本类型
                $content[] = array(
                    'id' => 0,
                    'title' => '',
                    'content' => '',
                    'category_ids' => '',
                    'category_names' => '',
                    'category_descriptions' => '',
                    'tag_names' => '',
                    'upload_text' => $item->upload_text
                );
            }
        } elseif ($item->post_id == 0 && !empty($item->category_names)) {
            // 随机分类类型
            $content[] = array(
                'id' => 0,
                'title' => $item->category_names,
                'content' => '',
                'category_ids' => $item->category_ids,
                'category_names' => $item->category_names,
                'category_descriptions' => $item->category_descriptions,
                'category_name' => $item->category_names,
                'category_description' => $item->category_descriptions
            );
        }
        
        return $content;
    }
}
?>