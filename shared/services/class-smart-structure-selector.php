<?php
/**
 * 智能文章结构优化系统 - 智能结构选择器
 * 
 * 负责在文章生成时基于受欢迎度进行智能加权选择
 * 实现 ε-greedy 策略和 softmax 选择算法
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_SmartStructureSelector {
    
    /**
     * 选择方法常量
     */
    const METHOD_EXPLORATION = 'exploration';   // 探索（随机选择）
    const METHOD_EXPLOITATION = 'exploitation'; // 利用（加权选择）
    const METHOD_FALLBACK = 'fallback';         // 回退（功能禁用时）
    
    /**
     * 冷启动管理器
     */
    private $cold_start_manager;
    
    /**
     * 受欢迎度计算器
     */
    private $popularity_calculator;
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * 日志记录器
     */
    private $logger;
    
    /**
     * 构造函数
     * 
     * @param Yali_AI_Writer_PluginLogger|null $logger 日志记录器
     */
    public function __construct($logger = null) {
        require_once dirname(__FILE__) . '/class-optimization-config.php';
        require_once dirname(__FILE__) . '/class-cold-start-manager.php';
        require_once dirname(__FILE__) . '/class-popularity-calculator.php';
        
        $this->config = new Yali_AI_Writer_OptimizationConfig();
        $this->cold_start_manager = new Yali_AI_Writer_ColdStartManager($logger);
        $this->popularity_calculator = new Yali_AI_Writer_PopularityCalculator($logger);
        $this->logger = $logger;
    }
    
    /**
     * 智能选择结构（增强版）
     * 
     * @param array $topic_data 主题数据（包含 id, source_angle 等）
     * @param array $candidate_structures 候选结构列表
     * @param int|null $task_id 任务ID（用于批量追踪）
     * @return array 选中的结构信息 ['structure' => array, 'method' => string, 'weight' => float]
     */
    public function select_structure($topic_data, $candidate_structures, $task_id = null) {
        // 检查功能开关
        if (!$this->config->is_optimization_enabled()) {
            return $this->fallback_random_select($candidate_structures);
        }
        
        // 验证输入
        if (empty($candidate_structures)) {
            return array(
                'structure' => null,
                'method' => self::METHOD_FALLBACK,
                'weight' => 0
            );
        }
        
        $content_angle = isset($topic_data['source_angle']) ? $topic_data['source_angle'] : '';
        
        // 获取冷启动阶段和探索率
        $exploration_rate = $this->cold_start_manager->get_exploration_rate_for_angle($content_angle);
        
        // 获取受欢迎度指数
        $popularity_indices = $this->popularity_calculator->get_indices_by_angle($content_angle);
        
        // 为候选结构添加受欢迎度指数
        $candidates_with_popularity = $this->enrich_candidates_with_popularity(
            $candidate_structures, 
            $popularity_indices
        );
        
        // 应用批量多样性惩罚
        if ($task_id !== null) {
            $candidates_with_popularity = $this->apply_batch_diversity_penalty(
                $candidates_with_popularity, 
                $task_id
            );
        }
        
        // 执行 ε-greedy 选择
        $result = $this->epsilon_greedy_select($candidates_with_popularity, $exploration_rate);
        
        // 记录选择结果
        if (isset($topic_data['id'])) {
            $this->record_selection(
                $topic_data['id'],
                $result['structure']['id'],
                $result['method'],
                $result['weight']
            );
        }
        
        // 记录日志
        $this->log_selection_decision($topic_data, $result, $task_id);
        
        return $result;
    }

    
    /**
     * 执行 ε-greedy 选择
     * 
     * @param array $candidates 候选结构（带受欢迎度）
     * @param float $exploration_rate 探索率
     * @return array [structure => array, method => string, weight => float]
     */
    public function epsilon_greedy_select($candidates, $exploration_rate) {
        if (empty($candidates)) {
            return array(
                'structure' => null,
                'method' => self::METHOD_FALLBACK,
                'weight' => 0
            );
        }
        
        // 生成 0-1 之间的随机数
        $random = mt_rand() / mt_getrandmax();
        
        if ($random < $exploration_rate) {
            // 探索：从 top-20 中随机选择
            return $this->random_select_from_top($candidates, 20);
        } else {
            // 利用：使用 softmax 加权选择
            $temperature = $this->config->get_softmax_temperature();
            return $this->softmax_select($candidates, $temperature);
        }
    }
    
    /**
     * 从 top-N 候选中随机选择
     * 
     * @param array $candidates 候选结构
     * @param int $top_n 取前N个
     * @return array [structure => array, method => string, weight => float]
     */
    private function random_select_from_top($candidates, $top_n = 20) {
        // 按受欢迎度排序
        usort($candidates, function($a, $b) {
            $pop_a = isset($a['popularity_index']) ? $a['popularity_index'] : 100;
            $pop_b = isset($b['popularity_index']) ? $b['popularity_index'] : 100;
            return $pop_b <=> $pop_a; // 降序
        });
        
        // 取 top-N
        $top_candidates = array_slice($candidates, 0, min($top_n, count($candidates)));
        
        // 随机选择
        $selected_index = array_rand($top_candidates);
        $selected = $top_candidates[$selected_index];
        
        return array(
            'structure' => $selected,
            'method' => self::METHOD_EXPLORATION,
            'weight' => isset($selected['popularity_index']) ? $selected['popularity_index'] : 100
        );
    }
    
    /**
     * 执行 softmax 加权选择
     * 
     * @param array $candidates 候选结构及其权重
     * @param float $temperature 温度参数
     * @return array [structure => array, method => string, weight => float]
     */
    public function softmax_select($candidates, $temperature = 1.0) {
        if (empty($candidates)) {
            return array(
                'structure' => null,
                'method' => self::METHOD_FALLBACK,
                'weight' => 0
            );
        }
        
        // 提取受欢迎度指数
        $indices = array();
        foreach ($candidates as $candidate) {
            $indices[] = isset($candidate['popularity_index']) ? $candidate['popularity_index'] : 100;
        }
        
        // 计算 softmax 概率
        $probabilities = $this->calculate_softmax_probabilities($indices, $temperature);
        
        // 根据概率选择
        $selected_index = $this->weighted_random_select($probabilities);
        $selected = $candidates[$selected_index];
        
        return array(
            'structure' => $selected,
            'method' => self::METHOD_EXPLOITATION,
            'weight' => isset($selected['popularity_index']) ? $selected['popularity_index'] : 100
        );
    }
    
    /**
     * 计算 softmax 概率
     * 
     * softmax(x_i) = exp(x_i / T) / Σ exp(x_j / T)
     * 
     * @param array $values 数值数组（受欢迎度指数）
     * @param float $temperature 温度参数（越高分布越均匀，越低越集中于高值）
     * @return array 概率数组（和为1）
     */
    public function calculate_softmax_probabilities($values, $temperature = 1.0) {
        if (empty($values)) {
            return array();
        }
        
        // 防止温度为0
        if ($temperature <= 0) {
            $temperature = 0.01;
        }
        
        // 为了数值稳定性，减去最大值
        $max_value = max($values);
        
        // 计算 exp(x_i / T)
        $exp_values = array();
        foreach ($values as $value) {
            $exp_values[] = exp(($value - $max_value) / $temperature);
        }
        
        // 计算总和
        $sum = array_sum($exp_values);
        
        // 防止除以零
        if ($sum <= 0) {
            // 返回均匀分布
            $count = count($values);
            return array_fill(0, $count, 1.0 / $count);
        }
        
        // 计算概率
        $probabilities = array();
        foreach ($exp_values as $exp_value) {
            $probabilities[] = $exp_value / $sum;
        }
        
        return $probabilities;
    }
    
    /**
     * 根据概率进行加权随机选择
     * 
     * @param array $probabilities 概率数组
     * @return int 选中的索引
     */
    private function weighted_random_select($probabilities) {
        if (empty($probabilities)) {
            return 0;
        }
        
        $random = mt_rand() / mt_getrandmax();
        $cumulative = 0;
        
        foreach ($probabilities as $index => $probability) {
            $cumulative += $probability;
            if ($random <= $cumulative) {
                return $index;
            }
        }
        
        // 由于浮点精度问题，可能到达这里，返回最后一个
        return count($probabilities) - 1;
    }

    
    /**
     * 为候选结构添加受欢迎度指数
     * 
     * @param array $candidates 候选结构列表
     * @param array $popularity_indices 受欢迎度指数 [structure_id => index]
     * @return array 带受欢迎度的候选结构
     */
    private function enrich_candidates_with_popularity($candidates, $popularity_indices) {
        $enriched = array();
        
        foreach ($candidates as $candidate) {
            $structure_id = isset($candidate['id']) ? $candidate['id'] : 0;
            $candidate['popularity_index'] = isset($popularity_indices[$structure_id]) 
                ? $popularity_indices[$structure_id] 
                : 100; // 默认值
            $enriched[] = $candidate;
        }
        
        return $enriched;
    }
    
    /**
     * 应用批量多样性惩罚
     * 
     * 当一个结构在同批次中使用超过25%时，应用0.3x惩罚
     * 
     * @param array $candidates 候选结构
     * @param int $task_id 任务ID
     * @return array 调整后的候选结构
     */
    public function apply_batch_diversity_penalty($candidates, $task_id) {
        if (empty($candidates) || empty($task_id)) {
            return $candidates;
        }
        
        // 获取批量使用统计
        $batch_usage = $this->get_batch_usage_stats($task_id);
        
        if (empty($batch_usage)) {
            return $candidates;
        }
        
        // 获取配置
        $threshold = $this->config->get_float('batch_diversity_threshold', 0.25);
        $penalty = $this->config->get_float('batch_diversity_penalty', 0.3);
        
        // 计算总使用次数
        $total_usage = array_sum($batch_usage);
        
        if ($total_usage <= 0) {
            return $candidates;
        }
        
        // 应用惩罚
        $adjusted = array();
        foreach ($candidates as $candidate) {
            $structure_id = isset($candidate['id']) ? $candidate['id'] : 0;
            $usage_count = isset($batch_usage[$structure_id]) ? $batch_usage[$structure_id] : 0;
            $usage_ratio = $usage_count / $total_usage;
            
            // 如果使用比例超过阈值，应用惩罚
            if ($usage_ratio > $threshold) {
                $candidate['popularity_index'] = $candidate['popularity_index'] * $penalty;
                $candidate['batch_penalty_applied'] = true;
            }
            
            $candidate['batch_usage_count'] = $usage_count;
            $candidate['batch_usage_ratio'] = $usage_ratio;
            $adjusted[] = $candidate;
        }
        
        return $adjusted;
    }
    
    /**
     * 获取批量使用统计
     * 
     * @param int $task_id 任务ID
     * @return array [structure_id => usage_count]
     */
    private function get_batch_usage_stats($task_id) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        $tasks_table = $wpdb->prefix . 'yali_ai_writer_article_tasks';
        
        // 🚀 性能优化：避免在 JOIN 中使用 JSON_CONTAINS，这在大数据量下会导致全表扫描和 CPU 飙升
        // 第一步：获取该任务关联的所有主题 ID
        $topic_ids_json = $wpdb->get_var($wpdb->prepare(
            "SELECT topic_ids FROM {$tasks_table} WHERE id = %d",
            $task_id
        ));
        
        if (empty($topic_ids_json)) {
            return array();
        }
        
        $topic_ids = json_decode($topic_ids_json, true);
        if (empty($topic_ids) || !is_array($topic_ids)) {
            return array();
        }
        
        // 转换为整数数组并序列化为 IN 子句
        $topic_ids = array_map('intval', $topic_ids);
        $topic_ids_str = implode(',', $topic_ids);
        
        // 第二步：直接从 topics 表查询这些 ID 的结构使用统计
        $results = $wpdb->get_results("
            SELECT used_structure_id, COUNT(*) as usage_count
            FROM {$topics_table}
            WHERE id IN ({$topic_ids_str})
            AND used_structure_id IS NOT NULL
            GROUP BY used_structure_id
        ", ARRAY_A);
        
        $stats = array();
        if ($results) {
            foreach ($results as $row) {
                $stats[(int)$row['used_structure_id']] = (int)$row['usage_count'];
            }
        }
        
        return $stats;
    }
    
    /**
     * 记录选择结果到 topics 表
     * 
     * @param int $topic_id 主题ID
     * @param int $structure_id 选中的结构ID
     * @param string $method 选择方法
     * @param float $weight 选择权重
     * @return bool 是否成功
     */
    public function record_selection($topic_id, $structure_id, $method, $weight) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        $result = $wpdb->update(
            $topics_table,
            array(
                'used_structure_id' => $structure_id,
                'selection_method' => $method,
                'selection_weight' => $weight
            ),
            array('id' => $topic_id),
            array('%d', '%s', '%f'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * 回退到原始随机选择
     * 
     * @param array $candidates 候选结构
     * @return array [structure => array, method => string, weight => float]
     */
    public function fallback_random_select($candidates) {
        if (empty($candidates)) {
            return array(
                'structure' => null,
                'method' => self::METHOD_FALLBACK,
                'weight' => 0
            );
        }
        
        // 从 top-20 中随机选择
        $top_candidates = array_slice($candidates, 0, min(20, count($candidates)));
        $selected_index = array_rand($top_candidates);
        $selected = $top_candidates[$selected_index];
        
        return array(
            'structure' => $selected,
            'method' => self::METHOD_FALLBACK,
            'weight' => isset($selected['popularity_index']) ? $selected['popularity_index'] : 100
        );
    }
    
    /**
     * 记录选择决策日志
     * 
     * @param array $topic_data 主题数据
     * @param array $result 选择结果
     * @param int|null $task_id 任务ID
     */
    private function log_selection_decision($topic_data, $result, $task_id = null) {
        if (!$this->logger) {
            return;
        }
        
        $structure = $result['structure'];
        
        $this->logger->info('智能结构选择完成', array(
            'topic_id' => isset($topic_data['id']) ? $topic_data['id'] : null,
            'content_angle' => isset($topic_data['source_angle']) ? $topic_data['source_angle'] : null,
            'structure_id' => $structure ? (isset($structure['id']) ? $structure['id'] : null) : null,
            'selection_method' => $result['method'],
            'selection_weight' => $result['weight'],
            'task_id' => $task_id,
            'batch_usage_count' => $structure && isset($structure['batch_usage_count']) 
                ? $structure['batch_usage_count'] : null,
            'batch_penalty_applied' => $structure && isset($structure['batch_penalty_applied']) 
                ? $structure['batch_penalty_applied'] : false
        ));
    }
    
    /**
     * 安全选择结构（带错误处理）
     * 
     * @param array $topic_data 主题数据
     * @param array $candidates 候选结构
     * @param int|null $task_id 任务ID
     * @return array 选择结果
     */
    public function select_structure_safe($topic_data, $candidates, $task_id = null) {
        try {
            return $this->select_structure($topic_data, $candidates, $task_id);
        } catch (Exception $e) {
            // 记录错误
            if ($this->logger) {
                $this->logger->error('智能结构选择失败', array(
                    'error' => $e->getMessage(),
                    'topic_id' => isset($topic_data['id']) ? $topic_data['id'] : null,
                    'task_id' => $task_id
                ));
            }
            
            // 回退到原始随机选择
            return $this->fallback_random_select($candidates);
        }
    }
    
    /**
     * 获取选择统计信息
     * 
     * @param string $content_angle 内容角度
     * @param int $days 统计天数
     * @return array 统计信息
     */
    public function get_selection_statistics($content_angle, $days = 7) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'yali_ai_writer_topics';
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                selection_method,
                COUNT(*) as count,
                AVG(selection_weight) as avg_weight
            FROM {$topics_table}
            WHERE source_angle = %s
            AND selection_method IS NOT NULL
            AND updated_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY selection_method
        ", $content_angle, $days), ARRAY_A);
        
        $stats = array(
            'total' => 0,
            'by_method' => array()
        );
        
        if ($results) {
            foreach ($results as $row) {
                $method = $row['selection_method'];
                $count = (int) $row['count'];
                $stats['by_method'][$method] = array(
                    'count' => $count,
                    'avg_weight' => (float) $row['avg_weight']
                );
                $stats['total'] += $count;
            }
        }
        
        return $stats;
    }
}
