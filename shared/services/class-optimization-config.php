<?php
/**
 * 智能文章结构优化系统 - 配置管理类
 * 
 * 负责管理智能结构优化功能的配置参数
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_OptimizationConfig {
    
    /**
     * 配置表名
     */
    private $table_name;
    
    /**
     * 配置缓存
     */
    private $cache = array();
    
    /**
     * 缓存是否已加载
     */
    private $cache_loaded = false;
    
    /**
     * 默认配置值
     */
    private static $defaults = array(
        'smart_optimization_enabled' => '0',
        'exploration_rate' => '0.3',
        'softmax_temperature' => '1.0',
        'visit_threshold_percentile' => '80',
        'batch_diversity_threshold' => '0.25',
        'batch_diversity_penalty' => '0.3',
        'window_diversity_threshold' => '0.30',
        'window_diversity_penalty' => '0.3',
        'new_structure_boost' => '2.0',
        'new_structure_boost_uses' => '5',
        'min_entropy_threshold' => '1.5',
        'analysis_schedule_hour' => '3',
        'min_articles_for_analysis' => '10',
        'min_days_published' => '7',
        'max_articles_per_angle' => '5',
        'time_decay_30_days' => '1.0',
        'time_decay_30_90_days' => '0.7',
        'time_decay_90_plus_days' => '0.4',
        'confidence_min_articles' => '3',
        // 淘汰机制配置
        'max_structures_per_angle' => '20',      // 每个内容角度最大结构数量
        'structure_min_age_days' => '30',        // 结构最小存活天数（新结构保护期）
        'structure_retire_enabled' => '1',       // 是否启用淘汰机制
        // 高表现文章识别配置
        'max_high_performers_per_angle' => '50', // 每个角度最多识别的高表现文章数量
        'max_days_for_analysis' => '90'          // 分析文章的时间窗口上限（天）
    );
    
    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'content_auto_optimization_config';
    }
    
    /**
     * 获取配置值
     * 
     * @param string $key 配置键名
     * @param mixed $default 默认值（如果未提供则使用内置默认值）
     * @return mixed 配置值
     */
    public function get_config($key, $default = null) {
        // 确保缓存已加载
        $this->load_cache();
        
        // 从缓存获取
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        
        // 返回默认值
        if ($default !== null) {
            return $default;
        }
        
        // 返回内置默认值
        return isset(self::$defaults[$key]) ? self::$defaults[$key] : null;
    }
    
    /**
     * 获取配置值（类型转换为浮点数）
     * 
     * @param string $key 配置键名
     * @param float $default 默认值
     * @return float 配置值
     */
    public function get_float($key, $default = 0.0) {
        $value = $this->get_config($key, $default);
        return (float) $value;
    }
    
    /**
     * 获取配置值（类型转换为整数）
     * 
     * @param string $key 配置键名
     * @param int $default 默认值
     * @return int 配置值
     */
    public function get_int($key, $default = 0) {
        $value = $this->get_config($key, $default);
        return (int) $value;
    }
    
    /**
     * 获取配置值（类型转换为布尔值）
     * 
     * @param string $key 配置键名
     * @param bool $default 默认值
     * @return bool 配置值
     */
    public function get_bool($key, $default = false) {
        $value = $this->get_config($key, $default ? '1' : '0');
        return $value === '1' || $value === 'true' || $value === true || $value === 1;
    }

    
    /**
     * 设置配置值
     * 
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @return bool 是否成功
     */
    public function set_config($key, $value) {
        global $wpdb;
        
        // 转换值为字符串
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else {
            $value = (string) $value;
        }
        
        // 检查配置是否存在
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE config_key = %s",
            $key
        ));
        
        if ($exists) {
            // 更新现有配置
            $result = $wpdb->update(
                $this->table_name,
                array('config_value' => $value),
                array('config_key' => $key),
                array('%s'),
                array('%s')
            );
        } else {
            // 插入新配置
            $result = $wpdb->insert(
                $this->table_name,
                array(
                    'config_key' => $key,
                    'config_value' => $value
                ),
                array('%s', '%s')
            );
        }
        
        // 更新缓存
        if ($result !== false) {
            $this->cache[$key] = $value;
            return true;
        }
        
        return false;
    }
    
    /**
     * 批量设置配置值
     * 
     * @param array $configs 配置数组 [key => value]
     * @return int 成功更新的配置数量
     */
    public function set_configs($configs) {
        $success_count = 0;
        
        foreach ($configs as $key => $value) {
            if ($this->set_config($key, $value)) {
                $success_count++;
            }
        }
        
        return $success_count;
    }
    
    /**
     * 获取所有配置
     * 
     * @return array 所有配置 [key => value]
     */
    public function get_all_configs() {
        $this->load_cache();
        
        // 合并默认值和数据库值
        return array_merge(self::$defaults, $this->cache);
    }
    
    /**
     * 删除配置
     * 
     * @param string $key 配置键名
     * @return bool 是否成功
     */
    public function delete_config($key) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->table_name,
            array('config_key' => $key),
            array('%s')
        );
        
        if ($result !== false) {
            unset($this->cache[$key]);
            return true;
        }
        
        return false;
    }
    
    /**
     * 重置配置为默认值
     * 
     * @param string|null $key 配置键名，为null时重置所有配置
     * @return bool 是否成功
     */
    public function reset_to_default($key = null) {
        if ($key !== null) {
            // 重置单个配置
            if (isset(self::$defaults[$key])) {
                return $this->set_config($key, self::$defaults[$key]);
            }
            return false;
        }
        
        // 重置所有配置
        $success = true;
        foreach (self::$defaults as $k => $v) {
            if (!$this->set_config($k, $v)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * 加载配置缓存
     */
    private function load_cache() {
        if ($this->cache_loaded) {
            return;
        }
        
        global $wpdb;
        
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") == $this->table_name;
        if (!$table_exists) {
            $this->cache_loaded = true;
            return;
        }
        
        // 加载所有配置
        $results = $wpdb->get_results(
            "SELECT config_key, config_value FROM {$this->table_name}",
            ARRAY_A
        );
        
        if ($results) {
            foreach ($results as $row) {
                $this->cache[$row['config_key']] = $row['config_value'];
            }
        }
        
        $this->cache_loaded = true;
    }
    
    /**
     * 清除缓存
     */
    public function clear_cache() {
        $this->cache = array();
        $this->cache_loaded = false;
    }
    
    /**
     * 检查智能优化功能是否启用
     * 
     * @return bool 是否启用
     */
    public function is_optimization_enabled() {
        return $this->get_bool('smart_optimization_enabled', false);
    }
    
    /**
     * 获取探索率
     * 
     * @return float 探索率 (0.0 - 1.0)
     */
    public function get_exploration_rate() {
        return $this->get_float('exploration_rate', 0.3);
    }
    
    /**
     * 获取 Softmax 温度参数
     * 
     * @return float 温度参数
     */
    public function get_softmax_temperature() {
        return $this->get_float('softmax_temperature', 1.0);
    }
    
    /**
     * 获取时间衰减因子
     * 
     * @param int $days_ago 距今天数
     * @return float 衰减因子
     */
    public function get_time_decay_factor($days_ago) {
        if ($days_ago <= 30) {
            return $this->get_float('time_decay_30_days', 1.0);
        } elseif ($days_ago <= 90) {
            return $this->get_float('time_decay_30_90_days', 0.7);
        } else {
            return $this->get_float('time_decay_90_plus_days', 0.4);
        }
    }
    
    /**
     * 获取默认配置值
     * 
     * @return array 默认配置
     */
    public static function get_defaults() {
        return self::$defaults;
    }
    
    /**
     * 验证配置值
     * 
     * @param string $key 配置键名
     * @param mixed $value 配置值
     * @return array 验证结果 ['valid' => bool, 'message' => string]
     */
    public function validate_config($key, $value) {
        $validators = array(
            'exploration_rate' => function($v) {
                $f = (float) $v;
                return $f >= 0 && $f <= 1 ? true : '探索率必须在 0 到 1 之间';
            },
            'softmax_temperature' => function($v) {
                $f = (float) $v;
                return $f > 0 ? true : '温度参数必须大于 0';
            },
            'visit_threshold_percentile' => function($v) {
                $i = (int) $v;
                return $i >= 1 && $i <= 100 ? true : '百分位数必须在 1 到 100 之间';
            },
            'batch_diversity_threshold' => function($v) {
                $f = (float) $v;
                return $f >= 0 && $f <= 1 ? true : '批量多样性阈值必须在 0 到 1 之间';
            },
            'batch_diversity_penalty' => function($v) {
                $f = (float) $v;
                return $f >= 0 && $f <= 1 ? true : '批量多样性惩罚必须在 0 到 1 之间';
            },
            'window_diversity_threshold' => function($v) {
                $f = (float) $v;
                return $f >= 0 && $f <= 1 ? true : '窗口多样性阈值必须在 0 到 1 之间';
            },
            'window_diversity_penalty' => function($v) {
                $f = (float) $v;
                return $f >= 0 && $f <= 1 ? true : '窗口多样性惩罚必须在 0 到 1 之间';
            },
            'new_structure_boost' => function($v) {
                $f = (float) $v;
                return $f >= 1 ? true : '新结构提升系数必须大于等于 1';
            },
            'new_structure_boost_uses' => function($v) {
                $i = (int) $v;
                return $i >= 1 ? true : '新结构提升次数必须大于等于 1';
            },
            'min_entropy_threshold' => function($v) {
                $f = (float) $v;
                return $f >= 0 ? true : '最小熵阈值必须大于等于 0';
            },
            'analysis_schedule_hour' => function($v) {
                $i = (int) $v;
                return $i >= 0 && $i <= 23 ? true : '分析计划时间必须在 0 到 23 之间';
            },
            'min_articles_for_analysis' => function($v) {
                $i = (int) $v;
                return $i >= 1 ? true : '最小分析文章数必须大于等于 1';
            },
            'min_days_published' => function($v) {
                $i = (int) $v;
                return $i >= 1 ? true : '最小发布天数必须大于等于 1';
            },
            'max_articles_per_angle' => function($v) {
                $i = (int) $v;
                return $i >= 1 ? true : '每角度最大文章数必须大于等于 1';
            },
            'confidence_min_articles' => function($v) {
                $i = (int) $v;
                return $i >= 1 ? true : '置信度最小文章数必须大于等于 1';
            }
        );
        
        if (!isset($validators[$key])) {
            return array('valid' => true, 'message' => '');
        }
        
        $result = $validators[$key]($value);
        
        if ($result === true) {
            return array('valid' => true, 'message' => '');
        }
        
        return array('valid' => false, 'message' => $result);
    }
}
