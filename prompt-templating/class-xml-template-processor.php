<?php
/**
 * XML模板处理器类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_XmlTemplateProcessor {
    
    /**
     * 根据主题数据和发布规则生成XML模板提示词
     */
    public function generate_prompt($topic_data, $publish_rules, $related_content = array(), $similar_articles = array()) {
        // 验证输入数据
        $validated_data = $this->validate_input_data($topic_data, $publish_rules);
        
        // 获取基础变量
        $title = $validated_data['title'];
        $source_angle = $validated_data['source_angle'];
        $user_value = $validated_data['user_value'];
        $seo_keywords = $validated_data['seo_keywords'];
        $matched_category = $validated_data['matched_category'];
        $target_length = $validated_data['target_length'];
        $knowledge_depth = $validated_data['knowledge_depth'];
        $reader_role = $validated_data['reader_role'];
        $normalize_output = $validated_data['normalize_output'];
        $auto_image_insertion = $validated_data['auto_image_insertion'];
        $enable_internal_linking = isset($publish_rules['enable_internal_linking']) ? $publish_rules['enable_internal_linking'] : 0;
        $publish_language = isset($publish_rules['publish_language']) ? $publish_rules['publish_language'] : 'zh-CN';
        $image_prompt_template = isset($publish_rules['image_prompt_template']) ? $publish_rules['image_prompt_template'] : '';
        
        // 处理SEO关键词
        $seo_keywords_list = $this->process_seo_keywords($seo_keywords);
        
        // 获取文章结构
        $structure_info = $this->get_dynamic_article_structure($topic_data, $normalize_output);
        $structure_template_name = $structure_info['name'];
        $structure_sections = $structure_info['sections'];
        // We will need this for post meta
        $GLOBALS['cam_used_structure_id'] = $structure_info['id'];

        // 处理相关内容
        $source_materials_content = $this->process_source_materials($related_content);
        
        // 处理相似文章（用于内链）
        $similar_articles_content = $this->process_similar_articles($similar_articles);

        // 获取规则的参考资料
        $reference_material = $this->get_reference_material($topic_data, $publish_rules);

        // 构建完整的XML模板提示词
        $prompt = $this->build_xml_prompt(
            $topic_data,
            $title,
            $source_angle,
            $user_value,
            $seo_keywords_list,
            $matched_category,
            $target_length,
            $knowledge_depth,
            $reader_role,
            $normalize_output,
            $auto_image_insertion,
            $enable_internal_linking,
            $publish_language,
            $structure_template_name,
            $structure_sections,
            $source_materials_content,
            $similar_articles_content,
            $reference_material,
            $image_prompt_template
        );
        
        return $prompt;
    }
    
    /**
     * 获取动态文章结构，如果失败则回退到静态结构
     */
    private function get_dynamic_article_structure($topic_data, $is_enabled) {
        // 如果功能未启用，则返回空结构
        if (!$is_enabled) {
            return ['id' => null, 'name' => '', 'sections' => ''];
        }

        global $wpdb;
        $structures_table = $wpdb->prefix . 'content_auto_article_structures';

        // 1. 检查主题是否有向量
        if (empty($topic_data['vector_embedding'])) {
            return $this->get_fallback_structure($topic_data['source_angle']);
        }

        $topic_vector = content_auto_decompress_vector_from_base64($topic_data['vector_embedding']);
        if (!$topic_vector) {
            return $this->get_fallback_structure($topic_data['source_angle']);
        }

        // 2. 从数据库获取候选结构
        $candidate_structures = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, structure, title_vector FROM {$structures_table} WHERE content_angle = %s AND title_vector IS NOT NULL AND title_vector != ''",
            $topic_data['source_angle']
        ), ARRAY_A);

        if (empty($candidate_structures)) {
            return $this->get_fallback_structure($topic_data['source_angle']);
        }

        // 3. 计算相似度并创建评分列表
        $scored_structures = [];
        foreach ($candidate_structures as $candidate) {
            $candidate_vector = content_auto_decompress_vector_from_base64($candidate['title_vector']);
            if ($candidate_vector) {
                $similarity = content_auto_calculate_cosine_similarity($topic_vector, $candidate_vector);
                $scored_structures[] = [
                    'structure' => $candidate,
                    'similarity' => $similarity
                ];
            }
        }

        // 4. 按相似度降序排序
        usort($scored_structures, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        // 5. 取相似度最高的前20个，如果不足20个则取所有
        $top_n = min(20, count($scored_structures));
        $top_structures = array_slice($scored_structures, 0, $top_n);

        // 6. 从前20个中随机选择一个
        $random_index = array_rand($top_structures);
        $selected_structure = $top_structures[$random_index]['structure'];

        // 7. 更新使用次数
        $wpdb->query($wpdb->prepare(
            "UPDATE {$structures_table} SET usage_count = usage_count + 1 WHERE id = %d",
            $selected_structure['id']
        ));

        // 8. 返回选中的结构信息
        return [
            'id' => $selected_structure['id'],
            'name' => $selected_structure['title'], // Use the structure's own title as its name
            'sections' => $selected_structure['structure']
        ];
    }

    /**
     * 获取静态的回退结构
     */
    private function get_fallback_structure($source_angle) {
        $structures = [
            '基础解析' => "<section>概念定义与核心要素解析</section><section>基本原理与工作机制深度剖析</section><section>关键特征识别与判断标准建立</section><section>实际应用场景与价值体现分析</section><section>常见误区澄清与进阶学习路径</section>",
            '实操指导' => "<section>实施前准备条件与环境搭建</section><section>核心操作流程分步骤详细指导</section><section>关键技术要点与注意事项分析</section><section>常见问题诊断与解决方案提供</section><section>效果评估方法与持续优化建议</section>",
            '案例研究' => "<section>案例背景介绍与核心挑战分析</section><section>解决方案设计与实施过程回顾</section><section>关键决策点与转折因素深度分析</section><section>实施成果评估与价值量化展示</section><section>成功经验总结与可复制要素提炼</section>",
            '对比分析' => "<section>对比维度确立与评价标准构建</section><section>各选项特征详细对比与优势分析</section><section>适用场景界定与局限性客观评估</section><section>决策框架建立与选择方法指导</section><section>最优方案推荐与实施路径规划</section>",
            '趋势洞察' => "<section>当前发展现状与行业背景分析</section><section>核心趋势识别与特征深度解读</section><section>驱动因素与影响机制系统分析</section><section>未来发展预判与机会点识别</section><section>应对策略制定与行动建议框架</section>"
        ];
        
        $valid_angles = array_keys($structures);
        $fallback_angle = in_array($source_angle, $valid_angles) ? $source_angle : '基础解析';

        return [
            'id' => null, // No ID for fallback structures
            'name' => $fallback_angle,
            'sections' => str_replace("\n      ", "", $structures[$fallback_angle])
        ];
    }
    
    /**
     * 验证输入数据
     */
    private function validate_input_data($topic_data, $publish_rules) {
        $validated = array();
        
        // 验证标题
        $validated['title'] = isset($topic_data['title']) ? trim($topic_data['title']) : '';
        if (empty($validated['title'])) {
            $validated['title'] = '未命名主题';
        }
        
        // 验证内容角度
        $validated['source_angle'] = isset($topic_data['source_angle']) ? trim($topic_data['source_angle']) : '基础解析';
        
        // 验证用户价值
        $validated['user_value'] = isset($topic_data['user_value']) ? trim($topic_data['user_value']) : '';
        if (empty($validated['user_value'])) {
            $validated['user_value'] = '为用户提供实用的知识和指导';
        }
        
        // 验证SEO关键词
        $validated['seo_keywords'] = isset($topic_data['seo_keywords']) ? $topic_data['seo_keywords'] : '';
        
        // 验证匹配分类
        $validated['matched_category'] = isset($topic_data['matched_category']) ? trim($topic_data['matched_category']) : '通用内容';
        
        // 验证目标字数
        $validated['target_length'] = isset($publish_rules['target_length']) ? trim($publish_rules['target_length']) : '800-1500';
        $valid_lengths = array('300-800', '500-1000', '800-1500', '1000-2000', '1500-3000', '2000-4000');
        if (!in_array($validated['target_length'], $valid_lengths)) {
            $validated['target_length'] = '800-1500';
        }
        
        // 验证内容深度 - 支持"未设置"选项
        $selected_knowledge_depth = isset($publish_rules['knowledge_depth']) ? trim($publish_rules['knowledge_depth']) : '未设置';
        $valid_knowledge_depth = array('未设置', '浅层普及', '实用指导', '深度分析', '全面综述');
        if (!in_array($selected_knowledge_depth, $valid_knowledge_depth)) {
            $selected_knowledge_depth = '未设置';
        }
        
        // 为内容深度构建完整指令 - 未设置时不注入内容
        if ($selected_knowledge_depth === '未设置') {
            $validated['knowledge_depth'] = ''; // 空字符串表示不注入
        } else {
            $knowledge_depth_instructions = array(
                '浅层普及' => '浅层普及（内容特点：概念介绍、热点解读、趣味知识。写作要求：使用通俗易懂的语言，避免专业术语，通过生动的例子和比喻帮助读者快速理解核心概念。重点在于激发读者兴趣，建立初步认知。）',
                '实用指导' => '实用指导（内容特点：操作步骤、使用技巧、解决方案。写作要求：提供具体可执行的步骤，使用清晰的指令性语言，包含实际操作中的注意事项和常见问题解决方案。重点在于帮助读者解决实际问题，促进转化。）',
                '深度分析' => '深度分析（内容特点：趋势解读、案例剖析、专业洞察。写作要求：展现专业深度，引用权威数据和案例，提供独到见解和前瞻性分析。重点在于建立行业权威形象，获取专业认可。）',
                '全面综述' => '全面综述（内容特点：系统梳理、知识图谱、完整指南。写作要求：构建完整的知识体系，涵盖各个方面和层次，提供深入的背景信息和关联知识。重点在于打造内容护城河，沉淀长期价值。）'
            );
            $validated['knowledge_depth'] = $knowledge_depth_instructions[$selected_knowledge_depth];
        }
        
        // 验证目标受众 - 支持"未设置"选项
        $selected_reader_role = isset($publish_rules['reader_role']) ? trim($publish_rules['reader_role']) : '未设置';
        $valid_reader_role = array('未设置', '潜在客户', '现有客户', '行业同仁', '决策者', '泛流量用户');
        if (!in_array($selected_reader_role, $valid_reader_role)) {
            $selected_reader_role = '未设置';
        }
        
        // 为目标受众构建完整指令 - 未设置时不注入内容
        if ($selected_reader_role === '未设置') {
            $validated['reader_role'] = ''; // 空字符串表示不注入
        } else {
            $reader_role_instructions = array(
                '潜在客户' => '潜在客户（受众特点：对产品/服务有兴趣但尚未购买。写作策略：突出产品价值主张，直接回应受众核心痛点，提供试用或体验机会。语言风格：友好、信任建立、价值导向。）',
                '现有客户' => '现有客户（受众特点：已购买产品/服务的用户。写作策略：提升使用技巧，介绍增值服务，解决使用中的进阶问题。语言风格：专业、贴心、增值服务导向。）',
                '行业同仁' => '行业同仁（受众特点：同行业从业者或合作伙伴。写作策略：分享专业见解，行业趋势分析，建立专业声誉。语言风格：专业、深度、合作导向。）',
                '决策者' => '决策者（受众特点：企业高管、投资人、政策制定者。写作策略：强调商业价值和战略意义，提供数据支撑和ROI分析。语言风格：权威、战略、价值导向。）',
                '泛流量用户' => '泛流量用户（受众特点：偶然访问的普通网民。写作策略：关注热点话题和生活需求，提供娱乐性和实用性并重的内容。语言风格：轻松、有趣、普适性。）'
            );
            $validated['reader_role'] = $reader_role_instructions[$selected_reader_role];
        }
        
        // 验证规范化输出设置
        $validated['normalize_output'] = isset($publish_rules['normalize_output']) ? (int)$publish_rules['normalize_output'] : 0;
        
        // 验证自动配图设置
        $validated['auto_image_insertion'] = isset($publish_rules['auto_image_insertion']) ? (int)$publish_rules['auto_image_insertion'] : 0;
        
        return $validated;
    }
    
    /**
     * 处理SEO关键词
     */
    private function process_seo_keywords($seo_keywords) {
        if (empty($seo_keywords)) {
            return '';
        }
        
        // 尝试解析JSON格式
        $keywords_array = json_decode($seo_keywords, true);
        if (is_array($keywords_array) && !empty($keywords_array)) {
            // 验证关键词质量
            $valid_keywords = array();
            foreach ($keywords_array as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && strlen($keyword) >= 2) {
                    $valid_keywords[] = $keyword;
                }
            }
            return !empty($valid_keywords) ? implode('、', $valid_keywords) : '';
        }
        
        // 如果不是JSON，尝试按分隔符分割
        if (is_string($seo_keywords)) {
            $keywords = preg_split('/[,，、\s]+/', $seo_keywords);
            $valid_keywords = array();
            foreach ($keywords as $keyword) {
                $keyword = trim($keyword);
                if (!empty($keyword) && strlen($keyword) >= 2) {
                    $valid_keywords[] = $keyword;
                }
            }
            return !empty($valid_keywords) ? implode('、', array_slice($valid_keywords, 0, 5)) : '';
        }
        
        return '';
    }
    
    /**
     * 根据内容角度获取结构模板
     */
    private function get_structure_template($source_angle) {
        // AI只会生成这5个标准角度，直接验证和返回
        $valid_templates = array(
            '基础解析',
            '实操指导', 
            '案例研究',
            '对比分析',
            '趋势洞察'
        );
        
        // 验证是否为有效值
        if (in_array($source_angle, $valid_templates)) {
            return $source_angle;
        }
        
        // 如果出现意外值，记录日志并使用默认值
        if (!empty($source_angle)) {
        }
        
        return '基础解析';
    }
    
    /**
     * 处理相关内容
     */
    private function process_source_materials($related_content) {
        if (empty($related_content) || !is_array($related_content)) {
            return '（无相关内容参考，请基于主题信息独立创作）';
        }
        
        $content = "参考材料：\n";
        foreach ($related_content as $item) {
            if (isset($item['title']) && isset($item['content'])) {
                $content .= "• " . $item['title'] . "\n";
                $content .= "  " . wp_trim_words($item['content'], 100) . "\n\n";
            }
        }
        
        return $content;
    }
    
    /**
     * 处理相似文章（用于内链）
     */
    private function process_similar_articles($similar_articles) {
        if (empty($similar_articles) || !is_array($similar_articles)) {
            return '';
        }
        
        $content = "\n<related_articles_for_internal_linking>\n";
        $content .= "相关文章（用于内链）：\n";
        foreach ($similar_articles as $article) {
            $content .= "• [" . $article['title'] . "](" . $article['url'] . ")\n";
        }
        $content .= "</related_articles_for_internal_linking>\n";
        
        return $content;
    }
    
    /**
     * 构建XML模板提示词
     */
    private function build_xml_prompt($topic_data, $title, $source_angle, $user_value, $seo_keywords, $matched_category, $target_length, $knowledge_depth, $reader_role, $normalize_output, $auto_image_insertion, $enable_internal_linking, $publish_language, $structure_template, $structure_sections, $source_materials_content, $similar_articles_content, $reference_material = '', $image_prompt_template = '') {
        
        // 引入语言映射文件
        require_once __DIR__ . '/language-mappings.php';
        
        // 验证和获取语言指令
        $validated_language = content_auto_validate_language_code($publish_language);
        $language_instruction = content_auto_get_language_instructions($validated_language);

        // 获取角色描述
        $role_description = $this->get_role_description_from_publish_rules();

        // 随机选择文章模板
        $available_templates = [
            'article-generation-prompt.xml',
            'article-generation-prompt1.xml',
            'article-generation-prompt2.xml'
        ];

        $selected_template = $available_templates[array_rand($available_templates)];
        $template_path = __DIR__ . '/' . $selected_template;

        if (!file_exists($template_path)) {
            return "模板加载失败，请检查插件文件完整性。";
        }
        $prompt = file_get_contents($template_path);

        // 记录选择的模板（仅在调试模式下）
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            error_log("Content Auto Manager: 选择的文章模板 - " . $selected_template . " (标题: " . $title . ")");
        }

        // 根据规范化输出设置决定是否包含结构章节
        $structure_block = '';
        $structure_usage_guidance = '';
        
        if ($normalize_output) {
            if (!empty($structure_sections)) {
                // 找到了匹配的文章结构，使用具体结构
                $structure_block = '
  <source_angle_structures>
    <structure name="' . $structure_template . '">
      ' . $structure_sections . '
    </structure>
  </source_angle_structures>';
            } else {
                // 开启了结构指导但没有匹配到具体结构，使用预设的专业结构
                $fallback_structure = $this->get_fallback_structure($source_angle);
                $structure_block = '
  <source_angle_structures>
    <structure name="' . $fallback_structure['name'] . '">
      ' . $fallback_structure['sections'] . '
    </structure>
  </source_angle_structures>';
            }
            
            // 添加结构使用指导
            $structure_usage_guidance = '
  
  <structure_usage_guidance>
    <guidance_principle>source_angle_structures中的&lt;section&gt;内容仅作为内容组织和要点参考，请根据具体文章主题创作自然、流畅的章节标题</guidance_principle>
    <title_creation>章节标题应简洁明了（5-12字），避免直接使用&lt;section&gt;标签中的完整表述作为H2标题</title_creation>
    <content_organization>严格按照&lt;section&gt;要点组织每个章节的内容，确保完整展开所有要素</content_organization>
    <logical_flow>内容按照&lt;structure&gt;中定义的逻辑顺序逐层深入展开，每个章节后必须有实质性内容段落</logical_flow>
  </structure_usage_guidance>';
        }
        // 如果未开启结构指导($normalize_output = false)，则为空

        // 根据自动配图设置决定是否包含配图指令
        $image_placeholder_config = '';
        if ($auto_image_insertion) {
            $image_placeholder_config = $image_prompt_template;
        }

        // 根据内链功能设置决定是否包含内链指令
        $internal_linking_instructions = '';
        $internal_linking_strategy = '';
        $internal_linking_standard = '';
        
        if ($enable_internal_linking && !empty($similar_articles_content)) {
            $internal_linking_instructions = '
  
  <internal_linking_instructions>
    <instruction>
      将以下相关文章的标题和链接自然融入文章正文中，要求：
      
      【融入方式】：
      1. 段落中间引用：在阐述相关概念时自然提及，如"这种方法在[文章标题](链接)中有详细分析"
      2. 对比引用：在对比分析时引入，如"与[文章标题](链接)中提到的方案相比"
      3. 深入引用：在需要展开说明时使用，如"关于这个问题的深入探讨可参考[文章标题](链接)"
      4. 案例引用：结合具体案例时提及，如"正如[文章标题](链接)中的案例所示"
      
      【语言模式】：
      - 使用过渡性词汇：如"此外"、"另外"、"同时"、"相关地"、"类似地"
      - 采用解释性引入：如"这正是...所强调的"、"正如...中分析的"
      - 运用补充性表达：如"进一步了解可查看"、"更多细节见"
      
      【禁止模式】：
      - 避免段落末尾突兀插入
      - 不使用"点击这里"、"详情请看"等机械表达
      - 杜绝与上下文无关的强行插入
      
      【质量要求】：
      - 每个链接必须与所在段落内容高度相关
      - 链接插入后段落仍需保持逻辑完整性
      - 优先选择能增强当前论述的相关文章
    </instruction>
    ' . $similar_articles_content . '
  </internal_linking_instructions>';
            
            // 添加内链相关的写作策略和质量标准
            $internal_linking_strategy = '<strategy name="内链融入">严格按照internal_linking_instructions的融入方式和语言模式，将相关文章链接自然嵌入段落中间，避免段落末尾突兀插入</strategy>';
            $internal_linking_standard = '<standard name="内链质量">链接必须与段落内容高度相关，使用过渡性词汇自然引入，保持文章逻辑完整性，每个链接都应能增强当前论述</standard>';
        }

        // 处理 content_strategy 和 target_audience 标签，只有在非空时才保留标签
        if (!empty($knowledge_depth)) {
            $content_strategy_block = '<content_strategy>' . $knowledge_depth . '</content_strategy>';
        } else {
            $content_strategy_block = '';
        }

        if (!empty($reader_role)) {
            $target_audience_block = '<target_audience>' . $reader_role . '</target_audience>';
        } else {
            $target_audience_block = '';
        }

        // 构建参考资料相关块
        $reference_material_block = '';
        $reference_material_strategy = '';
        $reference_material_principle = '';

        if (!empty($reference_material)) {
            $reference_material_block = "\n    <reference_material>\n      <reference_content>" . htmlspecialchars($reference_material) . "</reference_content>\n    </reference_material>";
            $reference_material_strategy = '<strategy name="参考资料融合">将reference_material中的关键信息自然融入到相关章节中，作为内容的有益补充，确保参考资料与文章主题高度相关</strategy>';
            $reference_material_principle = '<principle>参考资料运用：合理运用reference_material中的信息，不生硬堆砌，确保参考资料内容与文章主题和章节内容自然融合</principle>';
        }
        
        // 获取语言的AI识别名称
        $language_ai_name = content_auto_get_language_ai_name($validated_language);
        
        // 替换占位符
        $replacements = array(
            '{{TITLE}}' => $title,
            '{{SOURCE_ANGLE}}' => $source_angle,
            '{{USER_VALUE}}' => $user_value,
            '{{SEO_KEYWORDS}}' => $seo_keywords,
            '{{MATCHED_CATEGORY}}' => $matched_category,
            '{{TARGET_LENGTH}}' => $target_length,
            '{{CONTENT_STRATEGY_BLOCK}}' => $content_strategy_block, // 现在包含整个标签
            '{{TARGET_AUDIENCE_BLOCK}}' => $target_audience_block, // 现在包含整个标签
            '{{STRUCTURE_BLOCK}}' => $structure_block, // 包含整个结构块或空字符串
            '{{STRUCTURE_USAGE_GUIDANCE}}' => $structure_usage_guidance, // 结构使用指导
            '{{IMAGE_INSTRUCTIONS}}' => $auto_image_insertion ? $image_placeholder_config : '',
            '{{INTERNAL_LINKING_INSTRUCTIONS}}' => $internal_linking_instructions,
            '{{INTERNAL_LINKING_STRATEGY}}' => $internal_linking_strategy,
            '{{INTERNAL_LINKING_STANDARD}}' => $internal_linking_standard,
            '{{LANGUAGE_INSTRUCTION}}' => $language_instruction,
            '{{LANGUAGE_NAME}}' => $language_ai_name,
            '{{ROLE_DESCRIPTION}}' => $role_description, // 角色描述变量
            '{{CURRENT_DATE}}' => date('Y年m月d日'), // 添加当前日期替换
            // 参考资料相关占位符
            '{{REFERENCE_MATERIAL_BLOCK}}' => $reference_material_block,
            '{{REFERENCE_MATERIAL_STRATEGY}}' => $reference_material_strategy,
            '{{REFERENCE_MATERIAL_PRINCIPLE}}' => $reference_material_principle
        );

        $prompt = str_replace(array_keys($replacements), array_values($replacements), $prompt);

        // 仅在调试模式下记录完整的文章生成提示词到日志文件
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            $this->log_article_prompt_to_file($prompt, $topic_data, $title, $source_angle);
        }

        return $prompt;
    }
    
    /**
     * 获取结构模板的具体章节
     */
    private function get_structure_sections($structure_name) {
        $structures = array(
            '基础解析' => "
      <section>概念定义与核心要素解析</section>
      <section>基本原理与工作机制深度剖析</section>
      <section>关键特征识别与判断标准建立</section>
      <section>实际应用场景与价值体现分析</section>
      <section>常见误区澄清与进阶学习路径</section>",
            
            '实操指导' => "
      <section>实施前准备条件与环境搭建</section>
      <section>核心操作流程分步骤详细指导</section>
      <section>关键技术要点与注意事项分析</section>
      <section>常见问题诊断与解决方案提供</section>
      <section>效果评估方法与持续优化建议</section>",
            
            '案例研究' => "
      <section>案例背景介绍与核心挑战分析</section>
      <section>解决方案设计与实施过程回顾</section>
      <section>关键决策点与转折因素深度分析</section>
      <section>实施成果评估与价值量化展示</section>
      <section>成功经验总结与可复制要素提炼</section>",
            
            '对比分析' => "
      <section>对比维度确立与评价标准构建</section>
      <section>各选项特征详细对比与优势分析</section>
      <section>适用场景界定与局限性客观评估</section>
      <section>决策框架建立与选择方法指导</section>
      <section>最优方案推荐与实施路径规划</section>",
            
            '趋势洞察' => "
      <section>当前发展现状与行业背景分析</section>
      <section>核心趋势识别与特征深度解读</section>
      <section>驱动因素与影响机制系统分析</section>
      <section>未来发展预判与机会点识别</section>
      <section>应对策略制定与行动建议框架</section>"
        );
        
        return isset($structures[$structure_name]) ? $structures[$structure_name] : $structures['基础解析'];
    }
    
    /**
     * 将XML模板转换为纯文本提示词
     */
    public function xml_to_text_prompt($xml_prompt) {
        // 移除XML标签，保留内容
        $text_prompt = preg_replace('/<[^>]+>/', '', $xml_prompt);
        
        // 清理多余的空白字符
        $text_prompt = preg_replace('/\s+/', ' ', $text_prompt);
        $text_prompt = trim($text_prompt);
        
        // 重新格式化为可读的文本
        $lines = explode("\n", $xml_prompt);
        $formatted_lines = array();
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && !preg_match('/^<\/?[^>]+>$/', $line)) {
                // 移除XML标签但保留内容
                $clean_line = preg_replace('/<[^>]+>/', '', $line);
                if (!empty($clean_line)) {
                    $formatted_lines[] = $clean_line;
                }
            }
        }
        
        return implode("\n", $formatted_lines);
    }
    
    /**
     * 记录文章生成提示词到统一日志系统
     */
    private function log_article_prompt_to_file($prompt_content, $topic_data, $title, $source_angle) {
        try {
            // 仅在调试模式下记录完整提示词
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                // 引入日志系统
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
                $logger = new ContentAuto_LoggingSystem();

                // 调试：记录完整的 topic_data 结构到插件日志
                $logger->log_debug('TOPIC_DATA_DEBUG', '完整的 topic_data 结构', array(
                    'topic_keys' => is_array($topic_data) ? array_keys($topic_data) : null,
                    'topic_data' => $topic_data
                ));

                // 记录字段查找过程
                $logger->log_debug('FIELD_RESOLUTION_DEBUG', '字段查找过程', array(
                    'title_found' => !empty($title) && $title !== '未知标题',
                    'title_value' => $title,
                    'angle_found' => !empty($source_angle) && $source_angle !== '未知角度',
                    'angle_value' => $source_angle,
                    'available_fields' => is_array($topic_data) ? array_keys($topic_data) : null
                ));

                // 使用统一的日志系统记录完整提示词
                $context = array(
                    'type' => 'ARTICLE_PROMPT',
                    'title' => $title,
                    'source_angle' => $source_angle,
                    'prompt_length' => strlen($prompt_content),
                    'prompt_content' => $prompt_content
                );

                $logger->log_info('COMPLETE_PROMPT', '文章生成完整提示词', $context);
            }
        } catch (Exception $e) {
            // 使用插件日志系统记录错误
            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';
                $logger = new ContentAuto_LoggingSystem();
                $logger->log_error('PROMPT_LOG_ERROR', '文章提示词日志记录失败', array(
                    'error_message' => $e->getMessage(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine()
                ));
            }
        }
    }

    /**
     * 获取主题关联的参考资料（按优先级：主题级->规则级->品牌资料级）
     */
    private function get_reference_material($topic_data, $publish_rules = array()) {
        // 1. 优先使用主题级参考资料
        if (isset($topic_data['reference_material']) && !empty(trim($topic_data['reference_material']))) {
            return trim($topic_data['reference_material']);
        }
        
        // 2. 回退到规则级参考资料
        if (isset($topic_data['rule_id']) && !empty($topic_data['rule_id'])) {
            global $wpdb;
            $rules_table = $wpdb->prefix . 'content_auto_rules';

            $rule = $wpdb->get_var($wpdb->prepare(
                "SELECT reference_material FROM {$rules_table} WHERE id = %d",
                $topic_data['rule_id']
            ));

            if ($rule && !empty(trim($rule))) {
                return trim($rule);
            }
        }

        // 3. 如果发布规则启用了参考资料功能，从品牌资料中获取
        if (isset($publish_rules['enable_reference_material']) && $publish_rules['enable_reference_material']) {
            return $this->get_brand_profile_reference_material($topic_data);
        }

        return '';
    }

    /**
     * 从品牌资料中获取参考资料
     */
    private function get_brand_profile_reference_material($topic_data) {
        // 检查主题是否有向量
        if (empty($topic_data['vector_embedding'])) {
            return '';
        }

        $topic_vector = content_auto_decompress_vector_from_base64($topic_data['vector_embedding']);
        if (!$topic_vector) {
            return '';
        }

        global $wpdb;
        $brand_profiles_table = $wpdb->prefix . 'content_auto_brand_profiles';

        // 获取所有参考资料类型的品牌资料
        $reference_profiles = $wpdb->get_results($wpdb->prepare(
            "SELECT title, description, vector FROM {$brand_profiles_table} WHERE type = %s AND vector IS NOT NULL AND vector != ''",
            'reference'
        ), ARRAY_A);

        if (empty($reference_profiles)) {
            return '';
        }

        $best_match = null;
        $highest_similarity = 0.0;

        // 计算相似度并找到最匹配的参考资料
        foreach ($reference_profiles as $profile) {
            $profile_vector = content_auto_decompress_vector_from_base64($profile['vector']);
            if ($profile_vector) {
                $similarity = content_auto_calculate_cosine_similarity($topic_vector, $profile_vector);

                // 只有相似度不低于0.8的才考虑
                if ($similarity >= 0.8 && $similarity > $highest_similarity) {
                    $highest_similarity = $similarity;
                    $best_match = $profile;
                }
            }
        }

        // 返回最匹配的参考资料的描述
        return $best_match ? trim($best_match['description']) : '';
    }

    /**
     * 从发布规则中获取角色描述
     */
    private function get_role_description_from_publish_rules() {
        global $wpdb;

        $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';

        // 获取角色描述
        $role_description = $wpdb->get_var(
            "SELECT role_description FROM {$publish_rules_table} WHERE id = 1 LIMIT 1"
        );

        // 如果没有设置角色描述或为空，则使用默认值
        if (empty($role_description)) {
            $role_description = '专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。';
        }

        return $role_description;
    }
}