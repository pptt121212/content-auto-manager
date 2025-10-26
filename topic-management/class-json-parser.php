<?php
/**
 * JSON解析器
 * 负责解析API返回的JSON内容，验证数据结构
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_JsonParser {
    
    private $logger;
    
    public function __construct($logger = null) {
        $this->logger = $logger;
    }
    
    /**
     * 解析API返回的JSON主题数据
     */
    public function parse_json_topics($json_content, $count, $rule_id = null, $rule_item_index = null) {
        $method_start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        // 清理响应内容，移除可能的前后空白字符
        $json_content = trim($json_content);
        
        $context_array = array(
            '期望数量' => $count,
            '内容长度' => strlen($json_content),
            '操作类型' => 'parse_json_topics'
        );
        
        try {
            // JSON提取阶段
            $extracted_json = $this->extract_json_content($json_content);
            
            if ($extracted_json !== false) {
                // JSON解码阶段
                $json_data = json_decode($extracted_json, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    // JSON结构验证阶段
                    $validation_result = $this->validate_json_structure($json_data);
                    
                    if ($validation_result['valid']) {
                        return $validation_result['topics'];
                    } else {
                        $this->log_error('VALIDATION', 'JSON结构验证失败: ' . $validation_result['error']);
                        return array('error' => 'JSON结构验证失败: ' . $validation_result['error']);
                    }
                } else {
                    $this->log_error('JSON_PARSE', 'JSON解码失败: ' . json_last_error_msg());
                    return array('error' => 'JSON解码失败: ' . json_last_error_msg());
                }
            } else {
                $this->log_error('CONTENT_EXTRACTION', '无法从响应中提取有效的JSON内容');
                return array('error' => '无法从响应中提取有效的JSON内容');
            }
        } catch (Exception $e) {
            $this->log_error('SYSTEM', 'parse_json_topics方法发生异常: ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * 严格验证JSON结构和字段完整性
     */
    private function validate_json_structure($json_data) {
        try {
            // 检查必需字段 - 支持topics和generated_topics两种字段名
            $topics_field = null;
            if (isset($json_data['generated_topics']) && is_array($json_data['generated_topics'])) {
                $topics_field = 'generated_topics';
            } elseif (isset($json_data['topics']) && is_array($json_data['topics'])) {
                $topics_field = 'topics';
            }
            
            if (!$topics_field) {
                return array(
                    'valid' => false,
                    'error' => '缺少必需字段: topics 或 generated_topics 或不是数组类型',
                    'topics' => array()
                );
            }
        
            $validated_topics = array();
            $required_fields = array('title', 'source_angle', 'user_value', 'seo_keywords', 'matched_category', 'priority_score');
            $total_topics = count($json_data[$topics_field]);
            
            foreach ($json_data[$topics_field] as $index => $topic_data) {
                // 检查是否是数组格式
                if (!is_array($topic_data)) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] 不是对象格式",
                        'topics' => array()
                    );
                }
                
                // 检查必需字段
                $missing_fields = array();
                foreach ($required_fields as $field) {
                    if (!isset($topic_data[$field])) {
                        $missing_fields[] = $field;
                    }
                }
                
                // 如果缺少字段，检查是否是简单格式
                if (!empty($missing_fields)) {
                    if (isset($topic_data['title']) && (isset($topic_data['description']) || isset($topic_data['content']))) {
                        // 简单格式，自动填充缺失的字段
                        if (!isset($topic_data['source_angle'])) {
                            $topic_data['source_angle'] = $topic_data['description'] ?? $topic_data['content'] ?? '自动生成的来源角度';
                        }
                        if (!isset($topic_data['user_value'])) {
                            $topic_data['user_value'] = '提供有价值的信息和见解';
                        }
                        if (!isset($topic_data['seo_keywords'])) {
                            $topic_data['seo_keywords'] = array('相关主题', '关键词');
                        }
                        if (!isset($topic_data['matched_category'])) {
                            $topic_data['matched_category'] = '通用';
                        }
                        if (!isset($topic_data['priority_score'])) {
                            $topic_data['priority_score'] = 8;
                        }
                    } else {
                        return array(
                            'valid' => false,
                            'error' => "主题[$index] 缺少必需字段: " . implode(', ', $missing_fields),
                            'topics' => array()
                        );
                    }
                }
                
                // 验证字段类型
                if (!is_string($topic_data['title']) || empty(trim($topic_data['title']))) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] title字段必须是非空字符串",
                        'topics' => array()
                    );
                }
                
                if (!is_string($topic_data['source_angle']) || empty(trim($topic_data['source_angle']))) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] source_angle字段必须是非空字符串",
                        'topics' => array()
                    );
                }
                
                if (!is_string($topic_data['user_value']) || empty(trim($topic_data['user_value']))) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] user_value字段必须是非空字符串",
                        'topics' => array()
                    );
                }
                
                if (!is_array($topic_data['seo_keywords']) || empty($topic_data['seo_keywords'])) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] seo_keywords字段必须是非空数组",
                        'topics' => array()
                    );
                }
                
                if (!is_string($topic_data['matched_category']) || empty(trim($topic_data['matched_category']))) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] matched_category字段必须是非空字符串",
                        'topics' => array()
                    );
                }
                
                // 清理和验证SEO关键词
                $clean_keywords = array();
                foreach ($topic_data['seo_keywords'] as $keyword) {
                    if (is_string($keyword) && !empty(trim($keyword))) {
                        $clean_keywords[] = sanitize_text_field(trim($keyword));
                    }
                }
                
                if (empty($clean_keywords)) {
                    return array(
                        'valid' => false,
                        'error' => "主题[$index] seo_keywords数组不能为空或只包含空值",
                        'topics' => array()
                    );
                }
                
                // 所有验证通过，添加到验证通过的主题列表
                $validated_topics[] = array(
                    'title' => sanitize_text_field(trim($topic_data['title'])),
                    'source_angle' => sanitize_text_field(trim($topic_data['source_angle'])),
                    'user_value' => sanitize_text_field(trim($topic_data['user_value'])),
                    'seo_keywords' => $clean_keywords,
                    'matched_category' => sanitize_text_field(trim($topic_data['matched_category'])),
                    'priority_score' => $topic_data['priority_score']
                );
            }
            
            if (empty($validated_topics)) {
                return array(
                    'valid' => false,
                    'error' => '没有有效的主题数据',
                    'topics' => array()
                );
            }
            
            return array(
                'valid' => true,
                'error' => '',
                'topics' => $validated_topics
            );
            
        } catch (Exception $e) {
            return array(
                'valid' => false,
                'error' => '验证过程发生异常: ' . $e->getMessage(),
                'topics' => array()
            );
        }
    }
    
    /**
     * 从响应内容中提取JSON内容
     */
    private function extract_json_content($content) {
        // 方法0: 处理Markdown代码块包装的JSON
        if (strpos($content, '```json') === 0) {
            $content = preg_replace('/^```json\s*|\s*```$/', '', $content);
            $content = trim($content);
            $json_data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $content;
            }
        }
        
        // 方法1: 直接尝试解析整个内容
        $json_data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $content;
        }
        
        // 方法2: 查找第一个{和最后一个}之间的内容
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');
        
        if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
            $json_candidate = substr($content, $json_start, $json_end - $json_start + 1);
            $json_data = json_decode($json_candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json_candidate;
            }
        }
        
        // 方法3: 使用正则表达式查找JSON对象
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $matches)) {
            $json_candidate = $matches[0];
            $json_data = json_decode($json_candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json_candidate;
            }
        }
        
        // 方法4: 尝试查找数组格式的JSON
        $array_start = strpos($content, '[');
        $array_end = strrpos($content, ']');
        
        if ($array_start !== false && $array_end !== false && $array_end > $array_start) {
            $json_candidate = substr($content, $array_start, $array_end - $array_start + 1);
            $json_data = json_decode($json_candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json_candidate;
            }
        }
        
        // 方法5: 尝试清理内容中的常见干扰字符
        $cleaned_content = $this->clean_json_content($content);
        if ($cleaned_content !== $content) {
            $json_data = json_decode($cleaned_content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $cleaned_content;
            }
            
            // 再次尝试查找{和}之间的内容
            $json_start = strpos($cleaned_content, '{');
            $json_end = strrpos($cleaned_content, '}');
            
            if ($json_start !== false && $json_end !== false && $json_end > $json_start) {
                $json_candidate = substr($cleaned_content, $json_start, $json_end - $json_start + 1);
                $json_data = json_decode($json_candidate, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $json_candidate;
                }
            }
        }
        
        // 方法6: 尝试修复常见的JSON格式问题
        $repaired_content = $this->repair_json_format($content);
        if ($repaired_content !== $content) {
            $json_data = json_decode($repaired_content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $repaired_content;
            }
        }
        
        return false;
    }
    
    /**
     * 清理JSON内容中的常见干扰字符
     */
    private function clean_json_content($content) {
        // 移除常见的干扰字符和标记
        $content = preg_replace('/^```[a-z]*\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);
        
        // 移除JavaScript风格注释
        $content = $this->remove_json_comments($content);
        
        // 移除可能的前缀文本
        $lines = explode("\n", $content);
        $json_lines = array();
        $in_json = false;
        $brace_count = 0;
        
        foreach ($lines as $line) {
            $trimmed_line = trim($line);
            
            // 检测JSON开始
            if (!$in_json && (substr($trimmed_line, 0, 1) === '{' || substr($trimmed_line, 0, 1) === '[')) {
                $in_json = true;
                $json_lines[] = $line;
                
                // 计算初始大括号数量
                $brace_count += substr_count($trimmed_line, '{');
                $brace_count -= substr_count($trimmed_line, '}');
                
            } elseif ($in_json) {
                $json_lines[] = $line;
                
                // 更新大括号数量
                $brace_count += substr_count($trimmed_line, '{');
                $brace_count -= substr_count($trimmed_line, '}');
                
                // 检查JSON是否结束
                if ($brace_count <= 0 && (substr($trimmed_line, -1) === '}' || substr($trimmed_line, -1) === ']')) {
                    break;
                }
            }
        }
        
        if (!empty($json_lines)) {
            return implode("\n", $json_lines);
        }
        
        return $content;
    }
    
    /**
     * 移除JSON中的JavaScript风格注释
     */
    private function remove_json_comments($content) {
        // 先移除多行注释
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        // 移除单行注释
        $lines = explode("\n", $content);
        $cleaned_lines = array();
        
        foreach ($lines as $line) {
            $cleaned_line = '';
            $in_string = false;
            $string_char = '';
            $line_length = strlen($line);
            
            for ($i = 0; $i < $line_length; $i++) {
                $char = $line[$i];
                
                if (!$in_string && ($char === '"' || $char === "'")) {
                    $in_string = true;
                    $string_char = $char;
                    $cleaned_line .= $char;
                } elseif ($in_string && $char === $string_char) {
                    if ($i > 0 && $line[$i - 1] === '\\') {
                        $cleaned_line .= $char;
                    } else {
                        $in_string = false;
                        $string_char = '';
                        $cleaned_line .= $char;
                    }
                } elseif (!$in_string && $char === '/' && $i + 1 < $line_length && $line[$i + 1] === '/') {
                    break;
                } else {
                    $cleaned_line .= $char;
                }
            }
            
            $cleaned_lines[] = $cleaned_line;
        }
        
        return implode("\n", $cleaned_lines);
    }
    
    /**
     * 尝试修复常见的JSON格式问题
     */
    private function repair_json_format($content) {
        $repaired = $content;
        
        // 移除BOM和其他不可见字符
        $repaired = preg_replace('/^[\x00-\x1F\x80-\xFF]+/', '', $repaired);
        
        // 修复常见的引号问题
        $repaired = str_replace('"', '"', $repaired);
        $repaired = str_replace('"', '"', $repaired);
        $repaired = str_replace('\'', "'", $repaired);
        $repaired = str_replace('\'', "'", $repaired);
        
        // 智能修复缺失的引号
        $repaired = $this->fix_missing_quotes($repaired);
        
        // 修复常见的结构问题
        $repaired = $this->fix_json_structure($repaired);
        
        return $repaired;
    }
    
    /**
     * 智能修复缺失的引号
     */
    private function fix_missing_quotes($content) {
        $repaired = $content;
        
        // 修复缺失键名引号的常见模式
        $repaired = preg_replace('/(\{|\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $repaired);
        $repaired = preg_replace('/,\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', ', "$1":', $repaired);
        
        return $repaired;
    }
    
    /**
     * 修复JSON结构问题
     */
    private function fix_json_structure($content) {
        $repaired = $content;
        
        // 修复缺失的逗号
        $repaired = preg_replace('/"([0-9a-zA-Z_]+)":\s*("[^"]*"|\'[^\']*\'|\d+|true|false|null)\s+"([0-9a-zA-Z_]+)":/', '"$1": $2, "$3":', $repaired);
        
        // 修复对象末尾的逗号
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $repaired);
        
        return $repaired;
    }
    
    /**\n     * 记录错误信息\n     */
    private function log_error($error_type, $message, $context = '', $suggestions = array()) {
        if ($this->logger) {
            $this->logger->log_error($error_type, $message, $context, $suggestions);
        }
    }
}