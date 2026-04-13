<?php
/**
 * 参考资料服务类
 * 负责处理参考资料的获取、召回和大模型精选逻辑
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_ReferenceMaterialService {
    
    /**
     * 向量相似度阈值 - 普通模式
     */
    const SIMILARITY_THRESHOLD_NORMAL = 0.8;
    
    /**
     * 向量相似度阈值 - 大模型精选模式（降低阈值以召回更多候选）
     */
    const SIMILARITY_THRESHOLD_AI_SELECT = 0.5;
    
    /**
     * 大模型精选召回数量
     */
    const AI_SELECT_CANDIDATE_COUNT = 10;
    
    /**
     * 日志记录器
     */
    private $logger;
    
    public function __construct() {
        if (!class_exists('Yali_AI_Writer_PluginLogger')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $this->logger = new Yali_AI_Writer_PluginLogger();
    }
    
    /**
     * 测试品牌资料召回（公开方法，用于调试）
     * 
     * @param array $topic_data 主题数据
     * @return array 测试结果
     */
    public function test_brand_profile_recall($topic_data) {
        $result = array(
            'topic_id' => $topic_data['id'],
            'topic_title' => $topic_data['title'],
            'has_vector' => !empty($topic_data['vector_embedding']),
            'candidates' => array(),
            'all_profiles_similarity' => array(), // 新增：显示所有参考资料的相似度
            'ai_selected' => null,
            'final_result' => null,
            'error' => null,
            'debug_info' => array() // 新增：调试信息
        );
        
        // 检查主题是否有向量
        if (empty($topic_data['vector_embedding'])) {
            $result['error'] = '主题没有向量嵌入，无法进行召回测试。请先确保主题已生成向量。';
            return $result;
        }
        
        $topic_vector = yali_ai_writer_decompress_vector_from_base64($topic_data['vector_embedding']);
        if (!$topic_vector) {
            $result['error'] = '主题向量解码失败';
            return $result;
        }
        
        $result['debug_info']['topic_vector_dimensions'] = count($topic_vector);
        
        // 获取所有参考资料类型的品牌资料
        $reference_profiles = $this->get_reference_type_profiles();
        if (empty($reference_profiles)) {
            $result['error'] = '没有找到参考资料类型的品牌资料。请先在品牌资料管理中添加类型为"参考资料"的条目。';
            return $result;
        }
        
        $result['debug_info']['total_reference_profiles'] = count($reference_profiles);
        
        // 计算所有参考资料的相似度（用于调试）
        $all_similarities = array();
        foreach ($reference_profiles as $profile) {
            $profile_vector = yali_ai_writer_decompress_vector_from_base64($profile['vector']);
            if (!$profile_vector) {
                $all_similarities[] = array(
                    'id' => $profile['id'],
                    'title' => $profile['title'],
                    'similarity' => null,
                    'error' => '向量解码失败'
                );
                continue;
            }
            
            $similarity = yali_ai_writer_calculate_cosine_similarity($topic_vector, $profile_vector);
            $all_similarities[] = array(
                'id' => $profile['id'],
                'title' => $profile['title'],
                'similarity' => round($similarity, 4),
                'meets_threshold' => $similarity >= self::SIMILARITY_THRESHOLD_AI_SELECT
            );
        }
        
        // 按相似度降序排序
        usort($all_similarities, function($a, $b) {
            if ($a['similarity'] === null) return 1;
            if ($b['similarity'] === null) return -1;
            return $b['similarity'] <=> $a['similarity'];
        });
        
        $result['all_profiles_similarity'] = $all_similarities;
        
        // 召回候选（使用大模型精选模式的阈值）
        $candidates = $this->recall_candidates($topic_vector, $reference_profiles);
        
        if (empty($candidates)) {
            $result['error'] = sprintf(
                /* translators: 1: Threshold value, 2: Total reference count */
                __('没有召回到任何候选参考资料。当前阈值为 %.1f，共有 %d 条参考资料，但没有一条的相似度达到阈值。请查看下方「所有参考资料相似度」了解详情。', 'yali-ai-writer'),
                self::SIMILARITY_THRESHOLD_AI_SELECT,
                count($reference_profiles)
            );
            $result['debug_info']['suggestion'] = __('建议：1) 检查参考资料标题是否与主题相关；2) 或者降低相似度阈值', 'yali-ai-writer');
            return $result;
        }
        
        // 记录候选列表
        $result['candidates'] = array_map(function($c) {
            return array(
                'id' => $c['id'],
                'title' => $c['title'],
                'similarity' => round($c['similarity'], 4),
                'description_preview' => mb_substr($c['description'], 0, 100) . (mb_strlen($c['description']) > 100 ? '...' : '')
            );
        }, $candidates);
        
        // 如果只有一个候选，直接返回
        if (count($candidates) === 1) {
            $result['ai_selected'] = array(
                'id' => $candidates[0]['id'],
                'title' => $candidates[0]['title'],
                'reason' => __('只有一个候选，无需大模型精选', 'yali-ai-writer')
            );
            $result['final_result'] = array(
                'id' => $candidates[0]['id'],
                'title' => $candidates[0]['title'],
                'description' => $candidates[0]['description']
            );
            return $result;
        }
        
        // 调用大模型进行精选
        $selected_id = $this->ai_select_best_reference($topic_data, $candidates);
        
        if ($selected_id === null) {
            // 大模型选择失败，回退到相似度最高的
            $result['ai_selected'] = array(
                'id' => $candidates[0]['id'],
                'title' => $candidates[0]['title'],
                'reason' => __('大模型精选失败，回退到相似度最高的候选', 'yali-ai-writer')
            );
            $result['final_result'] = array(
                'id' => $candidates[0]['id'],
                'title' => $candidates[0]['title'],
                'description' => $candidates[0]['description']
            );
        } else {
            $selected_profile = $this->get_profile_by_id($selected_id, $candidates);
            if ($selected_profile) {
                $result['ai_selected'] = array(
                    'id' => $selected_id,
                    'title' => $selected_profile['title'],
                    'reason' => __('大模型精选成功', 'yali-ai-writer')
                );
                $result['final_result'] = array(
                    'id' => $selected_profile['id'],
                    'title' => $selected_profile['title'],
                    'description' => $selected_profile['description']
                );
            } else {
                // ID无效，回退
                $result['ai_selected'] = array(
                    'id' => $candidates[0]['id'],
                    'title' => $candidates[0]['title'],
                    'reason' => __('大模型返回的ID无效，回退到相似度最高的候选', 'yali-ai-writer')
                );
                $result['final_result'] = array(
                    'id' => $candidates[0]['id'],
                    'title' => $candidates[0]['title'],
                    'description' => $candidates[0]['description']
                );
            }
        }
        
        return $result;
    }
    
    /**
     * 获取参考资料（主入口方法）
     * 按优先级：主题级 -> 规则级 -> 品牌资料召回
     * 
     * @param array $topic_data 主题数据
     * @param array $publish_rules 发布规则
     * @return string 参考资料内容
     */
    public function get_reference_material($topic_data, $publish_rules = array()) {
        // 1. 优先使用主题级参考资料
        if (isset($topic_data['reference_material']) && !empty(trim($topic_data['reference_material']))) {
            $this->logger->info('REFERENCE_MATERIAL_SOURCE', '使用主题级参考资料', array(
                'topic_id' => $topic_data['id'] ?? null,
                'source' => 'topic'
            ));
            return trim($topic_data['reference_material']);
        }
        
        // 2. 回退到规则级参考资料
        if (isset($topic_data['rule_id']) && !empty($topic_data['rule_id'])) {
            $rule_material = $this->get_rule_reference_material($topic_data['rule_id']);
            if (!empty($rule_material)) {
                $this->logger->info('REFERENCE_MATERIAL_SOURCE', '使用规则级参考资料', array(
                    'topic_id' => $topic_data['id'] ?? null,
                    'rule_id' => $topic_data['rule_id'],
                    'source' => 'rule'
                ));
                return $rule_material;
            }
        }

        // 3. 如果发布规则启用了参考资料功能，从品牌资料中获取
        if (isset($publish_rules['enable_reference_material']) && $publish_rules['enable_reference_material']) {
            return $this->get_brand_profile_reference_material($topic_data, $publish_rules);
        }

        return '';
    }
    
    /**
     * 从规则表获取参考资料
     * 
     * @param int $rule_id 规则ID
     * @return string 参考资料内容
     */
    private function get_rule_reference_material($rule_id) {
        global $wpdb;
        $rules_table = $wpdb->prefix . 'yali_ai_writer_rules';

        $rule_material = $wpdb->get_var($wpdb->prepare(
            "SELECT reference_material FROM {$rules_table} WHERE id = %d",
            $rule_id
        ));

        return $rule_material && !empty(trim($rule_material)) ? trim($rule_material) : '';
    }
    
    /**
     * 从品牌资料中获取参考资料
     * 
     * @param array $topic_data 主题数据
     * @param array $publish_rules 发布规则
     * @return string 参考资料内容
     */
    private function get_brand_profile_reference_material($topic_data, $publish_rules) {
        // 检查主题是否有向量
        if (empty($topic_data['vector_embedding'])) {
            $this->logger->warning('REFERENCE_MATERIAL_NO_VECTOR', '主题没有向量嵌入，无法进行品牌资料召回', array(
                'topic_id' => $topic_data['id'] ?? null
            ));
            return '';
        }

        $topic_vector = yali_ai_writer_decompress_vector_from_base64($topic_data['vector_embedding']);
        if (!$topic_vector) {
            $this->logger->error('REFERENCE_MATERIAL_VECTOR_DECODE_FAILED', '主题向量解码失败', array(
                'topic_id' => $topic_data['id'] ?? null
            ));
            return '';
        }

        // 获取所有参考资料类型的品牌资料
        $reference_profiles = $this->get_reference_type_profiles();
        if (empty($reference_profiles)) {
            $this->logger->info('REFERENCE_MATERIAL_NO_PROFILES', '没有找到参考资料类型的品牌资料', array(
                'topic_id' => $topic_data['id'] ?? null
            ));
            return '';
        }

        // 判断是否启用大模型精选召回
        $enable_ai_select = isset($publish_rules['enable_ai_reference_select']) && $publish_rules['enable_ai_reference_select'];
        
        if ($enable_ai_select) {
            return $this->get_reference_material_with_ai_select($topic_data, $topic_vector, $reference_profiles);
        } else {
            return $this->get_reference_material_by_similarity($topic_data, $topic_vector, $reference_profiles);
        }
    }
    
    /**
     * 获取参考资料类型的品牌资料
     * 
     * @return array 品牌资料列表
     */
    private function get_reference_type_profiles() {
        global $wpdb;
        $brand_profiles_table = $wpdb->prefix . 'yali_ai_writer_brand_profiles';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, description, vector FROM {$brand_profiles_table} WHERE type = %s AND vector IS NOT NULL AND vector != ''",
            'reference'
        ), ARRAY_A);
    }
    
    /**
     * 通过相似度匹配获取参考资料（原有逻辑）
     * 
     * @param array $topic_data 主题数据
     * @param array $topic_vector 主题向量
     * @param array $reference_profiles 参考资料列表
     * @return string 参考资料内容
     */
    private function get_reference_material_by_similarity($topic_data, $topic_vector, $reference_profiles) {
        $best_match = null;
        $highest_similarity = 0.0;

        foreach ($reference_profiles as $profile) {
            $profile_vector = yali_ai_writer_decompress_vector_from_base64($profile['vector']);
            if (!$profile_vector) {
                continue;
            }
            
            $similarity = yali_ai_writer_calculate_cosine_similarity($topic_vector, $profile_vector);

            if ($similarity >= self::SIMILARITY_THRESHOLD_NORMAL && $similarity > $highest_similarity) {
                $highest_similarity = $similarity;
                $best_match = $profile;
            }
        }

        if ($best_match) {
            $this->logger->info('REFERENCE_MATERIAL_MATCHED', '通过相似度匹配到参考资料', array(
                'topic_id' => $topic_data['id'] ?? null,
                'topic_title' => $topic_data['title'] ?? null,
                'matched_profile_id' => $best_match['id'],
                'matched_profile_title' => $best_match['title'],
                'similarity' => $highest_similarity,
                'mode' => 'similarity'
            ));
            return trim($best_match['description']);
        }

        $this->logger->info('REFERENCE_MATERIAL_NO_MATCH', '相似度匹配未找到合适的参考资料', array(
            'topic_id' => $topic_data['id'] ?? null,
            'threshold' => self::SIMILARITY_THRESHOLD_NORMAL,
            'mode' => 'similarity'
        ));
        return '';
    }

    
    /**
     * 通过大模型精选获取参考资料（增强逻辑）
     * 
     * @param array $topic_data 主题数据
     * @param array $topic_vector 主题向量
     * @param array $reference_profiles 参考资料列表
     * @return string 参考资料内容
     */
    private function get_reference_material_with_ai_select($topic_data, $topic_vector, $reference_profiles) {
        // 第一步：降低阈值召回前N个候选
        $candidates = $this->recall_candidates($topic_vector, $reference_profiles);
        
        if (empty($candidates)) {
            $this->logger->info('REFERENCE_MATERIAL_NO_CANDIDATES', '大模型精选模式下未召回到候选参考资料', array(
                'topic_id' => $topic_data['id'] ?? null,
                'threshold' => self::SIMILARITY_THRESHOLD_AI_SELECT,
                'mode' => 'ai_select'
            ));
            return '';
        }
        
        // 如果只有一个候选，直接返回
        if (count($candidates) === 1) {
            $this->logger->info('REFERENCE_MATERIAL_SINGLE_CANDIDATE', '只有一个候选参考资料，直接使用', array(
                'topic_id' => $topic_data['id'] ?? null,
                'profile_id' => $candidates[0]['id'],
                'profile_title' => $candidates[0]['title'],
                'mode' => 'ai_select'
            ));
            return trim($candidates[0]['description']);
        }
        
        // 第二步：调用大模型进行精选
        $selected_id = $this->ai_select_best_reference($topic_data, $candidates);
        
        if ($selected_id === null) {
            // 大模型选择失败，回退到相似度最高的
            $this->logger->warning('REFERENCE_MATERIAL_AI_SELECT_FALLBACK', '大模型精选失败，回退到相似度最高的候选', array(
                'topic_id' => $topic_data['id'] ?? null,
                'fallback_profile_id' => $candidates[0]['id'],
                'fallback_profile_title' => $candidates[0]['title']
            ));
            return trim($candidates[0]['description']);
        }
        
        // 第三步：根据选中的ID获取参考资料内容
        $selected_profile = $this->get_profile_by_id($selected_id, $candidates);
        
        if ($selected_profile) {
            $this->logger->info('REFERENCE_MATERIAL_AI_SELECTED', '大模型精选成功', array(
                'topic_id' => $topic_data['id'] ?? null,
                'topic_title' => $topic_data['title'] ?? null,
                'selected_profile_id' => $selected_profile['id'],
                'selected_profile_title' => $selected_profile['title'],
                'candidates_count' => count($candidates),
                'mode' => 'ai_select'
            ));
            return trim($selected_profile['description']);
        }
        
        // 选中的ID无效，回退到相似度最高的
        $this->logger->warning('REFERENCE_MATERIAL_INVALID_SELECTION', '大模型返回的ID无效，回退到相似度最高的候选', array(
            'topic_id' => $topic_data['id'] ?? null,
            'invalid_id' => $selected_id,
            'fallback_profile_id' => $candidates[0]['id']
        ));
        return trim($candidates[0]['description']);
    }
    
    /**
     * 召回候选参考资料
     * 
     * @param array $topic_vector 主题向量
     * @param array $reference_profiles 参考资料列表
     * @return array 候选列表（按相似度降序排列）
     */
    private function recall_candidates($topic_vector, $reference_profiles) {
        $scored_profiles = array();
        
        foreach ($reference_profiles as $profile) {
            $profile_vector = yali_ai_writer_decompress_vector_from_base64($profile['vector']);
            if (!$profile_vector) {
                continue;
            }
            
            $similarity = yali_ai_writer_calculate_cosine_similarity($topic_vector, $profile_vector);
            
            // 只保留超过阈值的
            if ($similarity >= self::SIMILARITY_THRESHOLD_AI_SELECT) {
                $scored_profiles[] = array(
                    'id' => $profile['id'],
                    'title' => $profile['title'],
                    'description' => $profile['description'],
                    'similarity' => $similarity
                );
            }
        }
        
        // 按相似度降序排序
        usort($scored_profiles, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        // 取前N个
        return array_slice($scored_profiles, 0, self::AI_SELECT_CANDIDATE_COUNT);
    }
    
    /**
     * 调用大模型精选最佳参考资料
     * 使用插件标准的统一API处理器（支持API轮询、失败重试、预置API备选）
     * 
     * @param array $topic_data 主题数据
     * @param array $candidates 候选参考资料列表
     * @return int|null 选中的参考资料ID，失败返回null
     */
    private function ai_select_best_reference($topic_data, $candidates) {
        // 构建提示词（从XML模板加载）
        $prompt = $this->build_ai_select_prompt($topic_data, $candidates);
        
        // 使用插件标准的统一API处理器调用大模型
        // 该处理器支持：API轮询机制、失败自动重试、预置API备选方案
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
        $api_handler = new Yali_AI_Writer_UnifiedApiHandler();
        
        $response_data = $api_handler->generate_content($prompt, 'reference_select', array(
            'topic_id' => $topic_data['id'] ?? null,
            'return_usage' => true // 加入 return_usage 用于检测是否被截断
        ));
        
        // 检查API调用是否成功（finish_reason == 'length' 已由底层自动触发 API 轮询切换）
        if (is_array($response_data) && isset($response_data['error'])) {
            $this->logger->error('REFERENCE_MATERIAL_AI_API_ERROR', '大模型API调用失败', array(
                'topic_id' => $topic_data['id'] ?? null,
                'error' => $response_data['error']
            ));
            return null;
        }
        
        // 兼容新结构 (因为设置了 return_usage，实际文本在 content 里)
        $response_text = is_array($response_data) && isset($response_data['content']) ? $response_data['content'] : $response_data;
        
        // 解析大模型返回的ID
        $selected_id = $this->parse_ai_response($response_text, $candidates);
        
        return $selected_id;
    }
    
    /**
     * 构建大模型精选提示词
     * 从XML模板文件加载并替换占位符
     * 
     * @param array $topic_data 主题数据
     * @param array $candidates 候选参考资料列表
     * @return string 提示词
     */
    private function build_ai_select_prompt($topic_data, $candidates) {
        $topic_title = $topic_data['title'] ?? '未知主题';
        
        // 构建候选列表文本
        $candidates_text = '';
        foreach ($candidates as $candidate) {
            $candidates_text .= sprintf(
                "    <candidate>\n      <id>%d</id>\n      <title>%s</title>\n      <similarity>%.2f</similarity>\n    </candidate>\n",
                $candidate['id'],
                htmlspecialchars($candidate['title']),
                $candidate['similarity']
            );
        }
        
        // 从XML模板文件加载提示词
        $template_path = YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/reference-select-prompt.xml';
        
        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        if (strpos($locale, 'zh') !== 0) {
            $en_template_path = YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/en/reference-select-prompt.xml';
            if (file_exists($en_template_path)) {
                $template_path = $en_template_path;
            }
        }
        
        if (file_exists($template_path)) {
            $prompt_template = file_get_contents($template_path);
            
            // 替换占位符
            $prompt = str_replace(
                array('{{TOPIC_TITLE}}', '{{CANDIDATES_LIST}}'),
                array(htmlspecialchars($topic_title), $candidates_text),
                $prompt_template
            );
            
            $this->logger->debug('REFERENCE_SELECT_PROMPT_LOADED', '从XML模板加载参考资料精选提示词', array(
                'template_path' => $template_path,
                'topic_title' => $topic_title,
                'candidates_count' => count($candidates)
            ));
            
            return $prompt;
        }
        
        // 如果模板文件不存在，使用内置的备用提示词
        $this->logger->warning('REFERENCE_SELECT_TEMPLATE_NOT_FOUND', '参考资料精选提示词模板文件不存在，使用备用模板', array(
            'template_path' => $template_path
        ));
        
        return $this->build_fallback_prompt($topic_title, $candidates_text);
    }
    
    /**
     * 构建备用提示词（当XML模板不存在时使用）
     * 
     * @param string $topic_title 主题标题
     * @param string $candidates_text 候选列表文本
     * @return string 提示词
     */
    private function build_fallback_prompt($topic_title, $candidates_text) {
        return "你是一个专业的内容匹配专家。你的任务是从候选参考资料中选择一个与文章主题最相关、最具参考价值的资料。\n\n" .
               "## 文章主题\n" .
               $topic_title . "\n\n" .
               "## 候选参考资料列表\n" .
               $candidates_text . "\n\n" .
               "## 选择标准\n" .
               "1. 主题相关性：参考资料的标题与文章主题在内容领域上的匹配程度\n" .
               "2. 参考价值：参考资料能为文章创作提供的实际帮助和信息补充\n" .
               "3. 内容互补性：参考资料能否为文章带来有价值的补充视角或信息\n\n" .
               "## 输出要求\n" .
               "**重要：你必须且只能输出一个数字，即你选择的参考资料的ID。不要输出任何其他内容、解释或标点符号。**\n\n" .
               "请输出你选择的参考资料ID：";
    }
    
    /**
     * 解析大模型返回的响应，提取选中的ID
     * 
     * @param string $response 大模型响应
     * @param array $candidates 候选列表（用于验证ID有效性）
     * @return int|null 选中的ID，无效返回null
     */
    private function parse_ai_response($response, $candidates) {
        if (empty($response) || !is_string($response)) {
            $this->logger->warning('REFERENCE_MATERIAL_PARSE_EMPTY', '大模型返回为空', array(
                'response' => $response
            ));
            return null;
        }
        
        // 清理响应文本
        $cleaned_response = trim($response);
        
        // 尝试多种方式提取ID
        $selected_id = null;
        
        // 方式1：直接是数字
        if (is_numeric($cleaned_response)) {
            $selected_id = intval($cleaned_response);
        }
        
        // 方式2：包含"ID:"或"id:"格式
        if ($selected_id === null && preg_match('/(?:ID|id)[:\s]*(\d+)/i', $cleaned_response, $matches)) {
            $selected_id = intval($matches[1]);
        }
        
        // 方式3：提取第一个出现的数字
        if ($selected_id === null && preg_match('/(\d+)/', $cleaned_response, $matches)) {
            $selected_id = intval($matches[1]);
        }
        
        // 验证ID是否在候选列表中
        if ($selected_id !== null) {
            $valid_ids = array_column($candidates, 'id');
            if (in_array($selected_id, $valid_ids)) {
                $this->logger->debug('REFERENCE_MATERIAL_PARSE_SUCCESS', '成功解析大模型返回的ID', array(
                    'raw_response' => $cleaned_response,
                    'parsed_id' => $selected_id
                ));
                return $selected_id;
            } else {
                $this->logger->warning('REFERENCE_MATERIAL_INVALID_ID', '大模型返回的ID不在候选列表中', array(
                    'raw_response' => $cleaned_response,
                    'parsed_id' => $selected_id,
                    'valid_ids' => $valid_ids
                ));
            }
        }
        
        $this->logger->warning('REFERENCE_MATERIAL_PARSE_FAILED', '无法从大模型响应中解析有效ID', array(
            'raw_response' => $cleaned_response
        ));
        
        return null;
    }
    
    /**
     * 根据ID从候选列表中获取参考资料
     * 
     * @param int $id 参考资料ID
     * @param array $candidates 候选列表
     * @return array|null 参考资料数据，未找到返回null
     */
    private function get_profile_by_id($id, $candidates) {
        foreach ($candidates as $candidate) {
            if ($candidate['id'] == $id) {
                return $candidate;
            }
        }
        return null;
    }
}
