<?php
/**
 * 智能文章结构优化系统 - 结构提取器
 * 
 * 负责从受欢迎文章中逆向提取结构特征，生成泛化的结构模板
 * 
 * @package ContentAuto
 * @subpackage Services
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_StructureExtractor {
    
    /**
     * 统一API处理器（用于AI结构泛化）
     */
    private $api_handler;
    
    /**
     * 向量API处理器（用于向量生成）
     */
    private $vector_handler;
    
    /**
     * 日志记录器
     */
    private $logger;
    
    /**
     * 配置管理器
     */
    private $config;
    
    /**
     * 向量生成最大重试次数
     */
    const MAX_VECTOR_RETRIES = 3;
    
    /**
     * 结构相似度阈值（用于合并判断）
     */
    const SIMILARITY_THRESHOLD = 0.85;
    
    /**
     * 构造函数
     * 
     * @param ContentAuto_PluginLogger|null $logger 日志记录器
     */
    public function __construct($logger = null) {
        $this->logger = $logger;
        
        // 初始化API处理器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
        $this->api_handler = new ContentAuto_UnifiedApiHandler($logger);
        
        // 初始化向量API处理器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        $this->vector_handler = new ContentAuto_VectorApiHandler($logger);
        
        // 初始化配置管理器
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-optimization-config.php';
        $this->config = new ContentAuto_OptimizationConfig();
    }
    
    /**
     * 从文章HTML中提取结构
     * 
     * @param int $post_id WordPress文章ID
     * @return array|false 提取的结构数据或失败
     */
    public function extract_from_article($post_id) {
        // 获取文章
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            $this->log_error('EXTRACT_FAILED', '文章不存在或未发布', array('post_id' => $post_id));
            return false;
        }
        
        // 获取文章内容
        $content = $post->post_content;
        if (empty($content)) {
            $this->log_error('EXTRACT_FAILED', '文章内容为空', array('post_id' => $post_id));
            return false;
        }
        
        // 解析标题层级结构
        $headings = $this->parse_headings($content);
        
        if (empty($headings)) {
            $this->log_error('EXTRACT_FAILED', '未找到有效的标题结构', array('post_id' => $post_id));
            return false;
        }
        
        // 获取文章的 content_angle
        $content_angle = $this->get_article_content_angle($post_id);
        if (empty($content_angle)) {
            $this->log_error('EXTRACT_FAILED', '无法获取文章的内容角度', array('post_id' => $post_id));
            return false;
        }
        
        // 构建提取结果
        $extracted = array(
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'content_angle' => $content_angle,
            'headings' => $headings,
            'heading_count' => count($headings),
            'extracted_at' => current_time('mysql')
        );
        
        $this->log_info('EXTRACT_SUCCESS', '成功提取文章结构', array(
            'post_id' => $post_id,
            'heading_count' => count($headings),
            'content_angle' => $content_angle
        ));
        
        return $extracted;
    }
    
    /**
     * 解析HTML内容中的标题层级
     * 
     * @param string $content HTML内容
     * @return array 标题结构数组
     */
    private function parse_headings($content) {
        $headings = array();
        
        // 匹配 H2 和 H3 标签
        $pattern = '/<h([23])[^>]*>(.*?)<\/h\1>/is';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $level = (int) $match[1];
                $text = strip_tags($match[2]);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                $text = trim($text);
                
                if (!empty($text)) {
                    $headings[] = array(
                        'level' => $level,
                        'text' => $text
                    );
                }
            }
        }
        
        return $headings;
    }
    
    /**
     * 获取文章的 content_angle
     * 
     * @param int $post_id 文章ID
     * @return string|null content_angle 或 null
     */
    private function get_article_content_angle($post_id) {
        global $wpdb;
        
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        $articles_table = $wpdb->prefix . 'content_auto_articles';
        
        // 通过 articles 表关联 topics 表获取 source_angle
        $content_angle = $wpdb->get_var($wpdb->prepare("
            SELECT t.source_angle 
            FROM {$articles_table} a
            INNER JOIN {$topics_table} t ON a.topic_id = t.id
            WHERE a.post_id = %d
            LIMIT 1
        ", $post_id));
        
        return $content_angle;
    }
    
    /**
     * 调用AI生成泛化结构模板
     * 
     * @param array $extracted_structure 提取的原始结构
     * @param string $content_angle 内容角度
     * @return array|false 泛化后的结构模板或失败
     */
    public function generalize_structure($extracted_structure, $content_angle) {
        // 构建提示词
        $prompt = $this->build_generalization_prompt($extracted_structure, $content_angle);
        
        // 调用AI API
        $result = $this->api_handler->generate_content($prompt, 'structure_generation', array(
            'content_angle' => $content_angle,
            'source_article_id' => $extracted_structure['post_id']
        ));
        
        // 检查错误
        if (is_array($result) && isset($result['error'])) {
            $this->log_error('GENERALIZE_FAILED', 'AI API调用失败', array(
                'error' => $result['error'],
                'content_angle' => $content_angle
            ));
            return false;
        }
        
        // 解析AI返回的JSON
        $parsed = $this->parse_ai_response($result);
        
        if (!$parsed) {
            $this->log_error('GENERALIZE_FAILED', 'AI响应解析失败', array(
                'content_angle' => $content_angle,
                'response' => substr($result, 0, 500)
            ));
            return false;
        }
        
        // 构建泛化结构
        $generalized = array(
            'content_angle' => $content_angle,
            'title' => $parsed['title'],
            'structure' => $this->format_structure_xml($parsed['structure']),
            'source_article_id' => $extracted_structure['post_id'],
            'source_type' => 'data_driven',
            'extracted_at' => current_time('mysql')
        );
        
        $this->log_info('GENERALIZE_SUCCESS', '成功生成泛化结构', array(
            'content_angle' => $content_angle,
            'title' => $parsed['title'],
            'section_count' => count($parsed['structure'])
        ));
        
        return $generalized;
    }
    
    /**
     * 构建泛化提示词
     * 
     * @param array $extracted_structure 提取的结构
     * @param string $content_angle 内容角度
     * @return string 提示词
     */
    private function build_generalization_prompt($extracted_structure, $content_angle) {
        // 构建标题列表
        $headings_text = '';
        foreach ($extracted_structure['headings'] as $heading) {
            $prefix = $heading['level'] === 2 ? '## ' : '### ';
            $headings_text .= $prefix . $heading['text'] . "\n";
        }
        
        $prompt = <<<PROMPT
你是一位资深的内容结构设计专家。请基于以下从高表现文章中提取的结构，生成一个泛化的、可复用的文章结构模板。

## 原始文章信息
- 文章标题：{$extracted_structure['post_title']}
- 内容角度：{$content_angle}

## 提取的标题结构
{$headings_text}

## 任务要求
1. 分析上述标题结构的逻辑模式和组织规律
2. 将具体的标题内容泛化为通用的章节指导
3. 保持原有的逻辑顺序和层次关系
4. 生成的结构应该适用于同一内容角度下的其他主题

## 输出格式
请严格按照以下JSON格式输出，不要包含任何代码块标记：

{
    "title": "结构框架的标题（15-35字符，体现方法论特色）",
    "structure": [
        "第一章节的泛化指导",
        "第二章节的泛化指导",
        "..."
    ]
}

注意：
- title 应该是一个创新的框架名称，体现结构的方法论特色
- structure 数组中的每个元素应该是泛化后的章节指导，而非具体内容
- 章节数量应该在4-7个之间
- 直接输出JSON，不要包含```json标记
PROMPT;
        
        return $prompt;
    }
    
    /**
     * 解析AI响应
     * 
     * @param string $response AI响应内容
     * @return array|false 解析后的数据或失败
     */
    private function parse_ai_response($response) {
        if (empty($response) || !is_string($response)) {
            return false;
        }
        
        // 清理可能的代码块标记
        $response = trim($response);
        $response = preg_replace('/^```json\s*/i', '', $response);
        $response = preg_replace('/\s*```$/i', '', $response);
        $response = trim($response);
        
        // 尝试解析JSON
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // 验证必要字段
        if (!isset($data['title']) || !isset($data['structure'])) {
            return false;
        }
        
        // 验证 structure 是数组
        if (!is_array($data['structure']) || empty($data['structure'])) {
            return false;
        }
        
        return $data;
    }
    
    /**
     * 将结构数组格式化为XML格式
     * 
     * @param array $structure 结构数组
     * @return string XML格式的结构
     */
    private function format_structure_xml($structure) {
        $xml_parts = array();
        
        foreach ($structure as $section) {
            $section = htmlspecialchars($section, ENT_QUOTES, 'UTF-8');
            $xml_parts[] = '<section>' . $section . '</section>';
        }
        
        return implode('', $xml_parts);
    }

    
    /**
     * 准备结构向量文本
     * 组合 content_angle, title, 和从 structure 提取的语义文本
     * 
     * @param array $structure_data 结构数据
     * @return string 组合后的文本
     */
    public function prepare_structure_vector_text($structure_data) {
        $parts = array();
        
        // 1. 内容角度（权重最高，与主题的 source_angle 直接匹配）
        if (!empty($structure_data['content_angle'])) {
            $parts[] = $structure_data['content_angle'];
        }
        
        // 2. 结构标题（描述结构适用的主题类型）
        if (!empty($structure_data['title'])) {
            $parts[] = $structure_data['title'];
        }
        
        // 3. 结构语义文本（从 section 标签提取）
        if (!empty($structure_data['structure'])) {
            $section_text = $this->extract_section_text($structure_data['structure']);
            if (!empty($section_text)) {
                $parts[] = $section_text;
            }
        }
        
        return implode(' ', $parts);
    }
    
    /**
     * 从结构XML中提取section文本
     * 
     * @param string $structure_xml 结构XML字符串
     * @return string 提取的语义文本
     */
    private function extract_section_text($structure_xml) {
        // 使用正则提取 <section>...</section> 中的文本
        preg_match_all('/<section[^>]*>([^<]+)<\/section>/i', $structure_xml, $matches);
        
        if (!empty($matches[1])) {
            // 解码HTML实体
            $sections = array_map(function($text) {
                return html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8');
            }, $matches[1]);
            
            return implode(' ', $sections);
        }
        
        return '';
    }
    
    /**
     * 为结构生成向量
     * 
     * @param array $structure_data 结构数据
     * @return string|false Base64编码的向量或失败
     */
    public function generate_structure_vector($structure_data) {
        // 准备向量文本
        $vector_text = $this->prepare_structure_vector_text($structure_data);
        
        if (empty($vector_text)) {
            $this->log_error('VECTOR_FAILED', '向量文本为空', array(
                'structure_id' => isset($structure_data['id']) ? $structure_data['id'] : 'new'
            ));
            return false;
        }
        
        // 重试机制
        $last_error = null;
        for ($attempt = 1; $attempt <= self::MAX_VECTOR_RETRIES; $attempt++) {
            try {
                // 调用向量API
                $result = $this->vector_handler->generate_embeddings_batch(array($vector_text));
                
                if ($result && isset($result['embeddings'][0]['embedding'])) {
                    $embedding = $result['embeddings'][0]['embedding'];
                    
                    // 如果已经是base64字符串，直接返回
                    if (is_string($embedding)) {
                        $this->log_info('VECTOR_SUCCESS', '成功生成结构向量', array(
                            'attempt' => $attempt,
                            'text_length' => strlen($vector_text)
                        ));
                        return $embedding;
                    }
                    
                    // 如果是数组，转换为base64
                    if (is_array($embedding)) {
                        $binary = pack('f*', ...$embedding);
                        $base64 = base64_encode($binary);
                        
                        $this->log_info('VECTOR_SUCCESS', '成功生成结构向量', array(
                            'attempt' => $attempt,
                            'text_length' => strlen($vector_text),
                            'dimensions' => count($embedding)
                        ));
                        return $base64;
                    }
                }
                
                $last_error = $this->vector_handler->get_last_error();
                
            } catch (Exception $e) {
                $last_error = $e->getMessage();
            }
            
            // 如果不是最后一次尝试，等待后重试
            if ($attempt < self::MAX_VECTOR_RETRIES) {
                $delay = pow(2, $attempt - 1) * 1000; // 指数退避：1s, 2s
                usleep($delay * 1000);
            }
        }
        
        $this->log_error('VECTOR_FAILED', '向量生成失败，已达最大重试次数', array(
            'max_retries' => self::MAX_VECTOR_RETRIES,
            'last_error' => $last_error
        ));
        
        return false;
    }
    
    /**
     * 检查并合并相似结构，或创建新结构
     * 
     * @param array $new_structure 新提取的结构
     * @param string $content_angle 内容角度
     * @return int 结构ID（新建或已存在）
     */
    public function merge_or_create($new_structure, $content_angle) {
        global $wpdb;
        
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';
        
        // 首先生成新结构的向量
        $new_vector = $this->generate_structure_vector($new_structure);
        
        if (!$new_vector) {
            // 向量生成失败，仍然创建结构但不带向量
            $this->log_warning('MERGE_WARNING', '向量生成失败，将创建无向量结构', array(
                'content_angle' => $content_angle
            ));
        }
        
        // 获取同一 content_angle 下的现有结构
        $existing_structures = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, structure, title_vector 
             FROM {$structures_table} 
             WHERE content_angle = %s 
             AND title_vector IS NOT NULL 
             AND title_vector != ''",
            $content_angle
        ), ARRAY_A);
        
        // 如果有向量且有现有结构，检查相似度
        if ($new_vector && !empty($existing_structures)) {
            $new_vector_array = $this->decode_vector($new_vector);
            
            if ($new_vector_array) {
                foreach ($existing_structures as $existing) {
                    $existing_vector = $this->decode_vector($existing['title_vector']);
                    
                    if ($existing_vector) {
                        $similarity = $this->calculate_cosine_similarity($new_vector_array, $existing_vector);
                        
                        if ($similarity >= self::SIMILARITY_THRESHOLD) {
                            // 找到相似结构，更新而非创建
                            $this->log_info('MERGE_FOUND', '找到相似结构，将合并', array(
                                'existing_id' => $existing['id'],
                                'similarity' => $similarity,
                                'threshold' => self::SIMILARITY_THRESHOLD
                            ));
                            
                            // 更新现有结构的使用统计
                            $wpdb->query($wpdb->prepare(
                                "UPDATE {$structures_table} SET usage_count = usage_count + 1, updated_at = NOW() WHERE id = %d",
                                $existing['id']
                            ));
                            
                            return (int) $existing['id'];
                        }
                    }
                }
            }
        }
        
        // 没有找到相似结构，创建新结构
        $insert_data = array(
            'content_angle' => $content_angle,
            'title' => $new_structure['title'],
            'structure' => $new_structure['structure'],
            'title_vector' => $new_vector ? $new_vector : null,
            'usage_count' => 0,
            'source_type' => 'data_driven',
            'source_article_id' => isset($new_structure['source_article_id']) ? $new_structure['source_article_id'] : null,
            'extracted_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($structures_table, $insert_data);
        
        if ($result === false) {
            $this->log_error('CREATE_FAILED', '创建结构失败', array(
                'content_angle' => $content_angle,
                'error' => $wpdb->last_error
            ));
            return 0;
        }
        
        $new_id = $wpdb->insert_id;
        
        $this->log_info('CREATE_SUCCESS', '成功创建新结构', array(
            'structure_id' => $new_id,
            'content_angle' => $content_angle,
            'title' => $new_structure['title'],
            'has_vector' => !empty($new_vector)
        ));
        
        return $new_id;
    }
    
    /**
     * 解码Base64向量为数组
     * 
     * @param string $base64_vector Base64编码的向量
     * @return array|false 向量数组或失败
     */
    private function decode_vector($base64_vector) {
        if (empty($base64_vector)) {
            return false;
        }
        
        $binary = base64_decode($base64_vector, true);
        if ($binary === false) {
            return false;
        }
        
        $floats = unpack('f*', $binary);
        if ($floats === false) {
            return false;
        }
        
        return array_values($floats);
    }
    
    /**
     * 计算余弦相似度
     * 
     * @param array $vector1 向量1
     * @param array $vector2 向量2
     * @return float 相似度 (0-1)
     */
    private function calculate_cosine_similarity($vector1, $vector2) {
        if (count($vector1) !== count($vector2)) {
            return 0.0;
        }
        
        $dot_product = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        
        for ($i = 0; $i < count($vector1); $i++) {
            $dot_product += $vector1[$i] * $vector2[$i];
            $norm1 += $vector1[$i] * $vector1[$i];
            $norm2 += $vector2[$i] * $vector2[$i];
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0.0;
        }
        
        return $dot_product / ($norm1 * $norm2);
    }
    
    /**
     * 完整的结构提取流程
     * 从文章提取 -> AI泛化 -> 向量生成 -> 存储
     * 
     * @param int $post_id 文章ID
     * @return array|false 创建的结构信息或失败
     */
    public function extract_and_create_structure($post_id) {
        // 1. 从文章提取结构
        $extracted = $this->extract_from_article($post_id);
        if (!$extracted) {
            return false;
        }
        
        // 2. AI泛化结构
        $generalized = $this->generalize_structure($extracted, $extracted['content_angle']);
        if (!$generalized) {
            return false;
        }
        
        // 3. 合并或创建结构
        $structure_id = $this->merge_or_create($generalized, $extracted['content_angle']);
        if (!$structure_id) {
            return false;
        }
        
        return array(
            'structure_id' => $structure_id,
            'content_angle' => $extracted['content_angle'],
            'title' => $generalized['title'],
            'source_article_id' => $post_id
        );
    }
    
    /**
     * 从文章提取并保存结构（AJAX接口使用）
     * 
     * @param int $post_id 文章ID
     * @return array 结果数组 ['success' => bool, 'message' => string, 'structure_id' => int|null]
     */
    public function extract_and_save_from_article($post_id) {
        try {
            $result = $this->extract_and_create_structure($post_id);
            
            if ($result) {
                return array(
                    'success' => true,
                    'message' => '结构提取成功',
                    'structure_id' => $result['structure_id'],
                    'content_angle' => $result['content_angle'],
                    'title' => $result['title']
                );
            } else {
                return array(
                    'success' => false,
                    'message' => '结构提取失败，请检查文章是否有有效的标题结构',
                    'structure_id' => null
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => '提取过程出错: ' . $e->getMessage(),
                'structure_id' => null
            );
        }
    }
    
    /**
     * 批量处理高表现文章
     * 
     * @param string $content_angle 内容角度
     * @param int $limit 最大处理数量
     * @return array 处理结果
     */
    public function batch_extract_for_angle($content_angle, $limit = 5) {
        require_once dirname(__FILE__) . '/class-article-analyzer.php';
        $analyzer = new ContentAuto_ArticleAnalyzer($this->logger);
        
        // 获取未处理的高表现文章
        $articles = $analyzer->get_unprocessed_high_performers($content_angle, $limit);
        
        $results = array(
            'total' => count($articles),
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'details' => array()
        );
        
        foreach ($articles as $article) {
            $post_id = $article['post_id'];
            
            try {
                $result = $this->extract_and_create_structure($post_id);
                
                if ($result) {
                    $results['success']++;
                    $results['details'][] = array(
                        'post_id' => $post_id,
                        'status' => 'success',
                        'structure_id' => $result['structure_id']
                    );
                } else {
                    $results['failed']++;
                    $results['details'][] = array(
                        'post_id' => $post_id,
                        'status' => 'failed',
                        'error' => '提取或创建失败'
                    );
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][] = array(
                    'post_id' => $post_id,
                    'status' => 'failed',
                    'error' => $e->getMessage()
                );
            }
        }
        
        return $results;
    }
    
    /**
     * 记录信息日志
     */
    private function log_info($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->log($message, 'INFO', array_merge(array('code' => $code), $context));
        }
    }
    
    /**
     * 记录警告日志
     */
    private function log_warning($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->log($message, 'WARNING', array_merge(array('code' => $code), $context));
        }
    }
    
    /**
     * 记录错误日志
     */
    private function log_error($code, $message, $context = array()) {
        if ($this->logger) {
            $this->logger->log($message, 'ERROR', array_merge(array('code' => $code), $context));
        }
    }
}
