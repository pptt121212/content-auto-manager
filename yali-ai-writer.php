<?php
/**
 * Plugin Name: Yali AI Smart Article Writer (鸭梨AI文章智能写手)
 * Plugin URI: https://github.com/pptt121212/content-auto-manager
 * Description: An intelligent content generation plugin that helps WordPress administrators automatically generate high-quality articles. Supports multiple AI APIs, smart content strategies, and browser extension integration.
 * Version: 1.1.3
 * Author: 鸭梨AI
 * Author URI: https://www.yaliai.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yali-ai-writer
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('YALI_AI_WRITER_VERSION', '1.1.3');
define('YALI_AI_WRITER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YALI_AI_WRITER_PLUGIN_URL', plugin_dir_url(__FILE__));

// 定义每个子任务之间的处理间隔时间（秒）
define('YALI_AI_WRITER_SUBTASK_INTERVAL', 30);

// 定义单次 Cron 运行时处理的最大任务数量
define('YALI_AI_WRITER_MAX_JOBS_PER_RUN', 5);

// 调试模式将在插件初始化时根据数据库设置动态定义

// 包含助手文件
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/constants.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/functions.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';

// 编辑器助手组件将在 yali_ai_writer_manager_init 函数中统一加载

// 包含API渠道类
require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-api-channel.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-official-channel.php';

// 加载 Composer 自动加载器
if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'vendor/autoload.php';
}

// 自动加载类文件（确保在使用任何 Yali_AI_Writer_* 类之前注册）
spl_autoload_register('yali_ai_writer_manager_autoload');

// 注册AJAX动作
add_action('wp_ajax_yali_ai_writer_test_api_connection', 'yali_ai_writer_manager_test_api_connection');
add_action('wp_ajax_yali_ai_writer_test_predefined_api', 'yali_ai_writer_manager_test_predefined_api');
add_action('wp_ajax_yali_ai_writer_get_quota_info', 'yali_ai_writer_manager_get_quota_info');
add_action('wp_ajax_yali_ai_writer_search_articles', 'yali_ai_writer_manager_search_articles');
add_action('wp_ajax_yali_ai_writer_debug_tools', 'yali_ai_writer_manager_debug_tools');
add_action('wp_ajax_yali_ai_writer_get_task_status', 'yali_ai_writer_get_task_status');
add_action('wp_ajax_yali_ai_writer_pause_task', 'yali_ai_writer_pause_task');
add_action('wp_ajax_yali_ai_writer_resume_task', 'yali_ai_writer_resume_task');
add_action('wp_ajax_yali_ai_writer_delete_task', 'yali_ai_writer_delete_task');
add_action('wp_ajax_yali_ai_writer_delete_article_task', 'yali_ai_writer_delete_article_task');
add_action('wp_ajax_yali_ai_writer_get_task_progress', 'yali_ai_writer_get_task_progress');
add_action('wp_ajax_yali_ai_writer_get_article_task_details', 'yali_ai_writer_get_article_task_details');
add_action('wp_ajax_yali_ai_writer_retry_article_task', 'yali_ai_writer_retry_article_task');
add_action('wp_ajax_yali_ai_writer_retry_topic_task', 'yali_ai_writer_retry_topic_task');
add_action('wp_ajax_yali_ai_writer_bulk_retry_topic_tasks', 'yali_ai_writer_bulk_retry_topic_tasks');
add_action('wp_ajax_cam_modelscope_start_task', 'cam_modelscope_start_task_handler');
add_action('wp_ajax_cam_modelscope_check_task', 'cam_modelscope_check_task_handler');
add_action('wp_ajax_cam_test_image_api', 'cam_test_image_api_handler');
add_action('wp_ajax_cam_test_reference_recall', 'cam_test_reference_recall_handler');
add_action('wp_ajax_cam_fetch_pollinations_image_models', 'cam_fetch_pollinations_image_models_handler');
add_action('wp_ajax_yali_ai_writer_get_pollinations_account_info', 'yali_ai_writer_manager_get_pollinations_account_info');
add_action('wp_ajax_cam_save_language_setting', 'cam_save_language_setting_handler');

// 包含AJAX处理函数
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/ajax-handlers.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/pollinations-ajax-handler.php'; // Pollinations 模型同步
require_once YALI_AI_WRITER_PLUGIN_DIR . 'debug-tools/ajax-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/ajax-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/ajax-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/ajax-filter-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/ajax-manual-add-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/ajax-url-import-handler.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'admin/form-handlers.php'; // 表单处理（在 admin_init 中处理，支持 wp_safe_redirect）
require_once YALI_AI_WRITER_PLUGIN_DIR . 'deep-writing/class-deep-writing-handler.php'; // 深度写作处理器（注册 yali_ai_writer_deep_writing_initiated 监听和 REST 路由）
require_once YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/ajax-url-fetch-handler.php'; // 网址采集 AJAX 处理

// 引入外部访问统计功能
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/analytics/class-external-visit-tracker.php';
if (is_admin()) {
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/analytics/class-external-visit-admin.php';
    add_action('init', function() {
        global $yali_ai_writer_external_visit_tracker;
        if ($yali_ai_writer_external_visit_tracker) {
            new Yali_AI_Writer_ExternalVisitAdmin($yali_ai_writer_external_visit_tracker);
        }
    });
    
    // 加载搜索物料功能
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'search-materials/class-search-materials-admin-page.php';
    new Yali_AI_Writer_SearchMaterialsAdminPage();
}

// 仅在WP-CLI环境中加载命令文件
if (defined('WP_CLI') && WP_CLI) {
    // 检查文件是否存在再加载
    $wp_cli_commands_file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/cli/wp-cli-commands.php';
    if (file_exists($wp_cli_commands_file)) {
        require_once $wp_cli_commands_file;
    }
    
    // 加载测试命令
    $test_commands_file = YALI_AI_WRITER_PLUGIN_DIR . 'tests/test-commands.php';
    if (file_exists($test_commands_file)) {
        require_once $test_commands_file;
    }
    
    // 加载数据一致性命令
    $consistency_command_file = YALI_AI_WRITER_PLUGIN_DIR . 'shared/cli/commands/class-consistency-command.php';
    if (file_exists($consistency_command_file)) {
        require_once $consistency_command_file;
    }
}

function yali_ai_writer_manager_autoload($class_name) {
    // 只处理插件相关的类
    if (strpos($class_name, 'Yali_AI_Writer_') !== 0) {
        return;
    }
    
    // 从类名中移除插件前缀
    $file_name = str_replace('Yali_AI_Writer_', '', $class_name);
    
    // 将驼峰命名转换为中划线命名 (e.g., JobQueue -> job-queue, Consistency_Command -> consistency-command)
    $file_name = str_replace('_', '-', $file_name); // 先替换下划线为连字符
    $file_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $file_name)); // 再处理驼峰命名
    $file_name = preg_replace('/-+/', '-', $file_name); // 最后清理重复的连字符
    
    // 构建可能的文件路径
    $possible_paths = array(
        // API设置模块
        YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/params/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'api-settings/cli-adapters/class-' . $file_name . '.php',
        // 规则管理模块
        YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/params/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'rule-management/cli-adapters/class-' . $file_name . '.php',
        // 主题管理模块
        YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/params/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/cli-adapters/class-' . $file_name . '.php',
        // 文章任务模块
        YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/params/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/cli-adapters/class-' . $file_name . '.php',
        // 文章结构模块
        YALI_AI_WRITER_PLUGIN_DIR . 'article-structures/class-' . $file_name . '.php',
        // 提示词模板模块
        YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/class-' . $file_name . '.php',
        // 深度写作模块
        YALI_AI_WRITER_PLUGIN_DIR . 'deep-writing/class-' . $file_name . '.php',
        // 发布设置模块
        YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/class-' . $file_name . '.php',
        // shared目录
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/queue/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/admin/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/logging/class-' . $file_name . '.php',
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/cli/commands/class-' . $file_name . '.php'
    );
    
    // 查找存在的文件
    foreach ($possible_paths as $file_path) {
        if (file_exists($file_path)) {
            require_once $file_path;
            return;
        }
    }
}

// 包含数据一致性验证类
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-data-validator.php';
require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-database-wrapper.php';

// 插件激活钩子
register_activation_hook(__FILE__, 'yali_ai_writer_manager_activate');

function yali_ai_writer_manager_activate() {
    // 执行前缀迁移（如果存在旧数据）- 必须在创建新表之前执行
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-prefix-migration.php';
    if (Yali_AI_Writer_Prefix_Migration::needs_migration()) {
        Yali_AI_Writer_Prefix_Migration::migrate();
    }
    
    // 创建数据库表（仅当表不存在时）
    $database = new Yali_AI_Writer_Database();
    $database->create_tables();

    // 升级数据库结构
    yali_ai_writer_manager_upgrade_database();

    // 初始化缓存目录和权限
    yali_ai_writer_manager_init_cache_directories();

    // 添加默认选项
    add_option('yali_ai_writer_version', YALI_AI_WRITER_VERSION);
    

    // 注册智能结构优化定时任务
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-structure-optimization-scheduler.php';
    $scheduler = new Yali_AI_Writer_StructureOptimizationScheduler();
    $scheduler->register_cron_events();

    // --- 自动植入默认模板 ---
    // 逻辑已移至 Yali_AI_Writer_Database::seed_default_templates 进行统一管理
    // 避免在此处重复处理，防止逻辑冲突

}

/**
 * 升级数据库结构
 */
