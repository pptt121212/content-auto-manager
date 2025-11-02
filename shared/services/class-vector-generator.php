<?php
/**
 * 向量生成管理器
 * 负责主题向量的异步生成、压缩存储和速率控制
 */

if (!defined('ABSPATH')) {
    exit;
}

// 确保依赖类已加载
if (!class_exists('ContentAuto_VectorApiHandler')) {
    require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
}

class ContentAuto_VectorGenerator {
    
    private $database;
    private $vector_handler;
    private $logger;
    private $rate_limiter;
    private $category_manager;
    
    // API速率限制
    const VECTOR_RPM = 2000;  // 每分钟请求数
    const VECTOR_TPM = 500000; // 每分钟Token数
    const BATCH_SIZE = 10; // 每次批量处理的主题数量
    const MAX_RETRIES = 3; // 最大重试次数
    
    /**
     * 构造函数
     */
    public function __construct($logger = null) {
        $this->database = new ContentAuto_Database();
        $this->vector_handler = new ContentAuto_VectorApiHandler($logger);
        $this->logger = $logger;
        
        // 初始化速率限制器
        $this->rate_limiter = new ContentAuto_VectorRateLimiter(self::VECTOR_RPM, self::VECTOR_TPM);
        
        // 初始化分类向量管理器
        if (!class_exists('ContentAuto_CategoryVectorManager')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-category-vector-manager.php';
        }
        $this->category_manager = new ContentAuto_CategoryVectorManager($logger);
    }
    
    /**
     * 启动向量生成调度器，此方法现在直接处理批量生成
     */
    public function start_vector_generation_scheduler() {
        if ($this->has_active_topic_tasks()) {
            if ($this->logger) $this->logger->log('检测到活跃的主题任务，跳过本次向量生成。', 'INFO');
            return false;
        }

        if (!$this->rate_limiter->can_proceed()) {
            if ($this->logger) $this->logger->log('向量API速率受限，跳过本次向量生成。', 'INFO');
            return false;
        }
        
        $topics = $this->get_topics_needing_vectors(self::BATCH_SIZE);
        
        if (empty($topics)) {
            return false; // 没有需要处理的主题
        }

        // 组合多个字段生成向量文本
        $combined_texts = $this->prepare_combined_topic_texts($topics);
        
        // 提取纯文本数组用于API调用
        $text_array = array_column($combined_texts, 'combined_text');
        
        // 批量调用API生成向量
        $result = $this->vector_handler->generate_embeddings_batch($text_array);

        // 记录API使用情况
        if (!empty($result['tokens_used'])) {
            $this->rate_limiter->record_request($result['tokens_used']);
        }

        // 批量处理结果
        $this->process_batch_results($result, $topics);
        
        return true;
    }

  private function prepare_combined_topic_texts($topics) {
        $combined_texts = [];
        
        foreach ($topics as $topic) {
            $parts = [];
            
            // 添加标题
            if (!empty($topic['title'])) {
                $parts[] = $topic['title'];
            }
            
            // 添加内容角度
            if (!empty($topic['source_angle'])) {
                $parts[] = $topic['source_angle'];
            }
            
            // 添加用户价值
            if (!empty($topic['user_value'])) {
                $parts[] = $topic['user_value'];
            }
            
            // 添加SEO关键词
            if (!empty($topic['seo_keywords'])) {
                $parts[] = $topic['seo_keywords'];
            }
            
            // 添加匹配的分类
            if (!empty($topic['matched_category'])) {
                $parts[] = $topic['matched_category'];
            }
            
            // 组合所有文本
            $combined_text = implode(' ', $parts);
            
            $combined_texts[] = [
                'topic_id' => $topic['id'],
                'combined_text' => $combined_text
            ];
        }
        
        return $combined_texts;
    }

    /**
     * 批量处理API返回的结果，包含成功和失败的情况
     * @param array|false $api_result API返回的结果
     * @param array $topics 发起请求的原始主题数组
     */
    private function process_batch_results($api_result, $topics) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';

        // API调用如果完全失败，则将本次所有请求的主题标记为失败并增加重试次数
        if ($api_result === false || !isset($api_result['embeddings'])) {
            $error_message = $this->vector_handler->get_last_error() ?: 'API did not return valid embeddings.';
            if ($this->logger) $this->logger->log('批量向量生成API调用失败，将更新本次所有主题的状态。', 'ERROR', ['last_error' => $error_message]);

            foreach ($topics as $topic) {
                $wpdb->update(
                    $topics_table,
                    [
                        'vector_status' => 'failed',
                        'vector_error' => $error_message,
                        'vector_retry_count' => $topic['vector_retry_count'] + 1
                    ],
                    ['id' => $topic['id']]
                );
            }
            return;
        }

