<?php
/**
 * 网址内容采集 AJAX 处理器
 * 
 * 处理规则管理页面中的网址采集功能
 * 使用 JINA 获取原始 HTML，然后通过 Readability 提取正文
 * 
 * @package Yali_AI_Writer
 * @subpackage Rule_Management
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 网址内容采集处理器
 * 使用 JINA 获取原始 HTML，然后通过 Readability 提取正文
 */
add_action('wp_ajax_yali_ai_writer_fetch_url_content', 'yali_ai_writer_fetch_url_content_handler');
function yali_ai_writer_fetch_url_content_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }

    // 获取网址参数
    $url = isset($_POST['url']) ? sanitize_url($_POST['url']) : '';

    if (empty($url)) {
        wp_send_json_error(array('message' => __('请提供有效的网址。', 'yali-ai-writer')));
    }

    // 验证网址格式
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(array('message' => __('网址格式不正确。', 'yali-ai-writer')));
    }

    try {
        // 确保URL有协议前缀
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // 尝试使用 JINA 获取 HTML，如果失败则降级到 WordPress 原生抓取
        $html = yali_ai_writer_fetch_url_html($url);
        
        if (empty($html)) {
            wp_send_json_error(array('message' => __('无法获取网页内容，请检查网址是否正确或网络连接。', 'yali-ai-writer')));
        }

        // 使用 Readability 提取正文
        $content = yali_ai_writer_extract_content_with_readability($html, $url);
        
        if (empty($content)) {
            wp_send_json_error(array('message' => __('无法从网页中提取正文内容。', 'yali-ai-writer')));
        }

        // 注意：字符长度限制由前端JS控制（3000字符），服务端返回完整提取内容

        // 记录成功日志
        error_log('网址采集成功: ' . $url . ' - 提取内容长度: ' . mb_strlen($content, 'UTF-8'));

        wp_send_json_success(array(
            'content' => $content,
            'final_length' => mb_strlen($content, 'UTF-8'),
            'url' => $url
        ));

    } catch (Exception $e) {
        error_log('网址采集异常: ' . $e->getMessage() . ' - URL: ' . $url);
        wp_send_json_error(array('message' => __('采集过程中发生错误，请稍后重试。', 'yali-ai-writer')));
    }
}

/**
 * 获取网址的原始 HTML
 * 优先使用 JINA API，失败时降级到 WordPress 原生抓取
 *
 * @param string $url 目标网址
 * @return string|false HTML 内容或失败返回 false
 */
function yali_ai_writer_fetch_url_html($url) {
    $api_url = 'https://r.jina.ai/' . $url;
    
    $headers = [
        'X-Respond-With' => 'html',    // 请求返回原始 HTML
        'Accept' => 'text/html'
    ];

    // 动态获取 API Key
    $jina_key = get_option('yali_ai_writer_jina_api_key', '');
    if (!empty($jina_key)) {
        $headers['Authorization'] = 'Bearer ' . $jina_key;
    }

    $args = [
        'timeout' => 60,
        'headers' => $headers
    ];

    $response = wp_remote_get($api_url, $args);
    
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        
        // 智能降级：如果余额不足(402)且使用了Key，切换到匿名模式重试
        if ($code === 402 && !empty($jina_key)) {
            error_log('网址采集: Jina API 余额不足，尝试匿名模式');
            return yali_ai_writer_fetch_url_html_anonymous($url);
        }
        
        if ($code === 200) {
            $html = wp_remote_retrieve_body($response);
            if (!empty($html)) {
                error_log('网址采集: JINA 成功获取 HTML');
                return $html;
            }
        }
    }

    // JINA 失败，降级到 WordPress 原生抓取
    error_log('网址采集: JINA 失败，降级到 WordPress 原生抓取');
    return yali_ai_writer_fetch_url_html_wp($url);
}

/**
 * 使用 JINA 匿名模式获取 HTML
 *
 * @param string $url 目标网址
 * @return string|false HTML 内容或失败返回 false
 */
function yali_ai_writer_fetch_url_html_anonymous($url) {
    $api_url = 'https://r.jina.ai/' . $url;
    
    $args = [
        'timeout' => 60,
        'headers' => [
            'X-Respond-With' => 'html',
            'Accept' => 'text/html'
            // 不携带 Authorization 头
        ]
    ];

    $response = wp_remote_get($api_url, $args);
    
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            $html = wp_remote_retrieve_body($response);
            if (!empty($html)) {
                error_log('网址采集: JINA 匿名模式成功获取 HTML');
                return $html;
            }
        }
    }
    
    // 匿名模式也失败，降级到 WordPress 原生抓取
    error_log('网址采集: JINA 匿名模式失败，降级到 WordPress 原生抓取');
    return yali_ai_writer_fetch_url_html_wp($url);
}

