<?php
/**
 * Dependency Manager for Content Auto Manager
 * 
 * Handles remote download and installation of external libraries.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Content_Auto_Dependency_Manager {
    
    const REMOTE_URL = 'https://key.kdjingpai.com/deps/commonmark.zip';
    const RELATIVE_TARGET_DIR = 'shared/lib/commonmark';
    const VERIFY_FILE = 'autoload.php';

    public function __construct() {
        add_action('admin_notices', array($this, 'show_admin_notice'));
        add_action('wp_ajax_cam_install_dependency', array($this, 'ajax_install_dependency'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function is_commonmark_installed() {
        $target_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . self::RELATIVE_TARGET_DIR . '/' . self::VERIFY_FILE;
        return file_exists($target_file);
    }

    /**
     * 检查当前是否在插件页面
     */
    private function is_plugin_page() {
        if (!is_admin()) {
            return false;
        }

        // 获取当前页面参数
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // 插件页面标识符列表（必须与 add_menu_page/add_submenu_page 注册时的 slug 完全一致）
        $plugin_pages = array(
            'yali-ai-writer',                    // 主菜单/仪表盘
            'yali-ai-writer-api',                // API设置
            'cam-image-api-settings',            // 图像API
            'yali-ai-writer-keyword-tool',       // 关键词工具
            'yali-ai-writer-rules',              // 规则管理
            'yali-ai-writer-topic-jobs',         // 主题任务
            'yali-ai-writer-topics',             // 主题管理
            'yali-ai-writer-article-tasks',      // 文章任务
            'yali-ai-writer-publish-rules',      // 发布规则
            'yali-ai-writer-variable-guide',     // 提示词模板/变量指南
            'content-auto-search-materials',     // 搜索物料
            'yali-ai-writer-brand-profiles',     // 品牌资料
            'yali-ai-writer-debug-tools',        // 调试工具
            'cam-extension-api-key',             // 浏览器扩展连接
        );

        // 检查是否在插件页面
        if (in_array($page, $plugin_pages, true)) {
            return true;
        }

        // 检查是否在插件的POST类型页面
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen) {
            $post_types = array('yali_task', 'yali_topic'); // 插件自定义的post type
            if (in_array($screen->post_type, $post_types, true)) {
                return true;
            }
        }

        return false;
    }

    public function enqueue_assets($hook) {
        if ($this->is_commonmark_installed()) {
            return;
        }

        // 只在插件页面加载资源
        if (!$this->is_plugin_page()) {
            return;
        }

        // 加载项目统一的 UI 样式
        wp_enqueue_style(
            'yali-ui-kit',
            plugins_url('shared/assets/css/yali-ui-kit.css', CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'yali-ai-writer.php'),
            array(),
            CONTENT_AUTO_MANAGER_VERSION
        );

        wp_enqueue_script(
            'cam-dependency-installer',
            plugins_url('assets/js/dependency-installer.js', __FILE__),
            array('jquery'),
            CONTENT_AUTO_MANAGER_VERSION,
            true
        );
        
        wp_localize_script('cam-dependency-installer', 'cam_dependency_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cam_install_dependency_nonce'),
            'installing_text' => __('正在安装 CommonMark 库，请稍候...', 'yali-ai-writer'),
            'success_text' => __('安装成功！页面即将刷新...', 'yali-ai-writer'),
            'error_text' => __('安装失败，请重试或查看日志。', 'yali-ai-writer')
        ));
    }

    public function show_admin_notice() {
        if ($this->is_commonmark_installed()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // 只在插件页面显示，确保样式正确
        if (!$this->is_plugin_page()) {
            return;
        }

        ?>
        <div class="yali-notice yali-notice-warning is-dismissible cam-dependency-notice">
            <button type="button" class="notice-dismiss" onclick="this.parentElement.style.display='none';"><span class="screen-reader-text"><?php _e('Dismiss this notice', 'yali-ai-writer'); ?></span></button>
            <p style="margin-bottom: 12px;">
                <strong><?php _e('鸭梨AI文章智能写手 提示：', 'yali-ai-writer'); ?></strong>
                <?php _e('检测到高级 Markdown 解析库 (CommonMark) 未安装。插件目前使用基础解析模式 (Parsedown)，不支持部分高级语法。', 'yali-ai-writer'); ?>
            </p>
            <p style="margin-bottom: 0;">
                <button type="button" class="yali-btn yali-btn-primary" id="cam-install-dependency-btn">
                    <?php _e('点击下载并安装 CommonMark 库', 'yali-ai-writer'); ?>
                </button>
                <span class="spinner" style="float: none; margin-top: 0;"></span>
                <span id="cam-install-status" style="margin-left: 10px;"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_install_dependency() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('权限不足', 'yali-ai-writer')));
        }

        check_ajax_referer('cam_install_dependency_nonce', 'nonce');

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            wp_send_json_error(array('message' => __('无法初始化文件系统', 'yali-ai-writer')));
        }

        $target_dir_full = CONTENT_AUTO_MANAGER_PLUGIN_DIR . self::RELATIVE_TARGET_DIR;
        $parent_dir = dirname($target_dir_full);
        
        if (!wp_is_writable($parent_dir)) {
            $manual_msg = sprintf(
                /* translators: %s: Download URL, %s: Target directory path */
                __('插件目录不可写。<br>请手动操作：<br>1. <a href="%s" target="_blank">点击下载 commonmark.zip</a><br>2. 解压后上传到：<br><code>%s</code>', 'yali-ai-writer'),
                esc_url(self::REMOTE_URL),
                esc_html($target_dir_full)
            );
            wp_send_json_error(array('message' => $manual_msg));
        }

        $temp_file = download_url(self::REMOTE_URL);

        if (is_wp_error($temp_file)) {
            wp_send_json_error(array('message' => __('下载失败：', 'yali-ai-writer') . $temp_file->get_error_message()));
        }

        $target_dir = CONTENT_AUTO_MANAGER_PLUGIN_DIR . self::RELATIVE_TARGET_DIR;

        if (!$wp_filesystem->is_dir($parent_dir)) {
            $wp_filesystem->mkdir($parent_dir, FS_CHMOD_DIR);
        }

        if ($wp_filesystem->is_dir($target_dir)) {
            $wp_filesystem->delete($target_dir, true);
        }

        $temp_unzip_dir = $parent_dir . '/temp_commonmark_' . uniqid();
        $wp_filesystem->mkdir($temp_unzip_dir, FS_CHMOD_DIR);

        $unzip_result = unzip_file($temp_file, $temp_unzip_dir);
        $wp_filesystem->delete($temp_file);

        if (is_wp_error($unzip_result)) {
            $wp_filesystem->delete($temp_unzip_dir, true);
            wp_send_json_error(array('message' => __('解压失败：', 'yali-ai-writer') . $unzip_result->get_error_message()));
        }

        $files = $wp_filesystem->dirlist($temp_unzip_dir);
        $source_move_path = '';
        
        if ($wp_filesystem->exists($temp_unzip_dir . '/' . self::VERIFY_FILE)) {
            $source_move_path = $temp_unzip_dir;
        } elseif ($wp_filesystem->exists($temp_unzip_dir . '/commonmark/' . self::VERIFY_FILE)) {
            $source_move_path = $temp_unzip_dir . '/commonmark';
        } else {
            foreach ($files as $file) {
                if ($file['type'] === 'd' && $wp_filesystem->exists($temp_unzip_dir . '/' . $file['name'] . '/' . self::VERIFY_FILE)) {
                    $source_move_path = $temp_unzip_dir . '/' . $file['name'];
                    break;
                }
            }
        }

        if (empty($source_move_path)) {
            $wp_filesystem->delete($temp_unzip_dir, true);
            wp_send_json_error(array('message' => __('解压包结构错误', 'yali-ai-writer')));
        }

        $wp_filesystem->move($source_move_path, $target_dir, true);
        $wp_filesystem->delete($temp_unzip_dir, true);

        if ($this->is_commonmark_installed()) {
            if (class_exists('ContentAuto_MarkdownConverter')) {
                ContentAuto_MarkdownConverter::clear_cache();
            }
            wp_send_json_success(array('message' => __('CommonMark 库安装成功！', 'yali-ai-writer')));
        } else {
            wp_send_json_error(array('message' => __('安装验证失败', 'yali-ai-writer')));
        }
    }
}
