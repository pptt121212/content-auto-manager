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

        $this->add_log("步骤2: 正在反推搜索意图并生成关键词...");
        
        // ✅ 1. 使用AI反推搜索词
        $search_queries = $this->generate_search_queries_with_ai($data['topic_title'], $data['user_value'] ?? '');
        $this->add_log("AI生成的搜索策略: " . implode(", ", $search_queries));
        
        // ✅ 2. 执行聚合搜索（对每个词分别搜索，然后合并）
        $all_results = [];
        foreach ($search_queries as $query) {
            $this->add_log("正在检索: [{$query}] ...");
            $search_response = content_auto_search($query);
            
            // 容错：单个搜索词失败不应导致整体失败，除非全部失败
            if (is_wp_error($search_response) || empty($search_response['success']) || empty($search_response['results'])) {
                continue;
            }
            
            // 获取全局黑名单配置
            $global_blacklist = get_option('content_auto_material_search_blacklist', ['csdn.net', 'zhihu.com']);

            // 简单去重合并
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

                // 使用 link 作为去重键
                $all_results[$item['link']] = $item; 
            }
            
            // 避免触发 API 速率限制 (可选)
            usleep(500000); // 0.5s
        }
        
        if (empty($all_results)) {
            throw new Exception("所有关键词的搜索结果均为空");
        }
        
        // 转回索引数组并取前 20 条（因为源头多了，稍微扩大候选池）
        $final_results = array_values($all_results);
        $final_results = array_slice($final_results, 0, 20);
        
        $this->add_log("步骤2: 聚合搜索完成，共获取 " . count($final_results) . " 条去重结果");
        
        // 更新数据
        $data['search_results'] = $final_results;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }
    
    /**
     * 使用 AI 生成更具 E-E-A-T 价值的搜索词
     */
    private function generate_search_queries_with_ai($title, $user_value) {
        $prompt = "我需要为一个主题撰写一篇符合 Google E-E-A-T 标准的深度文章。请帮我构思 3 个搜索关键词，以便我在 Google 找到最优质的**原始参考素材**。\n\n";
        $prompt .= "主题：{$title}\n";
        if (!empty($user_value)) {
            $prompt .= "核心用户价值：{$user_value}\n";
        }
        
        $prompt .= "\n请模拟一位资深内容主编的查资料逻辑，针对以下 3 个不同的**搜索意图**，分别生成一个最精准的搜索词：\n\n";
        
        $prompt .= "1. **【溯源/权威】**：目的是找到该主题的官方定义、底层原理或权威解释。\n";
        $prompt .= "   - *（思考方向：官方文档、百科、原理图解、维基等）*\n";
        $prompt .= "2. **【数据/深度】**：目的是找到支撑观点的行业数据、趋势分析或深度研究。\n";
        $prompt .= "   - *（思考方向：行业报告、统计数据、白皮书、现状分析等）*\n";
        $prompt .= "3. **【实战/经验】**：目的是找到具体的落地步骤、避坑经验或真实案例。\n";
        $prompt .= "   - *（思考方向：实操教程、案例复盘、步骤拆解、经验分享等）*\n\n";
        
        $prompt .= "**严格约束：**\n";
        $prompt .= "- **语言一致**：必须与主题语言保持绝对一致（中文搜中文，英文搜英文）。\n";
        $prompt .= "- **自然精准**：请使用最符合人类搜索习惯的自然短语（通常 2-3 个词），严禁堆砌生硬的后缀。\n";
        $prompt .= "- **拒绝复读**：不要直接返回原标题。\n";
        
        $prompt .= "**输出格式约束：**\n";
        $prompt .= "请务必返回一个**纯 JSON 对象**，必须严格遵守以下结构：\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"search_queries\": [\n";
        $prompt .= "    \"搜索词1\",\n";
        $prompt .= "    \"搜索词2\",\n";
        $prompt .= "    \"搜索词3\"\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= "```\n";
        $prompt .= "严禁返回对象数组（如 `[{\"search_queries\":...}]`），严禁包含 Markdown 标记之外的任何解释性文字。";
        
        $response = $this->unified_api->generate_content($prompt, 'search_intent');
        
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
            
            // 跳过文档类文件（解析效果差）
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
        
        // 确保AI每次只处理新的候选集
        $this->add_log("步骤3: AI正在分析筛选相关内容 (剩余候选: " . count($valid_results) . "条)...");
        // ✅ 传递 user_value
        $relevant_urls = $this->analyze_relevance_with_ai($data['topic_title'], $valid_results, $data['user_value'] ?? '');
        
        if (empty($relevant_urls)) {
            throw new Exception("AI认为没有相关内容可供抓取");
        }
        $this->add_log("步骤3: 筛选出 " . count($relevant_urls) . " 个高相关链接");
        
        $data['relevant_urls'] = $relevant_urls;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }

// ... (omitted code) ...

    private function analyze_relevance_with_ai($topic, $results, $user_value = '') {
        $prompt = "你是一位专业的内容研究员。我准备撰写一篇题为“{$topic}”的深度文章，需要寻找最优质的参考素材。\n";
        
        // ✅ 引入用户价值上下文
        if (!empty($user_value)) {
            $prompt .= "目标读者的核心痛点/价值点是：“{$user_value}”。\n";
        }
        
        $prompt .= "请分析以下搜索结果（标题+摘要），预判哪些网页点开后最有可能提供**Google E-E-A-T**所需的权威背书或深度信息，且**能直接回应读者的痛点**。\n";
        
        $prompt .= "筛选标准（按优先级从高到低排序）：\n";
        $prompt .= "1. **⭐⭐⭐ 权威背书 (Authority)**：如果结果是官方文档、政府/机构白皮书、学术论文或维基百科定义，**请绝对优先选择**（即使摘要较短）。\n";
        $prompt .= "2. **⭐⭐ 由于数据 (Expertise)**：包含具体统计数据、行业研究报告、趋势图表的内容次之。\n";
        $prompt .= "3. **⭐ 深度经验 (Experience)**：如果以上两者缺席，再选择逻辑清晰、篇幅详实的长文教程或实操复盘。\n";
        $prompt .= "4. **❌ 排除低质/离题**：坚决剔除新闻快讯、广告软文、纯列表页，以及**与读者痛点无关**的内容。\n\n";
        
        $prompt .= "数量限制：请严格精选 **1-2 个**最符合上述高优先级标准的链接。\n\n";

        $prompt .= "**输出格式约束：**\n";
        $prompt .= "请务必返回一个**纯 JSON 对象**，必须严格遵守以下结构：\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"selected_urls\": [\n";
        $prompt .= "    \"https://example.com/url1\",\n";
        $prompt .= "    \"https://example.com/url2\"\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";
        $prompt .= "```\n";
        $prompt .= "严禁返回对象数组，严禁包含 Markdown 标记之外的任何解释性文字。";

        foreach ($results as $index => $item) {
            $prompt .= ($index + 1) . ". [{$item['title']}]({$item['link']})\n   摘要: {$item['snippet']}\n";
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

    private function step_scrape($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['relevant_urls'])) throw new Exception("缺少待抓取链接数据");

        $this->add_log("步骤4: 开始抓取网页内容 (Powered by Jina Reader)...");
        $scraped_contents = [];
        
        // ✅ 初始化黑名单
        if (!isset($data['blacklisted_urls'])) {
            $data['blacklisted_urls'] = [];
        }
        
        foreach ($data['relevant_urls'] as $url) {
            $content = $this->scrape_with_jina($url);
            if ($content) {
                $scraped_contents[] = "【来源: {$url}】\n" . $content;
                $this->add_log("  - 成功抓取: [{$url}]");
            } else {
                $this->add_log("  - 抓取失败: [{$url}]");
                $data['blacklisted_urls'][] = $url;
            }
            
            // ✅ 礼貌抓取：匿名模式限制 20 RPM，串行间隔 3 秒
            sleep(3);
            
            if (count($scraped_contents) >= 2) break; 
        }
        
        if (empty($scraped_contents)) {
            // 抓取全失败，触发重试流程
            $this->add_log("警告: 所有选中链接均抓取失败，触发重试流程...");
            
            // 增加重试计数
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
            $data['retry_count'] = $retry_count + 1;
            
            // 失败的 URL 已经在上面循环中加入 blacklisted_urls 了
            
            // 清理当前状态
            unset($data['relevant_urls']);
            // 不 unset scraped_contents 因为本来就是空的
            
            set_transient("cam_material_{$topic_id}_data", $data, 3600);
            
            if ($data['retry_count'] > 3) {
                throw new Exception("连续多次抓取失败，已达到重试上限，请检查网络或更换主题");
            }

            return 'RETRY_FILTER';
        }
        
        $data['scraped_contents'] = $scraped_contents;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
        return 'SUCCESS';
    }

    private function step_summarize($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['scraped_contents'])) throw new Exception("缺少抓取内容数据");

        $this->add_log("步骤5: AI正在汇总整理所有物料...");
        
        try {
            $summary = $this->summarize_with_ai($data['topic_title'], $data['scraped_contents']);
        } catch (Exception $e) {
            throw $e;
        }

        // 检查特殊返回
        if (is_string($summary) && strpos($summary, 'NO_VALID_CONTENT') !== false) {
            // 这里我们不仅要抛出异常，还要更新 retry_count 让流程知道
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
             // 增加重试计数
            $data['retry_count'] = $retry_count + 1;
            
            // 将当前使用的URL加入黑名单
            if (!isset($data['blacklisted_urls'])) {
                $data['blacklisted_urls'] = [];
            }
            if (isset($data['relevant_urls'])) {
                $data['blacklisted_urls'] = array_merge($data['blacklisted_urls'], $data['relevant_urls']);
            }
            // 清理当前状态
            unset($data['relevant_urls']);
            unset($data['scraped_contents']);
            set_transient("cam_material_{$topic_id}_data", $data, 3600);

            $this->add_log("警告: AI判定当前素材无效，触发重试流程...");
            return 'RETRY_FILTER'; 
        }

        if (is_array($summary) && isset($summary['error'])) {
            throw new Exception("AI汇总错误: " . $summary['error']);
        }
        
        $this->add_log("步骤5: 整理完成！");
        return $summary;
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
            'X-With-Images-Summary' => 'true'
        ];

        // 检查是否有配置 API Key
        // 如果是重试模式 ($retry_without_key)，强制不使用 Key
        $jina_key = $retry_without_key ? '' : get_option('content_auto_jina_api_key', '');
        
        if (!empty($jina_key)) {
            $headers['Authorization'] = 'Bearer ' . $jina_key;
        }

        $args = [
            'timeout' => 30,
            'headers' => $headers
        ];
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) return false;

        $code = wp_remote_retrieve_response_code($response);
        
        // ✅ 智能降级：如果余额不足(402)且当前使用了Key，切换到匿名模式重试
        if ($code === 402 && !empty($jina_key) && !$retry_without_key) {
            $this->add_log("Jina API 余额不足(402)，自动降级为匿名模式重试...");
            return $this->scrape_with_jina($url, true);
        }

        if ($code !== 200) return false;
        
        // ✅ 抓取内容长度限制（平衡信息量与API负载）
        return mb_substr(wp_remote_retrieve_body($response), 0, 2000); 
    }

    private function summarize_with_ai($topic, $contents) {
        $prompt = "你是一位资深的专业编辑。请阅读以下关于主题“{$topic}”的多篇素材内容，将它们**清洗并整理**为一份详实的“主题研究资料库”。\n";
        $prompt .= "核心原则：\n";
        $prompt .= "1. **信息提纯**：必须基于**原文实际内容**进行整理。严禁进行任何形式的扩写、编造或发散性创作。\n";
        $prompt .= "2. **去噪脱水**：\n";
        $prompt .= "   - **技术去噪**：剔除导航菜单、代码片段、版权声明等网页结构噪音。\n";
        $prompt .= "   - **内容去噪**：**大力删减**无实质信息的“各种废话”。例如：空洞的开场白（“在当今数字化时代...”）、营销自夸（“我们是行业领导者...”）、重复的口号和情绪化表达。\n";
        $prompt .= "3. **逻辑重组**：将分散在多篇素材中的同类信息（如观点、数据、步骤）归类合并，形成条理清晰的知识点。\n";
        $prompt .= "4. **格式规范**：使用标准 Markdown 格式，层级清晰。\n\n";
        
        $prompt .= "特别指令：请尽力从素材中挖掘有价值的信息。**只要素材中包含任何与主题相关的数据、观点、案例或步骤（哪怕是碎片化的），都请予以整理保留。**\n";
        $prompt .= "在以下情况下，返回 **NO_VALID_CONTENT**：\n";
        $prompt .= "- 素材内容与主题核心对象不相关（如讨论的是完全不同的产品/概念）；\n";
        $prompt .= "- 素材全是乱码、404错误页、验证码拦截页或纯广告；\n";
        $prompt .= "- 无法从素材中提取任何对撰写文章有实际价值的信息。\n\n";
        
        $prompt .= "输出控制：请**直接输出**整理后的资料库正文，**不要包含**任何前言、后记或“本资料库基于...”等废话。\n\n";
        $prompt .= "素材内容如下：\n\n";
        foreach ($contents as $i => $content) {
            $prompt .= "--- 素材 " . ($i + 1) . " ---\n" . $content . "\n\n";
        }
        
        // 增加超时时间到 300秒 (5分钟)，防止汇总长文时超时
        $response = $this->unified_api->generate_content($prompt, 'material_summary', ['timeout' => 300]);
        
        if (is_array($response) && isset($response['error'])) {
            throw new Exception("AI汇总失败: " . $response['error']);
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
        // 先检查主题是否存在
        $topic_info = $this->get_topic_info($topic_id);
        if (empty($topic_info) || empty($topic_info['title'])) {
            return new WP_Error('topic_not_found', "主题 ID {$topic_id} 不存在或标题为空");
        }
        
        $topic_title = $topic_info['title'];
        $user_value = $topic_info['user_value'];

        $blacklisted_urls = [];
        $retry_count = 0;
        $max_retries = 2; // 最大重试次数（总共尝试3次）
        $search_results_cache = null;

        // 使用 do-while 结构来支持 retry 逻辑
        while ($retry_count <= $max_retries) {
            try {
                // Step 1: 搜索 (只做一次，后面重试复用结果)
                if (!$search_results_cache) {
                    
                    // ✅ 核心改动：使用 AI 生成搜索词 + 聚合搜索
                    $this->add_log("开始反推搜索词 (Retry: {$retry_count})...");
                    $search_queries = $this->generate_search_queries_with_ai($topic_title, $user_value);
                    $this->add_log("搜索词策略: " . implode(" | ", $search_queries));
                    
                    $all_results = [];
                    foreach ($search_queries as $query) {
                        $search_response = content_auto_search($query);
                        if (!is_wp_error($search_response) && !empty($search_response['success']) && !empty($search_response['results'])) {
                            // 获取全局黑名单
                            $global_blacklist = get_option('content_auto_material_search_blacklist', ['csdn.net', 'zhihu.com']);
                            
                            foreach ($search_response['results'] as $item) {
                                // 动态黑名单过滤
                                $is_blacklisted = false;
                                foreach ($global_blacklist as $keyword) {
                                    if (!empty($keyword) && stripos($item['link'], trim($keyword)) !== false) {
                                        $is_blacklisted = true;
                                        break;
                                    }
                                }
                                if ($is_blacklisted) continue;

                                $all_results[$item['link']] = $item; // 去重
                            }
                        }
                        usleep(300000); // 0.3s 间隔
                    }
                    
                    if (empty($all_results)) {
                        throw new Exception("所有关键词的搜索结果均为空");
                    }
                    
                    // 取前 20 条
                    $search_results_cache = array_slice(array_values($all_results), 0, 20);
                }

                // Step 2: 过滤 (排除黑名单和文档类文件)
                $valid_results = [];
                $excluded_extensions = ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx', '.zip', '.rar'];
                
                foreach ($search_results_cache as $item) {
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
                    // 没有候选结果了，这通常意味着所有结果都被加入黑名单或尝试过了
                    // 无法继续重试
                    throw new Exception("没有更多可用的搜索结果（已过滤或已用尽）");
                }

                // Step 3: AI 筛选 (选最佳的1-2个)
                // ✅ 修复：传递 user_value 以保持与前台一致的筛选标准
                $relevant_urls = $this->analyze_relevance_with_ai($topic_title, $valid_results, $user_value);
                if (empty($relevant_urls)) {
                    // AI 筛选失败：当前批次的所有搜索结果都不符合要求
                    // 这种情况下，或许可以尝试扩大黑名单并重试（如果有剩余的），或者直接失败
                    // 这里我们假设如果AI从15个里都选不出来，那就是真的没有
                    throw new Exception("AI 未筛选出相关内容（搜索结果质量不足或与主题关联度低）");
                }

                // Step 4: 抓取
                $scraped_contents = [];
                foreach ($relevant_urls as $url) {
                    $content = $this->scrape_with_jina($url);
                    if ($content) {
                        $scraped_contents[] = "【来源: {$url}】\n" . $content;
                    } else {
                        // 如果抓取失败，也应该加入黑名单避免下次再抓
                        $blacklisted_urls[] = $url;
                    }
                    
                    // ✅ 礼貌抓取：匿名模式限制 20 RPM，串行间隔 3 秒 (与 AJAX 保持一致)
                    sleep(3);

                    // ✅ 与 AJAX 版本保持一致：最多抓取 2 条（AI 筛选最大数量）
                    if (count($scraped_contents) >= 2) break;
                }
                
                if (empty($scraped_contents)) {
                    // 抓取全失败，强制进入下一次循环
                    // 注意：失败的URL已经被加入黑名单，下次循环时 $valid_results 会排除它们
                    throw new Exception("抓取连续失败"); // 抛出异常以触发 catch 块中的重试逻辑
                }

                // Step 5: 汇总
                $summary = $this->summarize_with_ai($topic_title, $scraped_contents);

                // Step 6: 检查结果 (RETRY_FILTER 逻辑)
                if (is_string($summary) && strpos($summary, 'NO_VALID_CONTENT') !== false) {
                    // AI 判定无效，抛出异常以触发重试逻辑
                    // 必须先将这批URL加入黑名单
                    $blacklisted_urls = array_merge($blacklisted_urls, $relevant_urls); 
                    throw new Exception("AI判定内容无效(NO_VALID_CONTENT)"); 
                }

                if (is_array($summary) && isset($summary['error'])) {
                     throw new Exception("AI汇总错误: " . $summary['error']);
                }

                // 安全检查：如果AI返回空内容，视为失败
                if (empty($summary) || !is_string($summary) || trim($summary) === '') {
                    throw new Exception("AI汇总内容为空，无法保存");
                }

                // 成功！保存结果
                $this->add_log("搜索完成，准备保存资料库 (长度: " . mb_strlen($summary) . " chars)");
                $this->save_material_to_topic($topic_id, $summary);
                
                return true;

            } catch (Exception $e) {
                // ✅ 真正的重试逻辑
                $retry_count++;
                $error_msg = $e->getMessage();
                $this->add_log("尝试 {$retry_count} 失败: {$error_msg}。准备重试...");

                if ($retry_count > $max_retries) {
                    // 达到最大重试次数，返回最后的错误
                    return new WP_Error('process_failed', "达到最大重试次数，最后错误: " . $error_msg);
                }
                
                // 确保在重试前有短暂暂停，避免API频率限制
                sleep(2);
                
                // 继续下一次 while 循环
                continue;
            }
        }
        
        return new WP_Error('max_retries_exceeded', "达到最大重试次数");
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