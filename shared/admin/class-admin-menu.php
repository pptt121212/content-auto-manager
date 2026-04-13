<?php
/**
 * 后台菜单管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_AdminMenu {
    
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
        if (!class_exists('Yali_AI_Writer_ArticleStructureAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-structures/class-article-structure-admin-page.php';
        }

        // 实例化文章结构管理页面类以注册其AJAX处理器
        static $article_structures_page = null;
        if ($article_structures_page === null) {
            $article_structures_page = new Yali_AI_Writer_ArticleStructureAdminPage();
        }

        // 注册智能结构优化页面的AJAX处理器
        if (!class_exists('Yali_AI_Writer_SmartOptimizationAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-structures/class-smart-optimization-admin-page.php';
        }
        static $smart_optimization_page = null;
        if ($smart_optimization_page === null) {
            $smart_optimization_page = new Yali_AI_Writer_SmartOptimizationAdminPage();
        }

        // 如果需要，也可以在这里注册向量聚类的AJAX处理器
        // 目前向量聚类页面主要使用表单提交，不需要额外的AJAX处理器

        // 注册编辑器助手的AJAX处理器
        if (!class_exists('Yali_AI_Writer_EditorAssistantAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-editor-assistant-admin-page.php';
        }
        static $editor_assistant_page = null;
        if ($editor_assistant_page === null) {
            $editor_assistant_page = new Yali_AI_Writer_EditorAssistantAdminPage();
        }

        // 注册关键词研究工具的AJAX处理器
        if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php';
        }

        // 注册API设置AJAX处理器
        if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-ajax-handler.php')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-ajax-handler.php';
        }

        // 注册品牌资料的AJAX处理器
        if (!class_exists('Yali_AI_Writer_Brand_Profiles_Admin_Page')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'brand-profiles/admin/class-brand-profiles-admin-page.php';
        }
        static $brand_profiles_page = null;
        if ($brand_profiles_page === null) {
            $brand_profiles_page = new Yali_AI_Writer_Brand_Profiles_Admin_Page();
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
            'yali-hidden-pages', // Hide from menu (cannot be null in PHP 8.1+)
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
            'yali-hidden-pages', // Hide from menu (cannot be null in PHP 8.1+)
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
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'dashboard/views/enhanced-dashboard.php';
    }
    
    /**
     * 渲染API设置页面
     */
    public function render_api_config_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/views/api-config-form.php';
    }
    
    /**
     * 渲染图像API设置页面
     */
    public function render_image_api_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
        Yali_AI_Writer_Image_API_Admin_Page::create_page();
    }

    /**
     * 渲染关键词工具页面
     */
    public function render_keyword_tool_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'keyword-research-tool/keyword-research-admin-page.php';
    }
    
    
    /**
     * 渲染规则管理页面
     */
    public function render_rules_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/views/rule-management.php';
                break;
            default:
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/views/rules-list.php';
                break;
        }
    }
    
    /**
     * 渲染主题任务页面
     */
    public function render_topic_jobs_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/views/topic-jobs.php';
    }
    
    /**
     * 渲染主题管理页面
     */
    public function render_topics_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/views/topics-list.php';
    }
    
    /**
     * 渲染文章任务页面
     */
    public function render_article_tasks_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/views/article-tasks-list.php';
    }
    
    /**
     * 渲染发布规则页面
     */
    public function render_publish_rules_page() {
        if (!class_exists('Yali_AI_Writer_PublishRulesAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/class-publish-rules-admin-page.php';
        }
        $publish_rules_page = new Yali_AI_Writer_PublishRulesAdminPage();
        $publish_rules_page->render_page();
    }



    /**
     * 渲染智能结构优化页面 (现在作为Tab调用)
     */
    public function render_smart_optimization_page() {
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('Yali_AI_Writer_SmartOptimizationAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-structures/class-smart-optimization-admin-page.php';
        }

        $smart_optimization_page = new Yali_AI_Writer_SmartOptimizationAdminPage();
        $smart_optimization_page->render_page();
    }

    /**
     * 渲染品牌资料页面
     */
    public function render_brand_profiles_page() {
        wp_enqueue_media(); // Enqueue media scripts for the uploader
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('Yali_AI_Writer_Brand_Profiles_Admin_Page')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'brand-profiles/admin/class-brand-profiles-admin-page.php';
        }

        $brand_profiles_page = new Yali_AI_Writer_Brand_Profiles_Admin_Page();
        $brand_profiles_page->render_page();
    }

    /**
     * 禁用不必要的WordPress核心脚本（性能优化）
     * 仅在鸭梨AI写作插件页面执行，不影响其他功能
     */
    private function disable_unnecessary_wp_scripts() {
        // ⚠️ 重要：不禁用 wp-i18n, wp-hooks, wp-dom-ready, wp-polyfill
        // 这些是我们的脚本必需的依赖，禁用会导致功能失效
        
        // 只禁用古腾堡块编辑器相关脚本（插件页面不需要）
        wp_deregister_script('wp-block-editor');
        wp_deregister_script('wp-block-library');
        wp_deregister_script('wp-blocks');
        wp_deregister_script('wp-components');
        wp_deregister_script('wp-compose');
        wp_deregister_script('wp-core-data');
        wp_deregister_script('wp-data');
        wp_deregister_script('wp-data-controls');
        wp_deregister_script('wp-date');
        wp_deregister_script('wp-deprecated');
        wp_deregister_script('wp-dom');
        wp_deregister_script('wp-element');
        wp_deregister_script('wp-escape-html');
        wp_deregister_script('wp-html-entities');
        wp_deregister_script('wp-is-shallow-equal');
        wp_deregister_script('wp-keycodes');
        wp_deregister_script('wp-list-reusable-blocks');
        wp_deregister_script('wp-notices');
        wp_deregister_script('wp-plugins');
        wp_deregister_script('wp-primitives');
        wp_deregister_script('wp-rich-text');
        wp_deregister_script('wp-router');
        wp_deregister_script('wp-server-side-render');
        wp_deregister_script('wp-shortcode');
        wp_deregister_script('wp-token-list');
        wp_deregister_script('wp-url');
        wp_deregister_script('wp-viewport');
        wp_deregister_script('wp-warning');
        wp_deregister_script('wp-widgets');
        
        // 禁用古腾堡编辑器样式
        wp_deregister_style('wp-block-editor');
        wp_deregister_style('wp-block-library');
        wp_deregister_style('wp-blocks');
        wp_deregister_style('wp-components');
        wp_deregister_style('wp-edit-blocks');
        wp_deregister_style('wp-editor');
        wp_deregister_style('wp-list-reusable-blocks');
        wp_deregister_style('wp-nux');
        wp_deregister_style('wp-router');
        wp_deregister_style('wp-block-directory');
        wp_deregister_style('wp-customize-widgets');
        wp_deregister_style('wp-edit-widgets');
        wp_deregister_style('wp-reusable-blocks');
        
        // 不禁用媒体库脚本，可能在某些页面需要
        // wp_deregister_script('media-views');
        // wp_deregister_script('media-grid');
        // wp_deregister_script('media');
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

        // 🚀 性能优化：禁用不必要的WordPress核心脚本（仅在插件页面）
        $this->disable_unnecessary_wp_scripts();

        // 🚀 性能优化：生产环境加载 .min.js，调试模式加载原始 .js
        $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        
        // 首先，加载所有后台页面都需要的通用脚本和样式
        wp_enqueue_style(
            'yali-ai-brand-tokens',
            YALI_AI_WRITER_PLUGIN_URL . 'shared/assets/css/brand-tokens.css',
            array(),
            YALI_AI_WRITER_VERSION
        );

        wp_enqueue_style(
            'yali-ai-writer-admin-css',
            YALI_AI_WRITER_PLUGIN_URL . 'shared/assets/css/admin.css',
            array(),
            YALI_AI_WRITER_VERSION
        );

        wp_enqueue_style(
            'yali-ai-ui-kit',
            YALI_AI_WRITER_PLUGIN_URL . 'shared/assets/css/yali-ui-kit.css',
            array('yali-ai-brand-tokens'),
            YALI_AI_WRITER_VERSION
        );

        // Toast 通知样式 - 所有页面通用
        wp_enqueue_style(
            'yali-ai-toast-css',
            YALI_AI_WRITER_PLUGIN_URL . 'shared/assets/css/toast.css',
            array(),
            YALI_AI_WRITER_VERSION
        );
        
        wp_enqueue_script(
            'yali-ai-writer-admin-js',
            YALI_AI_WRITER_PLUGIN_URL . "shared/assets/js/admin{$suffix}.js",
            array('jquery', 'wp-i18n'),
            YALI_AI_WRITER_VERSION,
            true
        );
        wp_set_script_translations('yali-ai-writer-admin-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

        // 通用操作按钮库（所有页面共享）
        wp_enqueue_script(
            'yali-actions-js',
            YALI_AI_WRITER_PLUGIN_URL . "shared/assets/js/yali-actions{$suffix}.js",
            array('jquery', 'yali-ai-writer-admin-js'),
            YALI_AI_WRITER_VERSION,
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
            // 加载仪表盘特定的内联样式（已从视图文件提取）
            wp_enqueue_style(
                'yali-ai-writer-dashboard-inline',
                YALI_AI_WRITER_PLUGIN_URL . 'dashboard/assets/css/enhanced-dashboard-inline.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            // 仪表盘内联脚本（已从视图文件提取）
            wp_enqueue_script(
                'yali-ai-writer-dashboard-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'dashboard/assets/js/enhanced-dashboard-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_localize_script('yali-ai-writer-dashboard-inline-js', 'enhancedDashboardData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce'),
                'clearQueueNonce' => wp_create_nonce('yali_ai_writer_clear_queue'),
                'bulkCleanNonce' => wp_create_nonce('yali_ai_writer_bulk_clean')
            ));
            wp_set_script_translations('yali-ai-writer-dashboard-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-publish') !== false) {
            // 发布设置页面（包含分类过滤设置）
            // 加载分类过滤页面特定的样式和脚本（已从视图文件提取）
            wp_enqueue_style(
                'yali-ai-writer-category-filter-inline',
                YALI_AI_WRITER_PLUGIN_URL . 'publish-settings/assets/css/category-filter-inline.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-category-filter-inline',
                YALI_AI_WRITER_PLUGIN_URL . 'publish-settings/assets/js/category-filter-inline.js',
                array(),
                YALI_AI_WRITER_VERSION,
                true
            );
        }

        if (strpos($hook, 'yali-ai-writer-api') !== false) {
            // API设置页面
            wp_enqueue_style(
                'yali-ai-writer-api-css',
                YALI_AI_WRITER_PLUGIN_URL . 'api-settings/assets/css/api-settings.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-api-js',
                YALI_AI_WRITER_PLUGIN_URL . "api-settings/assets/js/api-settings{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-api-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // API配置表单内联脚本（已从视图文件提取）
            wp_enqueue_script(
                'yali-ai-writer-api-config-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'api-settings/assets/js/api-config-form-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            // 检查是否为编辑模式
            $is_edit_config = (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0);

            // 编辑模式下获取当前配置的模型名称和渠道
            $saved_model = '';
            $selected_channel = isset($_GET['channel']) ? sanitize_text_field($_GET['channel']) : '';
            
            if ($is_edit_config) {
                $config_id = intval($_GET['id']);
                // 确保 ApiConfig 类已加载
                if (!class_exists('Yali_AI_Writer_ApiConfig')) {
                    require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-config.php';
                }
                $api_config = new Yali_AI_Writer_ApiConfig();
                $config = $api_config->get_config($config_id);
                if ($config) {
                    if (!empty($config['model_name'])) {
                        $saved_model = $config['model_name'];
                    }
                    // 如果是预置API配置，获取渠道
                    if (!empty($config['predefined_channel'])) {
                        $selected_channel = $config['predefined_channel'];
                    }
                }
            }

            wp_localize_script('yali-ai-writer-api-config-inline-js', 'apiConfigFormData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce'),
                'managerNonce' => wp_create_nonce('yali_ai_writer_manager_nonce'),
                'quotaNonce' => wp_create_nonce('yali_ai_writer_manager_nonce'),
                'selectedChannel' => $selected_channel,
                'savedModel' => $saved_model,
                'isEditConfig' => $is_edit_config
            ));
            wp_set_script_translations('yali-ai-writer-api-config-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-image-api') !== false) {
            // Image API设置页面
            wp_enqueue_script(
                'cam-image-api-settings',
                YALI_AI_WRITER_PLUGIN_URL . "image-api-settings/assets/js/image-api-settings{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('cam-image-api-settings', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }
        
        if (strpos($hook, 'yali-ai-writer-rules') !== false) {
            // 规则管理页面
            wp_enqueue_style(
                'yali-ai-writer-rules-css',
                YALI_AI_WRITER_PLUGIN_URL . 'rule-management/assets/css/rule-management.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-rules-js',
                YALI_AI_WRITER_PLUGIN_URL . "rule-management/assets/js/rule-management{$suffix}.js",
                array('jquery', 'yali-ai-writer-admin-js', 'wp-i18n'), // Ensure admin.js leaks contentAutoManager
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-rules-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            wp_enqueue_script(
                'yali-ai-writer-rules-list-js',
                YALI_AI_WRITER_PLUGIN_URL . "rule-management/assets/js/rules-list{$suffix}.js",
                array('jquery', 'yali-actions-js', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-rules-list-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // 规则列表页内联脚本（已从视图文件提取）- 只在列表模式加载
            $rule_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
            if ($rule_action === 'list') {
                wp_enqueue_script(
                    'yali-ai-writer-rules-list-inline-js',
                    YALI_AI_WRITER_PLUGIN_URL . 'rule-management/assets/js/rules-list-inline.js',
                    array('jquery', 'wp-i18n'),
                    YALI_AI_WRITER_VERSION,
                    true
                );
                wp_set_script_translations('yali-ai-writer-rules-list-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
            }

            // 规则编辑页内联脚本（已从视图文件提取）- 只在添加/编辑模式加载
            $rule_action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
            if ($rule_action === 'add' || $rule_action === 'edit') {
                wp_enqueue_script(
                    'yali-ai-writer-rule-management-inline-js',
                    YALI_AI_WRITER_PLUGIN_URL . 'rule-management/assets/js/rule-management-inline.js',
                    array('jquery', 'wp-i18n'),
                    YALI_AI_WRITER_VERSION,
                    true
                );
                wp_localize_script('yali-ai-writer-rule-management-inline-js', 'ruleManagementData', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce')
                ));
                wp_set_script_translations('yali-ai-writer-rule-management-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
            }
        }
        
        if (strpos($hook, 'yali-ai-writer-topic-jobs') !== false || strpos($hook, 'yali-ai-writer-topics') !== false) {
            // 主题任务和主题管理页面
            wp_enqueue_style(
                'yali-ai-writer-topic-css',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/css/topic-management.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-topic-js',
                YALI_AI_WRITER_PLUGIN_URL . "topic-management/assets/js/topic-management{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-topic-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // 提取自 views/topics-list.php 的筛选器与模态框资源
            wp_enqueue_style(
                'cam-topic-filter',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/css/topic-filter.css',
                array(),
                YALI_AI_WRITER_VERSION
            );
            
            wp_enqueue_script(
                'cam-topic-filter',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/js/topic-filter.js',
                array('jquery'),
                YALI_AI_WRITER_VERSION,
                true
            );
            
            wp_localize_script('cam-topic-filter', 'camTopicFilterI18n', array(
                'detecting' => __('正在检测重复标题...', 'yali-ai-writer'),
                'detected' => __('检测完成，发现', 'yali-ai-writer'),
                'duplicateTopics' => __('个重复主题', 'yali-ai-writer'),
                'noDuplicates' => __('未发现重复标题', 'yali-ai-writer'),
                'detectFailed' => __('检测失败', 'yali-ai-writer'),
                'requestFailed' => __('请求失败', 'yali-ai-writer'),
                'summary' => __('检测结果汇总', 'yali-ai-writer'),
                'exactGroups' => __('完全相同标题组', 'yali-ai-writer'),
                'exactTopics' => __('完全重复主题数', 'yali-ai-writer'),
                'similarGroups' => __('向量相似组', 'yali-ai-writer'),
                'similarTopics' => __('相似重复主题数', 'yali-ai-writer'),
                'exactLabel' => __('完全相同', 'yali-ai-writer'),
                'exactTitle' => __('完全相同的标题', 'yali-ai-writer'),
                'similarLabel' => __('向量相似', 'yali-ai-writer'),
                'similarTitle' => __('向量相似的标题', 'yali-ai-writer'),
                'groups' => __('组', 'yali-ai-writer'),
                'topics' => __('个主题', 'yali-ai-writer'),
                'createdAt' => __('创建时间', 'yali-ai-writer'),
                'category' => __('分类', 'yali-ai-writer'),
                'keep' => __('保留', 'yali-ai-writer'),
                'willDelete' => __('将删除', 'yali-ai-writer'),
                'similarity' => __('相似度', 'yali-ai-writer'),
                'noResults' => __('未发现重复标题', 'yali-ai-writer'),
                'detectFirst' => __('请先检测重复标题', 'yali-ai-writer'),
                'noDuplicatesToDelete' => __('没有需要删除的重复主题', 'yali-ai-writer'),
                'confirmDeleteAll' => __('确定要删除所有重复主题吗？将保留每组中最早创建的主题。', 'yali-ai-writer'),
                'deleting' => __('正在删除重复主题...', 'yali-ai-writer'),
                'deleteFailed' => __('删除失败', 'yali-ai-writer'),
                'selectTopics' => __('请选择要删除的主题', 'yali-ai-writer'),
                'confirmDeleteSelected' => __('确定要删除选中的', 'yali-ai-writer'),
                'topicsConfirm' => __('个主题吗？此操作不可撤销。', 'yali-ai-writer'),
                'deletingText' => __('删除中...', 'yali-ai-writer'),
                'deleteSelected' => __('删除选中', 'yali-ai-writer'),
                'confirmGenerateReference' => __('确定要为选中的', 'yali-ai-writer'),
                'success' => __('操作成功', 'yali-ai-writer'),
                'noTopicsToDelete' => __('没有符合条件的主题可删除', 'yali-ai-writer'),
                'onlyUnusedCanDelete' => __('只能批量删除"未使用"状态的主题，请先在状态筛选中选择"未使用"', 'yali-ai-writer'),
                'confirmDeleteAllFiltered' => __('确定要删除所有符合筛选条件的', 'yali-ai-writer'),
                'topicsQuestion' => __('个主题吗？此操作不可撤销。', 'yali-ai-writer'),
                'deleteSuccess' => __('删除成功', 'yali-ai-writer')
            ));

            wp_enqueue_style(
                'cam-manual-add-modal',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/css/manual-add-modal.css',
                array(),
                YALI_AI_WRITER_VERSION
            );
            
            wp_enqueue_script(
                'cam-manual-add-modal',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/js/manual-add-modal.js',
                array('jquery'),
                YALI_AI_WRITER_VERSION,
                true
            );
            
            wp_localize_script('cam-manual-add-modal', 'camManualAddI18n', array(
                'adding' => __('正在添加...', 'yali-ai-writer'),
                'addSuccess' => __('添加成功', 'yali-ai-writer'),
                'addFailed' => __('添加失败', 'yali-ai-writer'),
                'requestFailed' => __('请求失败', 'yali-ai-writer'),
                'pleaseEnterTitle' => __('请至少输入一个主题标题', 'yali-ai-writer'),
                'success' => __('添加成功', 'yali-ai-writer')
            ));

            // 主题列表页内联样式和脚本（已从视图文件提取）
            wp_enqueue_style(
                'yali-ai-writer-topics-list-inline-css',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/css/topics-list-inline.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-topics-list-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/js/topics-list-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_localize_script('yali-ai-writer-topics-list-inline-js', 'topicsListData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'recallTestNonce' => wp_create_nonce('yali_ai_writer_reference_recall_test')
            ));
            wp_set_script_translations('yali-ai-writer-topics-list-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // 主题任务页内联脚本（已从视图文件提取）- 只在主题任务页面加载
            if (strpos($hook, 'yali-ai-writer-topic-jobs') !== false) {
                wp_enqueue_script(
                    'yali-ai-writer-topic-jobs-inline-js',
                    YALI_AI_WRITER_PLUGIN_URL . 'topic-management/assets/js/topic-jobs-inline.js',
                    array('jquery', 'wp-i18n'),
                    YALI_AI_WRITER_VERSION,
                    true
                );

                // 获取规则数据用于前端
                $rule_manager = new Yali_AI_Writer_RuleManager();
                $rules = $rule_manager->get_active_rules();
                $rule_type_map = array();
                $rule_reference_map = array();
                foreach ($rules as $r) {
                    $rule_type_map[$r->id] = $r->rule_type;
                    $rule_reference_map[$r->id] = !empty(trim($r->reference_material));
                }

                $db_access = new Yali_AI_Writer_Database();
                $publish_rules = $db_access->get_row('yali_ai_writer_publish_rules', array('id' => 1));
                $extension_rag_global = (isset($publish_rules['material_collection_mode']) && $publish_rules['material_collection_mode'] === 'extension_rag');

                wp_localize_script('yali-ai-writer-topic-jobs-inline-js', 'topicJobsData', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce'),
                    'ruleTypeMap' => $rule_type_map,
                    'ruleReferenceMap' => $rule_reference_map,
                    'extensionRagGlobal' => $extension_rag_global
                ));
                wp_set_script_translations('yali-ai-writer-topic-jobs-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
            }
        }
        
        if (strpos($hook, 'yali-ai-writer-article-tasks') !== false) {
            // 文章任务页面
            wp_enqueue_style(
                'yali-ai-writer-article-css',
                YALI_AI_WRITER_PLUGIN_URL . 'article-tasks/assets/css/article-tasks.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            $details_css_path = YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/assets/css/article-task-details.css';
            $details_css_ver = file_exists($details_css_path) ? filemtime($details_css_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_style(
                'yali-ai-writer-article-task-details-css',
                YALI_AI_WRITER_PLUGIN_URL . 'article-tasks/assets/css/article-task-details.css',
                array('yali-ai-writer-article-css'),
                $details_css_ver
            );

            // 文章任务列表页内联脚本（已从视图文件提取）
            wp_enqueue_script(
                'yali-ai-writer-article-tasks-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'article-tasks/assets/js/article-tasks-list-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_localize_script('yali-ai-writer-article-tasks-inline-js', 'articleTasksData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce')
            ));
            wp_set_script_translations('yali-ai-writer-article-tasks-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }
        
        if (strpos($hook, 'yali-ai-writer-debug-tools') !== false) {
            // 调试工具页面
            wp_enqueue_style(
                'yali-ai-writer-debug-css',
                YALI_AI_WRITER_PLUGIN_URL . 'debug-tools/assets/css/debug-tools.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-debug-js',
                YALI_AI_WRITER_PLUGIN_URL . "debug-tools/assets/js/debug-tools{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-debug-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // 调试工具页内联样式和脚本（已从视图文件提取）
            wp_enqueue_style(
                'yali-ai-writer-debug-tools-inline-css',
                YALI_AI_WRITER_PLUGIN_URL . 'debug-tools/assets/css/debug-tools-inline.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-debug-tools-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'debug-tools/assets/js/debug-tools-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_localize_script('yali-ai-writer-debug-tools-inline-js', 'debugToolsData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'debugModeNonce' => wp_create_nonce('debug_mode_toggle'),
                'debugLogsNonce' => wp_create_nonce('debug_logs_view'),
                'debugLogsClearNonce' => wp_create_nonce('debug_logs_clear')
            ));
            wp_set_script_translations('yali-ai-writer-debug-tools-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }

        if (strpos($hook, 'yali-ai-writer-variable-guide') !== false) {
            // 变量说明页面
            wp_enqueue_style(
                'yali-ai-writer-variable-guide-css',
                YALI_AI_WRITER_PLUGIN_URL . 'variable-guide/assets/css/variable-guide.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_style(
                'yali-ai-writer-variable-guide-inline-css',
                YALI_AI_WRITER_PLUGIN_URL . 'variable-guide/assets/css/variable-guide-inline.css',
                array(),
                YALI_AI_WRITER_VERSION
            );

            wp_enqueue_script(
                'yali-ai-writer-variable-guide-js',
                YALI_AI_WRITER_PLUGIN_URL . "variable-guide/assets/js/variable-guide{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-ai-writer-variable-guide-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            // 内联脚本（原内联代码已提取到外部文件，使用 wp.i18n 翻译方案）
            wp_enqueue_script(
                'yali-ai-writer-variable-guide-inline-js',
                YALI_AI_WRITER_PLUGIN_URL . 'variable-guide/assets/js/variable-guide-inline.js',
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_localize_script('yali-ai-writer-variable-guide-inline-js', 'variableGuideInline', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('yali_ai_writer_template_nonce'),
            ));
            wp_set_script_translations('yali-ai-writer-variable-guide-inline-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
        }

        // 发布规则主页面（无 action 参数时加载）
        if (strpos($hook, 'yali-ai-writer-publish-rules') !== false && !isset($_GET['action'])) {
            $js_path = YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/assets/js/publish-rules.js';
            $js_ver = file_exists($js_path) ? filemtime($js_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_script(
                'yali-ai-writer-publish-rules-js',
                YALI_AI_WRITER_PLUGIN_URL . 'publish-settings/assets/js/publish-rules.js',
                array(),
                $js_ver,
                true
            );

            $rules_css_path = YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/assets/css/publish-rules.css';
            $rules_css_ver = file_exists($rules_css_path) ? filemtime($rules_css_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_style(
                'yali-ai-writer-publish-rules-css',
                YALI_AI_WRITER_PLUGIN_URL . 'publish-settings/assets/css/publish-rules.css',
                array('yali-ai-ui-kit'),
                $rules_css_ver
            );
        }

        if (strpos($hook, 'yali-ai-writer-publish-rules') !== false && isset($_GET['action']) && $_GET['action'] === 'article-structures') {
            // 文章结构页面 (包含智能优化Tab)
            $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'structures';

            if ($current_tab === 'smart-optimization') {
                // 智能结构优化 Tab
                 wp_enqueue_style(
                    'yali-ai-writer-smart-optimization-css',
                    YALI_AI_WRITER_PLUGIN_URL . 'article-structures/assets/css/smart-optimization-settings.css',
                    array(),
                    YALI_AI_WRITER_VERSION
                );

                wp_enqueue_script(
                    'yali-ai-writer-smart-optimization-js',
                    YALI_AI_WRITER_PLUGIN_URL . "article-structures/assets/js/smart-optimization-settings{$suffix}.js",
                    array('jquery', 'wp-i18n', 'yali-actions-js'),
                    YALI_AI_WRITER_VERSION,
                    true
                );
                wp_set_script_translations('yali-ai-writer-smart-optimization-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

                wp_localize_script('yali-ai-writer-smart-optimization-js', 'smartOptimization', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('smart_optimization_nonce')
                ));
            } else {
                // 默认：结构管理 Tab
                wp_enqueue_style(
                    'yali-ai-writer-article-structures-css',
                    YALI_AI_WRITER_PLUGIN_URL . 'article-structures/assets/css/article-structure-management.css',
                    array(),
                    YALI_AI_WRITER_VERSION
                );

                wp_enqueue_script(
                    'yali-ai-writer-article-structures-js',
                    YALI_AI_WRITER_PLUGIN_URL . "article-structures/assets/js/article-structure-management{$suffix}.js",
                    array('jquery', 'jquery-ui-sortable', 'wp-i18n', 'yali-actions-js'),
                    YALI_AI_WRITER_VERSION,
                    true
                );
                wp_set_script_translations('yali-ai-writer-article-structures-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
                
                wp_localize_script('yali-ai-writer-article-structures-js', 'articleStructures', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('article_structures_nonce')
                ));
            }
        }
        
        // 编辑器AI助手配置页面
        if (strpos($hook, 'yali-ai-writer-publish-rules') !== false && isset($_GET['action']) && $_GET['action'] === 'editor-assistant-settings') {
            $css_path = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/assets/css/editor-assistant-settings.css';
            $css_ver = file_exists($css_path) ? filemtime($css_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_style(
                'yali-ai-writer-editor-assistant-settings-css',
                YALI_AI_WRITER_PLUGIN_URL . 'editor-assistant/assets/css/editor-assistant-settings.css',
                array('yali-ai-ui-kit'),
                $css_ver
            );

            $js_path = YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/assets/js/editor-assistant-settings.js';
            $js_ver = file_exists($js_path) ? filemtime($js_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_script(
                'yali-ai-writer-editor-assistant-settings-js',
                YALI_AI_WRITER_PLUGIN_URL . 'editor-assistant/assets/js/editor-assistant-settings.js',
                array('jquery', 'wp-i18n'),
                $js_ver,
                true
            );
            wp_set_script_translations('yali-ai-writer-editor-assistant-settings-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            wp_localize_script('yali-ai-writer-editor-assistant-settings-js', 'editorAssistantSettings', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('editor_assistant_settings_nonce')
            ));
        }

        // 关键词工具页面 - 使用更灵活的匹配方式
        if (strpos($hook, 'yali-ai-writer-keyword-tool') !== false) {
            // 关键词工具页面
            $css_path = YALI_AI_WRITER_PLUGIN_DIR . 'keyword-research-tool/assets/css/keyword-research.css';
            $css_ver = file_exists($css_path) ? filemtime($css_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_style(
                'keyword-research-tool-css',
                YALI_AI_WRITER_PLUGIN_URL . 'keyword-research-tool/assets/css/keyword-research.css',
                array(),
                $css_ver
            );
            
            $js_path = YALI_AI_WRITER_PLUGIN_DIR . "keyword-research-tool/assets/js/keyword-research{$suffix}.js";
            $js_ver = file_exists($js_path) ? filemtime($js_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_script(
                'keyword-research-tool-js',
                YALI_AI_WRITER_PLUGIN_URL . "keyword-research-tool/assets/js/keyword-research{$suffix}.js",
                array('jquery', 'wp-i18n'),
                $js_ver,
                true
            );
            wp_set_script_translations('keyword-research-tool-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            wp_localize_script('keyword-research-tool-js', 'keywordResearchToolData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('keyword_research_nonce')
            ));
        }

        // 搜索物料页面
        if (strpos($hook, 'content-auto-search-materials') !== false) {
            $js_path = YALI_AI_WRITER_PLUGIN_DIR . 'search-materials/assets/js/search-materials-inline.js';
            $js_ver = file_exists($js_path) ? filemtime($js_path) : YALI_AI_WRITER_VERSION;
            wp_enqueue_script(
                'yali-ai-writer-search-materials-js',
                YALI_AI_WRITER_PLUGIN_URL . 'search-materials/assets/js/search-materials-inline.js',
                array('jquery', 'wp-i18n'),
                $js_ver,
                true
            );
            wp_set_script_translations('yali-ai-writer-search-materials-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');

            wp_localize_script('yali-ai-writer-search-materials-js', 'searchMaterialsData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('yali_ai_writer_material_nonce')
            ));
        }

        if (strpos($hook, 'yali-ai-writer-brand-profiles') !== false) {
            // 品牌资料页面 - 样式
            wp_enqueue_style(
                'yali-brand-profiles-css',
                YALI_AI_WRITER_PLUGIN_URL . 'brand-profiles/assets/css/brand-profiles.css',
                array(),
                YALI_AI_WRITER_VERSION
            );
            
            // 品牌资料页面 - 脚本
            wp_enqueue_script(
                'yali-brand-profiles-js',
                YALI_AI_WRITER_PLUGIN_URL . "brand-profiles/assets/js/brand-profiles{$suffix}.js",
                array('jquery', 'wp-i18n'),
                YALI_AI_WRITER_VERSION,
                true
            );
            wp_set_script_translations('yali-brand-profiles-js', 'yali-ai-writer', YALI_AI_WRITER_PLUGIN_DIR . 'languages');
            
            // 本地化脚本数据
            wp_localize_script('yali-brand-profiles-js', 'brandProfilesManager', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('brand_profiles_nonce')
            ));
        }
        
        // 本地化脚本 - 保持原有变量名以兼容现有JS文件
        wp_localize_script('yali-ai-writer-admin-js', 'contentAutoManager', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('yali_ai_writer_manager_nonce')
        ));
    }
    
    /**
     * 渲染调试工具页面
     */
    public function render_debug_tools_page() {
        // 检查是否是特定的测试页面
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'debug-tools/views/debug-tools.php';
    }

    /**
     * 渲染搜索物料页面
     */
    public function render_search_materials_page() {
        if (!class_exists('Yali_AI_Writer_SearchMaterialsAdminPage')) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'search-materials/class-search-materials-admin-page.php';
        }
        $page = new Yali_AI_Writer_SearchMaterialsAdminPage();
        $page->render_page();
    }

    /**
     * 渲染变量说明页面
     */
    public function render_variable_guide_page() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'variable-guide/views/variable-guide.php';
    }
}