function yali_ai_writer_manager_upgrade_database() {
    global $wpdb;

    $publish_rules_table = $wpdb->prefix . 'yali_ai_writer_publish_rules';

    // 检查 role_description 字段是否存在
    $column_exists = $wpdb->get_var(
        "SHOW COLUMNS FROM $publish_rules_table LIKE 'role_description'"
    );

    // 如果字段不存在，则添加它
    if (!$column_exists) {
        $sql = "ALTER TABLE $publish_rules_table ADD COLUMN role_description text NOT NULL COMMENT 'AI角色描述，用于文章生成的提示词模板' AFTER publish_language";
        $wpdb->query($sql);

        // 为现有记录设置默认的角色描述
        $default_role_description = "专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。";
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $publish_rules_table SET role_description = %s WHERE role_description = '' OR role_description IS NULL",
                $default_role_description
            )
        );
    }
}

/**
 * 初始化缓存目录和权限
 */
function yali_ai_writer_manager_init_cache_directories() {
    $cache_dirs = array(
        YALI_AI_WRITER_PLUGIN_DIR . 'shared/cache/',
        YALI_AI_WRITER_PLUGIN_DIR . 'logs/'
    );
    
    foreach ($cache_dirs as $cache_dir) {
        // 创建目录
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // 设置目录权限
        if (file_exists($cache_dir)) {
            @chmod($cache_dir, 0755);
            
            // 自动生成 .htaccess 文件以提高安全性，防止直接读取日志或缓存文件
            $htaccess_file = $cache_dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "Deny from all";
                @file_put_contents($htaccess_file, $htaccess_content);
            }
        }
    }
}

