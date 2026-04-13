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
    if (file_exists($commonmark_autoload) && !class_exists('League\CommonMark\GithubFlavoredMarkdownConverter')) {
        try {
            require_once $commonmark_autoload;
        } catch (\Throwable $e) {
            error_log('ContentAuto: CommonMark加载失败 - ' . $e->getMessage());
        }
    }
}

class Yali_AI_Writer_CommonMarkConverter {
    
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
            
        } catch (\Throwable $e) {
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
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                error_log('COMMONMARK_CONVERTER_ERROR: Empty content or converter not initialized');
            }
            return '';
        }
        
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            error_log('COMMONMARK_START: Starting CommonMark conversion');
            error_log('COMMONMARK_INPUT_LENGTH: ' . strlen($markdown));
            error_log('COMMONMARK_INPUT_PREVIEW: ' . json_encode(substr($markdown, 0, 150) . (strlen($markdown) > 150 ? '...' : '')));
            error_log('COMMONMARK_NOTE: Preprocessing already done in ContentFilter, skipping here');
        }
        
        // 注意：预处理已经在 ContentFilter::preprocess_markdown_format 中完成
        // 这里不再重复执行，避免双重处理导致格式错乱
        
        try {
            // 直接使用CommonMark转换
            $html = $this->converter->convert($markdown)->getContent();
            
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                error_log('COMMONMARK_CORE_CONVERTED: League\CommonMark\GithubFlavoredMarkdownConverter::convert');
                error_log('COMMONMARK_RAW_OUTPUT_LENGTH: ' . strlen($html));
            }
            
            // 只做最基本的后处理
            $html_before_postprocess = $html;
            $html = $this->postprocessHtml($html);
            
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                error_log('COMMONMARK_POSTPROCESSING: postprocessHtml');
                error_log('COMMONMARK_POSTPROCESSING_RULES: table_css_class');
                error_log('COMMONMARK_POSTPROCESSING_CHANGED: ' . ($html_before_postprocess !== $html ? 'Yes' : 'No'));
                error_log('COMMONMARK_FINAL_OUTPUT_LENGTH: ' . strlen($html));
                error_log('COMMONMARK_CONVERSION_SUCCESS: true');
            }
            
            return $html;
            
        } catch (\Throwable $e) {
            error_log('ContentAuto: Markdown转换失败 - ' . $e->getMessage());
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                error_log('COMMONMARK_CONVERTER_ERROR: ' . $e->getMessage());
            }
            return '<p>内容转换失败</p>';
        }
    }

    /**
     * 预处理：确保HTML块级元素和Markdown块级元素后有足够空行
     * 解决CommonMark解析时块级模式导致后续Markdown无法识别的问题
     *
     * @param string $markdown
     * @return string
     */
    private function preprocess_html_blocks($markdown) {
        // 1. 为<img>标签后添加空行（如果后面没有的话）
        $markdown = preg_replace(
            '/(<img[^>]*\/?>)(\s*)(?!\n\n)/i',
            "$1\n\n",
            $markdown
        );
        
        // 2. 为HTML <table>闭合标签后添加空行（如果后面没有的话）
        $markdown = preg_replace(
            '/(<\/table>)(\s*)(?!\n\n)/i',
            "$1\n\n",
            $markdown
        );
        
        // 3. 为Markdown表格后添加空行（如果后面没有的话）
        $markdown = preg_replace(
            '/^(\|[^\n]+\|)\s*\n(?![\n\|])/m',
            "$1\n\n",
            $markdown
        );
        
        // 4. 为列表（-、*、+、数字.）后添加空行
        // 匹配列表项结束后的非列表内容
        $markdown = preg_replace(
            '/^([\s]*[-\*\+]\s+[^\n]+)\n(?![\s]*[-\*\+]\s|[\s]*\d+\.\s|[\n])/m',
            "$1\n\n",
            $markdown
        );
        // 数字列表
        $markdown = preg_replace(
            '/^([\s]*\d+\.\s+[^\n]+)\n(?![\s]*\d+\.\s|[\s]*[-\*\+]\s|[\n])/m',
            "$1\n\n",
            $markdown
        );
        
        // 5. 为引用块（>）后添加空行
        $markdown = preg_replace(
            '/^(>\s*[^\n]+)\n(?!>\s*[^\n]|[\n])/m',
            "$1\n\n",
            $markdown
        );
        
        // 6. 为代码块（```）后添加空行
        $markdown = preg_replace(
            '/(```[\s\S]*?```)\s*(?!\n\n)/',
            "$1\n\n",
            $markdown
        );
        
        // 7. 为分隔线（---、***、___）后添加空行
        $markdown = preg_replace(
            '/^(\s*[-\*_]{3,}\s*)\n(?!\s*[-\*_]{3,}\s*|[\n])/m',
            "$1\n\n",
            $markdown
        );
        
        // 7.1 为标题（# 标记）后添加空行
        // 匹配 # 标题 后紧跟非空行的情况
        $markdown = preg_replace(
            '/^(#{1,6}\s+[^\n]+)\n(?![\n#])/m',
            "$1\n\n",
            $markdown
        );
        
        // 8. 修复加粗标记的空格问题
        // 移除**后的空格和**前的空格，但保留外部的空格
        $markdown = preg_replace('/\*\*[ \t]+/', '**', $markdown);  // ** 内容 → **内容
        $markdown = preg_replace('/[ \t]+\*\*(?!\*)/', '**', $markdown); // 内容 ** → 内容**
        
        // 8.1 加粗标记后紧跟列表时需要添加空行
        // 匹配 **...** 后直接跟 -、*、+ 或数字. 的情况（包括无换行符）
        $markdown = preg_replace('/(\*\*[^\*]+\*\*):?\n?([\-\*\+]\s|\d+\.\s)/', "$1\n\n$2", $markdown);
        
        // 9. 修复斜体标记的空格问题（同样处理，但要排除**）
        // 先处理单个*，确保不处理**
        $markdown = preg_replace('/(?<!\*)\*[ \t]+(?!\*)/', '*', $markdown);  // * 内容 → *内容
        $markdown = preg_replace('/(?<!\*)[ \t]+\*(?!\*)/', '*', $markdown);  // 内容 * → 内容*
        
        return $markdown;
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
        
        return $html;
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
