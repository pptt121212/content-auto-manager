<?php
/**
 * 智能文章结构优化系统 - 受欢迎文章分析器
 * 
 * 负责识别和分析表现优秀的已发布文章，为结构提取提供数据支持
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ArticleAnalyzer {
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * 日志记录器
     */
    private $logger;
    
    /**
     * 外部访问统计的 meta_key
     */
    private $visit_meta_key = '_external_visit_count';
    
    /**
     * 构造函数
     * 
     * @param ContentAuto_PluginLogger|null $logger 日志记录器
     */
    public function __construct($logger = null) {
        require_once dirname(__FILE__) . '/class-optimization-config.php';
        $this->config = new ContentAuto_OptimizationConfig();
        $this->logger = $logger;
    }
    
    /**
     * 计算指定 content_angle 的中位数访问量
     * 
     * @param string $content_angle 内容角度
     * @return float 中位数访问量
     */
    public function calculate_median_visits($content_angle) {
        $articles = $this->get_articles_by_angle($content_angle);
        
        if (empty($articles)) {
            return 0.0;
        }
        
        // 提取访问量
        $visits = array_column($articles, 'visit_count');
        
        return $this->calculate_median($visits);
    }
    
    /**
     * 识别高表现文章（前20%，滑动时间窗口内）
     * 
     * @param string $content_angle 内容角度
     * @param int|null $min_articles 最小文章数要求（默认从配置读取）
     * @param int|null $min_days_published 最小发布天数（默认从配置读取）
     * @return array 高表现文章ID列表，包含详细信息
     */
    public function identify_high_performers($content_angle, $min_articles = null, $min_days_published = null) {
        // 使用配置值或参数值
        if ($min_articles === null) {
            $min_articles = $this->config->get_int('min_articles_for_analysis', 10);
        }
        if ($min_days_published === null) {
            $min_days_published = $this->config->get_int('min_days_published', 7);
        }
        
        // 时间窗口上限（只分析最近N天内发布的文章）
        $max_days_published = $this->config->get_int('max_days_for_analysis', 90);
        
        // 高表现文章的绝对数量上限
        $max_high_performers = $this->config->get_int('max_high_performers_per_angle', 50);
        
        // 获取时间窗口内的文章（min_days ~ max_days）
        $articles = $this->get_articles_by_angle_with_window($content_angle, $min_days_published, $max_days_published);
        
        // 检查最小文章数要求
        if (count($articles) < $min_articles) {
            if ($this->logger) {
                $this->logger->info('文章数量不足，跳过高表现文章识别', array(
                    'content_angle' => $content_angle,
                    'article_count' => count($articles),
                    'min_required' => $min_articles,
                    'time_window' => "{$min_days_published}-{$max_days_published}天"
                ));
            }
            return array();
        }
        
        // 按访问量排序（降序）
        usort($articles, function($a, $b) {
            return $b['visit_count'] - $a['visit_count'];
        });
        
        // 计算前20%的阈值位置
        $percentile = $this->config->get_int('visit_threshold_percentile', 80);
        $threshold_position = (int) ceil(count($articles) * (100 - $percentile) / 100);
        
        // 确保至少返回1篇文章，但不超过绝对上限
        $threshold_position = max(1, $threshold_position);
        $threshold_position = min($threshold_position, $max_high_performers);
        
        // 获取前20%的文章（受绝对上限约束）
        $high_performers = array_slice($articles, 0, $threshold_position);
        
        // 计算百分位排名
        $total_count = count($articles);
        foreach ($high_performers as $index => &$article) {
            // 百分位排名：(排名位置 / 总数) * 100
            $article['percentile_rank'] = round((1 - ($index / $total_count)) * 100, 2);
            $article['analysis_timestamp'] = current_time('mysql');
        }
        unset($article);
        
        return $high_performers;
    }
    
    /**
     * 分析指定 content_angle 的文章表现
     * 
     * @param string $content_angle 内容角度
     * @return array 分析结果
     */
    public function analyze_performance_by_angle($content_angle) {
        $min_articles = $this->config->get_int('min_articles_for_analysis', 10);
        $min_days_published = $this->config->get_int('min_days_published', 7);
        
        // 获取所有符合条件的文章
        $articles = $this->get_articles_by_angle($content_angle, $min_days_published);
        $article_count = count($articles);
        
        // 基础统计
        $result = array(
            'content_angle' => $content_angle,
            'total_articles' => $article_count,
            'min_articles_required' => $min_articles,
            'meets_minimum' => $article_count >= $min_articles,
            'analysis_timestamp' => current_time('mysql'),
            'median_visits' => 0,
            'avg_visits' => 0,
            'max_visits' => 0,
            'min_visits' => 0,
            'high_performers' => array(),
            'percentile_80_threshold' => 0
        );
        
        if ($article_count === 0) {
            return $result;
        }
        
        // 提取访问量
        $visits = array_column($articles, 'visit_count');
        
        // 计算统计数据
        $result['median_visits'] = $this->calculate_median($visits);
        $result['avg_visits'] = array_sum($visits) / count($visits);
        $result['max_visits'] = max($visits);
        $result['min_visits'] = min($visits);
        
        // 计算80百分位阈值
        sort($visits, SORT_NUMERIC);
        $percentile_index = (int) floor(count($visits) * 0.8);
        $result['percentile_80_threshold'] = $visits[$percentile_index] ?? 0;
        
        // 如果满足最小文章数要求，识别高表现文章
        if ($result['meets_minimum']) {
            $result['high_performers'] = $this->identify_high_performers(
                $content_angle, 
                $min_articles, 
                $min_days_published
            );
        }
        
        return $result;
    }
    
    /**
     * 获取指定 content_angle 下的所有文章
     * 
     * @param string $content_angle 内容角度
     * @param int|null $min_days_published 最小发布天数（可选）
     * @return array 文章数据数组
     */
    private function get_articles_by_angle($content_angle, $min_days_published = null) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // 构建日期过滤条件
        $date_condition = '';
        if ($min_days_published !== null && $min_days_published > 0) {
            $date_condition = $wpdb->prepare(
                " AND p.post_date <= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $min_days_published
            );
        }
        
        // 查询该 content_angle 下所有已发布文章
        $query = $wpdb->prepare("
            SELECT 
                a.post_id,
                a.topic_id,
                t.title as topic_title,
                t.source_angle,
                p.post_title,
                p.post_date,
                DATEDIFF(NOW(), p.post_date) as days_published
            FROM {$topics_table} t
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            WHERE t.source_angle = %s
            AND a.post_id > 0
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
            {$date_condition}
            ORDER BY p.post_date DESC
        ", $content_angle);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            return array();
        }
        
        // 获取每篇文章的外部访问量
        $articles = array();
        foreach ($results as $row) {
            $post_id = (int) $row['post_id'];
            $visit_count = (int) get_post_meta($post_id, $this->visit_meta_key, true);
            
            $articles[] = array(
                'post_id' => $post_id,
                'topic_id' => (int) $row['topic_id'],
                'topic_title' => $row['topic_title'],
                'source_angle' => $row['source_angle'],
                'post_title' => $row['post_title'],
                'post_date' => $row['post_date'],
                'days_published' => (int) $row['days_published'],
                'visit_count' => $visit_count
            );
        }
        
        return $articles;
    }
    
    /**
     * 获取指定 content_angle 下时间窗口内的文章
     * 
     * @param string $content_angle 内容角度
     * @param int $min_days_published 最小发布天数
     * @param int $max_days_published 最大发布天数
     * @param bool $exclude_processed 是否排除已处理的文章
     * @return array 文章数据数组
     */
    private function get_articles_by_angle_with_window($content_angle, $min_days_published, $max_days_published, $exclude_processed = false) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        // 构建排除已处理文章的条件
        $exclude_condition = '';
        if ($exclude_processed) {
            $exclude_condition = "AND a.post_id NOT IN (SELECT source_article_id FROM {$structures_table} WHERE source_article_id IS NOT NULL)";
        }
        
        // 查询时间窗口内的文章（min_days ~ max_days）
        $query = $wpdb->prepare("
            SELECT 
                a.post_id,
                a.topic_id,
                t.title as topic_title,
                t.source_angle,
                p.post_title,
                p.post_date,
                DATEDIFF(NOW(), p.post_date) as days_published
            FROM {$topics_table} t
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            WHERE t.source_angle = %s
            AND a.post_id > 0
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
            AND p.post_date <= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
            {$exclude_condition}
            ORDER BY p.post_date DESC
        ", $content_angle, $min_days_published, $max_days_published);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            return array();
        }
        
        // 获取每篇文章的外部访问量
        $articles = array();
        foreach ($results as $row) {
            $post_id = (int) $row['post_id'];
            $visit_count = (int) get_post_meta($post_id, $this->visit_meta_key, true);
            
            $articles[] = array(
                'post_id' => $post_id,
                'topic_id' => (int) $row['topic_id'],
                'topic_title' => $row['topic_title'],
                'source_angle' => $row['source_angle'],
                'post_title' => $row['post_title'],
                'post_date' => $row['post_date'],
                'days_published' => (int) $row['days_published'],
                'visit_count' => $visit_count
            );
        }
        
        return $articles;
    }
    
    /**
     * 计算数组的中位数
     * 
     * @param array $values 数值数组
     * @return float 中位数
     */
    public function calculate_median($values) {
        if (empty($values)) {
            return 0.0;
        }
        
        // 过滤非数值
        $values = array_filter($values, function($v) {
            return is_numeric($v);
        });
        
        if (empty($values)) {
            return 0.0;
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
            return (float) $values[$middle];
        }
    }
    
    /**
     * 获取所有 content_angle 的分析概览
     * 
     * @return array 所有角度的分析结果
     */
    public function get_all_angles_overview() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 获取所有不同的 content_angle
        $angles = $wpdb->get_col("
            SELECT DISTINCT source_angle 
            FROM {$topics_table} 
            WHERE source_angle IS NOT NULL 
            AND source_angle != ''
        ");
        
        $overview = array();
        foreach ($angles as $angle) {
            $overview[$angle] = $this->analyze_performance_by_angle($angle);
        }
        
        return $overview;
    }
    
    /**
     * 检查文章是否已被处理过（用于结构提取）
     * 
     * @param int $post_id 文章ID
     * @return bool 是否已处理
     */
    public function is_article_processed($post_id) {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$structures_table} WHERE source_article_id = %d",
            $post_id
        ));
        
        return (int) $exists > 0;
    }
    
    /**
     * 获取待处理的高表现文章
     * 排除已经被处理过的文章
     * 
     * @param string $content_angle 内容角度
     * @param int|null $limit 最大返回数量（默认从配置读取）
     * @return array 待处理的高表现文章列表
     */
    public function get_unprocessed_high_performers($content_angle, $limit = null) {
        if ($limit === null) {
            $limit = $this->config->get_int('max_articles_per_angle', 5);
        }
        
        // 直接获取未处理的高表现文章（在SQL层面排除已处理的）
        $high_performers = $this->identify_high_performers_unprocessed($content_angle);
        
        if (empty($high_performers)) {
            return array();
        }
        
        // 限制返回数量
        return array_slice($high_performers, 0, $limit);
    }
    
    /**
     * 识别未处理的高表现文章（在SQL层面排除已处理的文章）
     * 
     * @param string $content_angle 内容角度
     * @return array 未处理的高表现文章列表
     */
    private function identify_high_performers_unprocessed($content_angle) {
        // 使用配置值
        $min_articles = $this->config->get_int('min_articles_for_analysis', 10);
        $min_days_published = $this->config->get_int('min_days_published', 7);
        $max_days_published = $this->config->get_int('max_days_for_analysis', 90);
        $max_high_performers = $this->config->get_int('max_high_performers_per_angle', 50);
        $percentile = $this->config->get_int('visit_threshold_percentile', 80);
        
        // 1. 先获取所有文章（包括已处理的）来计算访问量阈值
        $all_articles = $this->get_articles_by_angle_with_window($content_angle, $min_days_published, $max_days_published, false);
        
        // 检查最小文章数要求
        if (count($all_articles) < $min_articles) {
            if ($this->logger) {
                $this->logger->info('文章数量不足，跳过高表现文章识别', array(
                    'content_angle' => $content_angle,
                    'article_count' => count($all_articles),
                    'min_required' => $min_articles,
                    'time_window' => "{$min_days_published}-{$max_days_published}天"
                ));
            }
            return array();
        }
        
        // 2. 计算访问量阈值（基于所有文章的前20%）
        $all_visits = array_column($all_articles, 'visit_count');
        sort($all_visits, SORT_NUMERIC);
        $threshold_index = (int) floor(count($all_visits) * $percentile / 100);
        $visit_threshold = $all_visits[$threshold_index] ?? 0;
        
        // 重要：如果阈值为0，说明大部分文章没有访问量数据，跳过分析
        if ($visit_threshold <= 0) {
            if ($this->logger) {
                $this->logger->info('访问量阈值为0，跳过高表现文章识别（文章缺少访问量数据）', array(
                    'content_angle' => $content_angle,
                    'total_articles' => count($all_articles),
                    'percentile' => $percentile
                ));
            }
            return array();
        }
        
        if ($this->logger) {
            $this->logger->info('计算访问量阈值', array(
                'content_angle' => $content_angle,
                'total_articles' => count($all_articles),
                'percentile' => $percentile,
                'visit_threshold' => $visit_threshold
            ));
        }
        
        // 3. 获取未处理的文章
        $unprocessed_articles = $this->get_articles_by_angle_with_window($content_angle, $min_days_published, $max_days_published, true);
        
        if (empty($unprocessed_articles)) {
            return array();
        }
        
        // 4. 筛选出访问量超过阈值的未处理文章（必须大于阈值，不是大于等于）
        $high_performers = array_filter($unprocessed_articles, function($article) use ($visit_threshold) {
            return $article['visit_count'] > $visit_threshold;
        });
        
        if (empty($high_performers)) {
            return array();
        }
        
        // 重新索引数组
        $high_performers = array_values($high_performers);
        
        // 按访问量排序（降序）
        usort($high_performers, function($a, $b) {
            return $b['visit_count'] - $a['visit_count'];
        });
        
        // 限制最大数量
        $high_performers = array_slice($high_performers, 0, $max_high_performers);
        
        // 计算百分位排名（基于所有文章）
        $total_count = count($all_articles);
        foreach ($high_performers as $index => &$article) {
            // 计算该文章在所有文章中的排名
            $rank = 0;
            foreach ($all_visits as $visit) {
                if ($visit < $article['visit_count']) {
                    $rank++;
                }
            }
            $article['percentile_rank'] = round(($rank / $total_count) * 100, 2);
            $article['analysis_timestamp'] = current_time('mysql');
            $article['visit_threshold'] = $visit_threshold;
        }
        unset($article);
        
        return $high_performers;
    }
    
    /**
     * 存储分析结果（用于趋势追踪）
     * 
     * @param string $content_angle 内容角度
     * @param array $analysis_result 分析结果
     * @return bool 是否成功
     */
    public function store_analysis_result($content_angle, $analysis_result) {
        // 使用 WordPress 选项存储分析历史
        $option_key = 'cam_article_analysis_' . md5($content_angle);
        $history = get_option($option_key, array());
        
        // 添加新的分析结果
        $history[] = array(
            'timestamp' => current_time('mysql'),
            'total_articles' => $analysis_result['total_articles'],
            'median_visits' => $analysis_result['median_visits'],
            'avg_visits' => $analysis_result['avg_visits'],
            'high_performer_count' => count($analysis_result['high_performers']),
            'percentile_80_threshold' => $analysis_result['percentile_80_threshold']
        );
        
        // 只保留最近30天的记录
        // 使用 current_time('timestamp') 保持与写入时间的一致性
        $cutoff_time = current_time('timestamp') - (30 * DAY_IN_SECONDS);
        $history = array_filter($history, function($item) use ($cutoff_time) {
            return strtotime($item['timestamp']) > $cutoff_time;
        });
        
        // 重新索引
        $history = array_values($history);
        
        return update_option($option_key, $history);
    }
    
    /**
     * 获取分析历史（用于趋势展示）
     * 
     * @param string $content_angle 内容角度
     * @param int $days 天数
     * @return array 历史分析数据
     */
    public function get_analysis_history($content_angle, $days = 30) {
        $option_key = 'cam_article_analysis_' . md5($content_angle);
        $history = get_option($option_key, array());
        
        // 过滤指定天数内的记录
        $cutoff_time = strtotime("-{$days} days");
        $history = array_filter($history, function($item) use ($cutoff_time) {
            return strtotime($item['timestamp']) > $cutoff_time;
        });
        
        return array_values($history);
    }
    
    /**
     * 计算百分位数
     * 
     * @param array $values 数值数组
     * @param int $percentile 百分位数 (0-100)
     * @return float 百分位数值
     */
    public function calculate_percentile($values, $percentile) {
        if (empty($values)) {
            return 0.0;
        }
        
        // 过滤非数值
        $values = array_filter($values, function($v) {
            return is_numeric($v);
        });
        
        if (empty($values)) {
            return 0.0;
        }
        
        // 重新索引并排序
        $values = array_values($values);
        sort($values, SORT_NUMERIC);
        
        $count = count($values);
        
        // 计算百分位位置
        $index = ($percentile / 100) * ($count - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower == $upper) {
            return (float) $values[$lower];
        }
        
        // 线性插值
        $fraction = $index - $lower;
        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }
}
