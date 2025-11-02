<?php
/**
 * 分类向量缓存管理器
 * 负责获取、缓存和匹配WordPress最子级分类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_CategoryVectorManager {
    
    private $cache_file;
    private $vector_handler;
    private $logger;
    
    public function __construct($logger = null) {
        $this->cache_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cache/category_vectors.json';
        
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }
        $this->vector_handler = new ContentAuto_VectorApiHandler($logger);
        $this->logger = $logger;
        
        // 确保缓存目录存在并具有正确权限
        $cache_dir = dirname($this->cache_file);
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            // 设置目录权限为755
            if (file_exists($cache_dir)) {
                chmod($cache_dir, 0755);
            }
        }
        
        // 检查目录是否可写
        if (!is_writable($cache_dir)) {
            if ($this->logger) {
                $this->logger->log('缓存目录不可写，尝试修复权限: ' . $cache_dir, 'WARNING');
            }
            // 尝试修复权限
            @chmod($cache_dir, 0755);
        }
    }
    
    /**
     * 获取最子级分类（没有子分类的分类）
     */
    private function get_leaf_categories() {
        // 使用分类过滤器获取允许的分类
        if (class_exists('ContentAuto_Category_Filter')) {
            $all_categories = ContentAuto_Category_Filter::get_filtered_categories(array(
                'hide_empty' => false,
                'number' => 0
            ));
        } else {
            $all_categories = get_categories(array(
                'hide_empty' => false,
                'number' => 0
            ));
        }
        
        $leaf_categories = array();
        
        foreach ($all_categories as $category) {
            // 检查是否有子分类（在过滤后的分类中检查）
            $children = array();
            if (class_exists('ContentAuto_Category_Filter')) {
                $filtered_categories = ContentAuto_Category_Filter::get_filtered_categories(array(
                    'parent' => $category->term_id,
                    'hide_empty' => false,
                    'number' => 1
                ));
                $children = $filtered_categories;
            } else {
                $children = get_categories(array(
                    'parent' => $category->term_id,
                    'hide_empty' => false,
                    'number' => 1
                ));
            }
            
            // 如果没有子分类，则为最子级分类
            if (empty($children)) {
                $leaf_categories[] = array(
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description
                );
            }
        }
        
        return $leaf_categories;
    }
    
    /**
     * 获取分类向量缓存（长期保存，手工更新）
     */
    public function get_category_vectors() {
        // 检查缓存是否存在
        if (file_exists($this->cache_file)) {
            $cached_data = json_decode(file_get_contents($this->cache_file), true);
            if ($cached_data && isset($cached_data['categories'])) {
                return $cached_data['categories'];
            }
        }
        
        // 如果缓存不存在，生成新缓存
        return $this->generate_category_vectors();
    }
    
    /**
     * 生成分类向量缓存
     */
    public function generate_category_vectors() {
        // 首先检查向量API配置
        if (!class_exists('ContentAuto_ApiConfig')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-config.php';
        }
        
        $api_config = new ContentAuto_ApiConfig();
        $vector_config = $api_config->get_vector_config();
        
        if (empty($vector_config)) {
            if ($this->logger) {
                $this->logger->log('没有找到向量API配置，请先配置向量API', 'ERROR');
            }
            return array();
        }
        
        if ($this->logger) {
            $this->logger->log('找到向量API配置: ' . $vector_config['name'], 'INFO');
        }
        
        $leaf_categories = $this->get_leaf_categories();
        
        if (empty($leaf_categories)) {
            if ($this->logger) {
                $this->logger->log('没有找到最子级分类', 'WARNING');
            }
            return array();
        }
        
        // 组合分类名称和描述用于批量向量化
        $category_texts = $this->prepare_combined_category_texts($leaf_categories);
        
        if ($this->logger) {
            $this->logger->log('开始生成分类向量，共 ' . count($category_texts) . ' 个分类', 'INFO');
        }
        
        // 分批生成向量（API限制最大批量大小为32）
        $batch_size = 30; // 设置为30，留点余量
        $all_category_vectors = array();
        
        if ($this->logger) {
            $this->logger->log('开始分批生成向量，总分类数: ' . count($category_texts) . '，批次大小: ' . $batch_size, 'INFO');
        }
        
        for ($i = 0; $i < count($category_texts); $i += $batch_size) {
            $batch_texts = array_slice($category_texts, $i, $batch_size);
            $batch_categories = array_slice($leaf_categories, $i, $batch_size);
            
            if ($this->logger) {
                $this->logger->log('处理第 ' . (floor($i / $batch_size) + 1) . ' 批，包含 ' . count($batch_texts) . ' 个分类', 'INFO');
            }
            
            $result = $this->vector_handler->generate_embeddings_batch($batch_texts);
            
            if ($result === false || !isset($result['embeddings'])) {
                $error_msg = $this->vector_handler->get_last_error() ?: '未知错误';
                if ($this->logger) {
                    $this->logger->log('第 ' . (floor($i / $batch_size) + 1) . ' 批向量生成失败: ' . $error_msg, 'ERROR');
                }
                continue; // 继续处理下一批
            }
            
            // 处理当前批次的结果
            foreach ($result['embeddings'] as $embedding_data) {
                $index = $embedding_data['index'];
                if (isset($batch_categories[$index])) {
                    $all_category_vectors[] = array(
                        'id' => $batch_categories[$index]['id'],
                        'name' => $batch_categories[$index]['name'],
                        'slug' => $batch_categories[$index]['slug'],
                        'vector' => $embedding_data['embedding']
                    );
                }
            }
            
            if ($this->logger) {
                $this->logger->log('第 ' . (floor($i / $batch_size) + 1) . ' 批处理完成，成功生成 ' . count($result['embeddings']) . ' 个向量', 'INFO');
            }
        }
        
        // 使用分批处理后的结果
        $category_vectors = $all_category_vectors;
        
        if (empty($category_vectors)) {
            if ($this->logger) {
                $this->logger->log('所有批次都失败，无法生成分类向量', 'ERROR');
            }
            return array();
        }
        
        // 保存缓存（长期保存）
        $cache_data = array(
            'generated_at' => time(),
            'category_count' => count($category_vectors),
            'categories' => $category_vectors
        );
        
        if ($this->logger) {
            $this->logger->log('准备写入缓存文件: ' . $this->cache_file, 'INFO');
        }
        
        $write_result = file_put_contents($this->cache_file, json_encode($cache_data, JSON_UNESCAPED_UNICODE));
        
        if ($write_result === false) {
            // 尝试修复权限后重试
            $cache_dir = dirname($this->cache_file);
            @chmod($cache_dir, 0755);
            
            $write_result = file_put_contents($this->cache_file, json_encode($cache_data, JSON_UNESCAPED_UNICODE));
            
            if ($write_result === false) {
                if ($this->logger) {
                    $this->logger->log('缓存文件写入失败，请检查目录权限: ' . $this->cache_file . ' (目录: ' . $cache_dir . ')', 'ERROR');
                }
                return array();
            }
        }
        
        // 设置文件权限
        if (file_exists($this->cache_file)) {
            @chmod($this->cache_file, 0644);
        }
        
        if ($this->logger) {
            $this->logger->log('分类向量缓存已生成，共 ' . count($category_vectors) . ' 个分类', 'INFO');
        }
        
        return $category_vectors;
    }

