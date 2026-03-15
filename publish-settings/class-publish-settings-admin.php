<?php
/**
 * 发布设置管理页面
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_Publish_Settings_Admin {
    
    public function __construct() {
        // 不再添加独立的分类过滤菜单项
        // 分类使用范围功能已集成到发布规则页面中
    }
    
    /**
     * 分类过滤设置页面（保留方法以供发布规则页面调用）
     */
    public function category_filter_page() {
        require_once dirname(__FILE__) . '/views/category-filter-settings.php';
    }
}

// 初始化管理页面
new ContentAuto_Publish_Settings_Admin();