/**
 * 使用 WordPress 原生方法抓取 HTML
 *
 * @param string $url 目标网址
 * @return string|false HTML 内容或失败返回 false
 */
function yali_ai_writer_fetch_url_html_wp($url) {
    $wp_args = [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
        ]
    ];
    
    $wp_response = wp_remote_get($url, $wp_args);
    
    if (is_wp_error($wp_response)) {
        error_log('网址采集: WordPress 原生抓取失败 - ' . $wp_response->get_error_message());
        return false;
    }
    
    $wp_code = wp_remote_retrieve_response_code($wp_response);
    if ($wp_code !== 200) {
        error_log('网址采集: WordPress 原生抓取返回错误状态码 - ' . $wp_code);
        return false;
    }
    
    $html = wp_remote_retrieve_body($wp_response);
    if (!empty($html)) {
        error_log('网址采集: WordPress 原生抓取成功');
        return $html;
    }
    
    return false;
}

/**
 * 使用 Readability 提取正文内容
 * 完全复刻网络搜索中的 extract_article_content 实现
 *
 * @param string $html 原始 HTML
 * @param string $url 原始 URL
 * @return string|false 提取的正文内容或失败返回 false
 */
function yali_ai_writer_extract_content_with_readability($html, $url) {
    if (empty($html) || mb_strlen($html) < 100) {
        return false;
    }

    try {
        // 确保 Readability 类可用
        if (!class_exists('\\fivefilters\\Readability\\Readability')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'vendor/autoload.php';
        }

        // Step 1: Readability 提取正文区域（HTML 格式）
        $config = new \fivefilters\Readability\Configuration();
        $config->setFixRelativeURLs(true);
        $config->setOriginalURL($url);
        
        $readability = new \fivefilters\Readability\Readability($config);
        $readability->parse($html);
        
        $article_html = $readability->getContent();
        
        if (empty($article_html) || mb_strlen(strip_tags($article_html)) < 100) {
            error_log('网址采集: Readability 提取内容过短，回退到纯文本模式');
            return yali_ai_writer_fallback_text_extract($html);
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
            error_log('网址采集: Markdown 转换后内容过短，回退到纯文本模式');
            return yali_ai_writer_fallback_text_extract($html);
        }
        
        error_log('网址采集: Readability + Markdown 提取成功 (' . mb_strlen($content) . ' chars)');
        
        // 返回完整内容，不限制长度。保存规则时的 3000 字符限制由前端和后端保存逻辑处理
        return $content;
        
    } catch (\fivefilters\Readability\ParseException $e) {
        error_log('网址采集: Readability 解析异常 - ' . $e->getMessage() . '，回退到纯文本模式');
        return yali_ai_writer_fallback_text_extract($html);
    } catch (Exception $e) {
        error_log('网址采集: Readability 处理异常 - ' . $e->getMessage() . '，回退到纯文本模式');
        return yali_ai_writer_fallback_text_extract($html);
    }
}

/**
 * 降级方案：当 Readability 失败时，简单去除 HTML 标签提取文本
 * 完全复刻网络搜索中的 fallback_text_extract 实现
 *
 * @param string $html 原始 HTML
 * @return string 提取的纯文本
 */
function yali_ai_writer_fallback_text_extract($html) {
    error_log('网址采集: 使用降级方案提取纯文本');
    
    // 首先尝试移除常见的非正文元素
    $patterns = [
        '/<script[^>]*>.*?<\/script>/si',     // 移除 script 标签
        '/<style[^>]*>.*?<\/style>/si',       // 移除 style 标签
        '/<nav[^>]*>.*?<\/nav>/si',           // 移除 nav 标签
        '/<header[^>]*>.*?<\/header>/si',     // 移除 header 标签
        '/<footer[^>]*>.*?<\/footer>/si',     // 移除 footer 标签
        '/<aside[^>]*>.*?<\/aside>/si',       // 移除 aside 标签
    ];
    
    $html = preg_replace($patterns, '', $html);
    
    // 使用 strip_tags 去除所有 HTML 标签
    $text = strip_tags($html);
    
    // 清理多余空白
    $text = preg_replace('/\s+/', ' ', $text);
    
    $text = trim($text);
    
    error_log('网址采集: 降级方案提取完成 (' . mb_strlen($text) . ' chars)');
    
    return $text;
}
