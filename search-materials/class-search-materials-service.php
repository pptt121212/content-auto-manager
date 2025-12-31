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
                    $this->step_scrape($topic_id);
                    $result['next_step'] = 'summarize';
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
        $topic_title = $this->get_topic_title($topic_id);
        if (empty($topic_title)) {
            throw new Exception("无法找到ID为 {$topic_id} 的主题或标题为空");
        }
        // 清理旧的缓存数据
        delete_transient("cam_material_{$topic_id}_data");
        
        $this->add_log("步骤1: 初始化任务成功");
        $this->add_log("目标主题: " . $topic_title);
        
        // 保存基础信息
        $data = ['topic_title' => $topic_title];
        set_transient("cam_material_{$topic_id}_data", $data, 3600); // 1小时过期
    }

    private function step_search($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (!$data || empty($data['topic_title'])) {
            throw new Exception("任务数据丢失，请重新开始");
        }

        $this->add_log("步骤2: 开始执行全网搜索...");
        $search_response = content_auto_search($data['topic_title']);
        
        // ✅ 加固错误检查（与异步版本保持一致）
        if (is_wp_error($search_response)) {
            throw new Exception("搜索请求失败: " . $search_response->get_error_message());
        }
        
        // ✅ 严格验证返回格式
        if (!is_array($search_response)) {
            throw new Exception("搜索API返回格式错误：期望数组，实际为 " . gettype($search_response));
        }
        
        if (empty($search_response['success'])) {
            throw new Exception("搜索API返回失败状态");
        }
        
        if (!isset($search_response['results']) || !is_array($search_response['results'])) {
            throw new Exception("搜索API未返回有效的 results 数组");
        }
        
        if (empty($search_response['results'])) {
            throw new Exception("无搜索结果");
        }
        
        $search_results = array_slice($search_response['results'], 0, 15);
        $this->add_log("步骤2: 搜索完成，获取到 " . count($search_results) . " 条结果");
        
        // 更新数据
        $data['search_results'] = $search_results;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }

    private function step_filter($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['search_results'])) throw new Exception("缺少搜索结果数据");

        // --- 黑名单过滤逻辑 ---
        $blacklisted_urls = isset($data['blacklisted_urls']) ? $data['blacklisted_urls'] : [];
        $valid_results = [];
        
        foreach ($data['search_results'] as $item) {
            if (!in_array($item['link'], $blacklisted_urls)) {
                $valid_results[] = $item;
            }
        }
        
        if (empty($valid_results)) {
            throw new Exception("所有搜索结果均已被过滤或尝试过，无更多可用内容");
        }
        
        // 确保AI每次只处理新的候选集
        $this->add_log("步骤3: AI正在分析筛选相关内容 (剩余候选: " . count($valid_results) . "条)...");
        $relevant_urls = $this->analyze_relevance_with_ai($data['topic_title'], $valid_results);
        
        if (empty($relevant_urls)) {
            throw new Exception("AI认为没有相关内容可供抓取");
        }
        $this->add_log("步骤3: 筛选出 " . count($relevant_urls) . " 个高相关链接");
        
        $data['relevant_urls'] = $relevant_urls;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }

    private function step_scrape($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['relevant_urls'])) throw new Exception("缺少待抓取链接数据");

        $this->add_log("步骤4: 开始抓取网页内容 (Powered by Jina Reader)...");
        $scraped_contents = [];
        
        // ✅ 初始化黑名单（用于后续重试）
        if (!isset($data['blacklisted_urls'])) {
            $data['blacklisted_urls'] = [];
        }
        
        foreach ($data['relevant_urls'] as $url) {
            $content = $this->scrape_with_jina($url);
            if ($content) {
                // 读取原始标题（稍微繁琐点，从search_results里不仅要URL还要标题）
                // 简化起见，直接用URL
                $scraped_contents[] = "【来源: {$url}】\n" . $content;
                $this->add_log("  - 成功抓取: [{$url}]");
            } else {
                $this->add_log("  - 抓取失败: [{$url}]");
                // ✅ 将抓取失败的URL加入黑名单，避免重试时再次尝试
                $data['blacklisted_urls'][] = $url;
            }
            
            if (count($scraped_contents) >= 2) break; // 与 AI 筛选数量一致：最多 2 条 
        }
        
        if (empty($scraped_contents)) {
            throw new Exception("未能成功抓取任何网页内容");
        }
        
        $data['scraped_contents'] = $scraped_contents;
        set_transient("cam_material_{$topic_id}_data", $data, 3600);
    }

    private function step_summarize($topic_id) {
        $data = get_transient("cam_material_{$topic_id}_data");
        if (empty($data['scraped_contents'])) throw new Exception("缺少抓取内容数据");

        $this->add_log("步骤5: AI正在汇总整理所有物料...");
        $summary = $this->summarize_with_ai($data['topic_title'], $data['scraped_contents']);
        
        // --- 检查是否需要重试 ---
        if (strpos($summary, 'NO_VALID_CONTENT') !== false) {
            $retry_count = isset($data['retry_count']) ? intval($data['retry_count']) : 0;
            
            if ($retry_count >= 2) {
                // 超过最大重试次数，抛出异常或返回失败信息
                throw new Exception("已达到最大重试次数(2次)，且未能生成有效内容。请检查主题是否过于冷门。");
            }
            
            // 增加重试计数
            $data['retry_count'] = $retry_count + 1;
            
            // 将当前使用的URL加入黑名单
            if (!isset($data['blacklisted_urls'])) {
                $data['blacklisted_urls'] = [];
            }
            if (isset($data['relevant_urls']) && is_array($data['relevant_urls'])) {
                $data['blacklisted_urls'] = array_merge($data['blacklisted_urls'], $data['relevant_urls']);
            }
            
            // 清理当前状态以便重新开始filter
            unset($data['relevant_urls']);
            unset($data['scraped_contents']);
            
            set_transient("cam_material_{$topic_id}_data", $data, 3600);
            
            $this->add_log("警告: AI判定当前素材无效。");
            $this->add_log("正在触发第 " . $data['retry_count'] . " 次重试 (排除已用链接)...");
            
            return 'RETRY_FILTER';
        }
        
        $this->add_log("步骤5: 整理完成！");
        
        // 完成后可以删除 transient，或者保留一会供调试
        // delete_transient("cam_material_{$topic_id}_data");
        
        return $summary;
    }

    // --- 辅助方法（复用之前的逻辑） ---

    private function get_topic_title($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'content_auto_topics';
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $topic_id));
        
        if (!$row) return null;
        
        // 根据最新的表结构，字段名为 title
        if (isset($row->title) && !empty($row->title)) return $row->title;
        
        // 兼容性保留
        if (isset($row->topic)) return $row->topic;
        
        return null;
    }

    private function analyze_relevance_with_ai($topic, $results) {
        $prompt = "你是一位专业的内容研究员。我准备撰写一篇题为“{$topic}”的深度文章，需要寻找最优质的参考素材。\n";
        $prompt .= "请分析以下搜索结果（标题+摘要），预判哪些网页点开后最有可能包含**深度观点、详细数据、具体案例或实操步骤**。\n";
        $prompt .= "筛选标准：\n";
        $prompt .= "1. **高相关性**：内容必须直接从核心角度切入主题，而非仅仅关键词沾边。\n";
        $prompt .= "2. **信息密度**：优先选择长文、分析报告、深度教程；排除短新闻、广告页或纯列表页。\n";
        $prompt .= "3. **数量限制**：严格只精选 1-2 个**最值得阅读**的链接。如有凑数嫌疑，宁缺毋滥，只选1个也可以。\n\n";
        $prompt .= "请直接返回纯JSON数组：[\"url1\", \"url2\"]（或仅[\"url1\"]）。\n\n";
        foreach ($results as $index => $item) {
            $prompt .= ($index + 1) . ". [{$item['title']}]({$item['link']})\n   摘要: {$item['snippet']}\n";
        }
        
        $response = $this->unified_api->generate_content($prompt, 'material_filtering');
        
        // ✅ 修复：正确处理错误情况
        if (is_array($response) && isset($response['error'])) {
            // API 调用失败
            error_log("ContentAuto Material Search: AI 筛选 API 错误 - " . $response['error']);
            return [];
        }
        
        // 清理并解析 JSON
        $json_str = $this->clean_json_string($response);
        $urls = json_decode($json_str, true);
        
        // ✅ 增强：验证返回数据的有效性
        if (!is_array($urls)) {
            error_log("ContentAuto Material Search: AI 返回的不是有效的 JSON 数组 - 原始响应: " . substr($response, 0, 200));
            return [];
        }
        
        // ✅ 过滤：确保返回的是 URL 字符串数组
        $valid_urls = array_filter($urls, function($url) {
            return is_string($url) && filter_var($url, FILTER_VALIDATE_URL);
        });
        
        return array_values($valid_urls);
    }

    private function scrape_with_jina($url) {
        $api_url = 'https://r.jina.ai/' . $url;
        
        $headers = [
            'X-With-Images-Summary' => 'true'
        ];

        // 检查是否有配置 API Key
        $jina_key = get_option('content_auto_jina_api_key', '');
        if (!empty($jina_key)) {
            $headers['Authorization'] = 'Bearer ' . $jina_key;
        }

        $args = [
            'timeout' => 30,
            'headers' => $headers
        ];
        $response = wp_remote_get($api_url, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;
        return mb_substr(wp_remote_retrieve_body($response), 0, 3000); 
    }

    private function summarize_with_ai($topic, $contents) {
        $prompt = "你是一位资深的专业编辑。请阅读以下关于主题“{$topic}”的多篇素材内容，将它们**清洗并整理**为一份详实的“主题研究资料库”。\n";
        $prompt .= "核心原则：\n";
        $prompt .= "1. **信息提纯**：必须基于**原文实际内容**进行整理。严禁进行任何形式的扩写、编造或发散性创作。你只是搬运工和整理者，不是创作者。\n";
        $prompt .= "2. **去伪存真**：剔除所有导航菜单、广告语、代码片段、版权声明等网页噪音，只保留与主题直接相关的正文信息。\n";
        $prompt .= "3. **逻辑重组**：将分散在多篇素材中的同类信息（如观点、数据、步骤）归类合并，形成条理清晰的知识点。\n";
        $prompt .= "4. **格式规范**：使用标准 Markdown 格式，层级清晰。\n\n";
        
        $prompt .= "特别指令：请先对素材进行'价值评估'。如果剔除导航、广告和代码噪音后，剩余有效内容满足以下任一情况，请判定为无效素材，直接返回 **NO_VALID_CONTENT**：\n";
        $prompt .= "- **文不对题**：内容与主题“{$topic}”无逻辑关联（如抓取到了网站首页或错误页面）。\n";
        $prompt .= "- **信息匮乏**：仅包含简短的口号、目录或纯粹的关键词堆砌，缺乏具体的事实、数据或论述支持。\n";
        $prompt .= "- **无法引用**：内容质量极低，无法为深度文章写作提供任何实质性参考。\n\n";
        $prompt .= "但是，如果素材**具备实质性参考价值**，请直接开始整理，严格遵守以下输出控制：\n";
        $prompt .= "输出控制：请**直接输出**整理后的资料库正文，**不要包含**任何前言、后记、自我解释、对执行原则的说明或“本资料库基于...”等废话。\n\n";
        $prompt .= "素材内容如下：\n\n";
        foreach ($contents as $i => $content) {
            $prompt .= "--- 素材 " . ($i + 1) . " ---\n" . $content . "\n\n";
        }
        $response = $this->unified_api->generate_content($prompt, 'material_summary');
        if (is_array($response) && isset($response['error'])) {
            throw new Exception("AI汇总失败: " . $response['error']);
        }
        return $response;
    }

    private function clean_json_string($str) {
        // 去除前后空白
        $str = trim($str);
        
        // 移除 Markdown 代码块标记（```json 或 ```）
        $str = preg_replace('/^```json?\s*/i', '', $str);
        $str = preg_replace('/\s*```$/', '', $str);
        
        // 再次去除空白
        $str = trim($str);
        
        // 提取 JSON 数组（从第一个 [ 到最后一个 ]）
        if (preg_match('/\[.*\]/s', $str, $matches)) {
            return $matches[0];
        }
        
        // 如果没有找到数组标记，返回原始字符串
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
        $topic_title = $this->get_topic_title($topic_id);
        if (empty($topic_title)) {
            return new WP_Error('topic_not_found', "主题 ID {$topic_id} 不存在或标题为空");
        }

        $blacklisted_urls = [];
        $retry_count = 0;
        $max_retries = 2; // 最大重试次数（总共尝试3次）
        $search_results_cache = null;

        // 使用 do-while 结构来支持 retry 逻辑
        while ($retry_count <= $max_retries) {
            try {
                // Step 1: 搜索 (只做一次，后面重试复用结果)
                if (!$search_results_cache) {
                    $search_response = content_auto_search($topic_title);
                    
                    // ✅ 加固错误检查
                    if (is_wp_error($search_response)) {
                        throw new Exception("搜索请求失败: " . $search_response->get_error_message());
                    }
                    
                    // ✅ 严格验证返回格式
                    if (!is_array($search_response)) {
                        throw new Exception("搜索API返回格式错误：期望数组，实际为 " . gettype($search_response));
                    }
                    
                    if (empty($search_response['success'])) {
                        throw new Exception("搜索API返回失败状态");
                    }
                    
                    if (!isset($search_response['results']) || !is_array($search_response['results'])) {
                        throw new Exception("搜索API未返回有效的 results 数组");
                    }
                    
                    if (empty($search_response['results'])) {
                        throw new Exception("无搜索结果");
                    }
                    
                    // 只取前15条作为候选池
                    $search_results_cache = array_slice($search_response['results'], 0, 15);
                }

                // Step 2: 过滤 (排除黑名单)
                $valid_results = [];
                foreach ($search_results_cache as $item) {
                    if (!in_array($item['link'], $blacklisted_urls)) {
                        $valid_results[] = $item;
                    }
                }
                
                if (empty($valid_results)) {
                    throw new Exception("没有更多可用的搜索结果（已过滤或已用尽）");
                }

                // Step 3: AI 筛选 (选最佳的1-2个)
                $relevant_urls = $this->analyze_relevance_with_ai($topic_title, $valid_results);
                if (empty($relevant_urls)) {
                    // AI 筛选失败：所有搜索结果都不符合要求
                    // 这种情况直接失败，不重试（因为搜索结果不会变化）
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
                    
                    // ✅ 与 AJAX 版本保持一致：最多抓取 2 条（AI 筛选最大数量）
                    if (count($scraped_contents) >= 2) break;
                }
                
                if (empty($scraped_contents)) {
                    // 抓取全失败，强制进入下一次循环
                    $retry_count++;
                    if ($retry_count > $max_retries) throw new Exception("抓取连续失败，达到最大重试次数");
                    continue;
                }

                // Step 5: 汇总
                $summary = $this->summarize_with_ai($topic_title, $scraped_contents);

                // Step 6: 检查结果 (RETRY_FILTER 逻辑)
                if (is_string($summary) && strpos($summary, 'NO_VALID_CONTENT') !== false) {
                    // AI 判定无效，需要重试
                    $retry_count++;
                    $blacklisted_urls = array_merge($blacklisted_urls, $relevant_urls); // 将这批URL加入黑名单
                    
                    if ($retry_count > $max_retries) {
                        throw new Exception("达到最大重试次数(2次)，AI仍判定内容无效。");
                    }
                    // 继续下一次循环 (continue while)
                    continue;
                }

                if (is_array($summary) && isset($summary['error'])) {
                     throw new Exception("AI汇总错误: " . $summary['error']);
                }

                // 安全检查：如果AI返回空内容，视为失败，不要保存为空
                if (empty($summary) || !is_string($summary) || trim($summary) === '') {
                    throw new Exception("AI汇总内容为空，无法保存");
                }

                // 成功！保存结果
                $this->add_log("搜索完成，准备保存资料库 (长度: " . mb_strlen($summary) . " chars)");
                $this->save_material_to_topic($topic_id, $summary);
                
                return true;

            } catch (Exception $e) {
                // 如果是“没有更多结果”等致命错误，停止重试
                // 但如果是临时网络错误，也许该重试？这里简单起见，异常即终止
                return new WP_Error('process_failed', $e->getMessage());
            }
        }
        
        return new WP_Error('max_retries_exceeded', "达到最大重试次数");
    }
}
