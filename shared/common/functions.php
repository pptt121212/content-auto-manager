<?php
/**
 * 公共函数文件
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取插件URL
 */
function content_auto_manager_plugin_url() {
    return plugin_dir_url(dirname(__FILE__));
}

/**
 * 获取插件目录路径
 */
function content_auto_manager_plugin_path() {
    return plugin_dir_path(dirname(__FILE__));
}

/**
 * 安全输出HTML
 */
function content_auto_manager_esc_html($text) {
    return esc_html($text);
}

/**
 * 安全输出URL
 */
function content_auto_manager_esc_url($url) {
    return esc_url($url);
}

/**
 * 生成随机字符串
 */
function content_auto_manager_generate_random_string($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    
    return $randomString;
}

/**
 * 格式化时间
 */
function content_auto_manager_format_time($timestamp) {
    return date('Y-m-d H:i:s', strtotime($timestamp));
}

/**
 * 获取状态标签
 */
function content_auto_manager_get_status_label($status) {
    $labels = array(
        CONTENT_AUTO_STATUS_PENDING => __('待处理', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_RUNNING => __('进行中', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_COMPLETED => __('已完成', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_FAILED => __('失败', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_PAUSED => __('已暂停', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_CANCELLED => __('已取消', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_RETRY => __('重试中', 'content-auto-manager'),
        CONTENT_AUTO_STATUS_PROCESSING => __('处理中', 'content-auto-manager')
    );
    
    return isset($labels[$status]) ? $labels[$status] : $status;
}

/**
 * 获取状态标签类
 */
function content_auto_manager_get_status_class($status) {
    $classes = array(
        CONTENT_AUTO_STATUS_PENDING => 'pending',
        CONTENT_AUTO_STATUS_RUNNING => 'running',
        CONTENT_AUTO_STATUS_COMPLETED => 'completed',
        CONTENT_AUTO_STATUS_FAILED => 'failed',
        CONTENT_AUTO_STATUS_PAUSED => 'paused',
        CONTENT_AUTO_STATUS_CANCELLED => 'cancelled',
        CONTENT_AUTO_STATUS_RETRY => 'retry',
        CONTENT_AUTO_STATUS_PROCESSING => 'processing'
    );
    
    return isset($classes[$status]) ? $classes[$status] : 'default';
}

/**
 * 显示管理通知
 */
function content_auto_manager_admin_notice($message, $type = 'info') {
    ?>
    <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
        <p><?php echo esc_html($message); ?></p>
    </div>
    <?php
}

/**
 * 验证API密钥格式
 */
function content_auto_manager_validate_api_key($api_key) {
    // 基本格式验证
    if (empty($api_key) || strlen($api_key) < 10) {
        return false;
    }
    
    // 检查是否包含特殊字符
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $api_key)) {
        return false;
    }
    
    return true;
}

/**
 * 截取字符串
 */
function content_auto_manager_truncate_string($string, $length = 50, $suffix = '...') {
    // 使用mb_strlen和mb_substr来正确处理UTF-8字符
    if (mb_strlen($string, 'UTF-8') <= $length) {
        return $string;
    }

    return mb_substr($string, 0, $length, 'UTF-8') . $suffix;
}

/**
 * 统一字符数统计
 * 使用 mb_strlen() 方法，英文和汉字都算一个字符
 */
function content_auto_manager_word_count($content) {
    // 移除HTML标签
    $content = strip_tags($content);

    // 移除多余的空白字符
    $content = preg_replace('/\s+/', '', $content);
    $content = trim($content);

    if (empty($content)) {
        return 0;
    }

    // 使用 mb_strlen() 统计字符数，支持UTF-8中文
    return mb_strlen($content, 'UTF-8');
}

/**
 * 获取主题状态标签
 */
function content_auto_manager_get_topic_status_label($status) {
    $labels = array(
        CONTENT_AUTO_TOPIC_UNUSED => __('未使用', 'content-auto-manager'),
        CONTENT_AUTO_TOPIC_QUEUED => __('队列中', 'content-auto-manager'),
        CONTENT_AUTO_TOPIC_USED => __('已使用', 'content-auto-manager'),
        CONTENT_AUTO_TOPIC_EXPIRED => __('已过期', 'content-auto-manager')
    );
    
    return isset($labels[$status]) ? $labels[$status] : $status;
}

/**
 * 获取文章状态标签
 */
function content_auto_manager_get_article_status_label($status) {
    $labels = array(
        CONTENT_AUTO_ARTICLE_PENDING => __('待处理', 'content-auto-manager'),
        CONTENT_AUTO_ARTICLE_SUCCESS => __('成功', 'content-auto-manager'),
        CONTENT_AUTO_ARTICLE_FAILED => __('失败', 'content-auto-manager'),
        CONTENT_AUTO_ARTICLE_DUPLICATE => __('重复', 'content-auto-manager'),
        CONTENT_AUTO_ARTICLE_INVALID => __('无效', 'content-auto-manager')
    );
    
    return isset($labels[$status]) ? $labels[$status] : $status;
}

/**
 * 获取任务类型标签
 */
function content_auto_manager_get_job_type_label($type) {
    $labels = array(
        CONTENT_AUTO_JOB_TYPE_TOPIC => __('主题任务', 'content-auto-manager'),
        CONTENT_AUTO_JOB_TYPE_ARTICLE => __('文章任务', 'content-auto-manager'),
        CONTENT_AUTO_JOB_TYPE_BATCH => __('批量任务', 'content-auto-manager'),
        CONTENT_AUTO_JOB_TYPE_SCHEDULED => __('定时任务', 'content-auto-manager')
    );
    
    return isset($labels[$type]) ? $labels[$type] : $type;
}

/**
 * 获取规则类型标签
 */
function content_auto_manager_get_rule_type_label($type) {
    $labels = array(
        CONTENT_AUTO_RULE_TYPE_CATEGORY => __('分类规则', 'content-auto-manager'),
        CONTENT_AUTO_RULE_TYPE_KEYWORD => __('关键词规则', 'content-auto-manager'),
        CONTENT_AUTO_RULE_TYPE_TEMPLATE => __('模板规则', 'content-auto-manager'),
        CONTENT_AUTO_RULE_TYPE_SCHEDULE => __('定时规则', 'content-auto-manager'),
        CONTENT_AUTO_RULE_TYPE_MIXED => __('混合规则', 'content-auto-manager')
    );
    
    return isset($labels[$type]) ? $labels[$type] : $type;
}

/**
 * 获取API类型标签
 */
function content_auto_manager_get_api_type_label($type) {
    $labels = array(
        CONTENT_AUTO_API_TYPE_OPENAI => __('OpenAI', 'content-auto-manager'),
        CONTENT_AUTO_API_TYPE_CUSTOM => __('自定义', 'content-auto-manager'),
        CONTENT_AUTO_API_TYPE_PREDEFINED => __('预置', 'content-auto-manager'),
        CONTENT_AUTO_API_TYPE_CLAUDE => __('Claude', 'content-auto-manager'),
        CONTENT_AUTO_API_TYPE_GEMINI => __('Gemini', 'content-auto-manager')
    );
    
    return isset($labels[$type]) ? $labels[$type] : $type;
}

/**
 * 获取发布状态标签
 */
function content_auto_manager_get_publish_status_label($status) {
    $labels = array(
        CONTENT_AUTO_PUBLISH_STATUS_DRAFT => __('草稿', 'content-auto-manager'),
        CONTENT_AUTO_PUBLISH_STATUS_PUBLISH => __('发布', 'content-auto-manager'),
        CONTENT_AUTO_PUBLISH_STATUS_SCHEDULE => __('定时发布', 'content-auto-manager'),
        CONTENT_AUTO_PUBLISH_STATUS_PENDING_REVIEW => __('待审核', 'content-auto-manager')
    );
    
    return isset($labels[$status]) ? $labels[$status] : $status;
}
/**
 * 更新数据库表结构
 * 
 * @return array 更新结果
 */
function content_auto_manager_update_database_structure() {
    $database = new ContentAuto_Database();
    $errors = array();
    $updates_applied = array();
    
    try {
        // 更新任务队列表结构
        $job_queue_result = $database->update_job_queue_table_structure();
        if ($job_queue_result) {
            $updates_applied[] = "任务队列表reference_id字段";
        }

        // 更新任务队列表，添加计划任务时间字段
        $job_queue_scheduling_result = $database->update_job_queue_table_for_scheduling();
        if ($job_queue_scheduling_result) {
            $updates_applied[] = "任务队列表scheduled_at字段";
        }
        
        // 更新文章任务表结构
        $article_tasks_result = $database->update_article_tasks_table_structure();
        if ($article_tasks_result) {
            $updates_applied[] = "文章任务表字段";
        }
        
        // 更新主题表结构，添加vector_embedding字段
        $topics_result = $database->update_topics_table_structure();
        if ($topics_result) {
            $updates_applied[] = "主题表vector_embedding字段";
        }
        
        // 更新API配置表结构，添加向量API相关字段
        $api_configs_result = $database->update_api_configs_table_structure();
        if ($api_configs_result) {
            $updates_applied[] = "API配置表向量API字段";
        }
        
        $success = empty($errors);
        $message = "";
        
        if ($success) {
            if (!empty($updates_applied)) {
                $message = __("数据库表结构已成功更新。已应用更新：", "content-auto-manager") . " " . implode(", ", $updates_applied);
            } else {
                $message = __("数据库表结构已是最新版本，无需更新。", "content-auto-manager");
            }
        } else {
            $message = __("数据库更新过程中出现错误：", "content-auto-manager") . " " . implode("; ", $errors);
        }
        
        return array(
            "success" => $success,
            "message" => $message,
            "errors" => $errors,
            "updates_applied" => $updates_applied
        );
        
    } catch (Exception $e) {
        return array(
            "success" => false,
            "message" => __("数据库更新失败：", "content-auto-manager") . $e->getMessage(),
            "errors" => array($e->getMessage()),
            "updates_applied" => $updates_applied
        );
    }
}

/**
 * 将从数据库获取的Base64向量解码为浮点数数组
 *
 * @param string $base64_vector Base64编码的向量字符串
 * @return array|false 解码后的浮点数数组，或在失败时返回false
 */
function content_auto_decompress_vector_from_base64($base64_vector) {
    $binary_data = base64_decode($base64_vector, true);
    if ($binary_data === false) {
        return false;
    }
    $float_array = unpack('f*', $binary_data);
    return is_array($float_array) ? array_values($float_array) : false;
}

/**
 * 计算两个向量之间的余弦相似度
 *
 * @param array $vec1 向量1 (浮点数数组)
 * @param array $vec2 向量2 (浮点数数组)
 * @return float 余弦相似度分数 (-1.0 to 1.0)
 */
function content_auto_calculate_cosine_similarity(array $vec1, array $vec2) {
    $dot_product = 0.0;
    $magnitude1 = 0.0;
    $magnitude2 = 0.0;
    $dimensions = count($vec1);

    if ($dimensions !== count($vec2) || $dimensions === 0) {
        return 0.0; // Or handle error
    }

    for ($i = 0; $i < $dimensions; $i++) {
        $dot_product += $vec1[$i] * $vec2[$i];
        $magnitude1 += $vec1[$i] * $vec1[$i];
        $magnitude2 += $vec2[$i] * $vec2[$i];
    }

    $magnitude1 = sqrt($magnitude1);
    $magnitude2 = sqrt($magnitude2);

    if ($magnitude1 == 0.0 || $magnitude2 == 0.0) {
        return 0.0;
    }

    return $dot_product / ($magnitude1 * $magnitude2);
}

/**
 * 查找相似标题（优化版本 - 只考虑已发布的WordPress文章）
 * 
 * @param int $topic_id 查询主题ID
 * @param int $num_results 返回结果数量
 * @param int $clusters_to_search 搜索的候选簇数量
 * @return array 相似主题的列表，每个元素包含 id, title, similarity
 */
function content_auto_find_similar_titles($topic_id, $num_results = 5, $clusters_to_search = 3) {
    global $wpdb;
    $topics_table = $wpdb->prefix . 'content_auto_topics';
    $articles_table = $wpdb->prefix . 'content_auto_articles';
    $posts_table = $wpdb->posts;

    // 1. 获取查询向量
    $query_topic = $wpdb->get_row($wpdb->prepare("SELECT vector_embedding FROM {$topics_table} WHERE id = %d", $topic_id));
    if (!$query_topic || empty($query_topic->vector_embedding)) {
        return []; // 没有可供查询的向量
    }
    $query_vector = content_auto_decompress_vector_from_base64($query_topic->vector_embedding);
    if (!$query_vector) {
        return [];
    }

    // 2. 获取聚类中心点
    $centroids = get_option('content_auto_vector_centroids');
    if (empty($centroids) || !is_array($centroids)) {
        // 尚未执行聚类，无法使用此方法
        return [];
    }

    // 3. 找到最近的N个候选簇
    $distances = [];
    foreach ($centroids as $cluster_id => $centroid_vector) {
        // 使用欧氏距离的平方来比较，避免开方运算，速度更快
        $sum = 0;
        for ($i = 0; $i < count($query_vector); $i++) {
            $diff = $query_vector[$i] - $centroid_vector[$i];
            $sum += $diff * $diff;
        }
        $distances[$cluster_id] = $sum;
    }
    asort($distances); // 按距离从小到大排序
    $candidate_cluster_ids = array_keys(array_slice($distances, 0, $clusters_to_search, true));

    // 4. 从候选簇中获取所有候选向量（只选择已发布的WordPress文章主题）
    $placeholders = implode(',', array_fill(0, count($candidate_cluster_ids), '%d'));
    $candidates = $wpdb->get_results($wpdb->prepare(
        "SELECT t.id, t.title, t.vector_embedding, a.post_id 
         FROM {$topics_table} t 
         INNER JOIN {$articles_table} a ON t.id = a.topic_id 
         INNER JOIN {$posts_table} p ON a.post_id = p.ID
         WHERE t.vector_cluster_id IN ({$placeholders}) 
         AND t.id != %d 
         AND a.status = 'success' 
         AND a.post_id IS NOT NULL 
         AND a.post_id > 0
         AND p.post_status = 'publish'",  // 只选择已发布的WordPress文章
        array_merge($candidate_cluster_ids, [$topic_id])
    ));

    if (empty($candidates)) {
        return [];
    }

    // 5. 精确计算余弦相似度，添加0.8阈值限制
    $results = [];
    foreach ($candidates as $candidate) {
        $candidate_vector = content_auto_decompress_vector_from_base64($candidate->vector_embedding);
        if ($candidate_vector) {
            $similarity = content_auto_calculate_cosine_similarity($query_vector, $candidate_vector);
            // 添加0.8相似度阈值限制，只保留高度相关的文章
            if ($similarity > 0.8) {
                $results[] = [
                    'id' => $candidate->id,
                    'post_id' => $candidate->post_id,
                    'title' => $candidate->title,
                    'similarity' => $similarity,
                ];
            }
        }
    }

    // 6. 按相似度排序并返回Top N结果
    usort($results, function($a, $b) {
        return $b['similarity'] <=> $a['similarity'];
    });

    return array_slice($results, 0, $num_results);
}