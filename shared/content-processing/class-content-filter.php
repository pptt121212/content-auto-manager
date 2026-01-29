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

class ContentAuto_ContentFilter {
    
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
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if (!class_exists('ContentAuto_PluginLogger')) {
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
            }
            $logger = new ContentAuto_PluginLogger();

            $logger->debug('CONTENT_FILTER_START', '开始内容过滤处理', array(
                'original_length' => strlen($content),
                'original_content' => $content
            ));
        }

        $original_content = $content;
        $content = trim($content);

        // 零步骤：过滤Pollinations广告内容
        $content_before_ad_filter = $content;
        $content = $this->remove_pollinations_ads($content);
        
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if ($content_before_ad_filter !== $content) {
                $logger->debug('POLLINATIONS_ADS_REMOVED', '移除Pollinations广告内容', array(
                    'content_before_removal' => $content_before_ad_filter,
                    'content_after_removal' => $content,
                    'ads_removed' => true,
                    'removed_length' => strlen($content_before_ad_filter) - strlen($content)
                ));
            }
            
            // 添加调试：检查广告移除后的内容长度
            $logger->debug('DEBUG_AFTER_AD_REMOVAL', '广告移除后的内容状态', array(
                'content_length_after_ad_removal' => strlen($content),
                'is_empty_after_ad_removal' => empty($content),
                'content_preview_after_ad_removal' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 零步骤之二：过滤思考标签内容（某些AI模型返回的 <think></think> 标签）
        $content_before_think_filter = $content;
        $content = $this->remove_think_tags($content);
        
        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if ($content_before_think_filter !== $content) {
                $logger->debug('THINK_TAGS_REMOVED', '移除思考标签内容', array(
                    'content_before_removal' => $content_before_think_filter,
                    'content_after_removal' => $content,
                    'think_tags_removed' => true,
                    'removed_length' => strlen($content_before_think_filter) - strlen($content)
                ));
            }
            
            // 添加调试：检查思考标签移除后的内容长度
            $logger->debug('DEBUG_AFTER_THINK_REMOVAL', '思考标签移除后的内容状态', array(
                'content_length_after_think_removal' => strlen($content),
                'is_empty_after_think_removal' => empty($content),
                'content_preview_after_think_removal' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第零步：修复转义字符，防止Markdown解析错误
        $content = $this->fix_escaped_characters($content);

        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
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

            if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                $logger->debug('JSON_CONTENT_EXTRACTED', '提取JSON字段内容', array(
                    'content_before_extraction' => $content,
                    'extracted_json_content' => $filtered,
                    'final_content' => $final_content,
                    'extraction_successful' => true
                ));

                $logger->debug('CONTENT_FILTER_COMPLETE', '内容过滤完成（JSON路径）', array(
                    'original_length' => strlen($original_content),
                    'final_length' => strlen($final_content),
                    'content_reduced' => strlen($original_content) - strlen($final_content),
                    'filter_path' => 'json_extraction'
                ));
            }

            return $final_content;
        }

        // 第二步：移除Markdown代码块包装
        $content_before_wrapper = $content;
        $content = $this->remove_markdown_wrapper($content);

        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if ($content_before_wrapper !== $content) {
                $logger->debug('MARKDOWN_WRAPPER_REMOVED', '移除Markdown包装', array(
                    'content_before_removal' => $content_before_wrapper,
                    'content_after_removal' => $content,
                    'wrapper_removed' => true
                ));
            }
            
            // 添加调试：检查包装移除后的内容长度
            $logger->debug('DEBUG_AFTER_WRAPPER_REMOVAL', '包装移除后的内容状态', array(
                'content_length_after_wrapper_removal' => strlen($content),
                'is_empty_after_wrapper_removal' => empty($content),
                'content_preview_after_wrapper_removal' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '')
            ));
        }

        // 第三步：优化Markdown链接格式
        $content_before_optimization = $content;
        $content = $this->optimize_markdown_links($content);

        if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
            if ($content_before_optimization !== $content) {
                $logger->debug('MARKDOWN_LINKS_OPTIMIZED', '优化Markdown链接', array(
                    'content_before_optimization' => $content_before_optimization,
                    'content_after_optimization' => $content,
                    'links_optimized' => true
                ));

                $logger->debug('CONTENT_FILTER_COMPLETE', '内容过滤完成（标准路径）', array(
                    'original_length' => strlen($original_content),
                    'final_length' => strlen($content),
                    'content_reduced' => strlen($original_content) - strlen($content),
                    'filter_path' => 'standard_filtering',
                    'processing_steps' => array(
                        'ads_filtered' => ($content_before_ad_filter !== $content),
                        'think_tags_removed' => ($content_before_think_filter !== $content),
                        'escaped_characters_fixed' => true,
                        'markdown_wrapper_removed' => ($content_before_wrapper !== $content),
                        'links_optimized' => ($content_before_optimization !== $content)
                    )
                ));
            }
        }

        return $content;
    }

/**
     * 移除Pollinations广告内容
     * 检测具体的广告起始模式，简单直接
     * 
     * @param string $content 原始内容
     * @return string 移除广告后的内容
     */
  private function remove_pollinations_ads($content) {
        if (empty($content)) {
            return $content;
        }

        // 查找广告开始的确切模式
        $ad_start_pattern = '/\n---\n\n\*\*Support Pollinations\.AI:/';
        
        if (preg_match($ad_start_pattern, $content)) {
            // 从广告开始位置截断
            $cleaned_content = preg_replace($ad_start_pattern . '.*$/s', '', $content);
            
            // 移除末尾可能的多余换行符
            $cleaned_content = rtrim($cleaned_content, "\n");
            
            return $cleaned_content;
        }

        // 如果没有找到广告标记，返回原内容
        return $content;
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
        if (!class_exists('ContentAuto_PluginLogger')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-plugin-logger.php';
        }
        $logger = new ContentAuto_PluginLogger();
        
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
     * @param string $content 原始内容
     * @return string 移除包装后的内容
     */
    private function remove_markdown_wrapper($content) {
        // 移除 ```markdown 开头和 ``` 结尾
        if (strpos($content, '```markdown') === 0) {
            $content = preg_replace('/^```markdown\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            return trim($content);
        }
        
        // 移除 ``` 开头和 ``` 结尾
        if (strpos($content, '```') === 0) {
            $content = preg_replace('/^```\s*/', '', $content);
            $content = preg_replace('/\s*```$/', '', $content);
            return trim($content);
        }
        
        // 移除内容中的 ```markdown 包围
        $content = preg_replace('/```markdown\s*(.*?)\s*```/s', '$1', $content);
        
        // 移除内容中的 ``` 包围
        $content = preg_replace('/```\s*(.*?)\s*```/s', '$1', $content);
        
        return trim($content);
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