// 插件停用钩子
register_deactivation_hook(__FILE__, 'yali_ai_writer_manager_deactivate');

function yali_ai_writer_manager_deactivate() {
    // 清理临时数据或选项
    // 注意：不要删除用户数据
    
    // 注销智能结构优化定时任务
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-structure-optimization-scheduler.php';
    $scheduler = new Yali_AI_Writer_StructureOptimizationScheduler();
    $scheduler->unregister_cron_events();
}


// 插件卸载钩子
register_uninstall_hook(__FILE__, 'yali_ai_writer_manager_uninstall');

function yali_ai_writer_manager_uninstall() {
    // 删除插件创建的所有数据和选项
    delete_option('yali_ai_writer_version');
    delete_option('cam_image_api_settings');  // 删除图像API设置
    delete_option('yali_ai_writer_allowed_categories');  // 删除分类过滤设置
    delete_option('yali_ai_writer_category_filter_enabled');  // 删除分类过滤启用状态
    delete_option('yali_ai_writer_license_key');  // 删除授权密钥
    delete_option('yali_ai_writer_license_data');  // 删除授权数据

    // 注意：谨慎删除数据库表，这会丢失所有用户数据
    // 如果需要删除表，请取消下面几行的注释
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_api_configs");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_rules");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_rule_items");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_topic_tasks");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_topics");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_article_tasks");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_articles");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_job_queue");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_publish_rules");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}yali_ai_writer_article_structures");
}

// 初始化插件
add_action('plugins_loaded', 'yali_ai_writer_manager_init');

// 检查数据库版本并升级
add_action('init', 'yali_ai_writer_manager_check_version');

