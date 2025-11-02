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

        // 确保文章结构和向量聚类的AJAX处理器被注册
        if (is_admin()) {
            $this->register_ajax_handlers();
        }
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

        // 如果需要，也可以在这里注册向量聚类的AJAX处理器
        // 目前向量聚类页面主要使用表单提交，不需要额外的AJAX处理器

        // 注册关键词研究工具的AJAX处理器
        if (file_exists(CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'keyword-research-tool/ajax-handler.php';
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
            __('Content Automation', 'content-auto-manager'),
            __('Content Automation', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager',
            array($this, 'render_dashboard_page'),
            'dashicons-admin-generic',
            30
        );
        
        // 子菜单
        add_submenu_page(
            'content-auto-manager',
            __('仪表盘', 'content-auto-manager'),
            __('仪表盘', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager',
            array($this, 'render_dashboard_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('API设置', 'content-auto-manager'),
            __('API设置', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-api',
            array($this, 'render_api_config_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('图像API', 'content-auto-manager'),
            __('图像API', 'content-auto-manager'),
            'manage_options',
            'cam-image-api-settings',
            array($this, 'render_image_api_page')
        );

        // 关键词工具页面
        add_submenu_page(
            'content-auto-manager',
            __('Keyword Tool', 'content-auto-manager'),
            __('Keyword Tool', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-keyword-tool',
            array($this, 'render_keyword_tool_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('规则管理', 'content-auto-manager'),
            __('规则管理', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-rules',
            array($this, 'render_rules_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('主题任务', 'content-auto-manager'),
            __('主题任务', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-topic-jobs',
            array($this, 'render_topic_jobs_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('主题管理', 'content-auto-manager'),
            __('主题管理', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-topics',
            array($this, 'render_topics_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('文章任务', 'content-auto-manager'),
            __('文章任务', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-article-tasks',
            array($this, 'render_article_tasks_page')
        );
        
        add_submenu_page(
            'content-auto-manager',
            __('发布规则', 'content-auto-manager'),
            __('发布规则', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-publish-rules',
            array($this, 'render_publish_rules_page')
        );
        
        // 向量聚类页面
        add_submenu_page(
            'content-auto-manager',
            __('向量聚类', 'content-auto-manager'),
            __('向量聚类', 'content-auto-manager'),
            'manage_options',
            'content-auto-vector-clustering',
            array($this, 'render_vector_clustering_page')
        );

        // 文章结构页面
        add_submenu_page(
            'content-auto-manager',
            __('文章结构', 'content-auto-manager'),
            __('文章结构', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-article-structures',
            array($this, 'render_article_structures_page')
        );

        // 品牌资料页面
        add_submenu_page(
            'content-auto-manager',
            __('品牌资料', 'content-auto-manager'),
            __('品牌资料', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-brand-profiles',
            array($this, 'render_brand_profiles_page')
        );

        // 调试工具页面
        add_submenu_page(
            'content-auto-manager',
            __('调试工具', 'content-auto-manager'),
            __('调试工具', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-debug-tools',
            array($this, 'render_debug_tools_page')
        );

        // 变量说明页面
        add_submenu_page(
            'content-auto-manager',
            __('变量说明', 'content-auto-manager'),
            __('变量说明', 'content-auto-manager'),
            'manage_options',
            'content-auto-manager-variable-guide',
            array($this, 'render_variable_guide_page')
        );

        $this->override_menu_titles();

          
          
  
    }

    /**
     * 动态改写菜单标题，以实现稳定的钩子和中文显示
     */
    private function override_menu_titles() {
        global $menu, $submenu;

        // 改写主菜单标题
        foreach ($menu as $key => $item) {
            if ($item[2] == 'content-auto-manager') {
                $menu[$key][0] = __('内容自动生成', 'content-auto-manager');
                break;
            }
        }

        // 改写子菜单标题
        if (isset($submenu['content-auto-manager'])) {
            foreach ($submenu['content-auto-manager'] as $key => $item) {
                if ($item[2] == 'content-auto-manager-keyword-tool') {
                    $submenu['content-auto-manager'][$key][0] = __('关键词工具', 'content-auto-manager');
                    break;
                }
            }
        }
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
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/views/publish-rules.php';
    }

    /**
     * 渲染向量聚类页面
     */
    public function render_vector_clustering_page() {
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('ContentAuto_ClusteringAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'admin/class-clustering-admin-page.php';
        }
        $clustering_page = new ContentAuto_ClusteringAdminPage();
        $clustering_page->render_page();
    }

    /**
     * 渲染文章结构页面
     */
    public function render_article_structures_page() {
        // 检查类是否存在，如果不存在则先加载
        if (!class_exists('ContentAuto_ArticleStructureAdminPage')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-article-structure-admin-page.php';
        }

        // 获取已经实例化的页面对象
        $article_structures_page = new ContentAuto_ArticleStructureAdminPage();
        $article_structures_page->render_page();
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
        // 只在插件页面加载
        if (strpos($hook, 'content-auto-manager') === false && $hook !== '%e5%86%85%e5%ae%b9%e8%87%aa%e5%8a%a8%e7%94%9f%e6%88%90_page_content-auto-manager-logs') {
            return;
        }
        
        // 首先，加载所有后台页面都需要的通用脚本和样式
        wp_enqueue_style(
            'content-auto-manager-admin-css',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/css/admin.css',
            array(),
            CONTENT_AUTO_MANAGER_VERSION
        );
        
        wp_enqueue_script(
            'content-auto-manager-admin-js',
            CONTENT_AUTO_MANAGER_PLUGIN_URL . 'shared/assets/js/admin.js',
            array('jquery'),
            CONTENT_AUTO_MANAGER_VERSION,
            true
        );

        // 其次，根据特定页面加载其独有的脚本和样式
        if ($hook == 'toplevel_page_content-auto-manager') {
            // 仪表盘页面（顶级菜单）
        } 
        
        if (strpos($hook, 'content-auto-manager-api') !== false) {
            // API设置页面
        }
        
        if (strpos($hook, 'content-auto-manager-rules') !== false) {
            // 规则管理页面
            wp_enqueue_style(
                'content-auto-manager-rules-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'rule-management/assets/css/rule-management.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'content-auto-manager-rules-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'rule-management/assets/js/rule-management.js',
                array('jquery'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
        }
        
        if (strpos($hook, 'content-auto-manager-topic-jobs') !== false || strpos($hook, 'content-auto-manager-topics') !== false) {
            // 主题任务和主题管理页面
            wp_enqueue_style(
                'content-auto-manager-topic-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'topic-management/assets/css/topic-management.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
        }
        
        if (strpos($hook, 'content-auto-manager-article-tasks') !== false) {
            // 文章任务页面
            wp_enqueue_style(
                'content-auto-manager-article-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'article-tasks/assets/css/article-tasks.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
        }
        
        if (strpos($hook, 'content-auto-manager-debug-tools') !== false) {
            // 调试工具页面
            wp_enqueue_style(
                'content-auto-manager-debug-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'debug-tools/assets/css/debug-tools.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'content-auto-manager-debug-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'debug-tools/assets/js/debug-tools.js',
                array('jquery'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
        }

        if (strpos($hook, 'content-auto-manager-variable-guide') !== false) {
            // 变量说明页面
            wp_enqueue_style(
                'content-auto-manager-variable-guide-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'variable-guide/assets/css/variable-guide.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );

            wp_enqueue_script(
                'content-auto-manager-variable-guide-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'variable-guide/assets/js/variable-guide.js',
                array('jquery'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );
        }

        // 关键词工具页面 - 使用更灵活的匹配方式
        if (strpos($hook, 'content-auto-manager-keyword-tool') !== false) {
            // 关键词工具页面
            wp_enqueue_style(
                'keyword-research-tool-css',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'keyword-research-tool/assets/css/keyword-research.css',
                array(),
                CONTENT_AUTO_MANAGER_VERSION
            );
            
            wp_enqueue_script(
                'keyword-research-tool-js',
                CONTENT_AUTO_MANAGER_PLUGIN_URL . 'keyword-research-tool/assets/js/keyword-research.js',
                array('jquery'),
                CONTENT_AUTO_MANAGER_VERSION,
                true
            );

            wp_localize_script('keyword-research-tool-js', 'keywordResearchToolData', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('keyword_research_nonce')
            ));
        }
        
        // 本地化脚本
        wp_localize_script('content-auto-manager-admin-js', 'contentAutoManager', array(
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
     * 渲染变量说明页面
     */
    public function render_variable_guide_page() {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'variable-guide/views/variable-guide.php';
    }
    

  }