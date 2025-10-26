<?php
/**
 * CommonMark Markdown to HTML converter class
 * 使用原生CommonMark扩展
 *
 * @package ContentAutoManager
 * @subpackage ContentProcessing
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查PHP版本，只有PHP 8.1+才加载CommonMark库
$php_version_compatible = version_compare(PHP_VERSION, '8.1.0', '>=');

if ($php_version_compatible) {
    $commonmark_autoload = __DIR__ . '/../lib/commonmark/autoload.php';
    if (file_exists($commonmark_autoload)) {
        try {
            require_once $commonmark_autoload;
        } catch (Exception $e) {
            error_log('ContentAuto: CommonMark加载失败 - ' . $e->getMessage());
        }
    }
}

class ContentAuto_CommonMarkConverter {
    
    private $converter;

    public function __construct() {
        // 检查PHP版本兼容性
        if (!version_compare(PHP_VERSION, '8.1.0', '>=')) {
            error_log('ContentAuto: PHP版本 ' . PHP_VERSION . ' 不支持CommonMark（需要8.1+），将使用ParsedownExtra');
            $this->converter = null;
            return;
        }

        try {
            // 检查CommonMark是否可用
            if (!class_exists('League\CommonMark\GithubFlavoredMarkdownConverter')) {
                error_log('ContentAuto: CommonMark库未找到，请先运行安装脚本');
                return;
            }

            // 配置选项
            $config = [
                'html_input' => 'allow',
                'allow_unsafe_links' => false,
                'disallowed_raw_html' => [
                    'disallowed_tags' => ['script', 'iframe', 'object', 'embed'],
                ],
            ];

            // 动态创建转换器（避免use语句的编译时问题）
            $converter_class = 'League\CommonMark\GithubFlavoredMarkdownConverter';
            $this->converter = new $converter_class($config);
            
            // 添加SmartPunct扩展
            $smartpunct_class = 'League\CommonMark\Extension\SmartPunct\SmartPunctExtension';
            if (class_exists($smartpunct_class)) {
                $environment = $this->converter->getEnvironment();
                $environment->addExtension(new $smartpunct_class());
                error_log('ContentAuto: 成功初始化GitHub风格CommonMark转换器，包含6个扩展：CommonMark Core, Autolink, DisallowedRawHtml, Strikethrough, Table, TaskList, SmartPunct');
            } else {
                error_log('ContentAuto: 成功初始化GitHub风格CommonMark转换器，包含5个扩展：CommonMark Core, Autolink, DisallowedRawHtml, Strikethrough, Table, TaskList');
            }
            
        } catch (Exception $e) {
            error_log('ContentAuto: CommonMark初始化失败 - ' . $e->getMessage());
            $this->converter = null;
        }
    }

    /**
     * 将Markdown转换为HTML
     *
     * @param string $markdown Markdown内容
     * @return string HTML内容
     */
    public function markdown_to_html($markdown) {
        if (empty($markdown) || !$this->converter) {
            return '';
        }
        
        try {
            // 直接使用CommonMark转换
            $html = $this->converter->convert($markdown)->getContent();
            
            // 只做最基本的后处理
            $html = $this->postprocessHtml($html);
            
            return $html;
            
        } catch (Exception $e) {
            error_log('ContentAuto: Markdown转换失败 - ' . $e->getMessage());
            return '<p>内容转换失败</p>';
        }
    }

    /**
     * 最小化的后处理
     *
     * @param string $html
     * @return string
     */
    private function postprocessHtml($html) {
        // 为表格添加CSS类
        $html = str_replace('<table>', '<table class="wp-table">', $html);
        
        // 如果需要Mermaid支持，可以在这里添加脚本加载
        if (strpos($html, '<code class="language-mermaid">') !== false) {
            $this->enqueueMermaidScript();
        }
        
        return $html;
    }

    /**
     * 加载Mermaid脚本（如果需要）
     */
    private function enqueueMermaidScript() {
        if (function_exists('wp_enqueue_script') && !wp_script_is('mermaid', 'enqueued')) {
            wp_enqueue_script('mermaid', 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js', [], '10.0.0', true);
            
            $init_script = "
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof mermaid !== 'undefined') {
                    mermaid.initialize({startOnLoad: true});
                    // 将code.language-mermaid转换为div.mermaid
                    document.querySelectorAll('code.language-mermaid').forEach(function(el) {
                        const div = document.createElement('div');
                        div.className = 'mermaid';
                        div.textContent = el.textContent;
                        el.parentNode.replaceChild(div, el);
                    });
                    mermaid.init();
                }
            });
            ";
            
            wp_add_inline_script('mermaid', $init_script);
        }
    }

    /**
     * 检查CommonMark是否可用
     *
     * @return bool
     */
    public function is_available() {
        return $this->converter !== null;
    }

    /**
     * 获取支持的功能列表
     *
     * @return array
     */
    public function get_supported_features() {
        return [
            'autolinks' => true,           // AutolinkExtension - 自动链接转换
            'disallowed_raw_html' => true, // DisallowedRawHtmlExtension - 危险HTML过滤
            'strikethrough' => true,       // StrikethroughExtension - 删除线支持
            'tables' => true,              // TableExtension - 表格支持
            'task_lists' => true,          // TaskListExtension - 任务列表支持
            'smart_punct' => true,         // SmartPunctExtension - 智能标点优化
            'mermaid' => true,             // Mermaid图表支持（通过后处理）
            'html_blocks' => true,         // 基础HTML块支持
        ];
    }

    /**
     * 获取已启用的扩展列表
     *
     * @return array
     */
    public function get_enabled_extensions() {
        return [
            'CommonMarkCoreExtension' => '核心Markdown功能',
            'AutolinkExtension' => '自动链接转换',
            'DisallowedRawHtmlExtension' => '危险HTML过滤',
            'StrikethroughExtension' => '删除线支持',
            'TableExtension' => '表格支持',
            'TaskListExtension' => '任务列表支持',
            'SmartPunctExtension' => '智能标点优化',
        ];
    }
}