function yali_ai_writer_manager_check_version() {
    $current_version = get_option('yali_ai_writer_version', '1.0.0');
    if (version_compare($current_version, YALI_AI_WRITER_VERSION, '<')) {
        yali_ai_writer_manager_upgrade_database();
        update_option('yali_ai_writer_version', YALI_AI_WRITER_VERSION);
    }
}
function yali_ai_writer_manager_init() {
    // 修复 Apache/FastCGI 环境下 Authorization 头丢失的问题
    if ( ! isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
        if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            $_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        } elseif ( isset( $_SERVER['HTTP_X_CAM_AUTH'] ) ) {
             $_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CAM_AUTH']));
        }
    }

    // 修复 PHP-FPM/FastCGI 不设置 PHP_AUTH_* 变量的问题
    $http_auth = isset($_SERVER['HTTP_AUTHORIZATION']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION'])) : '';
    if ( ! empty( $http_auth ) && 0 === strpos( $http_auth, 'Basic ' ) ) {
        $decoded = base64_decode( substr( $http_auth, 6 ) );
        if ( $decoded && strpos( $decoded, ':' ) !== false ) {
            list( $user, $pass ) = explode( ':', $decoded, 2 );
            $_SERVER['PHP_AUTH_USER'] = $user;
            $_SERVER['PHP_AUTH_PW']   = $pass;
        }
    }

    // --- 修复：浏览器插件 API Key 认证逻辑 ---
    // 允许通过 X-CAM-API-Key 头自动识别用户身份，无需 Cookie/Nonce
    add_filter('determine_current_user', function($user_id) {
        // 如果已经通过传统方式认证，直接返回
        if ($user_id) return $user_id;

        // 检查自定义认证头
        $api_key = '';
        if (isset($_SERVER['HTTP_X_CAM_API_KEY'])) {
            $api_key = $_SERVER['HTTP_X_CAM_API_KEY'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_X_CAM_API_KEY'])) {
            $api_key = $_SERVER['REDIRECT_HTTP_X_CAM_API_KEY'];
        }

        if (empty($api_key)) return $user_id;

        // 验证 API Key
        $stored_key = get_option('cam_extension_api_key', '');
        if (empty($stored_key) || !hash_equals($stored_key, $api_key)) {
            return $user_id;
        }

        // 验证通过，临时赋予管理员权限
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        if (!empty($admins)) {
            return $admins[0]->ID;
        }

        return $user_id;
    }, 20);

    // 对于使用了 API Key 的 REST 请求，放行认证错误（跳过 Nonce 检查）
    add_filter('rest_authentication_errors', function($result) {
        if (!empty($_SERVER['HTTP_X_CAM_API_KEY']) || !empty($_SERVER['REDIRECT_HTTP_X_CAM_API_KEY'])) {
            return true; // 强制通过认证
        }
        return $result;
    }, 100);

    // 允许自定义认证头通过 CORS
    add_filter( 'rest_allowed_cors_headers', function( $allow_headers ) {
        $allow_headers[] = 'X-Cam-Auth';
        $allow_headers[] = 'X-CAM-API-Key';
        return $allow_headers;
    });
    // --- 修复结束 ---
    // 允许插件内单独切换语言，如果未明确指定，非中文环境默认降级到英文
    add_filter('plugin_locale', function($locale, $domain) {
        if ($domain === 'yali-ai-writer') {
            $custom_locale = get_option('yali_ai_writer_locale', 'site_default');
            
            if ($custom_locale && $custom_locale !== 'site_default') {
                return $custom_locale;
            }

            // 如果跟随站点且站点非中文环境，强制回退到英文 UI
            if ($locale && strpos($locale, 'zh') !== 0) {
                return 'en_US';
            }
        }
        return $locale;
    }, 10, 2);

    // 强制指定MO文件路径（作为第二重保障）
    add_filter('load_textdomain_mofile', function($mofile, $domain) {
        if ($domain === 'yali-ai-writer') {
            $custom_locale = get_option('yali_ai_writer_locale', 'site_default');
            $target_locale = '';
            
            if ($custom_locale && $custom_locale !== 'site_default') {
                $target_locale = $custom_locale;
            } else {
                $current_locale = determine_locale();
                if ($current_locale && strpos($current_locale, 'zh') !== 0) {
                    $target_locale = 'en_US';
                }
            }
            
            if (!empty($target_locale)) {
                $custom_mofile = dirname(__FILE__) . '/languages/' . $domain . '-' . $target_locale . '.mo';
                if (file_exists($custom_mofile)) {
                    return $custom_mofile;
                }
            }
        }
        return $mofile;
    }, 10, 2);

    // 强制指定JED文件路径（JavaScript翻译，与MO文件保持一致）
    add_filter('load_script_translation_file', function($file, $handle, $domain) {
        if ($domain !== 'yali-ai-writer') {
            return $file;
        }
        
        // 如果 $file 为 null，直接返回
        if (empty($file)) {
            return $file;
        }
        
        $custom_locale = get_option('yali_ai_writer_locale', 'site_default');
        $site_locale = determine_locale();
        $target_locale = $site_locale;
        
        if ($custom_locale && $custom_locale !== 'site_default') {
            $target_locale = $custom_locale;
        } elseif ($site_locale && strpos($site_locale, 'zh') !== 0) {
            $target_locale = 'en_US'; // 非中文环境回退到英文 JS 翻译
        }
        
        // Replace site locale with custom/target locale in the file path
        $custom_file = str_replace(
            $domain . '-' . $site_locale . '-',
            $domain . '-' . $target_locale . '-',
            $file
        );
        
        if (file_exists($custom_file)) {
            return $custom_file;
        }
        
        // Fallback to en_US if target locale file doesn't exist
        if ($target_locale !== 'en_US') {
            $en_us_file = str_replace(
                $domain . '-' . $site_locale . '-',
                $domain . '-en_US-',
                $file
            );
            if (file_exists($en_us_file)) {
                return $en_us_file;
            }
        }
        
        return $file;
    }, 10, 3);

    // 加载文本域 - 优先使用WP标准加载
    load_plugin_textdomain('yali-ai-writer', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // 执行前缀迁移（如果存在旧数据）
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/database/class-prefix-migration.php';
    if (Yali_AI_Writer_Prefix_Migration::needs_migration()) {
        Yali_AI_Writer_Prefix_Migration::migrate();
    }
    
    // 初始化数据库
    $database = new Yali_AI_Writer_Database();
    
    // 检查是否需要更新数据库
    $installed_version = get_option('yali_ai_writer_version');
    if ($installed_version != YALI_AI_WRITER_VERSION) {
        $database->create_tables();
        update_option('yali_ai_writer_version', YALI_AI_WRITER_VERSION);
    }
    
    // 检查并设置调试模式
    $debug_mode = get_option('yali_ai_writer_debug_mode', false);
    if ($debug_mode && !defined('YALI_AI_WRITER_DEBUG_MODE')) {
        define('YALI_AI_WRITER_DEBUG_MODE', true);
    }
    
    // 初始化后台菜单
    $admin_menu = new Yali_AI_Writer_AdminMenu();
    
    // 初始化发布设置管理页面（包括分类过滤功能）
    if (is_admin()) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/class-publish-settings-admin.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/class-category-filter.php';
        
        // 加载扩展 API Key 管理页面
        $extension_admin_file = YALI_AI_WRITER_PLUGIN_DIR . 'admin/class-extension-api-key-admin.php';
        if (file_exists($extension_admin_file)) {
            require_once $extension_admin_file;
        }
        
        // 定期清理分类过滤设置
        add_action('wp_loaded', array('Yali_AI_Writer_Category_Filter', 'validate_and_clean_settings'));
        
        // Initialize Publish Rules Admin Page (Register AJAX & Scripts)
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'publish-settings/class-publish-rules-admin-page.php';
        Yali_AI_Writer_PublishRulesAdminPage::init();
    }


    
    // 初始化数据一致性验证系统
    $data_validator = new Yali_AI_Writer_DataValidator();
    $database_wrapper = new Yali_AI_Writer_DatabaseWrapper();
    
    // 初始化规则表单处理器
    new Yali_AI_Writer_RuleHandler();
    
    // 启动任务队列处理器
    add_action('init', 'yali_ai_writer_manager_start_queue_processor');

    // 加载向量聚类和文章结构相关服务 (仅在后台加载)
    // 注意：菜单注册已移至 Yali_AI_Writer_AdminMenu 类统一管理
    // 这里只需要确保类文件被加载，不需要实例化
    if (is_admin()) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'admin/class-clustering-admin-page.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-structures/class-article-structure-admin-page.php';
        // 这些类的实例化现在在需要时由 Yali_AI_Writer_AdminMenu 处理
    }
    // 2. 自动增量分配服务
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-incremental-clustering.php';
    new Yali_AI_Writer_IncrementalClustering();
    
    // 3. 初始化自动配图功能
    yali_ai_writer_init_auto_image_feature();
    
    // 4. 初始化智能结构优化定时任务钩子
    require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/services/class-structure-optimization-scheduler.php';
    Yali_AI_Writer_StructureOptimizationScheduler::init_hooks();

    // 5. 初始化 REST API
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/class-rest-api-manager.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/class-rest-api-manager.php';
        $rest_api_manager = new \ContentAutoManager\RestApi\Rest_Api_Manager();
        $rest_api_manager->init();
    }

    // 6. 初始化编辑器助手
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-prompt-manager.php';
    }
    // 加载图像生成提示词加载器
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-image-prompts-loader.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-image-prompts-loader.php';
    }
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-asset-loader.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-asset-loader.php';
        new Yali_AI_Writer_Editor_Asset_Loader();
    }
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-database-migration.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/class-database-migration.php';
        new Yali_AI_Writer_Editor_Database_Migration();
    }
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/gutenberg/index.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/gutenberg/index.php';
    }
}

