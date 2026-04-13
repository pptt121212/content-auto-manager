<?php
/**
 * 智能文章结构优化系统 - 多样性控制器
 * 
 * 负责监控和控制结构选择的多样性，防止过度依赖少数热门结构
 * 实现熵值计算、使用惩罚和新结构提升机制
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_DiversityController {
    
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
    private $cache_prefix = 'cam_diversity_';
    
    /**
     * 缓存过期时间（秒）- 1小时
     */
    private $cache_expiration = 3600;
    
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
     * 计算选择熵值
     * 
     * 熵值公式: H = -Σ(p_i * log2(p_i))
     * 其中 p_i 是每个结构的选择比例
     * 
     * 熵值越高表示选择越均匀，熵值越低表示选择越集中
     * 
     * @param string $content_angle 内容角度
     * @param int $days 统计天数（默认7天滚动窗口）
     * @return float 熵值
     */
    public function calculate_entropy($content_angle, $days = 7) {
        // 获取选择分布
        $distribution = $this->get_selection_distribution($content_angle, $days);
        
        if (empty($distribution)) {
            return 0.0;
        }
        
        // 计算总选择次数
        $total = array_sum($distribution);
        
        if ($total <= 0) {
            return 0.0;
        }
        
        // 计算熵值
        $entropy = 0.0;
        foreach ($distribution as $structure_id => $count) {
            if ($count > 0) {
                $p = $count / $total;
                $entropy -= $p * log($p, 2);
            }
        }
        
        return $entropy;
    }
    
    /**
     * 获取选择分布
     * 
     * @param string $content_angle 内容角度
     * @param int $days 统计天数
     * @return array [structure_id => selection_count]
     */
    public function get_selection_distribution($content_angle, $days = 7) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT used_structure_id, COUNT(*) as count
            FROM {$topics_table}
            WHERE source_angle = %s
            AND used_structure_id IS NOT NULL
            AND updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY used_structure_id
        ", $content_angle, $days), ARRAY_A);
        
        $distribution = array();
        if ($results) {
            foreach ($results as $row) {
                $distribution[(int)$row['used_structure_id']] = (int)$row['count'];
            }
        }
        
        return $distribution;
    }
    
    /**
     * 检查并获取使用惩罚系数
     * 
     * 当结构在窗口期内的使用比例超过阈值（默认30%）时，
     * 应用惩罚系数（默认0.3x）
     * 
     * @param int $structure_id 结构ID
     * @param string $content_angle 内容角度
     * @return float 惩罚系数（1.0表示无惩罚，<1.0表示有惩罚）
     */
    public function get_usage_penalty($structure_id, $content_angle) {
        // 获取配置
        $threshold = $this->config->get_float('window_diversity_threshold', 0.30);
        $penalty = $this->config->get_float('window_diversity_penalty', 0.3);
        $window_days = 7; // 7天滚动窗口
        
        // 获取选择分布
        $distribution = $this->get_selection_distribution($content_angle, $window_days);
        
        if (empty($distribution)) {
            return 1.0; // 无数据，不惩罚
        }
        
        // 计算总选择次数
        $total = array_sum($distribution);
        
        if ($total <= 0) {
            return 1.0;
        }
        
        // 获取该结构的使用次数
        $structure_count = isset($distribution[$structure_id]) ? $distribution[$structure_id] : 0;
        
        // 计算使用比例
        $usage_ratio = $structure_count / $total;
        
        // 如果超过阈值，应用惩罚
        if ($usage_ratio > $threshold) {
            return $penalty;
        }
        
        return 1.0;
    }

    
    /**
     * 获取新结构提升系数
     * 
     * 对于数据驱动的新结构（使用次数少于配置值），
     * 应用提升系数（默认2.0x）以鼓励探索
     * 
     * @param int $structure_id 结构ID
     * @return float 提升系数（1.0表示无提升，>1.0表示有提升）
     */
    public function get_new_structure_boost($structure_id) {
        // 获取配置
        $boost = $this->config->get_float('new_structure_boost', 2.0);
        $boost_uses = $this->config->get_int('new_structure_boost_uses', 5);
        
        // 获取结构信息
        $structure_info = $this->get_structure_info($structure_id);
        
        if (!$structure_info) {
            return 1.0; // 结构不存在，不提升
        }
        
        // 只对数据驱动结构应用提升
        if ($structure_info['source_type'] !== 'data_driven') {
            return 1.0;
        }
        
        // 检查使用次数
        if ($structure_info['usage_count'] < $boost_uses) {
            return $boost;
        }
        
        return 1.0;
    }
    
    /**
     * 获取结构信息
     * 
     * @param int $structure_id 结构ID
     * @return array|null 结构信息或null
     */
    private function get_structure_info($structure_id) {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT id, content_angle, source_type, usage_count 
            FROM {$structures_table} 
            WHERE id = %d",
            $structure_id
        ), ARRAY_A);
        
        return $result;
    }
    
    /**
     * 获取结构的综合调整系数
     * 
     * 结合使用惩罚和新结构提升，计算最终的权重调整系数
     * 
     * @param int $structure_id 结构ID
     * @param string $content_angle 内容角度
     * @return array ['factor' => float, 'penalty_applied' => bool, 'boost_applied' => bool]
     */
    public function get_adjustment_factor($structure_id, $content_angle) {
        $penalty = $this->get_usage_penalty($structure_id, $content_angle);
        $boost = $this->get_new_structure_boost($structure_id);
        
        // 综合调整系数 = 惩罚 * 提升
        $factor = $penalty * $boost;
        
        return array(
            'factor' => $factor,
            'penalty_applied' => $penalty < 1.0,
            'boost_applied' => $boost > 1.0,
            'penalty_value' => $penalty,
            'boost_value' => $boost
        );
    }
    
    /**
     * 检查熵值是否低于阈值（过度集中警告）
     * 
     * @param string $content_angle 内容角度
     * @return array ['is_low' => bool, 'entropy' => float, 'threshold' => float]
     */
    public function check_entropy_alert($content_angle) {
        $threshold = $this->config->get_float('min_entropy_threshold', 1.5);
        $entropy = $this->calculate_entropy($content_angle);
        
        return array(
            'is_low' => $entropy < $threshold,
            'entropy' => $entropy,
            'threshold' => $threshold
        );
    }
    
    /**
     * 获取窗口期内使用的不同结构数量
     * 
     * @param string $content_angle 内容角度
     * @param int $days 统计天数
     * @return int 不同结构数量
     */
    public function get_unique_structures_count($content_angle, $days = 7) {
        $distribution = $this->get_selection_distribution($content_angle, $days);
        return count($distribution);
    }
    
    /**
     * 检查是否满足最小多样性要求
     * 
     * 每个 content_angle 每周至少使用3个不同结构
     * 
     * @param string $content_angle 内容角度
     * @return array ['meets_requirement' => bool, 'unique_count' => int, 'required' => int]
     */
    public function check_minimum_diversity($content_angle) {
        $required = 3;
        $unique_count = $this->get_unique_structures_count($content_angle, 7);
        
        return array(
            'meets_requirement' => $unique_count >= $required,
            'unique_count' => $unique_count,
            'required' => $required
        );
    }
    
    /**
     * 生成多样性报告
     * 
     * @param string $content_angle 内容角度
     * @return array 报告数据
     */
    public function generate_diversity_report($content_angle) {
        $days = 7;
        
        // 获取选择分布
        $distribution = $this->get_selection_distribution($content_angle, $days);
        
        // 计算熵值
        $entropy = $this->calculate_entropy($content_angle, $days);
        
        // 检查熵值警告
        $entropy_alert = $this->check_entropy_alert($content_angle);
        
        // 检查最小多样性
        $min_diversity = $this->check_minimum_diversity($content_angle);
        
        // 计算总选择次数
        $total_selections = array_sum($distribution);
        
        // 获取结构详情
        $structure_details = $this->get_structure_details_for_report($distribution);
        
        // 获取惩罚和提升应用记录
        $adjustments = $this->get_recent_adjustments($content_angle, $days);
        
        return array(
            'content_angle' => $content_angle,
            'period_days' => $days,
            'entropy' => array(
                'value' => $entropy,
                'threshold' => $entropy_alert['threshold'],
                'is_low' => $entropy_alert['is_low'],
                'status' => $entropy_alert['is_low'] ? 'warning' : 'normal'
            ),
            'diversity' => array(
                'unique_structures' => $min_diversity['unique_count'],
                'required_minimum' => $min_diversity['required'],
                'meets_requirement' => $min_diversity['meets_requirement']
            ),
            'usage_distribution' => array(
                'total_selections' => $total_selections,
                'structures' => $structure_details
            ),
            'adjustments' => $adjustments,
            'generated_at' => current_time('mysql')
        );
    }

    
    /**
     * 获取报告用的结构详情
     * 
     * @param array $distribution 选择分布
     * @return array 结构详情列表
     */
    private function get_structure_details_for_report($distribution) {
        if (empty($distribution)) {
            return array();
        }
        
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        $structure_ids = array_keys($distribution);
        $placeholders = implode(',', array_fill(0, count($structure_ids), '%d'));
        
        $query = $wpdb->prepare(
            "SELECT id, title, source_type, usage_count 
            FROM {$structures_table} 
            WHERE id IN ($placeholders)",
            $structure_ids
        );
        
        $structures = $wpdb->get_results($query, ARRAY_A);
        
        $total = array_sum($distribution);
        $details = array();
        
        foreach ($structures as $structure) {
            $id = (int) $structure['id'];
            $count = isset($distribution[$id]) ? $distribution[$id] : 0;
            $percentage = $total > 0 ? ($count / $total) * 100 : 0;
            
            $details[] = array(
                'id' => $id,
                'title' => $structure['title'],
                'source_type' => $structure['source_type'],
                'total_usage_count' => (int) $structure['usage_count'],
                'window_selection_count' => $count,
                'window_percentage' => round($percentage, 2),
                'exceeds_threshold' => $percentage > ($this->config->get_float('window_diversity_threshold', 0.30) * 100)
            );
        }
        
        // 按选择次数降序排序
        usort($details, function($a, $b) {
            return $b['window_selection_count'] - $a['window_selection_count'];
        });
        
        return $details;
    }
    
    /**
     * 获取最近的调整记录
     * 
     * @param string $content_angle 内容角度
     * @param int $days 统计天数
     * @return array 调整记录
     */
    private function get_recent_adjustments($content_angle, $days) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $structures_table = $wpdb->prefix . 'yali_ai_writer_article_structures';
        
        // 获取最近的选择记录
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                t.id as topic_id,
                t.used_structure_id,
                t.selection_method,
                t.selection_weight,
                t.updated_at,
                s.title as structure_title,
                s.source_type
            FROM {$topics_table} t
            LEFT JOIN {$structures_table} s ON t.used_structure_id = s.id
            WHERE t.source_angle = %s
            AND t.used_structure_id IS NOT NULL
            AND t.updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY t.updated_at DESC
            LIMIT 50
        ", $content_angle, $days), ARRAY_A);
        
        $adjustments = array(
            'penalties_applied' => 0,
            'boosts_applied' => 0,
            'recent_selections' => array()
        );
        
        if ($results) {
            foreach ($results as $row) {
                $structure_id = (int) $row['used_structure_id'];
                $adjustment = $this->get_adjustment_factor($structure_id, $content_angle);
                
                if ($adjustment['penalty_applied']) {
                    $adjustments['penalties_applied']++;
                }
                if ($adjustment['boost_applied']) {
                    $adjustments['boosts_applied']++;
                }
                
                $adjustments['recent_selections'][] = array(
                    'topic_id' => (int) $row['topic_id'],
                    'structure_id' => $structure_id,
                    'structure_title' => $row['structure_title'],
                    'source_type' => $row['source_type'],
                    'selection_method' => $row['selection_method'],
                    'selection_weight' => (float) $row['selection_weight'],
                    'selected_at' => $row['updated_at'],
                    'penalty_applied' => $adjustment['penalty_applied'],
                    'boost_applied' => $adjustment['boost_applied']
                );
            }
        }
        
        return $adjustments;
    }
    
    /**
     * 获取所有 content_angle 的多样性概览
     * 
     * @return array 多样性概览
     */
    public function get_all_diversity_overview() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        // 获取所有不同的 content_angle
        $angles = $wpdb->get_col(
            "SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle != ''"
        );
        
        $overview = array();
        
        foreach ($angles as $angle) {
            $entropy_alert = $this->check_entropy_alert($angle);
            $min_diversity = $this->check_minimum_diversity($angle);
            
            $overview[$angle] = array(
                'entropy' => $entropy_alert['entropy'],
                'entropy_status' => $entropy_alert['is_low'] ? 'warning' : 'normal',
                'unique_structures' => $min_diversity['unique_count'],
                'meets_minimum_diversity' => $min_diversity['meets_requirement']
            );
        }
        
        return $overview;
    }
    
    /**
     * 清除缓存
     * 
     * @param string|null $content_angle 内容角度，为null时清除所有
     */
    public function clear_cache($content_angle = null) {
        if ($content_angle !== null) {
            $cache_key = $this->cache_prefix . 'distribution_' . md5($content_angle);
            delete_transient($cache_key);
        } else {
            // 清除所有缓存需要遍历所有 content_angle
            global $wpdb;
            $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
            
            $angles = $wpdb->get_col(
                "SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle != ''"
            );
            
            foreach ($angles as $angle) {
                $cache_key = $this->cache_prefix . 'distribution_' . md5($angle);
                delete_transient($cache_key);
            }
        }
    }
    
    /**
     * 记录多样性警告日志
     * 
     * @param string $content_angle 内容角度
     * @param string $warning_type 警告类型
     * @param array $details 详情
     */
    public function log_diversity_warning($content_angle, $warning_type, $details = array()) {
        if ($this->logger) {
            $this->logger->warning('多样性警告', array_merge(array(
                'content_angle' => $content_angle,
                'warning_type' => $warning_type
            ), $details));
        }
    }
    
    /**
     * 纯计算方法：计算熵值（用于测试）
     * 
     * @param array $distribution 选择分布 [id => count]
     * @return float 熵值
     */
    public function calculate_entropy_from_distribution($distribution) {
        if (empty($distribution)) {
            return 0.0;
        }
        
        // 计算总选择次数
        $total = array_sum($distribution);
        
        if ($total <= 0) {
            return 0.0;
        }
        
        // 计算熵值
        $entropy = 0.0;
        foreach ($distribution as $count) {
            if ($count > 0) {
                $p = $count / $total;
                $entropy -= $p * log($p, 2);
            }
        }
        
        return $entropy;
    }
}
