<?php
/**
 * 智能文章结构优化系统 - 受欢迎度计算器
 * 
 * 负责计算文章结构的受欢迎度指数，基于使用该结构的文章的外部访问量
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_PopularityCalculator {
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * 日志记录器
     */
    private $logger;
    
    /**
     * 缓存键前缀
     */
    private $cache_prefix = 'cam_popularity_';
    
    /**
     * 缓存过期时间（秒）- 1小时
     */
    private $cache_expiration = 3600;
    
    /**
     * 外部访问统计的 meta_key
     */
    private $visit_meta_key = '_external_visit_count';
    
    /**
     * 构造函数
     * 
     * @param Yali_AI_Writer_PluginLogger|null $logger 日志记录器
     */
    public function __construct($logger = null) {
        require_once dirname(__FILE__) . '/class-optimization-config.php';
        $this->config = new Yali_AI_Writer_OptimizationConfig();
        $this->logger = $logger;
    }
    
    /**
     * 计算结构的受欢迎度指数
     * 
     * 计算公式：
     * 1. 获取使用该结构的所有文章的外部访问量
     * 2. 计算这些文章访问量的中位数
     * 3. 获取同 content_angle 下所有文章的访问量中位数
     * 4. 归一化：(结构中位数 / 角度中位数) * 100
     * 5. 应用时间衰减因子
     * 6. 应用置信度折扣（文章数<3时）
     * 
     * @param int $structure_id 结构ID
     * @return float 受欢迎度指数（归一化后，100为基准）
     */
    public function calculate_popularity_index($structure_id) {
        global $wpdb;
        
        // 尝试从缓存获取
        $cache_key = $this->cache_prefix . 'index_' . $structure_id;
        $cached_value = get_transient($cache_key);
        if ($cached_value !== false) {
            return (float) $cached_value;
        }
        
        // 获取结构信息
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        $structure = $wpdb->get_row($wpdb->prepare(
            "SELECT id, content_angle FROM {$structures_table} WHERE id = %d",
            $structure_id
        ), ARRAY_A);
        
        if (!$structure) {
            return 0.0; // 结构不存在
        }
        
        $content_angle = $structure['content_angle'];
        
        // 获取使用该结构的文章访问数据
        $structure_articles = $this->get_articles_by_structure($structure_id);
        
        if (empty($structure_articles)) {
            // 没有关联文章的新结构，返回基准值100%
            // 这样新结构有公平的机会被选中，随着使用会根据实际表现调整
            set_transient($cache_key, 100.0, $this->cache_expiration);
            return 100.0;
        }
        
        // 计算结构文章的加权中位数访问量
        $structure_median = $this->calculate_weighted_median_visits($structure_articles);
        
        // 获取同 content_angle 下所有文章的中位数访问量
        $angle_median = $this->get_angle_median_visits($content_angle);
        
        // 避免除以零
        if ($angle_median <= 0) {
            $angle_median = 1;
        }
        
        // 归一化计算
        $raw_index = ($structure_median / $angle_median) * 100;
        
        // 应用置信度折扣
        $article_count = count($structure_articles);
        $confidence_min = $this->config->get_int('confidence_min_articles', 3);
        
        if ($article_count < $confidence_min) {
            $confidence_factor = $article_count / $confidence_min;
            $raw_index = $raw_index * $confidence_factor;
        }
        
        // 确保指数不为负
        $final_index = max(0, $raw_index);
        
        // 缓存结果
        set_transient($cache_key, $final_index, $this->cache_expiration);
        
        return $final_index;
    }
    
    /**
     * 获取使用指定结构的文章列表
     * 
     * @param int $structure_id 结构ID
     * @return array 文章数据数组 [['post_id' => int, 'visits' => int, 'days_ago' => int], ...]
     */
    private function get_articles_by_structure($structure_id) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        
        // 查询使用该结构的主题关联的已发布文章
        $query = $wpdb->prepare("
            SELECT 
                a.post_id,
                p.post_date
            FROM {$topics_table} t
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            WHERE t.used_structure_id = %d
            AND a.post_id > 0
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
        ", $structure_id);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            return array();
        }
        
        $articles = array();
        $now = current_time('timestamp');
        
        foreach ($results as $row) {
            $post_id = (int) $row['post_id'];
            $post_date = strtotime($row['post_date']);
            $days_ago = floor(($now - $post_date) / DAY_IN_SECONDS);
            
            // 获取外部访问量
            $visits = (int) get_post_meta($post_id, $this->visit_meta_key, true);
            
            $articles[] = array(
                'post_id' => $post_id,
                'visits' => $visits,
                'days_ago' => $days_ago
            );
        }
        
        return $articles;
    }
    
    /**
     * 计算加权中位数访问量（应用时间衰减）
     * 
     * @param array $articles 文章数据数组
     * @return float 加权中位数
     */
    private function calculate_weighted_median_visits($articles) {
        if (empty($articles)) {
            return 0;
        }
        
        // 应用时间衰减因子到访问量
        $weighted_visits = array();
        foreach ($articles as $article) {
            $decay_factor = $this->get_time_decay_factor($article['days_ago']);
            $weighted_visits[] = $article['visits'] * $decay_factor;
        }
        
        return $this->calculate_median($weighted_visits);
    }
    
    /**
     * 获取时间衰减因子
     * 
     * @param int $days_ago 距今天数
     * @return float 衰减因子
     */
    private function get_time_decay_factor($days_ago) {
        return $this->config->get_time_decay_factor($days_ago);
    }
    
    /**
     * 计算数组的中位数
     * 
     * @param array $values 数值数组
     * @return float 中位数
     */
    public function calculate_median($values) {
        if (empty($values)) {
            return 0;
        }
        
        // 过滤非数值
        $values = array_filter($values, function($v) {
            return is_numeric($v);
        });
        
        if (empty($values)) {
            return 0;
        }
        
        // 重新索引数组
        $values = array_values($values);
        
        // 排序
        sort($values, SORT_NUMERIC);
        
        $count = count($values);
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            // 偶数个元素，取中间两个的平均值
            return ($values[$middle - 1] + $values[$middle]) / 2;
        } else {
            // 奇数个元素，取中间值
            return $values[$middle];
        }
    }
    
    /**
     * 获取指定 content_angle 下所有文章的中位数访问量
     * 
     * @param string $content_angle 内容角度
     * @return float 中位数访问量
     */
    private function get_angle_median_visits($content_angle) {
        // 尝试从缓存获取
        $cache_key = $this->cache_prefix . 'angle_median_' . md5($content_angle);
        $cached_value = get_transient($cache_key);
        if ($cached_value !== false) {
            return (float) $cached_value;
        }
        
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $articles_table = $wpdb->prefix . 'yali_ai_writer_articles';
        
        // 查询该 content_angle 下所有已发布文章
        $query = $wpdb->prepare("
            SELECT 
                a.post_id
            FROM {$topics_table} t
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            WHERE t.source_angle = %s
            AND a.post_id > 0
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
        ", $content_angle);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            set_transient($cache_key, 0, $this->cache_expiration);
            return 0;
        }
        
        // 获取所有文章的访问量
        $visits = array();
        foreach ($results as $row) {
            $post_id = (int) $row['post_id'];
            $visit_count = (int) get_post_meta($post_id, $this->visit_meta_key, true);
            $visits[] = $visit_count;
        }
        
        $median = $this->calculate_median($visits);
        
        // 缓存结果
        set_transient($cache_key, $median, $this->cache_expiration);
        
        return $median;
    }
    
    /**
     * 获取指定 content_angle 下所有结构的受欢迎度指数
     * 
     * @param string $content_angle 内容角度
     * @return array [structure_id => popularity_index]
     */
    public function get_indices_by_angle($content_angle) {
        global $wpdb;
        
        // 尝试从缓存获取
        $cache_key = $this->cache_prefix . 'angle_indices_' . md5($content_angle);
        $cached_value = get_transient($cache_key);
        if ($cached_value !== false && is_array($cached_value)) {
            return $cached_value;
        }
        
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        
        // 获取该 content_angle 下的所有结构
        $structures = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$structures_table} WHERE content_angle = %s",
            $content_angle
        ), ARRAY_A);
        
        $indices = array();
        
        foreach ($structures as $structure) {
            $structure_id = (int) $structure['id'];
            $indices[$structure_id] = $this->calculate_popularity_index($structure_id);
        }
        
        // 缓存结果
        set_transient($cache_key, $indices, $this->cache_expiration);
        
        return $indices;
    }
    
    /**
     * 批量更新所有结构的受欢迎度指数
     * 
     * @return int 更新的结构数量
     */
    public function update_all_indices() {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        $analytics_table = $wpdb->prefix . 'yali_ai_writer_structure_analytics';
        
        // 获取所有结构
        $structures = $wpdb->get_results(
            "SELECT id, content_angle FROM {$structures_table}",
            ARRAY_A
        );
        
        if (empty($structures)) {
            return 0;
        }
        
        $updated_count = 0;
        $today = current_time('Y-m-d');
        
        foreach ($structures as $structure) {
            $structure_id = (int) $structure['id'];
            
            // 清除该结构的缓存
            $cache_key = $this->cache_prefix . 'index_' . $structure_id;
            delete_transient($cache_key);
            
            // 重新计算受欢迎度指数
            $popularity_index = $this->calculate_popularity_index($structure_id);
            
            // 获取使用次数
            $usage_count = $this->get_structure_usage_count($structure_id, $today);
            
            // 获取平均访问量
            $avg_visits = $this->get_structure_avg_visits($structure_id);
            
            // 检查今天的记录是否存在
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$analytics_table} WHERE structure_id = %d AND date = %s",
                $structure_id,
                $today
            ));
            
            if ($existing) {
                // 更新现有记录
                $wpdb->update(
                    $analytics_table,
                    array(
                        'usage_count' => $usage_count,
                        'avg_visits' => $avg_visits,
                        'popularity_index' => $popularity_index
                    ),
                    array(
                        'structure_id' => $structure_id,
                        'date' => $today
                    ),
                    array('%d', '%f', '%f'),
                    array('%d', '%s')
                );
            } else {
                // 插入新记录
                $wpdb->insert(
                    $analytics_table,
                    array(
                        'structure_id' => $structure_id,
                        'date' => $today,
                        'usage_count' => $usage_count,
                        'avg_visits' => $avg_visits,
                        'popularity_index' => $popularity_index,
                        'entropy_contribution' => 0
                    ),
                    array('%d', '%s', '%d', '%f', '%f', '%f')
                );
            }
            
            $updated_count++;
        }
        
        // 清除角度级别的缓存
        $this->clear_angle_caches();
        
        // 记录日志
        if ($this->logger) {
            $this->logger->info('受欢迎度指数批量更新完成', array(
                'updated_count' => $updated_count,
                'date' => $today
            ));
        }
        
        return $updated_count;
    }
    
    /**
     * 获取结构在指定日期的使用次数
     * 
     * @param int $structure_id 结构ID
     * @param string $date 日期 (Y-m-d)
     * @return int 使用次数
     */
    private function get_structure_usage_count($structure_id, $date) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$topics_table} 
            WHERE used_structure_id = %d 
            AND DATE(updated_at) = %s",
            $structure_id,
            $date
        ));
        
        return (int) $count;
    }
    
    /**
     * 获取结构关联文章的平均访问量
     * 
     * @param int $structure_id 结构ID
     * @return float 平均访问量
     */
    private function get_structure_avg_visits($structure_id) {
        $articles = $this->get_articles_by_structure($structure_id);
        
        if (empty($articles)) {
            return 0;
        }
        
        $total_visits = 0;
        foreach ($articles as $article) {
            $total_visits += $article['visits'];
        }
        
        return $total_visits / count($articles);
    }
    
    /**
     * 清除角度级别的缓存
     */
    private function clear_angle_caches() {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        
        // 获取所有不同的 content_angle
        $angles = $wpdb->get_col(
            "SELECT DISTINCT content_angle FROM {$structures_table}"
        );
        
        foreach ($angles as $angle) {
            $cache_key = $this->cache_prefix . 'angle_median_' . md5($angle);
            delete_transient($cache_key);
            
            $cache_key = $this->cache_prefix . 'angle_indices_' . md5($angle);
            delete_transient($cache_key);
        }
    }
    
    /**
     * 清除所有缓存
     */
    public function clear_all_caches() {
        global $wpdb;
        
        // 清除所有结构的缓存
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        $structure_ids = $wpdb->get_col(
            "SELECT id FROM {$structures_table}"
        );
        
        foreach ($structure_ids as $id) {
            $cache_key = $this->cache_prefix . 'index_' . $id;
            delete_transient($cache_key);
        }
        
        // 清除角度级别的缓存
        $this->clear_angle_caches();
    }
    
    /**
     * 获取结构的历史受欢迎度趋势
     * 
     * @param int $structure_id 结构ID
     * @param int $days 天数
     * @return array 历史数据 [['date' => string, 'popularity_index' => float], ...]
     */
    public function get_popularity_trend($structure_id, $days = 30) {
        global $wpdb;
        
        $analytics_table = $wpdb->prefix . 'yali_ai_writer_structure_analytics';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT date, popularity_index 
            FROM {$analytics_table} 
            WHERE structure_id = %d 
            AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            ORDER BY date ASC",
            $structure_id,
            $days
        ), ARRAY_A);
        
        return $results ?: array();
    }
    
    /**
     * 存储历史受欢迎度指数（用于趋势分析）
     * 
     * @param int $structure_id 结构ID
     * @param float $popularity_index 受欢迎度指数
     * @return bool 是否成功
     */
    public function store_historical_index($structure_id, $popularity_index) {
        global $wpdb;
        
        $analytics_table = $wpdb->prefix . 'yali_ai_writer_structure_analytics';
        $today = current_time('Y-m-d');
        
        // 检查今天的记录是否存在
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$analytics_table} WHERE structure_id = %d AND date = %s",
            $structure_id,
            $today
        ));
        
        if ($existing) {
            // 更新现有记录
            $result = $wpdb->update(
                $analytics_table,
                array('popularity_index' => $popularity_index),
                array('structure_id' => $structure_id, 'date' => $today),
                array('%f'),
                array('%d', '%s')
            );
        } else {
            // 插入新记录
            $result = $wpdb->insert(
                $analytics_table,
                array(
                    'structure_id' => $structure_id,
                    'date' => $today,
                    'popularity_index' => $popularity_index
                ),
                array('%d', '%s', '%f')
            );
        }
        
        return $result !== false;
    }
}