/**
 * 初始化异步任务管理器（必须在 init Hook 中注册，确保 Action 系统可用）
 */
add_action('init', function() {
    // 5. 初始化自动素材搜索管理器
    if (file_exists(YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-material-search-manager.php')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-material-search-manager.php';
        new Yali_AI_Writer_MaterialSearchManager();
    }
}, 5); // 优先级 5，早于业务逻辑运行

function yali_ai_writer_manager_start_queue_processor() {
    // 启动后台任务处理
    if (!wp_next_scheduled('yali_ai_writer_manager_process_queue')) {
        wp_schedule_event(time(), 'every_minute', 'yali_ai_writer_manager_process_queue');
    }
    
    add_action('yali_ai_writer_manager_process_queue', 'yali_ai_writer_manager_process_queue');
    
    // 启动任务恢复处理器
    if (!wp_next_scheduled('yali_ai_writer_manager_recover_tasks')) {
        wp_schedule_event(time(), 'every_5_minutes', 'yali_ai_writer_manager_recover_tasks');
    }
    
    add_action('yali_ai_writer_manager_recover_tasks', 'yali_ai_writer_manager_recover_tasks');
    
    // 启动文章任务超时处理器
    if (!wp_next_scheduled('yali_ai_writer_manager_handle_article_timeouts')) {
        wp_schedule_event(time(), 'every_minute', 'yali_ai_writer_manager_handle_article_timeouts');
    }
    
    add_action('yali_ai_writer_manager_handle_article_timeouts', 'yali_ai_writer_manager_handle_article_timeouts');
    
    // 启动历史任务自动清理（每天执行一次）
    if (!wp_next_scheduled('yali_ai_writer_manager_cleanup_old_tasks')) {
        // 安排在每天 UTC 时间 02:00 执行（北京时间 10:00）
        $next_cleanup_time = strtotime('tomorrow 02:00:00 UTC');
        wp_schedule_event($next_cleanup_time, 'daily', 'yali_ai_writer_manager_cleanup_old_tasks');
    }
    
    add_action('yali_ai_writer_manager_cleanup_old_tasks', 'yali_ai_writer_manager_cleanup_old_tasks');
    
    // 注册shutdown函数处理致命错误
    register_shutdown_function('yali_ai_writer_manager_handle_fatal_error');
}

/**
 * 处理PHP致命错误
 * 当PHP脚本因致命错误终止时，自动将正在处理的任务标记为失败
 */
function yali_ai_writer_manager_handle_fatal_error() {
    $error = error_get_last();
    
    // 只处理致命错误
    if ($error !== null && in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE))) {
        // 获取当前正在处理的任务信息
        $current_job = get_option('yali_ai_writer_current_processing_job');
        
        if ($current_job && !empty($current_job['job_id'])) {
            global $wpdb;
            
            $table_name = $current_job['table_name'];
            $error_message = sprintf(
                'PHP致命错误导致任务中断: %s in %s on line %d',
                $error['message'],
                basename($error['file']),
                $error['line']
            );
            
            // 更新任务状态为失败
            $wpdb->update(
                $table_name,
                array(
                    'status' => YALI_AI_WRITER_STATUS_FAILED,
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $current_job['job_id'])
            );
            
            // 如果是文章任务，更新父任务状态
            if ($current_job['job_type'] === 'article' && !empty($current_job['task_id'])) {
                $article_tasks_table = $wpdb->prefix . 'yali_ai_writer_article_tasks';
                $job_queue_table = $wpdb->prefix . 'yali_ai_writer_job_queue';
                
                // 获取子任务统计
                $stats = $wpdb->get_row($wpdb->prepare(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                    FROM {$job_queue_table}
                    WHERE job_type = 'article' AND job_id = %d",
                    $current_job['task_id']
                ));
                
                if ($stats) {
                    $processed_count = intval($stats->completed) + intval($stats->failed);
                    $task = $wpdb->get_row($wpdb->prepare(
                        "SELECT total_topics FROM {$article_tasks_table} WHERE id = %d",
                        $current_job['task_id']
                    ));
                    
                    if ($task && $processed_count >= intval($task->total_topics)) {
                        $final_status = ($stats->failed > 0) ? 'failed' : 'completed';
                        $wpdb->update(
                            $article_tasks_table,
                            array(
                                'status' => $final_status,
                                'completed_topics' => $stats->completed,
                                'failed_topics' => $stats->failed,
                                'updated_at' => current_time('mysql')
                            ),
                            array('id' => $current_job['task_id'])
                        );
                    }
                }
            }
            
            // 清除锁和当前任务标记
            delete_transient('yali_ai_writer_global_task_lock');
            delete_transient('yali_ai_writer_global_subtask_lock');
            delete_option('yali_ai_writer_current_processing_job');
            
            // 记录错误日志
            error_log('[ContentAutoManager] Fatal error handled: ' . $error_message);
        }
    }
}

