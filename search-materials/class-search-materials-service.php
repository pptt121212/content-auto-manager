<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 搜索物料服务类
 * 负责执行搜索、AI筛选、抓取和汇总的核心业务逻辑
 */
class ContentAuto_SearchMaterialsService {
    
    private $unified_api;
    private $log;

    public function __construct() {
        // 确保统一API处理器已加载
        if (!class_exists('ContentAuto_UnifiedApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
        }
        
        $this->unified_api = new ContentAuto_UnifiedApiHandler();
        $this->log = [];
    }

    /**
     * 获取当前语言环境上下文
     * 综合判断：发布语言和系统语言，任一非中文则使用英文 Prompt
     * 
     * @return array ['use_english' => bool, 'language_instruction' => string, 'language_name' => string]
     */
    private function get_language_context() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/language-mappings.php';
        
        // 1. 获取发布语言
        if (!class_exists('ContentAuto_Database')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/database/class-database.php';
        }
        $database = new ContentAuto_Database();
        $publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));
        $publish_language = isset($publish_rule['publish_language']) ? $publish_rule['publish_language'] : 'zh-CN';
        
        // 2. 获取系统语言
        $locale = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        
        // 3. 判断是否使用英文 Prompt（发布语言或系统语言非中文）
        $publish_is_chinese = strpos($publish_language, 'zh') === 0;
        $locale_is_chinese = strpos($locale, 'zh') === 0;
        $use_english = !$publish_is_chinese || !$locale_is_chinese;
        
        // 4. 获取语言指令
        $validated_language = content_auto_validate_language_code($publish_language);
        $language_instruction = content_auto_get_language_instructions($validated_language);
        $language_name = content_auto_get_language_ai_name($validated_language);
        
        return [
            'use_english' => $use_english,
            'language_instruction' => $language_instruction,
            'language_name' => $language_name,
            'publish_language' => $publish_language,
        ];
    }

    /**
     * 执行分步处理
     * @param string $step当前步骤
     * @param int $topic_id
     * @param array $params 额外参数
     */
    public function process_step($step, $topic_id, $params = []) {
        set_time_limit(300);
        $this->log = [];
        $result = ['success' => false, 'log' => [], 'data' => [], 'next_step' => ''];

        try {
            switch ($step) {
                case 'init':
                    $this->step_init($topic_id);
                    $result['next_step'] = 'search';
                    break;
                case 'search':
                    $this->step_search($topic_id);
                    $result['next_step'] = 'filter';
                    break;
                case 'filter':
                    $this->step_filter($topic_id);
                    $result['next_step'] = 'scrape';
                    break;
                case 'scrape':
                    $scrape_result = $this->step_scrape($topic_id);
                    if ($scrape_result === 'RETRY_FILTER') {
                        $result['next_step'] = 'filter';
                        $result['data']['retry'] = true;
                    } elseif ($scrape_result === 'RETRY_SCRAPE') {
                        $result['next_step'] = 'scrape';
                        $result['data']['retry'] = true;
                    } else {
                        $result['next_step'] = 'summarize';
                    }
                    break;
                case 'summarize':
                    $summary_result = $this->step_summarize($topic_id);
                    if ($summary_result === 'RETRY_FILTER') {
                        $result['next_step'] = 'filter';
                        // 强制前端能够感知这是重试
                        $result['data']['retry'] = true;
                    } else {
                        $result['data']['summary'] = $summary_result;
                        $result['next_step'] = 'done';
                    }
                    break;
                default:
                    throw new Exception("未知步骤: $step");
            }
            $result['success'] = true;

        } catch (Exception $e) {
            $this->add_log("错误: " . $e->getMessage());
            $result['message'] = $e->getMessage();
            $result['success'] = false;
        }

        $result['log'] = $this->log;
        return $result;
    }

    // --- 各个步骤的具体实现 ---

    private function step_init($topic_id) {
        $topic_info = $this->get_topic_info($topic_id);
        if (empty($topic_info) || empty($topic_info['title'])) {
            throw new Exception("无法找到ID为 {$topic_id} 的主题或标题为空");
        }
        // 清理旧的缓存数据
        delete_transient("cam_material_{$topic_id}_data");
        
        $this->add_log("步骤1: 初始化任务成功");
        $this->add_log("目标主题: " . $topic_info['title']);
        if (!empty($topic_info['user_value'])) {
             $this->add_log("用户价值: " . mb_substr($topic_info['user_value'], 0, 20) . "...");
        }
        
        // 保存基础信息
        $data = [
            'topic_title' => $topic_info['title'],
            'user_value'  => $topic_info['user_value'] // 保存用户价值字段
        ];
        set_transient("cam_material_{$topic_id}_data", $data, 3600); // 1小时过期
    }

    private function step_search($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (!$data || empty($data['topic_title'])) {
            throw new Exception("任务数据丢失，请重新开始");
        }

        $this->add_log(__('步骤2: 正在反推搜索意图并生成关键词...', 'yali-ai-writer'));

        $this->add_log("步骤2: 正在反推搜索意图并生成扩展关键词...");
        $search_queries = $this->generate_search_queries_with_ai($data['topic_title'], $data['user_value'] ?? '');
        
        // 核心优化：强制将最原始的主题作为最优先的搜索词
        array_unshift($search_queries, $data['topic_title']);
        $search_queries = array_slice($search_queries, 0, 3); // 确保总是不超过 3 个
        
        $this->add_log("AI生成的联合搜索策略: " . implode(", ", $search_queries));
        
        //获取单次搜索配置的最大数量，如果用户没配则默认为 10
        $search_settings = get_option('content_auto_search_settings', []);
        $max_results_per_query = isset($search_settings['max_results']) ? intval($search_settings['max_results']) : 10;
        
        // 存储按搜索词分组的结果
        $grouped_results = [];
        
        foreach ($search_queries as $query) {
            $this->add_log(__('正在检索', 'yali-ai-writer') . ': [' . $query . '] ...');
            // 尊重用户配置，申请更大的初选池
            $search_response = content_auto_search($query, $max_results_per_query);
            
            // 容错：单个搜索词失败不应导致整体失败，除非全部失败
            if (is_wp_error($search_response) || empty($search_response['success']) || empty($search_response['results'])) {
                continue;
            }
            
            // 获取全局黑名单配置
            $global_blacklist = get_option('content_auto_material_search_blacklist', ['csdn.net', 'zhihu.com']);

            $valid_items_for_query = [];
            foreach ($search_response['results'] as $item) {
                // ✅ 动态黑名单过滤
                $is_blacklisted = false;
                foreach ($global_blacklist as $keyword) {
                    if (!empty($keyword) && stripos($item['link'], trim($keyword)) !== false) {
                        $is_blacklisted = true;
                        break;
                    }
                }
                
                if ($is_blacklisted) continue;

                $valid_items_for_query[] = $item;
            }
            
            if (!empty($valid_items_for_query)) {
                $grouped_results[] = $valid_items_for_query;
            }
            
            // 避免触发 API 速率限制 (可选)
            usleep(500000); // 0.5s
        }
        
        if (empty($grouped_results)) {
            throw new Exception("所有关键词的搜索结果均为空");
        }
        
        // ✅ 核心优化：轮询合并 (Round-Robin) 与全局去重
        $all_results = [];
        $max_depth = 0;
        foreach ($grouped_results as $group) {
            $max_depth = max($max_depth, count($group));
        }
        
        for ($depth = 0; $depth < $max_depth; $depth++) {
            foreach ($grouped_results as $group) {
                if (isset($group[$depth])) {
                    $item = $group[$depth];
                    // 利用 URL 的唯一性进行全局去重
                    if (!isset($all_results[$item['link']])) {
                        $all_results[$item['link']] = $item;
                    }
                }
            }
        }
        
        // 取前 30 条，扩大候选池让 AI 有更多选择空间
        $final_results = array_slice(array_values($all_results), 0, 30);
        
        $this->add_log(__('步骤2: 聚合搜索完成，共获取 ', 'yali-ai-writer') . count($final_results) . __(' 条去重结果', 'yali-ai-writer'));
        
        // 更新数据
        $data['search_results'] = $final_results;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }
    
    /**
     * 使用 AI 生成更具 E-E-A-T 价值的搜索词
     */
    private function generate_search_queries_with_ai($title, $user_value) {
        $lang = $this->get_language_context();
        
        if ($lang['use_english']) {
            $prompt = "I need to write an in-depth article on a specific topic. Please help me generate 2 highly relevant supplementary search keywords to find the best reference materials on Google.\n\n";
            $prompt .= "Topic: {$title}\n";
            if (!empty($user_value)) {
                $prompt .= "Core User Value: {$user_value}\n";
            }
            $prompt .= "\n**Target Language: {$lang['language_name']}** — All search queries MUST be in {$lang['language_name']}.\n";
            $prompt .= "\nAnalyze the Topic and determine the **Core Subject** (the main entity or concept being discussed). Then, generate 2 search queries based on these two methods:\n\n";
            $prompt .= "1. **[Synonym/Alternative Phrasing]**: How else would professionals search for this exact Core Subject? Use different terminology or an alternative name that means the same thing.\n";
            $prompt .= "2. **[Specific Aspect/Long-tail]**: Combine the Core Subject with a specific, highly relevant investigative angle (e.g., \"pros and cons\", \"best tools\", \"how it works\", \"comparison\"). DO NOT drop the Core Subject.\n\n";
            $prompt .= "**Strict Constraints:**\n";
            $prompt .= "- **Anchored to Core Subject**: Both queries MUST be centrally focused on the Core Subject. Do not generate generic queries like \"industry report\" or \"case study\" if they dilute the main topic.\n";
            $prompt .= "- **Language Consistency**: Search queries MUST be in {$lang['language_name']} to match the target publication language.\n";
            $prompt .= "- **Natural & Precise**: Use natural phrasing (typically 3-5 words).\n";
            $prompt .= "- **Retain Key Constraints (CRITICAL)**: If the original topic contains specific limiters (such as a specific time, geographical location, specialized industry, or specific viewpoint), you MUST retain them in the generated queries to prevent the topic from becoming overly broad.\n";
            $prompt .= "- **No Parroting**: Do NOT simply return the original title.\n";
            $prompt .= "**Output Format:**\n";
            $prompt .= "Return a **pure JSON object** strictly following this structure:\n";
            $prompt .= "```json\n";
            $prompt .= "{\n";
            $prompt .= "  \"search_queries\": [\n";
            $prompt .= "    \"query 1\",\n";
            $prompt .= "    \"query 2\"\n";
            $prompt .= "  ]\n";
            $prompt .= "}\n";
            $prompt .= "```\n";
            $prompt .= "Do NOT return an array of objects (e.g. `[{\"search_queries\":...}]`). Do NOT include any explanatory text outside the JSON.";
        } else {
            $prompt = "我需要为一个主题撰写一篇深度文章。请帮我构思 2 个辅助搜索关键词，以便我在 Google 找到最优质的相关参考素材。\n\n";
            $prompt .= "主题：{$title}\n";
            if (!empty($user_value)) {
                $prompt .= "核心用户价值：{$user_value}\n";
            }
            $prompt .= "\n请你首先精准提取原主题中的**“核心主词”**（即讨论的真正核心客观事物或概念）。然后，基于该核心主词，构思以下 2 个辅助搜索词：\n\n";
            $prompt .= "1. **【同义替换/换种说法】**：业内人士还会用什么不同的专业词汇或别名来搜索这个核心主词？换一种说法，但保持搜索意图的完全一致。\n";
            $prompt .= "2. **【细分切面/长尾扩展】**：保留核心主词，并加上与其紧密绑定的、最具探究价值的延伸词（如：“优缺点对比”、“主流工具盘点”、“底层机制”等）。绝对不能丢掉核心主词。\n\n";
            $prompt .= "**严格约束：**\n";
            $prompt .= "- **死守核心主词**：生成的两个搜索词必须牢牢绑定核心客观事物。严禁凭空添加“趋势报告”、“实操案例”等宽泛且容易导致搜索结果跑偏（泛化）的后缀。\n";
            $prompt .= "- **保留核心限定（极其重要）**：如果原主题带有特定的限定词（如特定的时间、地域、特殊行业或明确的视点限制），生成的搜索词中也必须竭力保留，坚决杜绝把原主题泛化成宽泛的科普词。\n";
            $prompt .= "- **语言一致**：必须与主题语言保持绝对一致（中文搜中文，英文搜英文）。\n";
            $prompt .= "- **自然精准**：请使用最符合人类搜索习惯的词组。\n";
            $prompt .= "- **拒绝复读**：不要直接返回原标题。\n";
            $prompt .= "**输出格式约束：**\n";
            $prompt .= "请务必返回一个**纯 JSON 对象**，必须严格遵守以下结构：\n";
            $prompt .= "```json\n";
            $prompt .= "{\n";
            $prompt .= "  \"search_queries\": [\n";
            $prompt .= "    \"辅助搜索词1\",\n";
            $prompt .= "    \"辅助搜索词2\"\n";
            $prompt .= "  ]\n";
            $prompt .= "}\n";
            $prompt .= "```\n";
            $prompt .= "严禁返回对象数组（如 `[{\"search_queries\":...}]`），严禁包含 Markdown 标记之外的任何解释性文字。";
        }
        
        $response = $this->unified_api->generate_content($prompt, 'search_intent');

        // 预防API返回错误数组导致 json_decode 报错
        if (is_array($response) && isset($response['error'])) {
            error_log("Search Query Generation API Error: " . $response['error']);
            return [$title]; // 降级处理：直接返回标题作为搜索词
        }
        
        // 解析
        $queries = [];
        $data = null;
        
        // 1. 尝试直接全文 JSON 解析
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data = $decoded;
        } 
        // 2. 如果全文失败，尝试正则提取大括号内容再解析 (针对前缀干扰)
        elseif (preg_match('/\{.*\}/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
        }
        // 3. 尝试正则提取方括号内容 (针对直接返回数组的情况)
        elseif (preg_match('/\[.*\]/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
        }

        // --- 智能数据提取层 ---
        if (is_array($data)) {
            // 情况 A：标准结构 {"search_queries": ["A", "B"]}
            if (isset($data['search_queries']) && is_array($data['search_queries'])) {
                $queries = $data['search_queries'];
            }
            // 情况 B：直接返回了字符串数组 ["A", "B"]
            elseif (isset($data[0]) && is_string($data[0])) {
                $queries = $data;
            }
            // 情况 C：返回了对象数组 [{"search_queries": "A"}, {"search_queries": "B"}]
            elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['search_queries'])) {
                $queries = array_column($data, 'search_queries');
            }
        }
        
        // 兜底：如果解析失败或不仅是数组，则回退到仅搜索标题
        if (empty($queries)) {
            error_log("Search Query Generation Failed. Fallback to title. Response: " . substr($response, 0, 100));
            return [$title];
        }
        
        // 过滤非字符串项并取前3
        $queries = array_filter($queries, 'is_string');
        return array_slice($queries, 0, 3);
    }
    
    // 专门提取 JSON 数组的辅助方法 (不再需要，因为上面已经内联了更精准的逻辑)
    // private function extract_json_array_str($str) ...

    private function step_filter($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['search_results'])) throw new Exception("缺少搜索结果数据");

        // --- 黑名单过滤逻辑 ---
        $blacklisted_urls = isset($data['blacklisted_urls']) ? $data['blacklisted_urls'] : [];
        $valid_results = [];
        
        // 需要排除的文件扩展名（Jina Reader 无法有效解析）
        $excluded_extensions = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.rar'];
        
        foreach ($data['search_results'] as $item) {
            // 跳过黑名单 URL
            if (in_array($item['link'], $blacklisted_urls)) {
                continue;
            }
            
            // 跳过文档类文件
            $url_lower = strtolower($item['link']);
            $is_document = false;
            foreach ($excluded_extensions as $ext) {
                if (strpos($url_lower, $ext) !== false) {
                    $is_document = true;
                    break;
                }
            }
            if ($is_document) {
                continue;
            }
            
            $valid_results[] = $item;
        }
        
        if (empty($valid_results)) {
            throw new Exception("所有搜索结果均已被过滤或尝试过，无更多可用内容");
        }
        
        // 为 Queue & Consume 模型提供全景后备队列
        $this->add_log("步骤3: AI正在全盘扫描 ".count($valid_results)." 条搜索结果，甄别并对所有具备关联潜力的候选进行全量排序(构建深海火力网)...");
        // ✅ 传递 user_value
        $relevant_urls = $this->analyze_relevance_with_ai($data['topic_title'], $valid_results, $data['user_value'] ?? '');
        
        if (empty($relevant_urls)) {
            throw new Exception("AI认为没有相关内容可供抓取");
        }
        $this->add_log("步骤3: 成功建立了包含 " . count($relevant_urls) . " 个链接的高质量备用队列");
        
        $data['relevant_urls'] = $relevant_urls;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }

