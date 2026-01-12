<?php
/**
 * 主题高级筛选服务
 * 提供标题搜索、分类筛选、优先级筛选、向量状态筛选、参考资料筛选、重复标题检测等功能
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_TopicFilterService {
    
    /**
     * 向量相似度阈值，用于判断重复标题
     */
    const VECTOR_SIMILARITY_THRESHOLD = 0.90;
    
    /**
     * 每次处理的批量大小
     */
    const BATCH_SIZE = 100;
    
    /**
     * 根据筛选条件获取主题
     * 
     * @param array $filters 筛选条件
     * @param int $page 页码
     * @param int $per_page 每页数量
     * @return array 包含主题列表和分页信息
     */
    public function get_filtered_topics($filters, $page = 1, $per_page = 20) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $where_clauses = array();
        $where_values = array();
        
        // 基础状态筛选
        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $filters['status'];
        }
        
        // 标题关键字搜索
        if (!empty($filters['title_keyword'])) {
            $where_clauses[] = 'title LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($filters['title_keyword']) . '%';
        }
        
        // 推荐分类筛选
        if (!empty($filters['matched_category'])) {
            if ($filters['matched_category'] === '__empty__') {
                $where_clauses[] = "(matched_category IS NULL OR matched_category = '')";
            } else {
                $where_clauses[] = 'matched_category = %s';
                $where_values[] = $filters['matched_category'];
            }
        }
        
        // 优先级筛选
        if (!empty($filters['priority_score'])) {
            $where_clauses[] = 'priority_score = %d';
            $where_values[] = intval($filters['priority_score']);
        }
        
        // 生成向量状态筛选
        if (isset($filters['has_vector']) && $filters['has_vector'] !== '') {
            if ($filters['has_vector'] === '1') {
                $where_clauses[] = "(vector_embedding IS NOT NULL AND vector_embedding != '')";
            } else {
                $where_clauses[] = "(vector_embedding IS NULL OR vector_embedding = '')";
            }
        }
        
        // 参考资料筛选
        if (isset($filters['has_reference']) && $filters['has_reference'] !== '') {
            if ($filters['has_reference'] === '1') {
                $where_clauses[] = "(reference_material IS NOT NULL AND reference_material != '')";
            } else {
                $where_clauses[] = "(reference_material IS NULL OR reference_material = '')";
            }
        }
        
        // 任务ID筛选
        if (!empty($filters['task_id'])) {
            $where_clauses[] = 'task_id = %s';
            $where_values[] = $filters['task_id'];
        }
        
        // 构建WHERE子句
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // 获取总记录数
        $count_query = "SELECT COUNT(*) FROM {$topics_table} {$where_sql}";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_items = $wpdb->get_var($count_query);
        
        // 计算分页
        $offset = ($page - 1) * $per_page;
        $total_pages = ceil($total_items / $per_page);
        
        // 获取数据
        $query = "SELECT * FROM {$topics_table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, array($per_page, $offset));
        $topics = $wpdb->get_results($wpdb->prepare($query, $query_values), ARRAY_A);
        
        return array(
            'topics' => $topics,
            'total_items' => intval($total_items),
            'total_pages' => intval($total_pages),
            'current_page' => $page,
            'per_page' => $per_page
        );
    }
    
    /**
     * 检测重复标题
     * 返回两种类型的重复：完全相同的标题和向量相似度高的标题
     * 
     * @param string $status 主题状态筛选
     * @return array 重复标题分组
     */
    public function detect_duplicate_topics($status = 'unused', $threshold = 0.90) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $duplicates = array(
            'exact_duplicates' => array(),      // 完全相同的标题
            'similar_duplicates' => array(),    // 向量相似的标题
            'summary' => array(
                'exact_duplicate_groups' => 0,
                'exact_duplicate_topics' => 0,
                'similar_duplicate_groups' => 0,
                'similar_duplicate_topics' => 0
            )
        );
        
        // 1. 检测完全相同的标题
        $status_clause = '';
        $status_values = array();
        if (!empty($status)) {
            $status_clause = 'WHERE status = %s';
            $status_values[] = $status;
        }
        
        // 查找重复标题（移除 LIMIT 限制，检测所有完全重复的标题组）
        $exact_query = "
            SELECT title, GROUP_CONCAT(id ORDER BY created_at ASC) as topic_ids, COUNT(*) as count
            FROM {$topics_table}
            {$status_clause}
            GROUP BY title
            HAVING count > 1
            ORDER BY count DESC
        ";
        
        if (!empty($status_values)) {
            $exact_query = $wpdb->prepare($exact_query, $status_values);
        }
        
        $exact_results = $wpdb->get_results($exact_query, ARRAY_A);
        
        foreach ($exact_results as $row) {
            $topic_ids = explode(',', $row['topic_ids']);
            $topics = $this->get_topics_by_ids($topic_ids);
            
            $duplicates['exact_duplicates'][] = array(
                'title' => $row['title'],
                'count' => intval($row['count']),
                'topics' => $topics,
                'keep_id' => $topic_ids[0],  // 保留最早创建的
                'delete_ids' => array_slice($topic_ids, 1)  // 删除其余的
            );
            
            $duplicates['summary']['exact_duplicate_groups']++;
            $duplicates['summary']['exact_duplicate_topics'] += count($topic_ids) - 1;
        }
        
        // 2. 检测向量相似的标题（仅对有向量的主题）
        // 移除 LIMIT 限制，处理所有有向量的主题以确保全量检测
        $vector_query = "
            SELECT id, title, vector_embedding
            FROM {$topics_table}
            WHERE vector_embedding IS NOT NULL AND vector_embedding != ''
            {$status_clause}
            ORDER BY created_at ASC
        ";
        
        if (!empty($status) && !empty($status_clause)) {
            // 需要调整WHERE子句
            $vector_query = "
                SELECT id, title, vector_embedding
                FROM {$topics_table}
                WHERE vector_embedding IS NOT NULL AND vector_embedding != ''
                AND status = %s
                ORDER BY created_at ASC
            ";
            $vector_query = $wpdb->prepare($vector_query, $status);
        }
        
        $vector_topics = $wpdb->get_results($vector_query, ARRAY_A);
        
        if (count($vector_topics) > 1) {
            $similar_groups = $this->find_similar_vectors($vector_topics, $threshold);
            
            foreach ($similar_groups as $group) {
                if (count($group['topic_ids']) > 1) {
                    $topics = $this->get_topics_by_ids($group['topic_ids']);
                    
                    $duplicates['similar_duplicates'][] = array(
                        'similarity' => $group['max_similarity'],
                        'count' => count($group['topic_ids']),
                        'topics' => $topics,
                        'keep_id' => $group['topic_ids'][0],
                        'delete_ids' => array_slice($group['topic_ids'], 1)
                    );
                    
                    $duplicates['summary']['similar_duplicate_groups']++;
                    $duplicates['summary']['similar_duplicate_topics'] += count($group['topic_ids']) - 1;
                }
            }
        }
        
        return $duplicates;
    }
    
    /**
     * 查找向量相似的主题组
     * 
     * @param array $topics 包含向量的主题列表
     * @return array 相似主题组
     */
    private function find_similar_vectors($topics, $threshold = 0.90) {
        $groups = array();
        $processed = array();
        
        foreach ($topics as $i => $topic1) {
            if (in_array($topic1['id'], $processed)) {
                continue;
            }
            
            $vector1 = $this->decode_vector($topic1['vector_embedding']);
            if (empty($vector1)) {
                continue;
            }
            
            $group = array(
                'topic_ids' => array($topic1['id']),
                'max_similarity' => 0
            );
            
            for ($j = $i + 1; $j < count($topics); $j++) {
                $topic2 = $topics[$j];
                
                if (in_array($topic2['id'], $processed)) {
                    continue;
                }
                
                $vector2 = $this->decode_vector($topic2['vector_embedding']);
                if (empty($vector2)) {
                    continue;
                }
                
                $similarity = $this->calculate_cosine_similarity($vector1, $vector2);
                
                if ($similarity >= $threshold) {
                    $group['topic_ids'][] = $topic2['id'];
                    $group['max_similarity'] = max($group['max_similarity'], $similarity);
                    $processed[] = $topic2['id'];
                }
            }
            
            if (count($group['topic_ids']) > 1) {
                $groups[] = $group;
            }
            
            $processed[] = $topic1['id'];
        }
        
        return $groups;
    }
    
    /**
     * 解码Base64向量
     */
    private function decode_vector($base64_vector) {
        if (empty($base64_vector)) {
            return null;
        }
        
        // 尝试使用全局函数
        if (function_exists('content_auto_decompress_vector_from_base64')) {
            return content_auto_decompress_vector_from_base64($base64_vector);
        }
        
        // 降级方案：直接base64解码
        $decoded = base64_decode($base64_vector, true);
        if ($decoded === false) {
            return null;
        }
        
        // 尝试解析为float数组
        $floats = unpack('f*', $decoded);
        return $floats ? array_values($floats) : null;
    }
    
    /**
     * 计算余弦相似度
     */
    private function calculate_cosine_similarity($vector1, $vector2) {
        if (empty($vector1) || empty($vector2) || count($vector1) !== count($vector2)) {
            return 0;
        }
        
        // 使用全局函数
        if (function_exists('content_auto_calculate_cosine_similarity')) {
            return content_auto_calculate_cosine_similarity($vector1, $vector2);
        }
        
        // 降级计算
        $dot_product = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        for ($i = 0; $i < count($vector1); $i++) {
            $dot_product += $vector1[$i] * $vector2[$i];
            $norm1 += $vector1[$i] * $vector1[$i];
            $norm2 += $vector2[$i] * $vector2[$i];
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0;
        }
        
        return $dot_product / ($norm1 * $norm2);
    }
    
    /**
     * 根据ID列表获取主题详情
     */
    private function get_topics_by_ids($ids) {
        global $wpdb;
        
        if (empty($ids)) {
            return array();
        }
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $query = $wpdb->prepare(
            "SELECT id, title, status, priority_score, matched_category, created_at, 
                    vector_embedding IS NOT NULL AND vector_embedding != '' as has_vector,
                    reference_material IS NOT NULL AND reference_material != '' as has_reference
             FROM {$topics_table} 
             WHERE id IN ({$placeholders})
             ORDER BY created_at ASC",
            $ids
        );
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * 批量删除主题
     * 
     * @param array $topic_ids 要删除的主题ID列表
     * @return array 删除结果
     */
    public function bulk_delete_topics($topic_ids) {
        global $wpdb;
        
        if (empty($topic_ids)) {
            return array(
                'success' => false,
                'message' => '没有要删除的主题',
                'deleted_count' => 0
            );
        }
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 只删除未使用的主题
        $placeholders = implode(',', array_fill(0, count($topic_ids), '%d'));
        
        $query = $wpdb->prepare(
            "DELETE FROM {$topics_table} WHERE id IN ({$placeholders}) AND status = %s",
            array_merge($topic_ids, array(CONTENT_AUTO_TOPIC_UNUSED))
        );
        
        $deleted_count = $wpdb->query($query);
        
        return array(
            'success' => true,
            'message' => sprintf('成功删除 %d 个主题', $deleted_count),
            'deleted_count' => $deleted_count,
            'requested_count' => count($topic_ids)
        );
    }
    
    /**
     * 删除重复主题（保留每组中最早创建的一个）
     * 
     * @param string $duplicate_type 重复类型: 'exact' | 'similar' | 'all'
     * @param string $status 主题状态筛选
     * @return array 删除结果
     */
    public function delete_duplicate_topics($duplicate_type = 'all', $status = 'unused') {
        $duplicates = $this->detect_duplicate_topics($status);
        $delete_ids = array();
        
        // 收集要删除的ID
        if ($duplicate_type === 'exact' || $duplicate_type === 'all') {
            foreach ($duplicates['exact_duplicates'] as $group) {
                $delete_ids = array_merge($delete_ids, $group['delete_ids']);
            }
        }
        
        if ($duplicate_type === 'similar' || $duplicate_type === 'all') {
            foreach ($duplicates['similar_duplicates'] as $group) {
                $delete_ids = array_merge($delete_ids, $group['delete_ids']);
            }
        }
        
        $delete_ids = array_unique($delete_ids);
        
        if (empty($delete_ids)) {
            return array(
                'success' => true,
                'message' => '没有发现重复的主题需要删除',
                'deleted_count' => 0
            );
        }
        
        return $this->bulk_delete_topics($delete_ids);
    }
    
    /**
     * 删除所有符合筛选条件的主题
     * 
     * @param array $filters 筛选条件
     * @return array 删除结果
     */
    public function delete_all_filtered_topics($filters) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $where_clauses = array();
        $where_values = array();
        
        // 强制只删除未使用状态的主题
        $where_clauses[] = 'status = %s';
        $where_values[] = 'unused';
        
        // 标题关键字搜索
        if (!empty($filters['title_keyword'])) {
            $where_clauses[] = 'title LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($filters['title_keyword']) . '%';
        }
        
        // 推荐分类筛选
        if (!empty($filters['matched_category'])) {
            if ($filters['matched_category'] === '__empty__') {
                $where_clauses[] = "(matched_category IS NULL OR matched_category = '')";
            } else {
                $where_clauses[] = 'matched_category = %s';
                $where_values[] = $filters['matched_category'];
            }
        }
        
        // 优先级筛选
        if (!empty($filters['priority_score'])) {
            $where_clauses[] = 'priority_score = %d';
            $where_values[] = intval($filters['priority_score']);
        }
        
        // 生成向量状态筛选
        if (isset($filters['has_vector']) && $filters['has_vector'] !== '') {
            if ($filters['has_vector'] === '1') {
                $where_clauses[] = "(vector_embedding IS NOT NULL AND vector_embedding != '')";
            } else {
                $where_clauses[] = "(vector_embedding IS NULL OR vector_embedding = '')";
            }
        }
        
        // 参考资料筛选
        if (isset($filters['has_reference']) && $filters['has_reference'] !== '') {
            if ($filters['has_reference'] === '1') {
                $where_clauses[] = "(reference_material IS NOT NULL AND reference_material != '')";
            } else {
                $where_clauses[] = "(reference_material IS NULL OR reference_material = '')";
            }
        }
        
        // 任务ID筛选
        if (!empty($filters['task_id'])) {
            $where_clauses[] = 'task_id = %s';
            $where_values[] = $filters['task_id'];
        }
        
        // 构建WHERE子句
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        
        // 先获取要删除的数量
        $count_query = "SELECT COUNT(*) FROM {$topics_table} {$where_sql}";
        if (!empty($where_values)) {
            $count_query = $wpdb->prepare($count_query, $where_values);
        }
        $total_to_delete = $wpdb->get_var($count_query);
        
        if ($total_to_delete == 0) {
            return array(
                'success' => true,
                'message' => '没有符合条件的主题需要删除',
                'deleted_count' => 0
            );
        }
        
        // 执行删除
        $delete_query = "DELETE FROM {$topics_table} {$where_sql}";
        if (!empty($where_values)) {
            $delete_query = $wpdb->prepare($delete_query, $where_values);
        }
        $deleted_count = $wpdb->query($delete_query);
        
        return array(
            'success' => true,
            'message' => sprintf('成功删除 %d 个符合条件的主题', $deleted_count),
            'deleted_count' => $deleted_count,
            'total_matched' => $total_to_delete
        );
    }
    
    /**
     * 获取所有可用的分类列表（用于筛选下拉框）
     */
    public function get_available_categories() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        $categories = $wpdb->get_col("
            SELECT DISTINCT matched_category 
            FROM {$topics_table} 
            WHERE matched_category IS NOT NULL AND matched_category != ''
            ORDER BY matched_category ASC
        ");
        
        return $categories;
    }
    
    /**
     * 获取统计信息
     */
    public function get_filter_stats() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        return array(
            'total' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table}")),
            'unused' => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$topics_table} WHERE status = %s", 'unused'))),
            'with_vector' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''")),
            'without_vector' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NULL OR vector_embedding = ''")),
            'with_reference' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE reference_material IS NOT NULL AND reference_material != ''")),
            'without_reference' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE reference_material IS NULL OR reference_material = ''"))
        );
    }
}