// 添加自定义时间间隔
add_filter('cron_schedules', 'yali_ai_writer_manager_add_cron_intervals');

function yali_ai_writer_manager_add_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => '每分钟'
    );
    
    $schedules['every_5_minutes'] = array(
        'interval' => 300,
        'display' => '每5分钟'
    );
    
    $schedules['weekly'] = array(
        'interval' => 604800, // 7 days in seconds
        'display' => '每周一次'
    );
    
    return $schedules;
}

/**
 * 自动清理历史任务
 * 每天执行一次，清理：
 * - 7天前已完成的任务 (completed)
 * - 30天前失败的任务 (failed)
 * 
 * 涉及的表：
 * - wp_yali_ai_writer_job_queue
 * - wp_yali_ai_writer_topic_tasks
 * - wp_yali_ai_writer_article_tasks
 */
function yali_ai_writer_manager_cleanup_old_tasks() {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'yali_ai_writer_job_queue',
        $wpdb->prefix . 'yali_ai_writer_topic_tasks',
        $wpdb->prefix . 'yali_ai_writer_article_tasks'
    );
    
    // 使用 current_time 保持与写入时间的一致性
    // current_time('mysql') 返回本地时间，与 updated_at 字段的写入方式一致
    $completed_threshold = date('Y-m-d H:i:s', current_time('timestamp') - (7 * DAY_IN_SECONDS));
    $failed_threshold = date('Y-m-d H:i:s', current_time('timestamp') - (30 * DAY_IN_SECONDS));
    
    $total_deleted = 0;
    $cleanup_log = array();
    
    foreach ($tables as $table) {
        // 检查表是否存在
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            continue;
        }
        
        // 清理7天前已完成的任务
        $deleted_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND updated_at < %s",
            'completed',
            $completed_threshold
        ));
        
        // 清理30天前失败的任务
        $deleted_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = %s AND updated_at < %s",
            'failed',
            $failed_threshold
        ));
        
        $table_name = str_replace($wpdb->prefix, '', $table);
        $cleanup_log[] = sprintf(
            '%s: completed=%d, failed=%d',
            $table_name,
            $deleted_completed !== false ? $deleted_completed : 0,
            $deleted_failed !== false ? $deleted_failed : 0
        );
        
        $total_deleted += ($deleted_completed !== false ? $deleted_completed : 0);
        $total_deleted += ($deleted_failed !== false ? $deleted_failed : 0);
    }
    
    // 记录清理日志
    if ($total_deleted > 0) {
        error_log(sprintf(
            '[ContentAutoManager] Auto cleanup completed: Total deleted=%d (%s)',
            $total_deleted,
            implode('; ', $cleanup_log)
        ));
    }
    
    return $total_deleted;
}

