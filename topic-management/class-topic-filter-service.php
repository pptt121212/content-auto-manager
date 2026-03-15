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
    /**
     * 检测重复标题
     * 返回两种类型的重复：完全相同的标题和向量相似度高的标题
     * 
     * @param array|string $filters 主题筛选条件 (兼容旧版传入 string status)
     * @param float $threshold 相似度阈值
     * @return array 重复标题分组
     */
    public function detect_duplicate_topics($filters = array(), $threshold = 0.90) {
        global $wpdb;
        
        // 兼容旧版参数：如果传入的是字符串，则视为 status
        if (is_string($filters)) {
            $filters = array('status' => $filters);
        }
        
        if (!is_array($filters)) {
            $filters = array();
        }
        
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
        
        // 构建筛选条件 WHERE 子句
        $where_clauses = array();
        $where_values = array();
        
        // 1. 状态筛选 (默认 unused)
        if (!empty($filters['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $filters['status'];
        }
        
        // 2. 标题关键字搜索
        if (!empty($filters['title_keyword'])) {
            $where_clauses[] = 'title LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($filters['title_keyword']) . '%';
        }
        
        // 3. 推荐分类筛选
        if (!empty($filters['matched_category'])) {
            if ($filters['matched_category'] === '__empty__') {
                $where_clauses[] = "(matched_category IS NULL OR matched_category = '')";
            } else {
                $where_clauses[] = 'matched_category = %s';
                $where_values[] = $filters['matched_category'];
            }
        }
        
        // 4. 优先级筛选
        if (!empty($filters['priority_score'])) {
            $where_clauses[] = 'priority_score = %d';
            $where_values[] = intval($filters['priority_score']);
        }
        
        // 5. 生成向量状态筛选
        if (isset($filters['has_vector']) && $filters['has_vector'] !== '') {
            if ($filters['has_vector'] === '1') {
                $where_clauses[] = "(vector_embedding IS NOT NULL AND vector_embedding != '')";
            } else {
                $where_clauses[] = "(vector_embedding IS NULL OR vector_embedding = '')";
            }
        }
        
        // 6. 参考资料筛选
        if (isset($filters['has_reference']) && $filters['has_reference'] !== '') {
            if ($filters['has_reference'] === '1') {
                $where_clauses[] = "(reference_material IS NOT NULL AND reference_material != '')";
            } else {
                $where_clauses[] = "(reference_material IS NULL OR reference_material = '')";
            }
        }
        
        // 7. 任务ID筛选
        if (!empty($filters['task_id'])) {
            $where_clauses[] = 'task_id = %s';
            $where_values[] = $filters['task_id'];
        }
        
        // 组合 WHERE 子句
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // 1. 检测完全相同的标题
        // 查找重复标题（移除 LIMIT 限制，检测所有完全重复的标题组）
        $exact_query = "
            SELECT title, GROUP_CONCAT(id ORDER BY created_at ASC) as topic_ids, COUNT(*) as count
            FROM {$topics_table}
            {$where_sql}
            GROUP BY title
            HAVING count > 1
            ORDER BY count DESC
        ";
        
        if (!empty($where_values)) {
            $exact_query = $wpdb->prepare($exact_query, $where_values);
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
        
        // 特殊处理：如果 vector_query 的过滤条件和 exact_query 一样，
        // 还需要额外确保 vector_embedding IS NOT NULL
        // 我们重新构建一个专门针对向量的 where_sql
        
        $vector_where_clauses = $where_clauses;
        $vector_where_values = $where_values;
        
        // 强制必须有向量
        $vector_where_clauses[] = "(vector_embedding IS NOT NULL AND vector_embedding != '')";
        
        $vector_where_sql = 'WHERE ' . implode(' AND ', $vector_where_clauses);
        
        $vector_query = "
            SELECT id, title, vector_embedding
            FROM {$topics_table}
            {$vector_where_sql}
            ORDER BY created_at ASC
        ";
        
        if (!empty($vector_where_values)) {
            $vector_query = $wpdb->prepare($vector_query, $vector_where_values);
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
     * 优化版本：预解码向量 + 预计算模长 + 纯点积比较
     * 
     * @param array $topics 包含向量的主题列表
     * @return array 相似主题组
     */
    private function find_similar_vectors($topics, $threshold = 0.90) {
        // 延长执行时间
        @set_time_limit(300);
        
        $groups = array();
        
        // 1. 预处理：解码向量并计算模长
        $vectors_data = array();
        
        foreach ($topics as $topic) {
            $vector = $this->decode_vector($topic['vector_embedding']);
            if (empty($vector)) {
                continue;
            }
            
            // 计算模长
            $magnitude = 0.0;
            foreach ($vector as $val) {
                $magnitude += $val * $val;
            }
            $magnitude = sqrt($magnitude);
            
            if ($magnitude == 0) continue;
            
            $vectors_data[] = array(
                'id' => $topic['id'],
                'title' => $topic['title'], // 用于文本相似度辅助校验
                'vector' => $vector,
                'magnitude' => $magnitude
            );
        }
        
        $count = count($vectors_data);
        if ($count < 2) {
            return $groups;
        }
        
        // 2. 初始化并查集 (Union-Find)
        // parent 数组: parent[i] = k 表示第i个元素的父节点是第k个元素
        $parent = range(0, $count - 1);
        
        // 辅助函数：查找根节点（带路径压缩）
        $find = function($i) use (&$parent) {
            $path = array();
            // 查找根
            while ($parent[$i] !== $i) {
                $path[] = $i;
                $i = $parent[$i];
            }
            $root = $i;
            // 路径压缩：将路径上所有节点直接指向根
            foreach ($path as $node) {
                $parent[$node] = $root;
            }
            return $root;
        };
        
        // 辅助函数：合并两个集合
        $union = function($i, $j) use (&$parent, $find) {
            $rootI = $find($i);
            $rootJ = $find($j);
            if ($rootI !== $rootJ) {
                // 简单的合并规则：小的作为父节点
                if ($rootI < $rootJ) {
                    $parent[$rootJ] = $rootI;
                } else {
                    $parent[$rootI] = $rootJ;
                }
                return true;
            }
            return false;
        };
        
        // 用于记录每组的最高相似度
        $group_max_sim = array(); // root_index => max_sim
        
        // 3. 全量两两比较
        for ($i = 0; $i < $count; $i++) {
            $item1 = $vectors_data[$i];
            
            // 缓存向量1
            $vec1 = $item1['vector'];
            $mag1 = $item1['magnitude'];
            $dim = count($vec1);
            
            for ($j = $i + 1; $j < $count; $j++) {
                $item2 = $vectors_data[$j];
                
                // 快速点积计算
                $vec2 = $item2['vector'];
                if (count($vec2) !== $dim) continue;
                
                // 采样优化
                if ($dim > 1000) {
                    $sample_dot = 0.0;
                    $sample_mag1 = 0.0;
                    $sample_mag2 = 0.0;
                    // 步进采样
                    for ($k = 0; $k < $dim; $k += 20) {
                         $v1 = $vec1[$k];
                         $v2 = $vec2[$k];
                         $sample_dot += $v1 * $v2;
                         $sample_mag1 += $v1 * $v1;
                         $sample_mag2 += $v2 * $v2;
                    }
                    if ($sample_mag1 > 0 && $sample_mag2 > 0) {
                        $sample_sim = $sample_dot / (sqrt($sample_mag1) * sqrt($sample_mag2));
                        if ($sample_sim < ($threshold - 0.3)) {
                            continue;
                        }
                    }
                }
                
                // 全量点积
                $dot_product = 0.0;
                for ($k = 0; $k < $dim; $k++) {
                    $dot_product += $vec1[$k] * $vec2[$k];
                }
                
                $vector_similarity = $dot_product / ($mag1 * $item2['magnitude']);
                $final_similarity = $vector_similarity;
                
                // 混合相似度检测 (Hybrid Similarity)
                // 如果向量相似度略低于阈值（例如在阈值下方 0.15 范围内，且至少 > 0.7）
                // 且字面看起来非常相似，则尝试使用文本相似度进行"打捞"
                if ($vector_similarity < $threshold && $vector_similarity > max(0.7, $threshold - 0.15)) {
                    $text_percent = 0;
                    similar_text($item1['title'], $item2['title'], $text_percent);
                    $text_similarity = $text_percent / 100;
                    
                    // 取两者中的最大值作为最终判定
                    $final_similarity = max($vector_similarity, $text_similarity);
                }
                
                if ($final_similarity >= $threshold) {
                    // 发现相似，合并集合
                    $union($i, $j);
                    
                    // 更新该集合的最高相似度
                    $root = $find($i); // 获取合并后的新Root
                    if (!isset($group_max_sim[$root])) {
                        $group_max_sim[$root] = 0;
                    }
                    $group_max_sim[$root] = max($group_max_sim[$root], $final_similarity);
                }
            }
        }
        
        // 4. 整理结果：按根节点分组
        $clusters = array();
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            // 如果自己就是根，且没有被合并过(size=1)，则忽略
            // 但我们需要知道该组有多少人。
            // 先简单收集
            if (!isset($clusters[$root])) {
                $clusters[$root] = array();
            }
            $clusters[$root][] = $vectors_data[$i]['id'];
        }
        
        // 5. 格式化输出
        foreach ($clusters as $root => $ids) {
            // 只有包含多于1个元素的才算是重复组
            if (count($ids) > 1) {
                // 获取这组记录的最大相似度
                // 注意：由于路径压缩，$group_max_sim 中的 key 可能会过时
                // 简单的做法取默认值或之前的记录。
                // 重构逻辑：其实我们在 union 时更新的是当时的 root。
                // 最终合并时，可能会有多个 max_sim 需要取最大值。
                // 简单起见，这里可以回填一个合理的相似度，或者直接取记录的。
                // 为确保准确，我们遍历所有成员的两两相似度太慢了。
                // 我们直接使用 group_max_sim 中记录的值（虽然可能不完全精确覆盖所有合并边，但足够具有代表性）
                // 更好的策略：遍历 $group_max_sim，如果其 key 最终指向当前的 $root，则纳入比较。
                
                $max_sim = 0;
                foreach ($group_max_sim as $r => $sim) {
                    if ($find($r) == $root) {
                         $max_sim = max($max_sim, $sim);
                    }
                }
                if ($max_sim == 0) $max_sim = $threshold; // 兜底
                
                $groups[] = array(
                    'topic_ids' => $ids,
                    'max_similarity' => $max_sim
                );
            }
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
                'message' => __('没有要删除的主题', 'yali-ai-writer'),
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
            'message' => sprintf(__('成功删除 %d 个主题', 'yali-ai-writer'), $deleted_count),
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
                'message' => __('没有发现重复的主题需要删除', 'yali-ai-writer'),
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
                'message' => __('没有符合条件的主题需要删除', 'yali-ai-writer'),
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
            'message' => sprintf(__('成功删除 %d 个符合条件的主题', 'yali-ai-writer'), $deleted_count),
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