        // API调用成功，但可能包含部分失败的项
        $successful_embeddings = [];
        foreach ($api_result['embeddings'] as $embedding_data) {
            $successful_embeddings[$embedding_data['index']] = $embedding_data['embedding'];
        }

        foreach ($topics as $index => $topic) {
            $topic_id = $topic['id'];

            // 检查当前索引对应的向量是否成功返回
            if (isset($successful_embeddings[$index])) {
                $base64_vector = $successful_embeddings[$index];

                // 最终验证，确保数据是有效的Base64
                if (is_string($base64_vector) && !empty($base64_vector) && base64_decode($base64_vector, true) !== false) {
                    
                    // 准备更新数据
                    $update_data = [
                        'vector_embedding' => $base64_vector,
                        'vector_status' => 'completed',
                        'vector_error' => null,
                    ];
                    
                    // 为手动添加的主题自动匹配分类
                    $matched_category = $this->auto_match_category_for_manual_topic($topic_id, $base64_vector, $topic);
                    if ($matched_category !== null) {
                        $update_data['matched_category'] = $matched_category['name'];
                    }
                    
                    $wpdb->update($topics_table, $update_data, ['id' => $topic_id]);
                } else {
                    // 如果API返回了非Base64的无效数据
                    $wpdb->update(
                        $topics_table,
                        [
                            'vector_status' => 'failed',
                            'vector_error' => 'API returned invalid or non-base64 data.',
                            'vector_retry_count' => $topic['vector_retry_count'] + 1
                        ],
                        ['id' => $topic_id]
                    );
                }
            } else {
                // API的返回结果中没有包含这个索引，说明此项失败
                $wpdb->update(
                    $topics_table,
                    [
                        'vector_status' => 'failed',
                        'vector_error' => 'API response did not include an embedding for this topic.',
                        'vector_retry_count' => $topic['retry_count'] + 1
                    ],
                    ['id' => $topic_id]
                );
            }
        }
    }
    
    /**
     * 检查是否有正在运行的主题任务或子任务
     */
    public function has_active_topic_tasks() {
        global $wpdb;
        
        // 检查主题任务状态
        $topic_tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
        $active_topic_tasks = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$topic_tasks_table} 
            WHERE status IN (%s, %s, %s)
        ", 'pending', 'running', 'processing'));
        
        return ($active_topic_tasks > 0);
    }
    
    /**
     * 为手动添加的主题自动匹配分类
     */
    private function auto_match_category_for_manual_topic($topic_id, $topic_vector_base64, $topic_data) {
        // 首先检查是否为手动添加的主题且需要匹配分类
        if (!$this->should_auto_match_category($topic_id)) {
            return null;
        }
        
        // 从主题数据中提取标题
        $topic_title = '';
        if (is_array($topic_data)) {
            $topic_title = $topic_data['title'] ?? '';
        } elseif (is_string($topic_data)) {
            $topic_title = $topic_data;
        }
        
        try {
            $matched_category = $this->category_manager->find_best_matching_category($topic_vector_base64);
            
            if ($matched_category) {
                if ($this->logger) {
                    $this->logger->log('手动主题自动匹配分类成功: ' . $topic_title . ' -> ' . $matched_category['name'] . ' (相似度: ' . round($matched_category['similarity'], 4) . ')', 'INFO');
                }
                return $matched_category;
            } else {
                if ($this->logger) {
                    $this->logger->log('手动主题未找到合适的分类匹配: ' . $topic_title, 'INFO');
                }
            }
            
            return null;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->log('分类匹配过程出错: ' . $e->getMessage(), 'ERROR');
            }
            return null;
        }
    }
    
    /**
     * 判断是否应该为主题自动匹配分类
     * 只对手工添加的主题（rule_id = 0 且 matched_category 为空）进行分类匹配
     */
    private function should_auto_match_category($topic_id) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT rule_id, matched_category FROM {$topics_table} WHERE id = %d",
            $topic_id
        ), ARRAY_A);
        
        if (!$topic) {
            return false;
        }
        
        // 只对手工添加的主题（rule_id = 0）且当前没有分类的主题进行自动分类匹配
        return ($topic['rule_id'] == 0 && empty(trim($topic['matched_category'])));
    }
    
    /**
     * 手动刷新分类向量缓存的接口方法
     */
    public function refresh_category_cache() {
        return $this->category_manager->refresh_cache();
    }
    
    /**
     * 获取分类缓存状态
     */
    public function get_category_cache_status() {
        return $this->category_manager->get_cache_status();
    }
    
    /**
     * 获取需要生成向量的主题
     */
    public function get_topics_needing_vectors($limit = 10) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        $topics = $wpdb->get_results($wpdb->prepare("
            SELECT id, title, source_angle, user_value, seo_keywords, matched_category, vector_retry_count
            FROM {$topics_table} 
            WHERE 
                (vector_status = 'pending' OR vector_status IS NULL) OR 
                (vector_status = 'failed' AND vector_retry_count < %d)
            ORDER BY priority_score DESC, created_at ASC 
            LIMIT %d
        ", self::MAX_RETRIES, $limit), ARRAY_A);
        
        return $topics;
    }
    
    /**
     * 获取向量生成统计信息
     */
    public function get_vector_generation_stats() {
        global $wpdb;

        $topics_table = $wpdb->prefix . 'content_auto_topics';

        // 总主题数
        $total_topics = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table}");

        // 已生成向量的主题数
        $topics_with_vectors = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE vector_embedding IS NOT NULL AND vector_embedding != ''");

        // 待处理向量任务（没有向量且未使用的主题）
        $pending_vector_tasks = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE (vector_embedding IS NULL OR vector_embedding = '') AND status = 'unused'");

        // 处理中向量任务（正在生成向量的主题，这里假设状态为processing的主题为处理中）
        $processing_vector_tasks = $wpdb->get_var("SELECT COUNT(*) FROM {$topics_table} WHERE (vector_embedding IS NULL OR vector_embedding = '') AND status = 'processing'");

        return array(
            'total_topics' => intval($total_topics),
            'topics_with_vectors' => intval($topics_with_vectors),
            'vector_coverage' => $total_topics > 0 ? round(($topics_with_vectors / $total_topics) * 100, 2) : 0,
            'completion_rate' => $total_topics > 0 ? round(($topics_with_vectors / $total_topics) * 100, 2) : 0,
            'pending_vector_tasks' => intval($pending_vector_tasks),
            'processing_vector_tasks' => intval($processing_vector_tasks)
        );
    }
}