// 任务队列处理函数
function yali_ai_writer_manager_process_queue() {
    // -------------------------------------------------------------------------
    // 新增：自动修复 "定时发布失败" (Missed Schedule) 的文章
    // 在高负载下（如大量素材搜索任务运行时），WordPress原生Cron容易错过发布时间点
    // 此处每分钟强制检查并发布已过期的文章
    global $wpdb;
    $now = current_time('mysql');
    
    // 查找已过期但仍为 future 状态的文章 (每次处理5篇，避免阻塞)
    $missed_post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date <= %s LIMIT 5",
        $now
    ));
    
    if (!empty($missed_post_ids)) {
        foreach ($missed_post_ids as $p_id) {
            wp_publish_post($p_id);
            error_log("[ContentAutoManager] Fixed Missed Schedule for Post ID: {$p_id}");
        }
    }
    // -------------------------------------------------------------------------

    $queue = new Yali_AI_Writer_JobQueue();
    // 首先尝试处理队列中的任务（包括文章任务和主题任务）
    $result = $queue->process_next_job();
    
    // 如果队列中没有任务，再尝试直接处理主题任务
    if (!$result) {
        $queue->process_simple_topic_task();
    }
    
    // 尝试启动向量生成调度器（仅在系统空闲时运行）
    $queue->start_vector_generation_scheduler();
}

