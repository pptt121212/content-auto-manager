<?php

/**
 * 内容过滤器类
 * 用于过滤文章内容的外部包装标记
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
     * @param string $content 原始内容
     * @return string 过滤后的内容
     */
    public function filter_content($content) {
        if (empty($content)) {
            return $content;
        }
        
        $content = trim($content);
        
        // 第零步：修复转义字符，防止Markdown解析错误
        $content = $this->fix_escaped_characters($content);
        
        // 第一步：尝试提取JSON字段内容
        $filtered = $this->extract_json_content($content);
        if ($filtered !== $content) {
            return $this->remove_markdown_wrapper($filtered);
        }
        
        // 第二步：移除Markdown代码块包装
        $content = $this->remove_markdown_wrapper($content);
        
        // 第三步：优化Markdown链接格式
        $content = $this->optimize_markdown_links($content);
        
        return $content;
    }
    
    /**
     * 修复转义字符，防止Markdown解析错误
     * 主要处理API返回内容中的转义字符
     * 
     * @param string $content 原始内容
     * @return string 修复后的内容
     */
    private function fix_escaped_characters($content) {
        if (empty($content)) {
            return $content;
        }
        
        // 修复URL中的转义斜杠 - 将 https:// 转换为 https://
        $content = str_replace('https:\/\/', 'https://', $content);
        $content = str_replace('http:\/\/', 'http://', $content);
        
        // 修复其他常见的转义字符
        $content = str_replace('\/', '/', $content);  // 通用的斜杠转义
        $content = str_replace('\\"', '"', $content); // 双引号转义
        $content = str_replace("\\'", "'", $content); // 单引号转义
        $content = str_replace('\\\\', '\\', $content); // 反斜杠转义
        
        return $content;
    }
    
    /**
     * 提取JSON字段内容
     * 支持 {"任意字段名": "内容"} 格式
     * 
     * @param string $content 原始内容
     * @return string 提取的内容或原内容
     */
    private function extract_json_content($content) {
        // 尝试直接解析整个内容为JSON
        $json_data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
            // 查找第一个字符串类型的字段值
            foreach ($json_data as $value) {
                if (is_string($value)) {
                    return trim($value);
                }
            }
        }
        
        // 查找JSON对象模式：{"任意字段名": "内容"}
        if (preg_match('/\{[^{}]*"[^"]*"\s*:\s*"([^"]*)"[^{}]*\}/s', $content, $matches)) {
            if (isset($matches[1]) && !empty($matches[1])) {
                return trim($matches[1]);
            }
        }
        
        // 查找嵌套JSON中的内容字段
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $json_matches)) {
            $json_candidate = $json_matches[0];
            $json_data = json_decode($json_candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
                foreach ($json_data as $value) {
                    if (is_string($value)) {
                        return trim($value);
                    }
                }
            }
        }
        
        return $content;
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