/**
     * 组合分类名称和描述用于向量计算
     * @param array $categories 分类数组
     * @return array 组合后的文本数组
     */
    private function prepare_combined_category_texts($categories) {
        $combined_texts = [];
        
        foreach ($categories as $category) {
            $parts = [];
            
            // 添加分类名称
            if (!empty($category['name'])) {
                $parts[] = $category['name'];
            }
            
            // 添加分类描述
            if (!empty($category['description'])) {
                $parts[] = $category['description'];
            }
            
            // 组合所有文本
            $combined_text = implode(' ', $parts);
            
            $combined_texts[] = $combined_text;
        }
        
        return $combined_texts;
    }
    
    /**
     * 为主题向量找到最匹配的分类（仅返回一个最相似的）
     */
    public function find_best_matching_category($topic_vector_base64) {
        $category_vectors = $this->get_category_vectors();
        
        if (empty($category_vectors)) {
            return null;
        }
        
        // 解压主题向量
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/functions.php';
        $topic_vector = content_auto_decompress_vector_from_base64($topic_vector_base64);
        
        if ($topic_vector === false) {
            if ($this->logger) {
                $this->logger->log('主题向量解压失败', 'ERROR');
            }
            return null;
        }
        
        $best_match = null;
        $highest_similarity = -1;
        
        foreach ($category_vectors as $category_data) {
            $category_vector = content_auto_decompress_vector_from_base64($category_data['vector']);
            
            if ($category_vector === false) {
                continue;
            }
            
            $similarity = content_auto_calculate_cosine_similarity($topic_vector, $category_vector);
            
            if ($similarity > $highest_similarity) {
                $highest_similarity = $similarity;
                $best_match = array(
                    'id' => $category_data['id'],
                    'name' => $category_data['name'],
                    'slug' => $category_data['slug'],
                    'similarity' => $similarity
                );
            }
        }
        
        // 只有相似度超过阈值才返回匹配结果
        if ($best_match && $best_match['similarity'] > 0.3) {
            return $best_match;
        }
        
        return null;
    }
    
    /**
     * 手动刷新分类向量缓存
     */
    public function refresh_cache() {
        if (file_exists($this->cache_file)) {
            unlink($this->cache_file);
        }
        return $this->generate_category_vectors();
    }
    
    /**
     * 获取缓存状态信息
     */
    public function get_cache_status() {
        $status = array(
            'cache_exists' => file_exists($this->cache_file),
            'cache_time' => 0,
            'category_count' => 0
        );
        
        if ($status['cache_exists']) {
            $status['cache_time'] = filemtime($this->cache_file);
            
            $cached_data = json_decode(file_get_contents($this->cache_file), true);
            if ($cached_data && isset($cached_data['category_count'])) {
                $status['category_count'] = $cached_data['category_count'];
            }
        }
        
        return $status;
    }
}