// 任务恢复处理函数
function yali_ai_writer_manager_recover_tasks() {
    // 恢复主题任务
    if (class_exists('Yali_AI_Writer_TopicTaskManager')) {
        $task_manager = new Yali_AI_Writer_TopicTaskManager();
        $task_manager->auto_recover_hanging_tasks(); // topic_task
    }
    
    // 恢复素材搜索任务
    if (!class_exists('Yali_AI_Writer_TaskRecoveryHandler')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'topic-management/class-task-recovery-handler.php';
    }
    if (class_exists('Yali_AI_Writer_TaskRecoveryHandler')) {
        $recovery_handler = new Yali_AI_Writer_TaskRecoveryHandler();
        $recovery_handler->auto_recover_hanging_tasks('material_search');
    }
}

// 文章任务超时处理函数
function yali_ai_writer_manager_handle_article_timeouts() {
    // 确保文章任务超时处理器类已加载
    if (!class_exists('Yali_AI_Writer_ArticleTaskTimeoutHandler')) {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'article-tasks/class-article-task-timeout-handler.php';
    }
    
    if (class_exists('Yali_AI_Writer_ArticleTaskTimeoutHandler')) {
        $timeout_handler = new Yali_AI_Writer_ArticleTaskTimeoutHandler();
        $result = $timeout_handler->handle_timeout_tasks();
        
        // 记录处理结果到日志
        if ($result['total_found'] > 0) {
            $logger = new Yali_AI_Writer_PluginLogger();
            $logger->info("文章任务超时处理完成", $result);
        }
    }
}

/**
 * 初始化自动配图功能
 */
function yali_ai_writer_init_auto_image_feature() {
    // 检查图像API模块是否存在
    if (!class_exists('Yali_AI_Writer_Image_API_Handler')) {
        return; // 图像API模块不可用，跳过自动配图功能
    }
    
    // 加载自动配图集成模块
    $auto_image_integration = YALI_AI_WRITER_PLUGIN_DIR . 'image-tasks/auto-image-integration.php';
    
    if (file_exists($auto_image_integration)) {
        require_once $auto_image_integration;
    } else {
        // 记录模块文件不存在
        error_log('ContentAuto: 自动配图集成文件不存在: ' . $auto_image_integration);
    }
}


// 添加管理列
add_filter('manage_edit-post_columns', 'yali_ai_writer_manager_add_post_columns');

function yali_ai_writer_manager_add_post_columns($columns) {
    $columns['yali_ai_writer_manager'] = '自动生成';
    return $columns;
}

// 显示管理列内容
add_action('manage_post_posts_custom_column', 'yali_ai_writer_manager_post_column_content', 10, 2);

function yali_ai_writer_manager_post_column_content($column_name, $post_id) {
    if ($column_name == 'yali_ai_writer_manager') {
        echo '<span class="dashicons dashicons-admin-generic"></span>';
    }
}

/**
 * 启用调试模式
 */
function yali_ai_writer_enable_debug_mode() {
    update_option('yali_ai_writer_debug_mode', true);
}

/**
 * 禁用调试模式
 */
function yali_ai_writer_disable_debug_mode() {
    update_option('yali_ai_writer_debug_mode', false);
}

/**
 * 检查调试模式是否启用
 */
function yali_ai_writer_is_debug_mode() {
    return get_option('yali_ai_writer_debug_mode', false);
}

/**
 * ------------------------------------------------------------------------
 * 自动更新检查与活跃统计模块
 * ------------------------------------------------------------------------
 */

// 初始化后台缓存预热机制，提升管理界面响应速度
add_action('admin_init', 'yali_ai_writer_cache_warmup_init');
function yali_ai_writer_cache_warmup_init() {
    if (false !== get_transient('cam_cache_warmup_status')) {
        return;
    }
    set_transient('cam_cache_warmup_status', 'init', DAY_IN_SECONDS);
    if (!wp_next_scheduled('cam_async_cache_refresh')) {
        wp_schedule_single_event(time() + 5, 'cam_async_cache_refresh');
    }
}

add_action('cam_async_cache_refresh', 'yali_ai_writer_cache_refresh_handler');
// Cache warmup status is maintained for legacy compatibility but the remote call is removed for compliance.
function yali_ai_writer_cache_refresh_handler() {
    set_transient('cam_cache_warmup_status', 'done', DAY_IN_SECONDS);
}

// 加载依赖库 (CommonMark 已内置，不再需要 Dependency Manager)
require_once YALI_AI_WRITER_PLUGIN_DIR . 'gsc-auth/init.php';



/**
 * 函数别名 - 用于兼容旧的计划任务
 * 这些别名确保在函数名重命名后，已计划的cron任务仍能正常工作
 */
if (!function_exists('yali_ai_writer_start_queue_processor')) {
    function yali_ai_writer_start_queue_processor() {
        return yali_ai_writer_manager_start_queue_processor();
    }
}