/**
 * 向量API速率限制器
 */
class ContentAuto_VectorRateLimiter {
    
    private $rpm_limit;
    private $tpm_limit;
    private $current_rpm = 0;
    private $current_tpm = 0;
    private $reset_time;
    
    public function __construct($rpm_limit = 2000, $tpm_limit = 500000) {
        $this->rpm_limit = $rpm_limit;
        $this->tpm_limit = $tpm_limit;
        $this->reset_counters();
    }
    
    /**
     * 检查是否可以继续请求
     */
    public function can_proceed() {
        $this->check_reset_time();
        
        return ($this->current_rpm < $this->rpm_limit) && ($this->current_tpm < $this->tpm_limit);
    }
    
    /**
     * 记录API请求
     */
    public function record_request($tokens_used = 0) {
        $this->check_reset_time();
        
        $this->current_rpm++;
        $this->current_tpm += $tokens_used;
    }
    
    /**
     * 检查是否需要重置计数器
     */
    private function check_reset_time() {
        $current_time = time();
        
        if ($current_time >= $this->reset_time) {
            $this->reset_counters();
        }
    }
    
    /**
     * 重置计数器
     */
    private function reset_counters() {
        $this->current_rpm = 0;
        $this->current_tpm = 0;
        $this->reset_time = time() + 60; // 1分钟后重置
    }
    
    /**
     * 获取当前状态
     */
    public function get_status() {
        $this->check_reset_time();
        
        return array(
            'rpm_used' => $this->current_rpm,
            'rpm_limit' => $this->rpm_limit,
            'rpm_remaining' => max(0, $this->rpm_limit - $this->current_rpm),
            'tpm_used' => $this->current_tpm,
            'tpm_limit' => $this->tpm_limit,
            'tpm_remaining' => max(0, $this->tpm_limit - $this->current_tpm),
            'reset_in_seconds' => max(0, $this->reset_time - time())
        );
    }
}

/**
 * 便捷函数：启动向量生成调度器
 */
function content_auto_start_vector_generation() {
    static $generator = null;
    
    if ($generator === null) {
        $generator = new ContentAuto_VectorGenerator();
    }
    
    return $generator->start_vector_generation_scheduler();
}

/**
 * 便捷函数：获取向量生成统计
 */
function content_auto_get_vector_stats() {
    static $generator = null;
    
    if ($generator === null) {
        $generator = new ContentAuto_VectorGenerator();
    }
    
    return $generator->get_vector_generation_stats();
}

/**
 * 便捷函数：刷新分类向量缓存
 */
function content_auto_refresh_category_cache() {
    static $generator = null;
    
    if ($generator === null) {
        $generator = new ContentAuto_VectorGenerator();
    }
    
    return $generator->refresh_category_cache();
}

/**
 * 便捷函数：获取分类缓存状态
 */
function content_auto_get_category_cache_status() {
    static $generator = null;
    
    if ($generator === null) {
        $generator = new ContentAuto_VectorGenerator();
    }
    
    return $generator->get_category_cache_status();
}
?>