// ... (omitted code) ...

    private function analyze_relevance_with_ai($topic, $results, $user_value = '') {
        $lang = $this->get_language_context();

        if ($lang['use_english']) {
            $prompt = "You are a professional content researcher. I am preparing to write an in-depth article titled \"{$topic}\" and need to find the highest-quality reference materials.\n";
            if (!empty($user_value)) {
                $prompt .= "The target reader's core pain point/value is: \"{$user_value}\".\n";
            }
            $prompt .= "Please analyze the following search results (title + snippet) and predict which web pages are most likely to provide **Google E-E-A-T** authoritative endorsement or in-depth information that **directly addresses the reader's pain point**.\n";
            $prompt .= "Selection criteria (in descending priority):\n";
            $prompt .= "1. **Strict Relevance (CRITICAL)**: The content MUST be directly and highly relevant to the specific topic \"{$topic}\". First, analyze if the topic contains any strict constraints (e.g., specialized terminology, specific timeframes, or explicit scenarios). If so, completely reject any link that does not clearly align with those specific constraints, regardless of how authoritative it is.\n";
            $prompt .= "2. **Authority**: Official documents, government/institutional white papers, academic papers, or Wikipedia definitions.\n";
            $prompt .= "3. **Expertise & Experience**: Content containing specific statistics, industry research reports, or detailed practical long-form tutorials and reviews.\n";
            $prompt .= "4. **Exclude Low Quality/Off-topic**: Firmly eliminate news flashes, advertorials, pure list pages, and content that is too generic or **unrelated to the reader's pain point**.\n\n";
            $prompt .= "Quantity limit: Please evaluate ALL provided links. Select and rank **ALL** links that have any potential relevance, ordering them strictly from most relevant (highest priority) to least relevant. You may exclude links that are completely off-topic or pure garbage.\n\n";
            $prompt .= "**Output Format:**\n";
            $prompt .= "Return a **pure JSON object** strictly following this structure:\n";
            $prompt .= "```json\n";
            $prompt .= "{\n";
            $prompt .= "  \"selected_urls\": [\n";
            $prompt .= "    \"https://example.com/url1\"\n";
            $prompt .= "  ]\n";
            $prompt .= "}\n";
            $prompt .= "```\n";
            $prompt .= "Do NOT return an array of objects. Do NOT include any explanatory text outside the JSON.\n\n";
            foreach ($results as $index => $item) {
                $prompt .= ($index + 1) . ". [{$item['title']}]({$item['link']})\n   Snippet: {$item['snippet']}\n";
            }
        } else {
            $prompt = "你是一位专业的内容研究员。我准备撰写一篇题为“{$topic}”的深度文章，需要寻找最优质的参考素材。\n";
            if (!empty($user_value)) {
                $prompt .= "目标读者的核心痛点/价值点是：“{$user_value}”。\n";
            }
            $prompt .= "请分析以下搜索结果（标题+摘要），预判哪些网页点开后最有可能提供**直接切中主题**且具备深度的信息。\n";
            $prompt .= "筛选标准（按优先级从高到低排序，请严格执行）：\n";
            $prompt .= "1. **⭐⭐⭐ 强相关性 (绝对优先)**：内容必须与主题“{$topic}”具有极强的直接相关性。请先敏锐识别原主题中是否隐含了严格的限定条件（比如包含特定的专有名词、时间节点、或特定场景限制）。如果有，**即使某搜索结果极其官方或权威，只要它没有紧扣这些核心限制元素，请直接淘汰！**（反之，如果主题较为宽泛，则优选切题的百科/官方内容）。\n";
            $prompt .= "2. **⭐⭐ 权威背书 (Authority)**：在满足强相关性的前提下，优先选择官方文档、机构白皮书、知名学术/统计平台。\n";
            $prompt .= "3. **⭐ 深度数据与经验 (Depth)**：包含具体统计数据、行业研究报告、或逻辑清晰篇幅详实的评测/实操复盘的内容次之。\n";
            $prompt .= "4. **❌ 排除低质与泛化内容**：坚决排除宽泛的入门科普（如“什么是SEM”）、资讯快讯、纯软文广告，以及**与核心痛点无关**的内容。\n\n";
            $prompt .= "数量限制：请评估提供的所有链接。将**所有**具备潜在关联价值的链接提取出来，并**按相关性从高到低进行全盘排序**（1号位必须是最直接切题的王牌链接）。你可以直接剔除那些一眼离题或纯粹是垃圾引流的链接，保留其余所有排序好的链接。\n\n";
            $prompt .= "**输出格式：**\n";
            $prompt .= "请返回一个**纯 JSON 对象**，必须严格符合以下结构：\n";
            $prompt .= "```json\n";
            $prompt .= "{\n";
            $prompt .= "  \"selected_urls\": [\n";
            $prompt .= "    \"https://example.com/url1\"\n";
            $prompt .= "  ]\n";
            $prompt .= "}\n";
            $prompt .= "```\n";
            $prompt .= "绝对不要返回数组套对象格式（如 `[{\"selected_urls\":...}]`），也不要在 JSON 外附加任何解释性文字。\n\n";
            foreach ($results as $index => $item) {
                $prompt .= ($index + 1) . ". [{$item['title']}]({$item['link']})\n   摘要: {$item['snippet']}\n";
            }
        }
        
        $response = $this->unified_api->generate_content($prompt, 'material_filtering');
        
        // ✅ 修复：API 调用失败时抛出明确异常，而不是静默返回空数组
        if (is_array($response) && isset($response['error'])) {
            error_log("ContentAuto Material Search: AI 筛选 API 错误 - " . $response['error']);
            throw new Exception("AI筛选API请求失败: " . $response['error']);
        }
        
        // 使用更健壮的提取逻辑
        $urls = $this->extract_urls_from_response($response);
        
        // ✅ 过滤：确保返回的是 URL 字符串数组
        $valid_urls = array_filter($urls, function($url) {
            return is_string($url) && filter_var($url, FILTER_VALIDATE_URL);
        });
        
        return array_values($valid_urls);
    }

    /**
     * 极速判断抓取内容是否为常见的云盾防御/验证码页面 (省钱神器)
     */
    private function is_waf_blocked($text) {
        $blacklisted_strings = [
            'Access Denied', 'Please wait while we verify', 'Just a moment...',
            'Checking your browser before accessing', 'Enable JavaScript and cookies to continue',
            'Cloudflare', 'DDoS protection', 'Security check',
            '您可以尝试刷新页面', '身份验证', '请进行安全验证', '验证码', '滑动图块',
            '访问受限', '404 Not Found', '403 Forbidden'
        ];
        
        $early_text = mb_substr($text, 0, 800); // 通常阻拦提示都在页面最前面
        foreach ($blacklisted_strings as $str) {
            if (stripos($early_text, $str) !== false) {
                // 如果命中超过1个极有可能是防护页，或者命中强阻拦词
                return true;
            }
        }
        return false;
    }

    private function step_scrape($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['relevant_urls'])) throw new Exception("缺少待抓取链接备用队列");

        $this->add_log("步骤4: 从备用队列中提取候选网页进行抓取...");
        
        // 我们改为累加逻辑，保留之前成功的素材
        if (!isset($data['valid_scraped_contents'])) {
            $data['valid_scraped_contents'] = [];
        }
        
        if (!isset($data['blacklisted_urls'])) {
            $data['blacklisted_urls'] = [];
        }
        
        // 计算差额 (Deficit)：还需要抓取多少篇有效素材
        $valid_count = count($data['valid_scraped_contents']);
        $deficit = max(1, 3 - $valid_count); 
        
        // 提取当前需要抓取的这批 URL（从队列头剥离）
        $urls_to_scrape = [];
        $remaining_queue = [];
        
        foreach ($data['relevant_urls'] as $url) {
            // 如果已经被拉黑过（表示由于各种原因已被抛弃），则跳过
            if (in_array($url, $data['blacklisted_urls'])) {
                continue;
            }
            if (count($urls_to_scrape) < $deficit) {
                $urls_to_scrape[] = $url;
            } else {
                $remaining_queue[] = $url; // 剩下的留给后续微循环
            }
        }
        
        $data['relevant_urls'] = array_merge($urls_to_scrape, $remaining_queue); // 重建队列
        set_transient("cam_material_{$topic_id}_data", $data, 3600); // 暂存队列，防意外退出全丢
        
        if (empty($urls_to_scrape)) {
            $this->add_log("警告: 备选队列已耗尽，没有任何候选可供抓取。触发深度重试...");
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
            $data['retry_count'] = $retry_count + 1;
            set_transient("cam_material_{$topic_id}_data", $data, 3600);
            return 'RETRY_FILTER'; // 真的彻底没牌了，才回去重新问 AI 海选
        }

        $new_batch = [];
        
        foreach ($urls_to_scrape as $url) {
            $content = $this->scrape_with_jina($url);
            if ($content) {
                // 极速鉴渣：如果在源头就是典型的 CC 防护或无意义页面，直接丢弃，不让它进 AI 质检
                if ($this->is_waf_blocked($content)) {
                    $this->add_log("  - 抓取成功但已被规则命中(防爬/无意义页): [{$url}]");
                    $data['blacklisted_urls'][] = $url;
                } elseif (mb_strlen($content) < 800) {
                    $this->add_log("  - 内容过短(" . mb_strlen($content) . " chars < 800)，判定为薄内容页，跳过AI质检直接淘汰: [{$url}]");
                    $data['blacklisted_urls'][] = $url;
                } else {
                    $new_batch[] = [
                        'url' => $url,
                        'text' => $content
                    ];
                    $this->add_log("  - 成功抓取(" . mb_strlen($content) . " chars，初步通过规则): [{$url}]");
                }
            } else {
                $this->add_log("  - 抓取失败: [{$url}]");
                $data['blacklisted_urls'][] = $url;
            }
            
            // 礼貌抓取：匿名模式限制 20 RPM，串行间隔 3 秒
            sleep(3);
        }
        
        // 核心流程解耦阶段 A：AI 质量检验员 (把关人)
        if (!empty($new_batch)) {
            $this->add_log("  - 正在呼叫 AI 质检员对 ".count($new_batch)." 篇新抓取素材进行深度把关...");
            
            $valid_indices = $this->validate_contents_with_ai($data['topic_title'], $new_batch);
            
            foreach ($new_batch as $index => $item) {
                if (in_array($index, $valid_indices)) {
                    $this->add_log("  - [✅ 质检通过]: {$item['url']} 包含可用干货，存入蓄水池。");
                    $data['valid_scraped_contents'][] = "【来源: {$item['url']}】\n" . $item['text'];
                    // 修复：必须拉黑已经被采纳的好文章，防止在“差额补足”的下一次重试中被选中
                    $data['blacklisted_urls'][] = $item['url'];
                } else {
                    $this->add_log("  - [❌ 质检淘汰]: {$item['url']} 经 AI 判定无实质信息，抛弃并拉黑。");
                    $data['blacklisted_urls'][] = $item['url'];
                }
            }
        }
        
        // 将已经被消费过的头部 urls_to_scrape 从队列中剔除
        $data['relevant_urls'] = $remaining_queue;
        $valid_count = count($data['valid_scraped_contents']);
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
        
        // 核心流程解耦阶段 B：判断蓄水池是否已满
        if ($valid_count < 3) {
            $this->add_log("警告: 蓄水池当前仅累计 {$valid_count} 篇有效素材，未达 3 篇标准，检查备用队列...");
            
            // Queue & Consume: 备用队列里还有未被消费的内容吗？（且未被拉黑）
            $has_usable_queue = false;
            foreach ($data['relevant_urls'] as $queued_url) {
                if (!in_array($queued_url, $data['blacklisted_urls'])) {
                    $has_usable_queue = true;
                    break;
                }
            }
            
            if ($has_usable_queue) {
                $this->add_log("提示: 备用队列中仍有候选文章，触发内部差额微循环进行提取...");
                return 'RETRY_SCRAPE'; // Frontend will call `scrape` immediately again without AI snippet call
            }
            
            $this->add_log("警告: 备用队列已耗尽。触发全面重新检索与筛查循环...");
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
            $data['retry_count'] = $retry_count + 1;
            set_transient("cam_material_{$topic_id}_data", $data, 3600);
            
            if ($data['retry_count'] > 5) { // 放宽一次重试次数以适应更严格的筛选
                throw new Exception("多次尝试抓取补充，可用内容始终不达标，已达到全面重试上限，请检查网络或更换主题");
            }

            return 'RETRY_FILTER'; 
        }
        
        $this->add_log("恭喜: 蓄水池已满 3 篇高质量素材！进入最终精炼提纯流程...");
        return 'SUCCESS';
    }

    private function step_summarize($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['valid_scraped_contents'])) throw new Exception("缺少抓取内容数据");

        $this->add_log("步骤5: AI正在汇总整理蓄水池中的优质物料...");
        
        try {
            $summary = $this->summarize_with_ai($data['topic_title'], $data['valid_scraped_contents'], $data['user_value'] ?? '');
        } catch (Exception $e) {
            throw $e;
        }

        // 既然素材已经通过验证了，那么 NO_VALID_CONTENT 的情况基本不可能出现
        if (is_string($summary) && strpos($summary, 'NO_VALID_CONTENT') !== false) {
            $reason = '';
            if (preg_match('/NO_VALID_CONTENT:\s*(.*)/i', $summary, $matches)) {
                $reason = ' (' . trim($matches[1]) . ')';
            }

            // 遇到极端异常：所有已验证素材组合起来仍然毫无意义，只能全盘抛弃重头再来
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
            $data['retry_count'] = $retry_count + 1;
            
            unset($data['valid_scraped_contents']);
            set_transient("cam_material_{$topic_id}_data", $data, 3600);

            $this->add_log("极端警告: 组合提炼失败" . $reason . "，全盘抛弃蓄水池并重置流程...");
            return 'RETRY_FILTER'; 
        }

        if (is_array($summary) && isset($summary['error'])) {
            throw new Exception("AI汇总错误: " . $summary['error']);
        }
        
        $this->add_log("步骤5: 整理精炼完成！");
        return $summary;
    }
    
    /**
     * 调用大模型快速判定刚抓取的文章内容是否含有实质干货
     * 返回含有干货的文章在原生数组中的下标列表 (e.g. [0, 2])
     */
    private function validate_contents_with_ai($topic, $new_batch) {
        $lang = $this->get_language_context();
        
        $prompt = "";
        if ($lang['use_english']) {
            $prompt .= "You are a professional content inspector. Our web scraper just collected " . count($new_batch) . " raw articles for the topic \"{$topic}\".\n";
            $prompt .= "Your job is to quickly scan them and identify which ones ACTUALLY CONTAIN substantive information (e.g., data, opinions, tutorials, specific cases) related to the topic.\n\n";
            $prompt .= "STRICTLY REJECT an article if it falls into these categories:\n";
            $prompt .= "- ❌ Junk/Anti-bot: The article is mostly completely garbled text, \"404 Not Found\", \"Access Denied\", CAPTCHA prompts, or \"Enable Javascript to continue\".\n";
            $prompt .= "- ❌ Off-topic: Disconnected from \"{$topic}\".\n";
            $prompt .= "- ❌ Pure Marketing: Pure promotional slogans with zero informative depth.\n\n";
            $prompt .= "You must respond with a JSON object containing the `valid_indices` array, which holds the zero-based indices of the articles that PASSED the inspection. If none pass, return an empty array `[]`.\n\n";
        } else {
            $prompt .= "你是一位专业的内容质检员。爬虫刚刚为主题“{$topic}”抓取了 " . count($new_batch) . " 篇文章原文。\n";
            $prompt .= "你的任务是快速扫描，判定哪些文章里【确实包含】能支撑该主题的实质干货（例如案例、数据、步骤、明确观点等）。\n\n";
            $prompt .= "出现以下情况的，必须将其【无情淘汰】：\n";
            $prompt .= "- ❌ 无意义废话：内容大部分是乱码、防抓取拦截语（如“请完成安全验证”、“开启JS环境”）、找不到页面（404）。\n";
            $prompt .= "- ❌ 严重离题：通篇东拉西扯，与“{$topic}”无关。\n";
            $prompt .= "- ❌ 纯广告软文：通篇兜售产品，无任何知识增量。\n\n";
            $prompt .= "你必须严格返回一个合法的 JSON 对象。该对象包含一个数组 `valid_indices`，里面填写所有【通过质检的】文章数组下标（索引起始为 0）。如果全军覆没，返回 `[]`。\n\n";
        }
        
        $prompt .= "```json\n{\n  \"valid_indices\": [0]\n}\n```\n\n";
        $prompt .= "------ THE ARTICLES ------\n\n";
        
        foreach ($new_batch as $index => $item) {
            // 为了节约 Token 和加快速度，质检环节每一篇只发送头部和尾部核心文本（大约1200字）
            $text = $item['text'];
            if (mb_strlen($text) > 1500) {
                // 截取前800，和后400
                $text = mb_substr($text, 0, 800) . "\n\n...(中间已折叠)...\n\n" . mb_substr($text, -400);
            }
            $prompt .= ">>> ARTICLE [INDEX: {$index}] [URL: {$item['url']}] <<<\n```text\n{$text}\n```\n\n";
        }
        
        try {
            $response = $this->unified_api->generate_content($prompt, 'material_search', ['timeout' => 60]);
            
            if (is_array($response) && isset($response['error'])) {
                $this->add_log("AI 质检 API 调用失败: {$response['error']}");
                // 如果 AI 质检失败了，降级处理：我们盲目相信它们都是好的，交给下一步重裁
                // 或者直接清退也是一种做法。这里选择为了流程畅通临时视为全过。
                return array_keys($new_batch);
            }
            
            // 提取 JSON
            $json_str = $response;
            if (preg_match('/```json(.*?)```/is', $response, $matches)) {
                $json_str = $matches[1];
            } elseif (preg_match('/\{.*\}/is', $response, $matches)) {
                $json_str = $matches[0];
            }
            
            $data = json_decode(trim($json_str), true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['valid_indices']) && is_array($data['valid_indices'])) {
                return $data['valid_indices'];
            }
            
            return array_keys($new_batch); // 解码失败盲猜全过
            
        } catch (Exception $e) {
            $this->add_log("AI 质检发生异常: " . $e->getMessage());
            return array_keys($new_batch); // 盲猜全过
        }
    }
    
    /**
     * 从 AI 响应中提取 URL 列表（强健版 V2.0）
     * 采用了与 search_queries 相同的三层兼容逻辑
     */
    private function extract_urls_from_response($response) {
        if (empty($response) || !is_string($response)) {
            return [];
        }

        $urls = [];
        $data = null;

        // 1. 尝试直接全文 JSON 解析
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data = $decoded;
        } 
        // 2. 如果全文失败，尝试正则提取大括号内容再解析 (针对前缀干扰)
        elseif (preg_match('/\{.*\}/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
        }
        // 3. 尝试正则提取方括号内容 (针对直接返回数组干扰)
        elseif (preg_match('/\[.*\]/s', $response, $matches)) {
            $data = json_decode($matches[0], true);
        }

        // --- 智能数据提取层 ---
        if (is_array($data)) {
            // 情况 A：标准结构 {"selected_urls": ["url1", "url2"]}
            if (isset($data['selected_urls']) && is_array($data['selected_urls'])) {
                $urls = $data['selected_urls'];
            }
            // 情况 B：直接返回了字符串数组 ["url1", "url2"]
            elseif (isset($data[0]) && is_string($data[0])) {
                $urls = $data;
            }
            // 情况 C：返回了对象数组 [{"selected_urls": "url1"}, ...] (虽然少见但兼容)
            elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['selected_urls'])) {
                $urls = array_column($data, 'selected_urls');
            }
        }

        // 4. 兜底方案：如果上述结构化提取全失败，尝试暴力正则匹配 http 链接
        if (empty($urls)) {
             if (preg_match_all('/https?:\/\/[^\s"\'<>\]]+/', $response, $matches)) {
                $urls = $matches[0];
             } else {
                error_log("ContentAuto Material Search:无法从响应中提取URL - " . substr($response, 0, 500));
                return [];
             }
        }

        return $urls;
    }

    private function scrape_with_jina($url, $retry_without_key = false) {
        $api_url = 'https://r.jina.ai/' . $url;
        
        $headers = [
            'X-Respond-With' => 'html',    // ✅ 请求返回原始 HTML，便于 Readability 提取正文
            'Accept' => 'text/html'
        ];

        // 检查是否有配置 API Key
        // 如果是重试模式 ($retry_without_key)，强制不使用 Key
        $jina_key = $retry_without_key ? '' : get_option('content_auto_jina_api_key', '');
        
        if (!empty($jina_key)) {
            $headers['Authorization'] = 'Bearer ' . $jina_key;
        }

        $args = [
            'timeout' => 60, // 增加到 60 秒，处理响应较慢的网页
            'headers' => $headers
        ];
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            $this->add_log("  - Jina 请求异常: " . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        
        // ✅ 智能降级：如果余额不足(402)且当前使用了Key，切换到匿名模式重试
        if ($code === 402 && !empty($jina_key) && !$retry_without_key) {
            $this->add_log("  - Jina API 余额不足(402)，自动降级为匿名模式重试...");
            return $this->scrape_with_jina($url, true);
        }

        if ($code !== 200) {
            $this->add_log("  - Jina 抓取失败 (状态码: " . $code . ")，尝试使用 WordPress 原生方法抓取兜底...");
            
            // 兜底方案：使用 WP 原生 wp_remote_get 抓取原始 URL
            $wp_args = [
                'timeout' => 30, // 原生抓取超时设短一点
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                ]
            ];
            $wp_response = wp_remote_get($url, $wp_args);
            
            if (is_wp_error($wp_response)) {
                $this->add_log("  - 原生抓取也失败: " . $wp_response->get_error_message());
                return false;
            }
            
            $wp_code = wp_remote_retrieve_response_code($wp_response);
            if ($wp_code !== 200) {
                $this->add_log("  - 原生抓取返回错误状态码: " . $wp_code);
                return false;
            }
            
            $this->add_log("  - 原生抓取成功，准备提取正文...");
            $html = wp_remote_retrieve_body($wp_response);
            return $this->extract_article_content($html, $url);
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // ✅ 使用 Readability 智能提取正文区域
        return $this->extract_article_content($html, $url);
    }

    /**
     * 使用 fivefilters/readability.php (Mozilla Readability 的 PHP 移植版) 提取正文
     * 然后通过 league/html-to-markdown 转换为结构化 Markdown
     * 自动过滤导航栏、侧边栏、页脚等噪音内容，只保留文章核心正文
     *
     * @param string $html 原始 HTML
     * @param string $url  原始 URL（用于修复相对链接）
     * @return string|false 提取的 Markdown 正文，失败返回 false
     */
    private function extract_article_content($html, $url) {
        if (empty($html) || mb_strlen($html) < 100) {
            return false;
        }

        try {
            // Step 1: Readability 提取正文区域（HTML 格式）
            $config = new \fivefilters\Readability\Configuration();
            $config->setFixRelativeURLs(true);
            $config->setOriginalURL($url);
            
            $readability = new \fivefilters\Readability\Readability($config);
            $readability->parse($html);
            
            $article_html = $readability->getContent();
            
            if (empty($article_html) || mb_strlen(strip_tags($article_html)) < 100) {
                $this->add_log("  Readability 提取内容过短，回退到纯文本模式");
                return $this->fallback_text_extract($html);
            }
            
            // Step 2: HTML → Markdown（保留标题层级、列表、加粗等结构）
            $converter = new \League\HTMLToMarkdown\HtmlConverter([
                'header_style'    => 'atx',        // ## 风格标题
                'strip_tags'      => true,         // 移除无法转换的标签
                'remove_nodes'    => 'img script style svg picture video canvas',  // 移除媒体/脚本等
                'hard_break'      => false,
                'use_autolinks'   => false,        // 不自动转换裸链接
            ]);
            
            $content = $converter->convert($article_html);
            
            // Step 3: 清理 Markdown 中的噪音
            $content = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $content);   // 残留图片语法 ![alt](url)
            $content = preg_replace('/!\[[^\]]*\]\[[^\]]*\]/', '', $content);  // 残留图片引用 ![alt][ref]
            $content = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $content);  // 链接只保留文字 [text](url) → text
            $content = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/', '$1', $content); // 引用链接只保留文字 [text][ref] → text
            $content = preg_replace('/\n{3,}/', "\n\n", $content);             // 过多空行
            $content = trim($content);
            
            // 最终有效性检查
            if (mb_strlen($content) < 100) {
                $this->add_log("  Markdown 转换后内容过短，回退到纯文本模式");
                return $this->fallback_text_extract($html);
            }
            
            $this->add_log("  Readability + Markdown 提取成功 (" . mb_strlen($content) . " chars)");
            
            // ✅ 正文已去噪且结构化，可以使用更大的长度限制
            return mb_substr($content, 0, 8000);
            
        } catch (\fivefilters\Readability\ParseException $e) {
            $this->add_log("  Readability 解析异常: " . $e->getMessage() . "，回退到纯文本模式");
            return $this->fallback_text_extract($html);
        } catch (\Exception $e) {
            $this->add_log("  提取/转换异常: " . $e->getMessage() . "，回退到纯文本模式");
            return $this->fallback_text_extract($html);
        }
    }

    /**
     * 降级方案：当 Readability 失败时，简单去除 HTML 标签提取文本
     */
    private function fallback_text_extract($html) {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        if (mb_strlen($text) < 100) {
            return false;
        }
        
        return mb_substr($text, 0, 6000);
    }

    private function summarize_with_ai($topic, $contents, $user_value = '') {
        $lang = $this->get_language_context();
        
        if ($lang['use_english']) {
            $prompt = "You are a senior professional researcher and editor. Please read the following web materials about the topic \"{$topic}\".\n";
            $prompt .= "Clean, deduplicate, and organize them into a well-structured \"Topic Research Library\".\n\n";
            $prompt .= "{$lang['language_instruction']}\n\n";
            if (!empty($user_value)) {
                $prompt .= "Reader's Core Focus: \"{$user_value}\"\n";
                $prompt .= "Please prioritize extracting and highlighting information directly relevant to this focus.\n\n";
            }
            $prompt .= "Core Principles:\n";
            $prompt .= "1. **Fact-First**: Prioritize verifiable facts, data, statistics, and specific cases. Subjective opinions should be secondary.\n";
            $prompt .= "2. **Information Purification**: Strictly base your summary on the **actual content from the source materials**. Absolutely no embellishment, fabrication, or creative extrapolation.\n";
            $prompt .= "3. **Noise Removal**:\n";
            $prompt .= "   - Remove structural web noise (navigation menus, code snippets, copyright notices).\n";
            $prompt .= "   - Aggressively remove filler without substantive information (e.g., empty openings, marketing boasts, repetitive slogans).\n";
            $prompt .= "4. **Cross-Source Deduplication**: If multiple sources contain the exact same information or statistics, retain only the most complete version. Do not repeat identical points.\n";
            $prompt .= "5. **Logical Reorganization**: Merge related information scattered across multiple sources into clearly organized knowledge modules.\n\n";
            $prompt .= "Suggested Output Structure (Adapt as needed based on the materials):\n";
            $prompt .= "- **Core Findings**: The most important conclusions and insights.\n";
            $prompt .= "- **Data & Statistics**: Specific numbers, percentages, and trends.\n";
            $prompt .= "- **Practical Advice/Steps**: Actionable methodologies or guides.\n\n";
            $prompt .= "Special Instruction: As long as the materials contain data, opinions, cases, or steps related to the topic, retain and organize them.\n";
            $prompt .= "If you truly cannot extract any substantive value, return **NO_VALID_CONTENT: [Brief Reason]**.\n\n";
            $prompt .= "Output Control: **Directly output** the formatted content using standard Markdown syntax (headings, bold, lists, tables). Do NOT wrap the entire output inside a code block (no opening \`\`\`markdown or closing \`\`\`). Do NOT include any preamble, postscript, source notes, or disclaimers.\n\n";
            $prompt .= "Materials below:\n\n";
            foreach ($contents as $i => $content) {
                $prompt .= "--- Material " . ($i + 1) . " ---\n" . $content . "\n\n";
            }
        } else {
            $prompt = "你是一位资深的专业研究员和编辑。请阅读以下关于主题“{$topic}”的多篇网络素材，\n";
            $prompt .= "将它们清洗、去重并整理为一份结构清晰的“主题研究资料库”。\n\n";
            if (!empty($user_value)) {
                $prompt .= "读者核心关注点：“{$user_value}”\n";
                $prompt .= "请在整理时优先提炼和突出与上述关注点直接相关的信息。\n\n";
            }
            $prompt .= "核心原则：\n";
            $prompt .= "1. **事实优先**：优先保留可验证的事实、数据、统计数字和具体案例。主观观点和推测放在次要位置。\n";
            $prompt .= "2. **信息提纯**：必须基于**原文实际内容**进行整理。严禁进行任何形式的扩写、编造或发散性创作。\n";
            $prompt .= "3. **去噪脱水**：\n";
            $prompt .= "   - 剔除导航菜单、代码片段、版权声明等网页结构噪音。\n";
            $prompt .= "   - 大力删减空洞的开场白、营销自夸、重复口号和情绪化表达。\n";
            $prompt .= "4. **跨源去重**：如果多篇素材包含相同的信息或数据，只保留一份最完整的版本，不要重复列举。\n";
            $prompt .= "5. **逻辑重组**：将分散在多篇素材中的同类信息归类合并，形成条理清晰的知识模块。\n\n";
            $prompt .= "建议的输出结构（可根据素材实际内容灵活调整）：\n";
            $prompt .= "- **核心发现**：最重要的结论和洞察\n";
            $prompt .= "- **数据与统计**：具体的数字、比例、趋势\n";
            $prompt .= "- **实操建议/步骤**：可落地的方法论或操作指南\n\n";
            $prompt .= "特别指令：只要素材中包含任何与主题相关的数据、观点、案例或步骤，都请予以整理保留。\n";
            $prompt .= "如果确实完全无法从素材中提取实质价值，请返回 **NO_VALID_CONTENT: [简要理由]**。\n\n";
            $prompt .= "输出控制：请**直接输出** Markdown 格式的正文内容（使用标题、加粗、列表、表格等语法）。\n";
            $prompt .= "**严禁**将整体内容包裹在代码块中（即不要在最外层加 \`\`\`markdown 或 \`\`\` 标记）。\n";
            $prompt .= "不要包含任何前言、后记、来源说明、免责注释等无关内容。\n\n";
            $prompt .= "素材内容如下：\n\n";
            foreach ($contents as $i => $content) {
                $prompt .= "--- 素材 " . ($i + 1) . " ---\n" . $content . "\n\n";
            }
        }
        
        // 增加超时时间到 300秒 (5分钟)，防止汇总长文时超时，开启 usage 以检测是否模型输出受限
        $response_data = $this->unified_api->generate_content($prompt, 'material_summary', [
            'timeout' => 300,
            'return_usage' => true
        ]);
        
        // 检查错误（finish_reason == 'length' 已由底层自动触发 API 轮询切换）
        if (is_array($response_data) && isset($response_data['error'])) {
            throw new Exception("AI汇总失败: " . $response_data['error']);
        }
        
        $response = is_array($response_data) && isset($response_data['content']) ? $response_data['content'] : $response_data;
        
        // 智能剥壳：如果 AI 将整个回答打包进了一个 ```markdown 代码块里，则剥掉外壳
        // 关键：只在整个响应刚好就是一个单独的 fence 时生效，不会影响正文内部的 ```json 等代码块
        if (is_string($response)) {
            $trimmed = trim($response);
            if (preg_match('/^```(?:markdown)?\s*\n([\s\S]*)\n```$/s', $trimmed, $fence_matches)) {
                $response = trim($fence_matches[1]);
            }
        }
        
        return $response;
    }



    // 保留旧方法名以防兼容性问题，虽然内部已不再直接使用
    private function clean_json_string($str) {
        return $str; 
    }

    private function add_log($msg) {
        $this->log[] = date('H:i:s') . ' - ' . $msg;
    }

    public function save_material_to_topic($topic_id, $summary) {
        global $wpdb;
        $table = $wpdb->prefix . 'content_auto_topics';
        $result = $wpdb->update($table, ['reference_material' => $summary], ['id' => $topic_id], ['%s'], ['%d']);
        if ($result === false) return new WP_Error('db_error', '数据库更新失败: ' . $wpdb->last_error);
        return true;
    }

    /**
     * 执行全自动素材搜索流程（后台任务专用）
     * 包含完整的 搜索 -> 过滤(重试) -> 抓取 -> 汇总 -> 保存 逻辑
     * 
     * @param int $topic_id 主题ID
     * @return bool|WP_Error 成功返回true，失败返回WP_Error
     */
    public function execute_full_auto_search($topic_id) {
        $topic_info = $this->get_topic_info($topic_id);
        if (empty($topic_info) || empty($topic_info['title'])) {
            return new WP_Error('topic_not_found', "主题 ID {$topic_id} 不存在或标题为空");
        }
        
        // 彻底重构：弃用原有的数百行独立逻辑，完全复用具有最新优化的 process_step 方法链条
        // 这意味着后台任务与前台页面享有完全一致的 Queue & Consume, RETRY_SCRAPE, 800字防空转 等全部优势
        set_time_limit(600);
        
        // 修复: process_step 内部每次会重置 $this->log，所以必须在外层采用累加罗记方式收集日志
        $all_logs = [];
        
        // 1. 强制初始化（清理上次可能残留的队列和状态）
        $init_result = $this->process_step('init', $topic_id);
        $all_logs = array_merge($all_logs, $init_result['log'] ?? []);
        if (!$init_result['success']) {
            $this->log = $all_logs;
            return new WP_Error('init_failed', "初始化失败: " . ($init_result['message'] ?? ''));
        }
        
        // 2. 编排并逐级调度
        $current_step = 'search';
        $max_iterations = 20; // 考虑抓取失败重试，给充足的跳跃次数限制防死循环
        $iteration = 0;
        
        while ($current_step !== 'done' && $iteration < $max_iterations) {
            $iteration++;
            $result = $this->process_step($current_step, $topic_id);
            $all_logs = array_merge($all_logs, $result['log'] ?? []);
            
            if (!$result['success']) {
                $error_msg = $result['message'] ?? '未知错误';
                $this->log = $all_logs;
                return new WP_Error('step_failed', "自动执行步骤 {$current_step} 失败: " . $error_msg);
            }
            
            $next_step = $result['next_step'] ?? '';
            
            if (empty($next_step)) {
                $this->log = $all_logs;
                return new WP_Error('pipeline_broken', "自动流程异常中断：未指明下一个步骤节点");
            }
            
            // 如果流程跑通到了终点，执行落库处理
            if ($next_step === 'done') {
                $summary = $result['data']['summary'] ?? '';
                if (empty($summary)) {
                    $this->log = $all_logs;
                    return new WP_Error('empty_summary', "汇总内容为空，落库取消");
                }
                
                $save_res = $this->save_material_to_topic($topic_id, $summary);
                if (is_wp_error($save_res)) {
                    $this->log = $all_logs;
                    return $save_res;
                }
                
                $all_logs[] = date('H:i:s') . " - 全自动后台任务彻底闭环，资料库已落库成功！";
                break;
            }
            
            // 将控制器指针指向 process_step 自主决策出的下一个环节（含重试流转逻辑）
            $current_step = $next_step;
            
            // 后台任务非用户等待，适当增加一点停顿防高频发请求
            usleep(1000000); // 1.0s
        }
        
        $this->log = $all_logs; // 将完整的累积日志写回实例，外部调用者可通过 get_log() 读取
        
        if ($iteration >= $max_iterations) {
             return new WP_Error('max_iterations_exceeded', "任务内部重试跳转次数超载，防死循环强制终止");
        }
        
        return true;
    }
    // --- 辅助方法 ---

    private function get_topic_info($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'content_auto_topics';
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $topic_id));
        
        if (!$row) return null;
        
        // 构造返回数组
        $info = [
            'title' => '',
            'user_value' => ''
        ];
        
        if (isset($row->title) && !empty($row->title)) $info['title'] = $row->title;
        elseif (isset($row->topic)) $info['title'] = $row->topic;
        
        if (isset($row->user_value)) $info['user_value'] = $row->user_value;
        
        return $info;
    }
    
    // 兼容旧调用 (如果有其他地方调用get_topic_title)
    private function get_topic_title($topic_id) {
        $info = $this->get_topic_info($topic_id);
        return $info ? $info['title'] : null;
    }
}