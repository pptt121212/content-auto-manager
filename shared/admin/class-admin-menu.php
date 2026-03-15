<?php
/**
 * 后台菜单管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_AdminMenu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // 保持隐藏页面（搜索物料/调试工具）的菜单展开状态
        add_filter('parent_file', array($this, 'fix_hidden_submenu_parent'));

        // 确保文章结构和向量聚类的AJAX处理器被注册
        if (is_admin()) {
            $this->register_ajax_handlers();
        }
    }

    /**
     * 修复隐藏子菜单的父级菜单展开状态
     * 确保进入这些无父级挂载的页面时，左侧的插件主菜单依然高亮展开
     */
    public function fix_hidden_submenu_parent($parent_file) {
        global $plugin_page;
        if ($plugin_page === 'content-auto-search-materials' || $plugin_page === 'yali-ai-writer-debug-tools') {
            return 'yali-ai-writer';
        }
        return $parent_file;
    }

    /**
     * 注册AJAX处理器
     */
    private function register_ajax_handlers() {
        // 确保必要的类文件已加载
        if (!class_exists('ContentAuto_ArticleStructureAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-article-structure-admin-page.php';
        }

        // 实例化文章结构管理页面类以注册其AJAX处理器
        static $article_structures_page = null;
        if ($article_structures_page === null) {
            $article_structures_page = new ContentAuto_ArticleStructureAdminPage();
        }

        // 注册智能结构优化页面的AJAX处理器
        if (!class_exists('ContentAuto_SmartOptimizationAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-smart-optimization-admin-page.php';
        }
        static $smart_optimization_page = null;
        if ($smart_optimization_page === null) {
            $smart_optimization_page = new ContentAuto_SmartOptimizationAdminPage();
        }

        // 如果需要，也可以在这里注册向量聚类的AJAX处理器
        // 目前向量聚类页面主要使用表单提交，不需要额外的AJAX处理器

        // 注册编辑器助手的AJAX处理器
        if (!class_exists('ContentAuto_EditorAssistantAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'editor-assistant/class-editor-assistant-admin-page.php';
        }
        static $editor_assistant_page = null;
        if ($editor_assistant_page === null) {
            $editor_assistant_page = new ContentAuto_EditorAssistantAdminPage();
        }

        // 注册关键词研究工具的AJAX处理器
        if (file_exists(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php';
        }

        // 注册API设置AJAX处理器
        if (file_exists(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-ajax-handler.php')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-ajax-handler.php';
        }

        // 注册品牌资料的AJAX处理器
        if (!class_exists('ContentAuto_Brand_Profiles_Admin_Page')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/admin/class-brand-profiles-admin-page.php';
        }
        static $brand_profiles_page = null;
        if ($brand_profiles_page === null) {
            $brand_profiles_page = new ContentAuto_Brand_Profiles_Admin_Page();
        }
    }

    /**
     * 添加后台菜单
     */
    public function add_admin_menus() {
        // 主菜单
        add_menu_page(
            __('鸭梨AI文章智能写手', 'yali-ai-writer'),
            __('鸭梨AI写手', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer',
            array($this, 'render_dashboard_page'),
            'dashicons-edit-page',
            30
        );
        
        // 子菜单
        add_submenu_page(
            'yali-ai-writer',
            __('仪表盘', 'yali-ai-writer'),
            __('仪表盘', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer',
            array($this, 'render_dashboard_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('API设置', 'yali-ai-writer'),
            __('API设置', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-api',
            array($this, 'render_api_config_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('图像API', 'yali-ai-writer'),
            __('图像API', 'yali-ai-writer'),
            'manage_options',
            'cam-image-api-settings',
            array($this, 'render_image_api_page')
        );

        // 关键词工具页面
        add_submenu_page(
            'yali-ai-writer',
            __('关键词工具', 'yali-ai-writer'),
            __('关键词工具', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-keyword-tool',
            array($this, 'render_keyword_tool_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('规则管理', 'yali-ai-writer'),
            __('规则管理', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-rules',
            array($this, 'render_rules_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('主题任务', 'yali-ai-writer'),
            __('主题任务', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-topic-jobs',
            array($this, 'render_topic_jobs_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('主题管理', 'yali-ai-writer'),
            __('主题管理', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-topics',
            array($this, 'render_topics_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('文章任务', 'yali-ai-writer'),
            __('文章任务', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-article-tasks',
            array($this, 'render_article_tasks_page')
        );
        
        add_submenu_page(
            'yali-ai-writer',
            __('发布规则', 'yali-ai-writer'),
            __('发布规则', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-publish-rules',
            array($this, 'render_publish_rules_page')
        );
        

        // 提示词模板页面 (原变量说明)
        add_submenu_page(
            'yali-ai-writer',
            __('提示词模板', 'yali-ai-writer'),
            __('提示词模板', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-variable-guide',
            array($this, 'render_variable_guide_page')
        );


        // 搜索物料页面（不再显示在左侧菜单栏，通过仪表盘或URL参数进入）
        add_submenu_page(
            null, // Hide from menu
            __('搜索物料', 'yali-ai-writer'),
            __('搜索物料', 'yali-ai-writer'),
            'manage_options',
            'content-auto-search-materials',
            array($this, 'render_search_materials_page')
        );



        // 品牌资料页面
        add_submenu_page(
            'yali-ai-writer',
            __('品牌资料', 'yali-ai-writer'),
            __('品牌资料', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-brand-profiles',
            array($this, 'render_brand_profiles_page')
        );

        // 调试工具页面（不再显示在左侧菜单栏，通过仪表盘或URL参数进入）
        add_submenu_page(
            null, // Hide from menu
            __('调试工具', 'yali-ai-writer'),
            __('调试工具', 'yali-ai-writer'),
            'manage_options',
            'yali-ai-writer-debug-tools',
            array($this, 'render_debug_tools_page')
        );



        $this->override_menu_titles();

          
          
  
    }

    /**
     * 动态改写菜单标题，以实现稳定的钩子和中文显示
     */
    private function override_menu_titles() {
        // 不再需要动态改写，菜单标题已在注册时直接设置为中文
    }
    
    /**
     * 渲染仪表盘页面
     */
    public function render_dashboard_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'dashboard/views/enhanced-dashboard.php';
    }
    
    /**
     * 渲染API设置页面
     */
    public function render_api_config_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/views/api-config-form.php';
    }
    
    /**
     * 渲染图像API设置页面
     */
    public function render_image_api_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
        CAM_Image_API_Admin_Page::create_page();
    }

    /**
     * 渲染关键词工具页面
     */
    public function render_keyword_tool_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/keyword-research-admin-page.php';
    }
    
    
    /**
     * 渲染规则管理页面
     */
    public function render_rules_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/views/rule-management.php';
                break;
            default:
                require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/views/rules-list.php';
                break;
        }
    }
    
    /**
     * 渲染主题任务页面
     */
    public function render_topic_jobs_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/views/topic-jobs.php';
    }
    
    /**
     * 渲染主题管理页面
     */
    public function render_topics_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/views/topics-list.php';
    }
    
    /**
     * 渲染文章任务页面
     */
    public function render_article_tasks_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/views/article-tasks-list.php';
    }
    
    /**
     * 渲染发布规则页面
     */
    public function render_publish_rules_page() {
        if (!class_exists('ContentAuto_PublishRulesAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/class-publish-rules-admin-page.php';
        }
        $publish_rules_page = new ContentAuto_PublishRulesAdminPage();
        $publish_rules_page->render_page();
    }



    /**
     * 渲染智能结构优化页面 (现在作为Tab调用)
     */
    public function render_smart_optimization_page() {
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('ContentAuto_SmartOptimizationAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-smart-optimization-admin-page.php';
        }

        $smart_optimization_page = new ContentAuto_SmartOptimizationAdminPage();
        $smart_optimization_page->render_page();
    }

    /**
     * 渲染品牌资料页面
     */
    public function render_brand_profiles_page() {
        wp_enqueue_media(); // Enqueue media scripts for the uploader
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('ContentAuto_Brand_Profiles_Admin_Page')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'brand-profiles/admin/class-brand-profiles-admin-page.php';
        }

        $brand_profiles_page = new ContentAuto_Brand_Profiles_Admin_Page();
        $brand_profiles_page->render_page();
    }

    /**
     * 加载后台脚本和样式
     */
    public function enqueue_admin_scripts($hook) {
        // 只在插件页面加载 - 包含所有可能的页面slug前缀
        if (strpos($hook, 'yali-ai-writer') === false && 
            strpos($hook, 'cam-') === false && 
            strpos($hook, 'content-auto-') === false) {
            return;
        }

        // 🚀 性能优化：生产环境加载 .min.js，调试模式加载原始 .js
        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        
        // 首先，加载所有后台页面都需要的通用脚本和样式
        wp_enqueue_style(
            'yali-ai-brand-tokens',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/brand-tokens.css',
            array(),
            CONTENT_AUTO_MANAGER_VERSION
        );

        wp_enqueue_style(
            'yali-ai-writer-admin-css',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/admin.css',
            array(),
            CONTENT_AUTO_MANAGER_VERSION
        );

        wp_enqueue_style(
            'yali-ai-ui-kit',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/yali-ui-kit.css',
            array('yali-ai-brand-tokens'),
            CONTENT_AUTO_MANAGER_VERSION
        );

        // Toast 通知样式 - 所有页面通用
        wp_enqueue_style(
            'yali-ai-toast-css',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/toast.css',
            array(),
            CONTENT_AUTO_MANAGER_VERSION
        );
        
        wp_enqueue_script(
            'yali-ai-writer-admin-js',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . "shared/assets/js/admin{$suffix}.js",
            array('jquery', 'wp-i18n'),
            CONTENT_AUTO_MANAGER_VERSION,
            true
        );
        wp_set_script_translations('yali-ai-writer-admin-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');

        // 通用操作按钮库（所有页面共享）
        wp_enqueue_script(
            'yali-actions-js',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . "shared/assets/js/yali-actions{$suffix}.js",
            array('jquery', 'yali-ai-writer-admin-js'),
            CONTENT_AUTO_MANAGER_VERSION,
            true
        );
        wp_localize_script('yali-actions-js', 'yaliActionStrings', array(
            'confirmDelete' => __('确定要删除吗？此操作不可撤销。', 'yali-ai-writer'),
            'deleteSuccess' => __('删除成功', 'yali-ai-writer'),
            'deleteFailed' => __('删除失败', 'yali-ai-writer'),
            'loading' => __('处理中...', 'yali-ai-writer'),
            'serverError' => __('服务器错误', 'yali-ai-writer'),
            'networkError' => __('网络请求失败', 'yali-ai-writer'),
            'selectItems' => __('请选择要删除的项目', 'yali-ai-writer'),
            'itemsCount' => __(' (%d 个项目)', 'yali-ai-writer')
        ));

        // 其次，根据特定页面加载其独有的脚本和样式
        if ($hook == 'toplevel_page_yali-ai-writer') {
            // 仪表盘页面（顶级菜单）
        } 
        
        if (strpos($hook, 'yali-ai-writer-api') !== false) {
            // API设置页面
            wp_enqueue_style(
                'yali-ai-writer-api-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'api-settings/assets/css/api-settings.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-api-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "api-settings/assets/js/api-settings{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-api-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-image-api') !== false) {
            // Image API设置页面
            wp_enqueue_script(
                'cam-image-api-settings',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "image-api-settings/assets/js/image-api-settings{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('cam-image-api-settings', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }
        
        if (strpos($hook, 'yali-ai-writer-rules') !== false) {
            // 规则管理页面
            wp_enqueue_style(
                'yali-ai-writer-rules-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'rule-management/assets/css/rule-management.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-rules-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "rule-management/assets/js/rule-management{$suffix}.js",
                array('jquery', 'yali-ai-writer-admin-js', 'wp-i18n'), // Ensure admin.js leaks contentAutoManager
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-rules-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');

            wp_enqueue_script(
                'yali-ai-writer-rules-list-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "rule-management/assets/js/rules-list{$suffix}.js",
                array('jquery', 'yali-actions-js', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-rules-list-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }
        
        if (strpos($hook, 'yali-ai-writer-topic-jobs') !== false || strpos($hook, 'yali-ai-writer-topics') !== false) {
            // 主题任务和主题管理页面
            wp_enqueue_style(
                'yali-ai-writer-topic-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'topic-management/assets/css/topic-management.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
            
            wp_enqueue_script(
                'yali-ai-writer-topic-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "topic-management/assets/js/topic-management{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-topic-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }
        
        if (strpos($hook, 'yali-ai-writer-article-tasks') !== false) {
            // 文章任务页面
            wp_enqueue_style(
                'yali-ai-writer-article-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'article-tasks/assets/css/article-tasks.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
        }
        
        if (strpos($hook, 'yali-ai-writer-debug-tools') !== false) {
            // 调试工具页面
            wp_enqueue_style(
                'yali-ai-writer-debug-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'debug-tools/assets/css/debug-tools.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-debug-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "debug-tools/assets/js/debug-tools{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-debug-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-variable-guide') !== false) {
            // 变量说明页面
            wp_enqueue_style(
                'yali-ai-writer-variable-guide-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'variable-guide/assets/css/variable-guide.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-variable-guide-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "variable-guide/assets/js/variable-guide{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-variable-guide-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-publish-rules') !== false && isset($_GET['action']) && $_GET['action'] === 'article-structures') {
            // 文章结构页面 (包含智能优化Tab)
            $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'structures';

            if ($current_tab === 'smart-optimization') {
                // 智能结构优化 Tab
                 wp_enqueue_style(
                    'yali-ai-writer-smart-optimization-css',
                    CONTENT_AUTO_MANAGER_PLUGIN_URL . 'article-structures/assets/css/smart-optimization-settings.css',
                    array(),
                    CONTENT_AUTO_MANAGER_VERSION
                );

                wp_enqueue_script(
                    'yali-ai-writer-smart-optimization-js',
                    CONTENT_AUTO_MANAGER_PLUGIN_URL . "article-structures/assets/js/smart-optimization-settings{$suffix}.js",
                    array('jquery', 'wp-i18n', 'yali-actions-js'),
                    CONTENT_AUTO_MANAGER_VERSION,
                    true
                );
                wp_set_script_translations('yali-ai-writer-smart-optimization-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');

                wp_localize_script('yali-ai-writer-smart-optimization-js', 'smartOptimizationSettings', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('content_auto_manager_nonce')
                ));
            } else {
                // 默认：结构管理 Tab
                wp_enqueue_style(
                    'yali-ai-writer-article-structures-css',
                    CONTENT_AUTO_MANAGER_PLUGIN_URL . 'article-structures/assets/css/article-structure-management.css',
                    array(),
                    CONTENT_AUTO_MANAGER_VERSION
                );

                wp_enqueue_script(
                    'yali-ai-writer-article-structures-js',
                    CONTENT_AUTO_MANAGER_PLUGIN_URL . "article-structures/assets/js/article-structure-management{$suffix}.js",
                    array('jquery', 'jquery-ui-sortable', 'wp-i18n', 'yali-actions-js'),
                    CONTENT_AUTO_MANAGER_VERSION,
                    true
                );
                wp_set_script_translations('yali-ai-writer-article-structures-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
                
                wp_localize_script('yali-ai-writer-article-structures-js', 'articleStructuresData', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('content_auto_manager_nonce')
                ));
            }
        }
        
        // 关键词工具页面 - 使用更灵活的匹配方式
        if (strpos($hook, 'yali-ai-writer-keyword-tool') !== false) {
            // 关键词工具页面
            $css_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/assets/css/keyword-research.css';
            $css_ver = file_exists($css_path) ? filemtime($css_path) : CONTENT_AUTO_MANAGER_VERSION;
            wp_enqueue_style(
                'keyword-research-tool-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'keyword-research-tool/assets/css/keyword-research.css',
                array(),
                $css_ver
            );
            
            $js_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . "keyword-research-tool/assets/js/keyword-research{$suffix}.js";
            $js_ver = file_exists($js_path) ? filemtime($js_path) : CONTENT_AUTO_MANAGER_VERSION;
            wp_enqueue_script(
                'keyword-research-tool-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "keyword-research-tool/assets/js/keyword-research{$suffix}.js",
                array('jquery', 'wp-i18n'),
                $js_ver,
                true
            );
            wp_set_script_translations('keyword-research-tool-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');

            wp_localize_script('keyword-research-tool-js', 'keywordResearchToolData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('keyword_research_nonce')
            ));
        }

        if (strpos($hook, 'yali-ai-writer-brand-profiles') !== false) {
            // 品牌资料页面 - 样式
            wp_enqueue_style(
                'yali-brand-profiles-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'brand-profiles/assets/css/brand-profiles.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
            
            // 品牌资料页面 - 脚本
            wp_enqueue_script(
                'yali-brand-profiles-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . "brand-profiles/assets/js/brand-profiles{$suffix}.js",
                array('jquery', 'wp-i18n'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
            wp_set_script_translations('yali-brand-profiles-js', 'yali-ai-writer', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'languages');
            
            // 本地化脚本数据
            wp_localize_script('yali-brand-profiles-js', 'brandProfilesManager', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('brand_profiles_nonce')
            ));
        }
        
        // 本地化脚本 - 保持原有变量名以兼容现有JS文件
        wp_localize_script('yali-ai-writer-admin-js', 'contentAutoManager', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('content_auto_manager_nonce')
        ));
    }
    
    /**
     * 渲染调试工具页面
     */
    public function render_debug_tools_page() {
        // 检查是否是特定的测试页面
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'debug-tools/views/debug-tools.php';
    }

    /**
     * 渲染搜索物料页面
     */
    public function render_search_materials_page() {
        if (!class_exists('ContentAuto_SearchMaterialsAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'search-materials/class-search-materials-admin-page.php';
        }
        $page = new ContentAuto_SearchMaterialsAdminPage();
        $page->render_page();
    }

    /**
     * 渲染变量说明页面
     */
    public function render_variable_guide_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'variable-guide/views/variable-guide.php';
    }
}