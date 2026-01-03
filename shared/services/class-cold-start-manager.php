<?php
/**
 * 智能文章结构优化系统 - 冷启动管理器
 * 
 * 负责管理冷启动阶段的判断和探索率计算
 * 按 content_angle 分别判断冷启动阶段，采用渐进式过渡策略
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ColdStartManager {
    
    /**
     * 冷启动阶段常量
     * 
     * Phase 1: 完全冷启动 (0-20篇文章) - 100%随机选择
     * Phase 2: 过渡阶段 (21-50篇文章) - 50%随机 + 50%加权
     * Phase 3: 早期正常 (51-100篇文章) - 30%随机 + 70%加权
     * Phase 4: 正常阶段 (100+篇文章) - 使用配置的探索率
     */
    const PHASE_FULL_COLD = 1;
    const PHASE_TRANSITION = 2;
    const PHASE_EARLY_NORMAL = 3;
    const PHASE_NORMAL = 4;
    
    /**
     * 阶段边界值
     */
    const THRESHOLD_FULL_COLD = 20;
    const THRESHOLD_TRANSITION = 50;
    const THRESHOLD_EARLY_NORMAL = 100;
    
    /**
     * 各阶段的探索率
     */
    const EXPLORATION_RATE_FULL_COLD = 1.0;      // 100% 随机
    const EXPLORATION_RATE_TRANSITION = 0.5;     // 50% 随机
    const EXPLORATION_RATE_EARLY_NORMAL = 0.3;   // 30% 随机
    
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
    private $cache_prefix = 'cam_cold_start_';
    
    /**
     * 缓存过期时间（秒）- 1小时
     */
    private $cache_expiration = 3600;
    
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
     * 获取指定 content_angle 的冷启动阶段
     * 
     * 根据该 content_angle 下已关联结构的文章数量判断阶段：
     * - 0-20篇: Phase 1 (完全冷启动)
     * - 21-50篇: Phase 2 (过渡阶段)
     * - 51-100篇: Phase 3 (早期正常)
     * - 100+篇: Phase 4 (正常阶段)
     * 
     * @param string $content_angle 内容角度
     * @return int 阶段常量 (PHASE_FULL_COLD, PHASE_TRANSITION, PHASE_EARLY_NORMAL, PHASE_NORMAL)
     */
    public function get_phase($content_angle) {
        // 尝试从缓存获取
        $cache_key = $this->cache_prefix . 'phase_' . md5($content_angle);
        $cached_value = get_transient($cache_key);
        if ($cached_value !== false) {
            return (int) $cached_value;
        }
        
        // 获取文章数量
        $article_count = $this->get_article_count_with_structure($content_angle);
        
        // 判断阶段
        $phase = $this->determine_phase_by_count($article_count);
        
        // 缓存结果
        set_transient($cache_key, $phase, $this->cache_expiration);
        
        return $phase;
    }
    
    /**
     * 根据文章数量判断阶段
     * 
     * @param int $article_count 文章数量
     * @return int 阶段常量
     */
    private function determine_phase_by_count($article_count) {
        if ($article_count <= self::THRESHOLD_FULL_COLD) {
            return self::PHASE_FULL_COLD;
        } elseif ($article_count <= self::THRESHOLD_TRANSITION) {
            return self::PHASE_TRANSITION;
        } elseif ($article_count <= self::THRESHOLD_EARLY_NORMAL) {
            return self::PHASE_EARLY_NORMAL;
        } else {
            return self::PHASE_NORMAL;
        }
    }
    
    /**
     * 获取指定阶段的探索率
     * 
     * @param int $phase 阶段常量
     * @return float 探索率 (0.0 - 1.0)
     */
    public function get_exploration_rate($phase) {
        switch ($phase) {
            case self::PHASE_FULL_COLD:
                return self::EXPLORATION_RATE_FULL_COLD;
                
            case self::PHASE_TRANSITION:
                return self::EXPLORATION_RATE_TRANSITION;
                
            case self::PHASE_EARLY_NORMAL:
                return self::EXPLORATION_RATE_EARLY_NORMAL;
                
            case self::PHASE_NORMAL:
                // 正常阶段使用配置的探索率
                return $this->config->get_exploration_rate();
                
            default:
                // 未知阶段，使用完全随机作为安全回退
                return self::EXPLORATION_RATE_FULL_COLD;
        }
    }
    
    /**
     * 获取指定 content_angle 的探索率
     * 
     * 便捷方法，结合 get_phase() 和 get_exploration_rate()
     * 
     * @param string $content_angle 内容角度
     * @return float 探索率 (0.0 - 1.0)
     */
    public function get_exploration_rate_for_angle($content_angle) {
        $phase = $this->get_phase($content_angle);
        return $this->get_exploration_rate($phase);
    }
    
    /**
     * 获取所有 content_angle 的阶段状态
     * 
     * @return array [content_angle => ['phase' => int, 'article_count' => int, 'phase_name' => string]]
     */
    public function get_all_phases() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 获取所有不同的 content_angle
        $angles = $wpdb->get_col(
            "SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle != ''"
        );
        
        $result = array();
        
        foreach ($angles as $angle) {
            $article_count = $this->get_article_count_with_structure($angle);
            $phase = $this->determine_phase_by_count($article_count);
            
            $result[$angle] = array(
                'phase' => $phase,
                'article_count' => $article_count,
                'phase_name' => $this->get_phase_name($phase),
                'exploration_rate' => $this->get_exploration_rate($phase)
            );
        }
        
        return $result;
    }
    
    /**
     * 获取阶段名称
     * 
     * @param int $phase 阶段常量
     * @return string 阶段名称
     */
    public function get_phase_name($phase) {
        switch ($phase) {
            case self::PHASE_FULL_COLD:
                return '完全冷启动';
            case self::PHASE_TRANSITION:
                return '过渡阶段';
            case self::PHASE_EARLY_NORMAL:
                return '早期正常';
            case self::PHASE_NORMAL:
                return '正常阶段';
            default:
                return '未知阶段';
        }
    }
    
    /**
     * 获取阶段描述
     * 
     * @param int $phase 阶段常量
     * @return string 阶段描述
     */
    public function get_phase_description($phase) {
        switch ($phase) {
            case self::PHASE_FULL_COLD:
                return '0-20篇文章，100%随机选择结构';
            case self::PHASE_TRANSITION:
                return '21-50篇文章，50%随机 + 50%加权选择';
            case self::PHASE_EARLY_NORMAL:
                return '51-100篇文章，30%随机 + 70%加权选择';
            case self::PHASE_NORMAL:
                return '100+篇文章，使用配置的探索率';
            default:
                return '未知阶段';
        }
    }
    
    /**
     * 获取指定 content_angle 下已关联结构的文章数量
     * 
     * 统计该 content_angle 下，topics 表中 used_structure_id 不为空的记录数
     * 
     * @param string $content_angle 内容角度
     * @return int 文章数量
     */
    private function get_article_count_with_structure($content_angle) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // 统计该 content_angle 下已关联结构且已发布的文章数量
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.id) 
            FROM {$topics_table} t
            INNER JOIN {$articles_table} a ON t.id = a.topic_id
            INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
            WHERE t.source_angle = %s
            AND t.used_structure_id IS NOT NULL
            AND a.post_id > 0
            AND p.post_status = 'publish'
            AND p.post_type = 'post'",
            $content_angle
        ));
        
        return (int) $count;
    }
    
    /**
     * 检查是否应该使用随机选择（探索）
     * 
     * 根据当前阶段的探索率，随机决定是否使用随机选择
     * 
     * @param string $content_angle 内容角度
     * @return bool true 表示应该使用随机选择，false 表示应该使用加权选择
     */
    public function should_explore($content_angle) {
        $exploration_rate = $this->get_exploration_rate_for_angle($content_angle);
        
        // 生成 0-1 之间的随机数
        $random = mt_rand() / mt_getrandmax();
        
        return $random < $exploration_rate;
    }
    
    /**
     * 记录阶段转换事件
     * 
     * @param string $content_angle 内容角度
     * @param int $old_phase 旧阶段
     * @param int $new_phase 新阶段
     * @param int $article_count 当前文章数量
     */
    public function log_phase_transition($content_angle, $old_phase, $new_phase, $article_count) {
        if ($this->logger) {
            $this->logger->info('冷启动阶段转换', array(
                'content_angle' => $content_angle,
                'old_phase' => $this->get_phase_name($old_phase),
                'new_phase' => $this->get_phase_name($new_phase),
                'article_count' => $article_count,
                'old_exploration_rate' => $this->get_exploration_rate($old_phase),
                'new_exploration_rate' => $this->get_exploration_rate($new_phase)
            ));
        }
    }
    
    /**
     * 清除指定 content_angle 的缓存
     * 
     * @param string $content_angle 内容角度
     */
    public function clear_cache($content_angle) {
        $cache_key = $this->cache_prefix . 'phase_' . md5($content_angle);
        delete_transient($cache_key);
    }
    
    /**
     * 清除所有缓存
     */
    public function clear_all_caches() {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 获取所有不同的 content_angle
        $angles = $wpdb->get_col(
            "SELECT DISTINCT source_angle FROM {$topics_table} WHERE source_angle != ''"
        );
        
        foreach ($angles as $angle) {
            $this->clear_cache($angle);
        }
    }
    
    /**
     * 获取阶段统计信息
     * 
     * @return array 统计信息
     */
    public function get_phase_statistics() {
        $all_phases = $this->get_all_phases();
        
        $stats = array(
            'total_angles' => count($all_phases),
            'phase_distribution' => array(
                self::PHASE_FULL_COLD => 0,
                self::PHASE_TRANSITION => 0,
                self::PHASE_EARLY_NORMAL => 0,
                self::PHASE_NORMAL => 0
            ),
            'total_articles_with_structure' => 0
        );
        
        foreach ($all_phases as $angle_data) {
            $stats['phase_distribution'][$angle_data['phase']]++;
            $stats['total_articles_with_structure'] += $angle_data['article_count'];
        }
        
        return $stats;
    }
}
