<?php
/**
 * Markdown to HTML converter class using Parsedown.
 *
 * @package ContentAutoManager
 * @subpackage ContentProcessing
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include the Parsedown library using a robust path.
require_once __DIR__ . '/../lib/Parsedown.php';

// 检查是否有ParsedownExtra（支持表格等扩展语法）
$parsedown_extra_path = __DIR__ . '/../lib/ParsedownExtra.php';
if (file_exists($parsedown_extra_path)) {
    require_once $parsedown_extra_path;
}

class Yali_AI_Writer_MarkdownConverter {
    
    private $converter;
    private $converter_type;
    
    // 静态缓存，避免重复检测
    private static $cached_converter = null;
    private static $cached_converter_type = null;
    private static $cache_initialized = false;
    private static $cached_php_version = null;

    public function __construct() {
        // 构造函数保持为空，实现懒加载
        // 实际的初始化将在首次使用时通过 ensure_converter_initialized() 进行
    }
    
    /**
     * 确保转换器已初始化
     * 懒加载机制的核心
     */
    private function ensure_converter_initialized() {
        // 如果已经初始化，直接返回
        if ($this->converter) {
            return;
        }

        // 检查缓存是否有效（PHP版本是否改变）
        $current_php_version = PHP_VERSION;
        $cache_valid = self::$cache_initialized && (self::$cached_php_version === $current_php_version);
        
        if ($cache_valid) {
            // 缓存有效，直接使用
            $this->converter = self::$cached_converter;
            $this->converter_type = self::$cached_converter_type;
            return;
        }
        
        // 缓存无效或首次初始化，重新检测
        if (self::$cache_initialized && self::$cached_php_version !== $current_php_version) {
            error_log('ContentAuto: 检测到PHP版本变化 (' . self::$cached_php_version . ' -> ' . $current_php_version . ')，重新选择转换器');
        }
        
        $this->initialize_converter_impl();
        
        // 更新缓存
        self::$cached_converter = $this->converter;
        self::$cached_converter_type = $this->converter_type;
        self::$cached_php_version = $current_php_version;
        self::$cache_initialized = true;
    }
    
    private function initialize_converter_impl() {
        // 优先使用CommonMark，fallback到ParsedownExtra，最后使用标准Parsedown
        
        // 尝试加载CommonMark转换器
        $commonmark_file = __DIR__ . '/class-commonmark-converter.php';
        if (file_exists($commonmark_file)) {
            require_once $commonmark_file;
            
            if (class_exists('Yali_AI_Writer_CommonMarkConverter')) {
                $commonmark_converter = new Yali_AI_Writer_CommonMarkConverter();
                if ($commonmark_converter->is_available()) {
                    $this->converter = $commonmark_converter;
                    $this->converter_type = 'commonmark';
                    $extensions = $commonmark_converter->get_enabled_extensions();
                    $ext_names = implode(', ', array_keys($extensions));
                    error_log('ContentAuto: 使用CommonMark转换器，包含' . count($extensions) . '个扩展：' . $ext_names);
                    return;
                }
            }
        }
        
        // Fallback到ParsedownExtra
        if (class_exists('ParsedownExtra')) {
            $this->converter = new ParsedownExtra();
            $this->converter_type = 'parsedown_extra';
            error_log('ContentAuto: 使用ParsedownExtra，支持表格语法');
            
            // Configure Parsedown instance
            $this->converter->setSafeMode(false);
            $this->converter->setBreaksEnabled(true);
            
        } elseif (class_exists('Parsedown')) {
            $this->converter = new Parsedown();
            $this->converter_type = 'parsedown';
            error_log('ContentAuto: 使用标准Parsedown，不支持表格语法');
            
            // Configure Parsedown instance
            $this->converter->setSafeMode(false);
            $this->converter->setBreaksEnabled(true);
            
        } else {
            error_log('ContentAuto: 没有找到可用的Markdown转换器');
            $this->converter = null;
            $this->converter_type = 'none';
        }
    }

    /**
     * Converts Markdown content to HTML.
     * Automatically uses the best available converter (CommonMark > ParsedownExtra > Parsedown).
     * Preserves HTML comments which are used as image placeholders.
     *
     * @param string $markdown Markdown content.
     * @return string HTML content.
     */
    public function markdown_to_html($markdown) {
        $this->ensure_converter_initialized();

        if (empty($markdown) || !$this->converter) {
            // 记录错误日志
            if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                error_log('MARKDOWN_CONVERTER_ERROR: Empty content or converter not initialized');
            }
            return '';
        }

        // 记录转换开始
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            error_log('MARKDOWN_CONVERSION_START: Starting Markdown to HTML conversion');
            error_log('MARKDOWN_CONVERTER_TYPE: ' . $this->converter_type);
            error_log('MARKDOWN_INPUT_LENGTH: ' . strlen($markdown));
            error_log('MARKDOWN_INPUT_PREVIEW: ' . json_encode(substr($markdown, 0, 200) . (strlen($markdown) > 200 ? '...' : '')));
        }
        
        $input_length = strlen($markdown);
        
        // 使用相应的转换方法
        switch ($this->converter_type) {
            case 'commonmark':
                if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                    error_log('MARKDOWN_METHOD_USED: Yali_AI_Writer_CommonMarkConverter::markdown_to_html');
                    error_log('MARKDOWN_PREPROCESSING: preprocess_html_blocks (inside CommonMarkConverter)');
                }
                $html = $this->converter->markdown_to_html($markdown);
                if ($html === '' || $html === '<p>内容转换失败</p>') {
                    $fallback_html = $this->fallback_to_parsedown($markdown);
                    if ($fallback_html !== null) {
                        $html = $fallback_html;
                    }
                }
                break;
                
            case 'parsedown_extra':
                if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                    error_log('MARKDOWN_METHOD_USED: ParsedownExtra::text');
                }
                $html = $this->converter->text($markdown);
                break;
                
            case 'parsedown':
                if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                    error_log('MARKDOWN_METHOD_USED: Parsedown::text');
                }
                $html = $this->converter->text($markdown);
                break;
                
            default:
                if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
                    error_log('MARKDOWN_CONVERTER_ERROR: Unknown converter type: ' . $this->converter_type);
                }
                return '';
        }

        // 记录转换完成
        if (defined('YALI_AI_WRITER_DEBUG_MODE') && YALI_AI_WRITER_DEBUG_MODE) {
            error_log('MARKDOWN_CONVERSION_COMPLETE: Conversion finished');
            error_log('MARKDOWN_OUTPUT_LENGTH: ' . strlen($html));
            error_log('MARKDOWN_CONTENT_CHANGE: ' . ($input_length !== strlen($html) ? 'Changed' : 'Unchanged'));
            error_log('MARKDOWN_OUTPUT_PREVIEW: ' . json_encode(substr($html, 0, 200) . (strlen($html) > 200 ? '...' : '')));
        }

        return $html;
    }

    private function fallback_to_parsedown($markdown) {
        try {
            if (class_exists('ParsedownExtra')) {
                $fallback = new ParsedownExtra();
                $fallback->setSafeMode(false);
                $fallback->setBreaksEnabled(true);
                error_log('ContentAuto: CommonMark失败，降级到ParsedownExtra');
                return $fallback->text($markdown);
            }

            if (class_exists('Parsedown')) {
                $fallback = new Parsedown();
                $fallback->setSafeMode(false);
                $fallback->setBreaksEnabled(true);
                error_log('ContentAuto: CommonMark失败，降级到Parsedown');
                return $fallback->text($markdown);
            }
        } catch (\Throwable $e) {
            error_log('ContentAuto: Parsedown降级失败 - ' . $e->getMessage());
        }

        return null;
    }

    /**
     * 获取当前使用的转换器类型
     *
     * @return string
     */
    public function get_converter_type() {
        $this->ensure_converter_initialized();
        return $this->converter_type;
    }

    /**
     * 获取支持的功能
     *
     * @return array
     */
    public function get_supported_features() {
        $this->ensure_converter_initialized();
        switch ($this->converter_type) {
            case 'commonmark':
                return $this->converter->get_supported_features();
                
            case 'parsedown_extra':
                return [
                    'tables' => true,
                    'strikethrough' => false,
                    'autolinks' => false,
                    'task_lists' => false,
                    'mermaid' => false,
                    'html_blocks' => true,
                ];
                
            case 'parsedown':
                return [
                    'tables' => true, // Parsedown也支持基本表格
                    'strikethrough' => false,
                    'autolinks' => false,
                    'task_lists' => false,
                    'mermaid' => false,
                    'html_blocks' => true,
                ];
                
            default:
                return [];
        }
    }

    /**
     * 获取已启用的扩展列表（仅CommonMark）
     *
     * @return array
     */
    public function get_enabled_extensions() {
        $this->ensure_converter_initialized();

        if ($this->converter_type === 'commonmark' && method_exists($this->converter, 'get_enabled_extensions')) {
            return $this->converter->get_enabled_extensions();
        }
        return [];
    }

    /**
     * 显示转换器状态信息
     *
     * @return string
     */
    public function get_converter_info() {
        $this->ensure_converter_initialized();

        $info = "转换器类型: {$this->converter_type}\n";
        $info .= "缓存的PHP版本: " . (self::$cached_php_version ?: '未缓存') . "\n";
        $info .= "当前PHP版本: " . PHP_VERSION . "\n";
        
        if ($this->converter_type === 'commonmark') {
            $extensions = $this->get_enabled_extensions();
            $info .= "支持的扩展 (" . count($extensions) . "个):\n";
            foreach ($extensions as $name => $description) {
                $info .= "  ✅ {$name} - {$description}\n";
            }
        } else {
            $features = $this->get_supported_features();
            $enabled_features = array_filter($features);
            $info .= "支持的功能 (" . count($enabled_features) . "个):\n";
            foreach ($enabled_features as $feature => $enabled) {
                if ($enabled) {
                    $info .= "  ✅ {$feature}\n";
                }
            }
        }
        
        return $info;
    }

    /**
     * 清除缓存，强制重新检测转换器
     * 用于调试或手动重置
     *
     * @return void
     */
    public static function clear_cache() {
        self::$cached_converter = null;
        self::$cached_converter_type = null;
        self::$cached_php_version = null;
        self::$cache_initialized = false;
        error_log('ContentAuto: 转换器缓存已清除');
    }

    /**
     * 获取缓存状态信息
     *
     * @return array
     */
    public static function get_cache_info() {
        return [
            'initialized' => self::$cache_initialized,
            'cached_php_version' => self::$cached_php_version,
            'current_php_version' => PHP_VERSION,
            'cached_converter_type' => self::$cached_converter_type,
            'cache_valid' => self::$cache_initialized && (self::$cached_php_version === PHP_VERSION),
        ];
    }
}
