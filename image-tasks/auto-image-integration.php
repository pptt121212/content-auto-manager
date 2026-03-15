<?php
/**
 * 自动配图集成文件
 * 将自动配图功能集成到主插件中
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 自动配图集成类
 */
class ContentAuto_AutoImageIntegration {
    
    private static $instance = null;
    private $initialized = false;
    
    /**
     * 单例模式
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化集成
     */
    public function init() {
        if ($this->initialized) {
            return;
        }
        
        // 加载必要文件
        $this->load_required_files();
        
        // 注册钩子
        $this->register_hooks();
        
        // 初始化异步处理器
        $this->init_async_processor();
        
        $this->initialized = true;
    }
    
    /**
     * 加载必要文件
     */
    private function load_required_files() {
        $files = [
            'class-auto-image-generator.php',
            'class-async-image-processor.php'
        ];
        
        foreach ($files as $file) {
            $file_path = __DIR__ . '/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * 注册WordPress钩子
     */
    private function register_hooks() {
        // 自动配图功能无需管理界面，仅后台运行
    }
    
    /**
     * 初始化异步处理器
     */
    private function init_async_processor() {
        // 异步处理器会自动初始化
    }
    
    // 自动配图功能完全后台运行，无需统计和管理界面
}

// 初始化自动配图集成
function content_auto_init_auto_image_integration() {
    $integration = ContentAuto_AutoImageIntegration::get_instance();
    $integration->init();
}

// 在插件加载后初始化
add_action('plugins_loaded', 'content_auto_init_auto_image_integration', 25);