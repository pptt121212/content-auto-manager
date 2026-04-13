<?php

/**
 * 内容过滤器类
 * 用于过滤文章内容的外部包装标记
 * 
 * 过滤流程：
 * 1. 移除 Pollinations 广告内容
 * 2. 移除 AI 模型思考标签 (<think></think>)
 * 3. 修复转义字符
 * 4. 提取 JSON 字段内容（如适用）
 * 5. 移除 Markdown 代码块包装
 * 6. 优化 Markdown 链接格式
 * 
 * @package ContentAutoManager
 * @subpackage ContentFilter
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_ContentFilter {
    
    /**
     * 过滤文章内容，移除外部包装标记
     * 
     * 处理步骤：
     * 1. 移除 Pollinations 广告内容
     * 2. 移除 AI 模型思考标签 (<think></think>) - 某些 AI 模型会返回包含思考过程的标签
     * 3. 修复转义字符，防止 Markdown 解析错误
     * 4. 提取 JSON 字段内容（如果内容是 JSON 格式）
     * 5. 移除 Markdown 代码块包装
     * 6. 优化 Markdown 链接格式
     * 
     * @param string $content 原始内容
     * @return string 过滤后的内容
     */
    public function filter_content($content) {
        if (empty($content)) {
            return $content;
        }

        // 初始化日志记录器（仅在调试模式下）
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            if (!class_exists('Yali_AI_Writer_PluginLogger')) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
            }
            $logger = new Yali_AI_Writer_PluginLogger();

            $logger->debug('CONTENT_FILTER_START', '开始内容过滤处理', array(
                'original_length' => strlen($content),
                'original_content' => $content
            ));
        }

        $original_content = $content;
        $content = trim($content);

        // 记录处理流程开始
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('CONTENT_FILTER_FLOW_START', '内容过滤流程开始', array(
                'original_length' => strlen($original_content),
                'trimmed_length' => strlen($content),
                'processing_steps' => array()
            ));
        }

        // 零步骤：过滤Pollinations广告内容
        $content_before_ad_filter = $content;
        $content = $this->remove_pollinations_ads($content);
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_ad_filter !== $content);
            $logger->debug('STEP_1_ADS_FILTER', '步骤1: 过滤Pollinations广告', array(
                'method_used' => 'remove_pollinations_ads',
                'input_length' => strlen($content_before_ad_filter),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Ads removed' : 'No ads found',
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 零步骤之二：过滤思考标签内容（某些AI模型返回的 <think></think> 标签）
        $content_before_think_filter = $content;
        $content = $this->remove_think_tags($content);
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_think_filter !== $content);
            $logger->debug('STEP_2_THINK_FILTER', '步骤2: 过滤思考标签', array(
                'method_used' => 'remove_think_tags',
                'input_length' => strlen($content_before_think_filter),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Think tags removed' : 'No think tags found',
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第零步：修复转义字符，防止Markdown解析错误
        $content_before_escape_fix = $content;
        $content = $this->fix_escaped_characters($content);
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_escape_fix !== $content);
            $logger->debug('STEP_3_ESCAPE_FIX', '步骤3: 修复转义字符', array(
                'method_used' => 'fix_escaped_characters',
                'input_length' => strlen($content_before_escape_fix),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Escaped characters fixed' : 'No escape fixes needed',
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('ESCAPED_CHARACTERS_FIXED', '修复转义字符', array(
                'before_fix_length' => strlen(trim($original_content)),
                'after_fix_length' => strlen($content),
                'content_after_fix' => $content
            ));
            
            // 添加调试：检查转义字符修复后的内容长度
            $logger->debug('DEBUG_AFTER_ESCAPE_FIX', '转义字符修复后的内容状态', array(
                'content_length_after_escape_fix' => strlen($content),
                'is_empty_after_escape_fix' => empty($content),
                'content_preview_after_escape_fix' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第一步：尝试提取JSON字段内容
        $filtered = $this->extract_json_content($content);
        if ($filtered !== $content) {
            $final_content = $this->remove_markdown_wrapper($filtered);

            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                $logger->debug('STEP_4A_JSON_EXTRACT', '步骤4A: 提取JSON字段内容', array(
                    'method_used' => 'extract_json_content',
                    'input_length' => strlen($content),
                    'extracted_length' => strlen($filtered),
                    'final_length' => strlen($final_content),
                    'content_changed' => true,
                    'change_details' => 'JSON content extracted',
                    'filter_path' => 'json_extraction'
                ));

                $logger->debug('STEP_4B_WRAPPER_REMOVE', '步骤4B: 移除Markdown包装', array(
                    'method_used' => 'remove_markdown_wrapper',
                    'input_length' => strlen($filtered),
                    'output_length' => strlen($final_content),
                    'content_changed' => ($filtered !== $final_content),
                    'change_details' => ($filtered !== $final_content) ? 'Wrapper removed' : 'No wrapper found'
                ));

                $logger->debug('CONTENT_FILTER_COMPLETE', '内容过滤完成（JSON路径）', array(
                    'original_length' => strlen($original_content),
                    'final_length' => strlen($final_content),
                    'content_reduced' => strlen($original_content) - strlen($final_content),
                    'filter_path' => 'json_extraction',
                    'all_steps' => array(
                        'step1_ads' => 'remove_pollinations_ads',
                        'step2_think' => 'remove_think_tags',
                        'step3_escape' => 'fix_escaped_characters',
                        'step4a_json' => 'extract_json_content',
                        'step4b_wrapper' => 'remove_markdown_wrapper'
                    )
                ));
            }

            return $final_content;
        }

        // 步骤4: 尝试提取JSON（未匹配），继续标准流程
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $logger->debug('STEP_4_JSON_SKIPPED', '步骤4: JSON提取（跳过）', array(
                'method_used' => 'extract_json_content',
                'input_length' => strlen($content),
                'output_length' => strlen($content),
                'content_changed' => false,
                'change_details' => 'Content is not JSON, skipped'
            ));
        }

        // 第二步：移除Markdown代码块包装
        $content_before_wrapper = $content;
        $content = $this->remove_markdown_wrapper($content);

        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_wrapper !== $content);
            $logger->debug('STEP_5_WRAPPER_REMOVE', '步骤5: 移除Markdown代码块包装', array(
                'method_used' => 'remove_markdown_wrapper',
                'input_length' => strlen($content_before_wrapper),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Markdown wrapper removed' : 'No wrapper found',
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第三步：优化Markdown链接格式
        $content_before_optimization = $content;
        $content = $this->optimize_markdown_links($content);

        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_optimization !== $content);
            $logger->debug('STEP_6_LINKS_OPTIMIZE', '步骤6: 优化Markdown链接格式', array(
                'method_used' => 'optimize_markdown_links',
                'input_length' => strlen($content_before_optimization),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Links optimized' : 'No links to optimize',
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第四步：预处理Markdown格式，确保CommonMark正确解析
        // 这是关键步骤：为标题、列表等元素后添加必要的空行
        $content_before_preprocessing = $content;
        $content = $this->preprocess_markdown_format($content);

        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            $step_changed = ($content_before_preprocessing !== $content);
            $logger->debug('STEP_7_PREPROCESS', '步骤7: 预处理Markdown格式', array(
                'method_used' => 'preprocess_markdown_format',
                'input_length' => strlen($content_before_preprocessing),
                'output_length' => strlen($content),
                'content_changed' => $step_changed,
                'change_details' => $step_changed ? 'Markdown format preprocessed' : 'No preprocessing needed',
                'rules_applied' => array(
                    'headers_empty_line' => true,
                    'lists_empty_line' => true,
                    'blockquotes_empty_line' => true,
                    'code_blocks_empty_line' => true,
                    'separators_empty_line' => true,
                    'bold_before_lists' => true
                ),
                'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));

            $logger->debug('CONTENT_FILTER_COMPLETE', '内容过滤流程完成', array(
                'filter_path' => 'standard_filtering',
                'original_length' => strlen($original_content),
                'final_length' => strlen($content),
                'total_reduction' => strlen($original_content) - strlen($content),
                'all_processing_steps' => array(
                    array('step' => 1, 'method' => 'remove_pollinations_ads', 'changed' => ($content_before_ad_filter !== $this->remove_pollinations_ads($original_content))),
                    array('step' => 2, 'method' => 'remove_think_tags', 'changed' => ($content_before_think_filter !== $this->remove_think_tags($content_before_ad_filter))),
                    array('step' => 3, 'method' => 'fix_escaped_characters', 'changed' => ($content_before_escape_fix !== $content)),
                    array('step' => 4, 'method' => 'extract_json_content', 'executed' => false, 'reason' => 'Content not JSON'),
                    array('step' => 5, 'method' => 'remove_markdown_wrapper', 'changed' => ($content_before_wrapper !== $content)),
                    array('step' => 6, 'method' => 'optimize_markdown_links', 'changed' => ($content_before_optimization !== $content)),
                    array('step' => 7, 'method' => 'preprocess_markdown_format', 'changed' => $step_changed)
                ),
                'final_content_preview' => substr($content, 0, 300) . (strlen($content) > 300 ? '...' : '')
            ));
        }

        return $content;
    }

  /**
     * 移除Pollinations广告内容
     * 检测多种广告模式并移除
     * 
     * @param string $content 原始内容
     * @return string 移除广告后的内容
     */
  private function remove_pollinations_ads($content) {
        if (empty($content)) {
            return $content;
        }

        $original_length = strlen($content);
        
        // 定义多种广告起始模式（按优先级排序）
        $ad_patterns = array(
            // 模式1: 代理服务广告 (Need proxies cheaper than the market?)
            '/\n?---\n?\s*Need proxies cheaper than the market\?.*$/is',
            // 模式2: 升级计划广告 (Upgrade your plan to remove this message)
            '/\n?---\n?\s*Upgrade your plan to remove this message.*$/is',
            // 模式3: Discord 邀请广告
            '/\n?---\n?\s*discord\.gg\/airforce.*$/is',
            // 模式4: 原有的 Pollinations.AI 支持广告
            '/\n?---\n?\s*\*\*Support Pollinations\.AI:.*$/is',
            // 模式5: 通用推广链接模式（包含多个 http 链接的推广块）
            '/\n?---\n?\s*(?:https?:\/\/|www\.)[a-zA-Z0-9.-]+.*(?:https?:\/\/|www\.)[a-zA-Z0-9.-]+.*$/is',
        );
        
        $cleaned_content = $content;
        $ad_removed = false;
        
        foreach ($ad_patterns as $pattern) {
            if (preg_match($pattern, $cleaned_content)) {
                $cleaned_content = preg_replace($pattern, '', $cleaned_content);
                $ad_removed = true;
                // 只应用第一个匹配的模式，避免过度过滤
                break;
            }
        }
        
        // 如果没有找到广告标记，检查是否包含特定的广告域名
        if (!$ad_removed) {
            // 检测分散的广告链接模式
            $ad_domains = array(
                '/\n?Need proxies cheaper than the market\?\s*\nhttps?:\/\/op\.wtf.*$/is',
                '/\n?Upgrade your plan to remove this message.*\nhttps?:\/\/api\.airforce.*$/is',
                '/\n?discord\.gg\/airforce.*$/im',
            );
            
            foreach ($ad_domains as $domain_pattern) {
                if (preg_match($domain_pattern, $cleaned_content)) {
                    $cleaned_content = preg_replace($domain_pattern, '', $cleaned_content);
                    $ad_removed = true;
                }
            }
        }
        
        // 统一清理：移除末尾可能的多余换行符和分隔线
        $cleaned_content = rtrim($cleaned_content, "\n");
        $cleaned_content = preg_replace('/\n?---\s*$/', '', $cleaned_content);
        $cleaned_content = rtrim($cleaned_content, "\n");
        
        // 记录调试信息
        if ($ad_removed && defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            if (!class_exists('Yali_AI_Writer_PluginLogger')) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
            }
            $logger = new Yali_AI_Writer_PluginLogger();
            $logger->debug('POLLINATIONS_ADS_PATTERN_REMOVED', '移除Pollinations广告内容（多种模式）', array(
                'original_length' => $original_length,
                'cleaned_length' => strlen($cleaned_content),
                'removed_length' => $original_length - strlen($cleaned_content)
            ));
        }

        return $cleaned_content;
  }
  /**
     * 移除思考标签内容
     * 某些AI模型会返回包含 <think></think> 标签的内容，其中包含模型的思考过程
     * 这些内容应该被过滤掉，不应该出现在最终的文章中
     * 
     * @param string $content 原始内容
     * @return string 移除思考标签后的内容
     */
  private function remove_think_tags($content) {
        if (empty($content)) {
            return $content;
        }

        // 移除 <think>...</think> 标签及其内容
        // 使用 s 修饰符使 . 能匹配换行符，处理多行内容
        // 使用非贪婪匹配 .*? 避免匹配过多内容
        $content = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content);
        
        // 清理可能留下的多余空白
        $content = preg_replace('/^\s*\n/m', '', $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        
        // 清理开头和结尾的空白
        $content = trim($content);
        
        return $content;
  }

  /**
     * 修复转义字符，防止Markdown解析错误
    
    /**
     * 修复转义字符，防止Markdown解析错误
     * 主要处理API返回内容中的转义字符，特别是多层嵌套的转义
     * 采用简洁的统一处理规则，避免复杂的逻辑导致深层异常
     * 
     * @param string $content 原始内容
     * @return string 修复后的内容
     */
    private function fix_escaped_characters($content) {
        if (empty($content)) {
            return $content;
        }
        
        // === 第1步：处理换行符（优先级最高）===
        $content = str_replace('\\\\\\\\n', "\n", $content);  // 双重转义的换行符
        $content = str_replace('\\\\n', "\n", $content);    // 单重转义的换行符
        $content = str_replace("\\\\r\\n", "\n", $content);  // Windows换行符
        $content = str_replace('\\\\r', "\n", $content);    // 转义回车符
        $content = str_replace("\\r", "\n", $content);     // 普通回车符统一
        
        // === 第2步：处理制表符和空白 ===
        $content = str_replace('\\\\\\\\t', "\t", $content);
        $content = str_replace('\\\\t', "\t", $content);
        $content = str_replace('\\\\s', ' ', $content);     // 转义空格
        $content = str_replace('&nbsp;', ' ', $content);  // HTML实体空格
        
        // === 第3步：处理常见转义字符（URL、引号、斜杠）===
        $content = str_replace('https:\\\/', 'https://', $content);
        $content = str_replace('http:\\\/', 'http://', $content);
        $content = str_replace('\\/', '/', $content);
        $content = str_replace('\\"', '"', $content);
        $content = str_replace("\\\\'", "'", $content);
        
        // === 第4步：处理HTML实体（简洁版本）===
        $html_entities = [
            '&quot;' => '"',
            '&amp;' => '&',
            '&lt;' => '<',
            '&gt;' => '>',
            '&apos;' => "'"
        ];
        $content = str_replace(array_keys($html_entities), array_values($html_entities), $content);
        
        // === 第5步：清理多余空白（保持简洁）===
        $content = preg_replace('/\n{3,}/', "\n\n", $content);  // 最多保留2个换行
        $content = preg_replace('/ {2,}/', ' ', $content);       // 最多保留1个空格
        $content = preg_replace('/\t+/', ' ', $content);         // 制表符转空格
        $content = trim($content);
        
        // === 第6步：修复常见格式问题（仅处理最关键的）===
        // 标题后的换行问题（修复错误的正则表达式）
        $content = preg_replace('/^(#{1,6}\s+[^\n]*?)(\n)([^\n])/m', '$1$2$3', $content);
        
        // 列表项格式
        $content = preg_replace('/^(\s*[-*+]\s+)[\t ]+/', '$1', $content);
        
        // === 第7步：清理多余反斜杠（最后的清理）===
        $content = preg_replace('/\\\\+([a-zA-Z])/', '$1', $content);  // 清理字母前的多余反斜杠
        $content = preg_replace('/\\\\+$/', '', $content);              // 清理末尾多余反斜杠
        
        return $content;
    }
    
    /**
     * 提取JSON字段内容
     * 
     * 重要设计原则：
     * 1. API响应的JSON解析应该在API层完成，这里只处理"AI返回的内容本身是JSON"的情况
     * 2. 只有当内容明确是完整的JSON对象时才尝试提取
     * 3. 不应该因为内容中包含JSON代码示例而误判
     * 
     * @param string $content 原始内容
     * @return string 提取的内容或原内容
     */
    private function extract_json_content($content) {
        // 初始化日志
        if (!class_exists('Yali_AI_Writer_PluginLogger')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new Yali_AI_Writer_PluginLogger();
        
        $trimmed = trim($content);
        
        // 🚀 关键检查：只有当整个内容是完整的JSON对象时才处理
        // 如果内容不是以 { 开头并以 } 结尾，直接返回原内容
        if (strlen($trimmed) < 2 || $trimmed[0] !== '{' || substr($trimmed, -1) !== '}') {
            $logger->info('[CONTENT_FILTER_NOT_JSON] Content is not wrapped in JSON braces, returning as-is', [
                'content_length' => strlen($content),
                'first_char' => substr($trimmed, 0, 1),
                'last_char' => substr($trimmed, -1)
            ]);
            return $content;
        }
        
        // 尝试解析为 JSON
        $json_data = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_data)) {
            $logger->info('[CONTENT_FILTER_JSON_INVALID] Content looks like JSON but failed to parse', [
                'content_length' => strlen($content),
                'json_error' => json_last_error_msg()
            ]);
            return $content;
        }
        
        $logger->info('[CONTENT_FILTER_JSON_PARSED] Valid JSON detected', [
            'content_length' => strlen($content),
            'json_keys' => array_keys($json_data)
        ]);
        
        // 优先处理结构化文章JSON（markdown + sections 格式）
        $structured_content = $this->extract_structured_article_content($json_data);
        if ($structured_content !== null) {
            $logger->info('[CONTENT_FILTER_STRUCTURED] Extracted structured article content', [
                'extracted_length' => strlen($structured_content)
            ]);
            return $this->fix_escaped_characters($structured_content);
        }
        
        // 查找已知的内容字段（按优先级排序）
        $known_content_fields = [
            'content',           // 最常见的内容字段
            'article',           // 文章字段
            'article_content',   // 文章内容字段
            'text',              // 文本字段
            'body',              // 正文字段
            'markdown',          // Markdown字段
            'html',              // HTML字段
            'result',            // 结果字段
            'output',            // 输出字段
            'message',           // 消息字段（可能是 AI 返回的）
        ];
        
        foreach ($known_content_fields as $field) {
            if (isset($json_data[$field]) && is_string($json_data[$field]) && strlen($json_data[$field]) > 50) {
                $logger->info('[CONTENT_FILTER_KNOWN_FIELD] Extracted from known field', [
                    'field_name' => $field,
                    'extracted_length' => strlen($json_data[$field])
                ]);
                return $this->fix_escaped_characters(trim($json_data[$field]));
            }
        }
        
        // 如果没有找到已知字段，查找最长的字符串字段（但必须足够长以确定是文章内容）
        $longest_field = null;
        $longest_length = 0;
        $longest_key = '';
        
        foreach ($json_data as $key => $value) {
            if (is_string($value) && strlen($value) > $longest_length) {
                $longest_length = strlen($value);
                $longest_field = $value;
                $longest_key = $key;
            }
        }
        
        // 只有当最长字段足够长（至少100字符）时才提取，避免提取短字段如 "role": "assistant"
        if ($longest_field !== null && $longest_length >= 100) {
            $logger->info('[CONTENT_FILTER_LONGEST_FIELD] Extracted longest string field', [
                'field_name' => $longest_key,
                'extracted_length' => $longest_length
            ]);
            return $this->fix_escaped_characters(trim($longest_field));
        }
        
        // 如果 JSON 中没有找到合适的内容字段，返回原内容
        $logger->warning('[CONTENT_FILTER_NO_CONTENT_FIELD] JSON parsed but no suitable content field found', [
            'json_keys' => array_keys($json_data),
            'longest_field_length' => $longest_length
        ]);
        
        return $content;
    }
    
    /**
     * 提取结构化文章JSON的内容
     * 支持格式：{"markdown": "...", "sections": [...], "chapter3": [...], "chapter4": [...], ...}
     * 
     * @param array $json_data 解析后的JSON数据
     * @return string|null 合并后的文章内容，如果不是结构化文章则返回null
     */
    private function extract_structured_article_content($json_data) {
        if (!is_array($json_data)) {
            return null;
        }
        
        // 检查是否包含markdown字段（结构化文章的标识）
        if (!isset($json_data['markdown']) || !is_string($json_data['markdown'])) {
            return null;
        }
        
        $content_parts = [];
        
        // 1. 添加主要内容部分（markdown字段）
        $content_parts[] = $json_data['markdown'];
        
        // 2. 添加sections内容
        if (isset($json_data['sections']) && is_array($json_data['sections'])) {
            foreach ($json_data['sections'] as $section) {
                if (isset($section['content']) && is_string($section['content'])) {
                    $content_parts[] = $section['content'];
                }
            }
        }
        
        // 3. 添加chapter3内容
        if (isset($json_data['chapter3']) && is_array($json_data['chapter3'])) {
            foreach ($json_data['chapter3'] as $chapter) {
                if (isset($chapter['content']) && is_string($chapter['content'])) {
                    $content_parts[] = $chapter['content'];
                }
            }
        }
        
        // 4. 添加chapter4内容
        if (isset($json_data['chapter4']) && is_array($json_data['chapter4'])) {
            foreach ($json_data['chapter4'] as $chapter) {
                if (isset($chapter['content']) && is_string($chapter['content'])) {
                    $content_parts[] = $chapter['content'];
                }
            }
        }
        
        // 5. 添加其他可能的章节字段（如chapter5、chapter6等）
        foreach ($json_data as $key => $value) {
            if (preg_match('/^chapter\d+$/', $key) && is_array($value)) {
                foreach ($value as $chapter) {
                    if (isset($chapter['content']) && is_string($chapter['content'])) {
                        $content_parts[] = $chapter['content'];
                    }
                }
            }
        }
        
        // 6. 添加结尾内容
        if (isset($json_data['closing']) && is_string($json_data['closing'])) {
            $content_parts[] = $json_data['closing'];
        }
        
        // 如果没有找到任何内容部分，返回null
        if (empty($content_parts)) {
            return null;
        }
        
        // 用双换行符连接所有内容部分
        return implode("\n\n", $content_parts);
    }
    
    /**
     * 专门修复多层嵌套转义的JSON字符串
     * 采用简洁的规则，仅处理最常见的多层转义问题
     * 
     * @param string $json_string JSON字符串
     * @return string 修复后的JSON字符串
     */
    private function fix_multilayer_escaped_json($json_string) {
        // 仅处理最常见的多层转义问题（保持简洁）
        $replacements = [
            '\\\\n' => '\\n',    // 双重换行符转义
            '\\\\r' => '\\r',    // 双重回车符转义
            '\\\\t' => '\\t',    // 双重制表符转义
            '\\\\"' => '\\"',    // 双重引号转义
            '\\\\/' => '\\/',    // 双重斜杠转义
            '\\\\\\' => '\\\\',  // 双重反斜杠转义
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $json_string);
    }
    
    /**
     * 移除Markdown代码块包装
     * 
     * 重要：只移除包裹"整个内容"的代码块标记（开头和结尾），
     * 保留文章内部的代码块，避免破坏代码示例。
     * 
     * @param string $content 原始内容
     * @return string 移除包装后的内容
     */
    private function remove_markdown_wrapper($content) {
        $trimmed = trim($content);
        
        // 情况1：内容以 ```markdown 开头，以 ``` 结尾
        if (strpos($trimmed, '```markdown') === 0 && substr($trimmed, -3) === '```') {
            // 找到第一个换行后的内容开始位置
            $first_newline = strpos($trimmed, "\n");
            if ($first_newline !== false) {
                // 移除开头的 ```markdown 和结尾的 ```，保留中间所有内容
                $content = substr($trimmed, $first_newline + 1);
                $content = substr($content, 0, -3); // 移除结尾的 ```
                return trim($content);
            }
        }
        
        // 情况2：内容以 ``` 开头，以 ``` 结尾（且不是文章内部的代码块）
        if (strpos($trimmed, '```') === 0 && substr($trimmed, -3) === '```') {
            // 检查是否只有一对代码块标记（包裹整个内容）
            $code_block_count = substr_count($trimmed, '```');
            if ($code_block_count === 2) {
                // 只有开头和结尾各一个，说明是包裹整个文章的
                $first_newline = strpos($trimmed, "\n");
                if ($first_newline !== false) {
                    $content = substr($trimmed, $first_newline + 1);
                    $content = substr($content, 0, -3); // 移除结尾的 ```
                    return trim($content);
                }
            }
            // 如果有多个 ```，说明文章内部有代码块，不能移除
        }
        
        // 其他情况：返回原内容，不移除任何代码块
        return $content;
    }
    
    /**
     * 检查内容是否包含外部包装
     * 
     * @param string $content 内容
     * @return bool 是否包含包装
     */
    public function has_wrapper($content) {
        if (empty($content)) {
            return false;
        }
        
        $content = trim($content);
        
        // 检查JSON包装
        if ($content[0] === '{' && substr($content, -1) === '}') {
            return true;
        }
        
        // 检查Markdown代码块包装
        if (strpos($content, '```markdown') === 0 || strpos($content, '```') === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 优化Markdown链接格式
     * 确保链接格式正确，便于后续Markdown到HTML的转换
     * 
     * @param string $content 原始内容
     * @return string 优化后的内容
     */
    private function optimize_markdown_links($content) {
        if (empty($content)) {
            return $content;
        }
        
        // 1. 确保Markdown链接语法正确
        // 只修复明显格式错误的链接，不处理正常格式的链接
        // 修复缺少闭合括号的链接，如 [文本](URL  -> [文本](URL)
        $content = preg_replace('/\[([^\]]+)\]\s*\(\s*([^\s\)]+)\s*(?!\))$/m', '[$1]($2)', $content);
        
        // 2. 移除链接中的多余空格
        $content = preg_replace('/\[([^\]]+)\]\s*\(\s*([^\s\)]+)\s*\)/', '[$1]($2)', $content);
        
        // 3. 修复链接后的多余右括号 - 处理API返回的错误语法
        $content = preg_replace('/\]\(([^\)]+)\)\s*\)/', ']($1)', $content);
        
        // 3. 确保URL格式正确（在之前的fix_escaped_characters中已经处理了转义问题）
        // 这里主要检查URL是否完整
        $content = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            function($matches) {
                $text = $matches[1];
                $url = $matches[2];
                
                // 确保URL有协议头
                if (!preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url)) {
                    // 如果是相对路径，添加https://
                    if (strpos($url, '/') === 0 || !preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url)) {
                        $url = 'https://' . $url;
                    }
                }
                
                // 确保URL没有空格
                $url = str_replace(' ', '', $url);
                
                return '[' . $text . '](' . $url . ')';
            },
            $content
        );
        
        return $content;
    }

    /**
     * 预处理Markdown格式，确保CommonMark正确解析
     * 为标题、列表等元素后添加必要的空行
     * 
     * @param string $content Markdown内容
     * @return string 预处理后的内容
     */
    private function preprocess_markdown_format($content) {
        if (empty($content)) {
            return $content;
        }

        $content = $this->normalize_risky_separator_blocks($content);

        // 1. 为标题（# 标记）后添加空行
        // 匹配 # 标题 后紧跟非空行的情况（包括加粗标记**）
        $content = preg_replace(
            '/^(#{1,6}\s+[^\n]+)\n(?![\n#])/m',
            "$1\n\n",
            $content
        );

        // 2. 为列表（-、*、+、数字.）后添加空行
        // 匹配列表项结束后的非列表内容
        $content = preg_replace(
            '/^([\s]*[-\*\+]\s+[^\n]+)\n(?![\s]*[-\*\+]\s|[\s]*\d+\.\s|[\n])/m',
            "$1\n\n",
            $content
        );
        // 数字列表
        $content = preg_replace(
            '/^([\s]*\d+\.\s+[^\n]+)\n(?![\s]*\d+\.\s|[\s]*[-\*\+]\s|[\n])/m',
            "$1\n\n",
            $content
        );

        // 3. 为引用块（>）后添加空行
        $content = preg_replace(
            '/^(>\s*[^\n]+)\n(?!>\s*[^\n]|[\n])/m',
            "$1\n\n",
            $content
        );

        // 4. 为代码块（```）后添加空行
        $content = preg_replace(
            '/(```[\s\S]*?```)\s*(?!\n\n)/',
            "$1\n\n",
            $content
        );

        // 5. 为分隔线（---、***、___）后添加空行
        $content = preg_replace(
            '/^(\s*[-\*_]{3,}\s*)\n(?!\s*[-\*_]{3,}\s*|[\n])/m',
            "$1\n\n",
            $content
        );

        // 5.1 为Markdown表格后添加空行
        // 匹配表格行（| ... |）后紧跟非表格内容的情况
        $content = preg_replace(
            '/^(\|[^\n]+\|)\s*\n(?![\n\|])/m',
            "$1\n\n",
            $content
        );

        // 5.2 为段落后紧跟的加粗标记添加空行
        // 匹配普通段落后的新行加粗标记
        $content = preg_replace(
            '/([^\n])\n(\*\*[^\*]+\*\*)/',
            "$1\n\n$2",
            $content
        );

        // 6. 修复加粗标记后紧跟列表的情况
        $content = preg_replace('/(\*\*[^\*]+\*\*):?\n?([-\*\+]\s|\d+\.\s)/', "$1\n\n$2", $content);

        // 7. 清理多余的空行（最多保留2个连续换行）
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return $content;
    }

    private function normalize_risky_separator_blocks($content) {
        $lines = preg_split('/\r?\n/', $content);
        if ($lines === false) {
            return $content;
        }

        $normalized = array();
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            $isSeparator = preg_match('/^[-*_]+$/', $trimmed) === 1;

            if (!$isSeparator) {
                $normalized[] = $line;
                continue;
            }

            if (!empty($normalized) && trim((string) end($normalized)) !== '') {
                $normalized[] = '';
            }

            $normalized[] = $trimmed;

            if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                $normalized[] = '';
            }
        }

        return implode("\n", $normalized);
    }
    
    /**
     * 获取包装类型信息
     * 
     * @param string $content 内容
     * @return string 包装类型
     */
    public function get_wrapper_type($content) {
        if (empty($content)) {
            return 'none';
        }
        
        $content = trim($content);
        
        // 检查JSON包装
        if ($content[0] === '{' && substr($content, -1) === '}') {
            $json_data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return 'json';
            }
        }
        
        // 检查Markdown代码块包装
        if (strpos($content, '```markdown') === 0) {
            return 'markdown';
        }
        
        if (strpos($content, '```') === 0) {
            return 'code_block';
        }
        
        return 'none';
    }
}
