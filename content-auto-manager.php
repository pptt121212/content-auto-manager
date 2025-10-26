<?php
/**
 * Plugin Name: 内容自动生成管家
 * Plugin URI: https://example.com/content-auto-manager
 * Description: 一款智能内容生成插件，帮助WordPress管理员自动生成高质量中文文章。
 * Version: 1.0.2
 * Author: AI TOOL
 * Author URI: https://www.kdjingpai.com/
 * License: Custom Non-Commercial License
 * License URI: https://example.com/license
 * Text Domain: content-auto-manager
 * 
 * 此插件仅供个人和非商业用途使用。
 * 严禁将此插件或其任何部分用于商业目的。
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('CONTENT_AUTO_MANAGER_VERSION', '1.0.2');
define('CONTENT_AUTO_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CONTENT_AUTO_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

// 定义每个子任务之间的处理间隔时间（秒）
define('CONTENT_AUTO_SUBTASK_INTERVAL', 30);

// 定义单次 Cron 运行时处理的最大任务数量
define('CONTENT_AUTO_MAX_JOBS_PER_RUN', 5);

// 调试模式将在插件初始化时根据数据库设置动态定义

// 包含助手文件
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/constants.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/common/functions.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'includes/class-license-manager.php';

// 自动加载类文件（确保在使用任何 ContentAuto_* 类之前注册）
spl_autoload_register('content_auto_manager_autoload');

// 注册AJAX动作
add_action('wp_ajax_content_auto_test_api_connection', 'content_auto_manager_test_api_connection');
add_action('wp_ajax_content_auto_test_predefined_api', 'content_auto_manager_test_predefined_api');
add_action('wp_ajax_content_auto_search_articles', 'content_auto_manager_search_articles');
add_action('wp_ajax_content_auto_debug_tools', 'content_auto_manager_debug_tools');
add_action('wp_ajax_content_auto_get_task_status', 'content_auto_get_task_status');
add_action('wp_ajax_content_auto_pause_task', 'content_auto_pause_task');
add_action('wp_ajax_content_auto_resume_task', 'content_auto_resume_task');
add_action('wp_ajax_content_auto_delete_task', 'content_auto_delete_task');
add_action('wp_ajax_content_auto_get_task_progress', 'content_auto_get_task_progress');
add_action('wp_ajax_content_auto_get_article_task_details', 'content_auto_get_article_task_details');
add_action('wp_ajax_content_auto_retry_article_task', 'content_auto_retry_article_task');
add_action('wp_ajax_content_auto_retry_topic_task', 'content_auto_retry_topic_task');
add_action('wp_ajax_content_auto_bulk_retry_topic_tasks', 'content_auto_bulk_retry_topic_tasks');
add_action('wp_ajax_cam_modelscope_start_task', 'cam_modelscope_start_task_handler');
add_action('wp_ajax_cam_modelscope_check_task', 'cam_modelscope_check_task_handler');
add_action('wp_ajax_cam_test_image_api', 'cam_test_image_api_handler');

// 包含AJAX处理函数
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/ajax-handlers.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'debug-tools/ajax-handler.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-api-settings/ajax-handler.php';

// 引入外部访问统计功能
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/analytics/class-external-visit-tracker.php';
if (is_admin()) {
    require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/analytics/class-external-visit-admin.php';
    add_action('init', function() {
        global $content_auto_external_visit_tracker;
        if ($content_auto_external_visit_tracker) {
            new ContentAuto_ExternalVisitAdmin($content_auto_external_visit_tracker);
        }
    });
}

// 仅在WP-CLI环境中加载命令文件
if (defined('WP_CLI') && WP_CLI) {
    // 检查文件是否存在再加载
    $wp_cli_commands_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cli/wp-cli-commands.php';
    if (file_exists($wp_cli_commands_file)) {
        require_once $wp_cli_commands_file;
    }
    
    // 加载测试命令
    $test_commands_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'tests/test-commands.php';
    if (file_exists($test_commands_file)) {
        require_once $test_commands_file;
    }
    
    // 加载数据一致性命令
    $consistency_command_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cli/commands/class-consistency-command.php';
    if (file_exists($consistency_command_file)) {
        require_once $consistency_command_file;
    }
}

function content_auto_manager_autoload($class_name) {
    // 只处理插件相关的类
    if (strpos($class_name, 'ContentAuto_') !== 0) {
        return;
    }
    
    // 从类名中移除插件前缀
    $file_name = str_replace('ContentAuto_', '', $class_name);
    
    // 将驼峰命名转换为中划线命名 (e.g., JobQueue -> job-queue, Consistency_Command -> consistency-command)
    $file_name = str_replace('_', '-', $file_name); // 先替换下划线为连字符
    $file_name = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $file_name)); // 再处理驼峰命名
    $file_name = preg_replace('/-+/', '-', $file_name); // 最后清理重复的连字符
    
    // 构建可能的文件路径
    $possible_paths = array(
        // API设置模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/params/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/cli-adapters/class-' . $file_name . '.php',
        // 规则管理模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/params/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rule-management/cli-adapters/class-' . $file_name . '.php',
        // 主题管理模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/params/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'topic-management/cli-adapters/class-' . $file_name . '.php',
        // 文章任务模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/params/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/cli-adapters/class-' . $file_name . '.php',
        // 文章结构模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-' . $file_name . '.php',
        // 提示词模板模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/class-' . $file_name . '.php',
        // 发布设置模块
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/class-' . $file_name . '.php',
        // shared目录
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/database/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/queue/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/admin/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-' . $file_name . '.php',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cli/commands/class-' . $file_name . '.php'
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
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-data-validator.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/database/class-database-wrapper.php';

// 插件激活钩子
register_activation_hook(__FILE__, 'content_auto_manager_activate');

function content_auto_manager_activate() {
    // 创建数据库表
    $database = new ContentAuto_Database();
    $database->create_tables();

    // 升级数据库结构
    content_auto_manager_upgrade_database();

    // 初始化缓存目录和权限
    content_auto_manager_init_cache_directories();

    // 添加默认选项
    add_option('content_auto_manager_version', CONTENT_AUTO_MANAGER_VERSION);
}

/**
 * 升级数据库结构
 */
function content_auto_manager_upgrade_database() {
    global $wpdb;

    $publish_rules_table = $wpdb->prefix . 'content_auto_publish_rules';

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
function content_auto_manager_init_cache_directories() {
    $cache_dirs = array(
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cache/',
        CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'logs/'
    );
    
    foreach ($cache_dirs as $cache_dir) {
        // 创建目录
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        
        // 设置目录权限
        if (file_exists($cache_dir)) {
            @chmod($cache_dir, 0755);
            
            // 创建 .htaccess 文件保护缓存目录
            $htaccess_file = $cache_dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "# Protect cache directory\n";
                $htaccess_content .= "Order deny,allow\n";
                $htaccess_content .= "Deny from all\n";
                $htaccess_content .= "<Files ~ \"\\.(json|log)$\">\n";
                $htaccess_content .= "    Order deny,allow\n";
                $htaccess_content .= "    Deny from all\n";
                $htaccess_content .= "</Files>\n";
                
                @file_put_contents($htaccess_file, $htaccess_content);
                @chmod($htaccess_file, 0644);
            }
        }
    }
}

// 插件停用钩子
register_deactivation_hook(__FILE__, 'content_auto_manager_deactivate');

function content_auto_manager_deactivate() {
    // 清理临时数据或选项
    // 注意：不要删除用户数据
}


// 插件卸载钩子
register_uninstall_hook(__FILE__, 'content_auto_manager_uninstall');

function content_auto_manager_uninstall() {
    // 删除插件创建的所有数据和选项
    delete_option('content_auto_manager_version');
    delete_option('cam_image_api_settings');  // 删除图像API设置
    
    // 注意：谨慎删除数据库表，这会丢失所有用户数据
    // 如果需要删除表，请取消下面几行的注释
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_api_configs");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_rules");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_rule_items");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_topic_tasks");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_topics");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_article_tasks");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_articles");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_job_queue");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_publish_rules");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}content_auto_article_structures");
}

// 初始化插件
add_action('plugins_loaded', 'content_auto_manager_init');

// 检查数据库版本并升级
add_action('init', 'content_auto_manager_check_version');

function content_auto_manager_check_version() {
    $current_version = get_option('content_auto_manager_version', '1.0.0');
    if (version_compare($current_version, CONTENT_AUTO_MANAGER_VERSION, '<')) {
        content_auto_manager_upgrade_database();
        update_option('content_auto_manager_version', CONTENT_AUTO_MANAGER_VERSION);
    }
}

function content_auto_manager_init() {
    // 加载文本域
    load_plugin_textdomain('content-auto-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // 初始化数据库
    $database = new ContentAuto_Database();
    
    // 检查是否需要更新数据库
    $installed_version = get_option('content_auto_manager_version');
    if ($installed_version != CONTENT_AUTO_MANAGER_VERSION) {
        $database->create_tables();
        update_option('content_auto_manager_version', CONTENT_AUTO_MANAGER_VERSION);
    }
    
    // 检查并设置调试模式
    $debug_mode = get_option('content_auto_debug_mode', false);
    if ($debug_mode && !defined('CONTENT_AUTO_DEBUG_MODE')) {
        define('CONTENT_AUTO_DEBUG_MODE', true);
    }
    
    // 初始化后台菜单
    $admin_menu = new ContentAuto_AdminMenu();
    
    // 初始化发布设置管理页面（包括分类过滤功能）
    if (is_admin()) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/class-publish-settings-admin.php';
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'publish-settings/class-category-filter.php';
        
        // 定期清理分类过滤设置
        add_action('wp_loaded', array('ContentAuto_Category_Filter', 'validate_and_clean_settings'));
    }


    
    // 初始化数据一致性验证系统
    $data_validator = new ContentAuto_DataValidator();
    $database_wrapper = new ContentAuto_DatabaseWrapper();
    
    // 初始化规则表单处理器
    new ContentAuto_RuleHandler();
    
    // 启动任务队列处理器
    add_action('init', 'content_auto_manager_start_queue_processor');

    // 加载向量聚类和文章结构相关服务 (仅在后台加载)
    // 注意：菜单注册已移至 ContentAuto_AdminMenu 类统一管理
    // 这里只需要确保类文件被加载，不需要实例化
    if (is_admin()) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'admin/class-clustering-admin-page.php';
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-structures/class-article-structure-admin-page.php';
        // 这些类的实例化现在在需要时由 ContentAuto_AdminMenu 处理
    }
    // 2. 自动增量分配服务
    require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-incremental-clustering.php';
    new ContentAuto_IncrementalClustering();
    
    // 3. 初始化自动配图功能
    content_auto_init_auto_image_feature();
}

function content_auto_manager_start_queue_processor() {
    // 启动后台任务处理
    if (!wp_next_scheduled('content_auto_manager_process_queue')) {
        wp_schedule_event(time(), 'every_minute', 'content_auto_manager_process_queue');
    }
    
    add_action('content_auto_manager_process_queue', 'content_auto_manager_process_queue');
    
    // 启动任务恢复处理器
    if (!wp_next_scheduled('content_auto_manager_recover_tasks')) {
        wp_schedule_event(time(), 'every_5_minutes', 'content_auto_manager_recover_tasks');
    }
    
    add_action('content_auto_manager_recover_tasks', 'content_auto_manager_recover_tasks');
    
    // 启动文章任务超时处理器
    if (!wp_next_scheduled('content_auto_manager_handle_article_timeouts')) {
        wp_schedule_event(time(), 'every_minute', 'content_auto_manager_handle_article_timeouts');
    }
    
    add_action('content_auto_manager_handle_article_timeouts', 'content_auto_manager_handle_article_timeouts');
}

// 添加自定义时间间隔
add_filter('cron_schedules', 'content_auto_manager_add_cron_intervals');

function content_auto_manager_add_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute', 'content-auto-manager')
    );
    
    $schedules['every_5_minutes'] = array(
        'interval' => 300,
        'display' => __('Every 5 Minutes', 'content-auto-manager')
    );
    
    return $schedules;
}

// 任务队列处理函数
function content_auto_manager_process_queue() {
    $queue = new ContentAuto_JobQueue();
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
function content_auto_manager_recover_tasks() {
    if (class_exists('ContentAuto_TopicTaskManager')) {
        $task_manager = new ContentAuto_TopicTaskManager();
        $recovered_count = $task_manager->auto_recover_hanging_tasks();
    }
}

// 文章任务超时处理函数
function content_auto_manager_handle_article_timeouts() {
    // 确保文章任务超时处理器类已加载
    if (!class_exists('ContentAuto_ArticleTaskTimeoutHandler')) {
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'article-tasks/class-article-task-timeout-handler.php';
    }
    
    if (class_exists('ContentAuto_ArticleTaskTimeoutHandler')) {
        $timeout_handler = new ContentAuto_ArticleTaskTimeoutHandler();
        $result = $timeout_handler->handle_timeout_tasks();
        
        // 记录处理结果到日志
        if ($result['total_found'] > 0) {
            $logger = new ContentAuto_PluginLogger();
            $logger->info("文章任务超时处理完成", $result);
        }
    }
}

/**
 * 初始化自动配图功能
 */
function content_auto_init_auto_image_feature() {
    // 检查图像API模块是否存在
    if (!class_exists('CAM_Image_API_Handler')) {
        return; // 图像API模块不可用，跳过自动配图功能
    }
    
    // 加载自动配图集成模块
    $auto_image_integration = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-tasks/auto-image-integration.php';
    
    if (file_exists($auto_image_integration)) {
        require_once $auto_image_integration;
    } else {
        // 记录模块文件不存在
        error_log('ContentAuto: 自动配图集成文件不存在: ' . $auto_image_integration);
    }
}


// 添加管理列
add_filter('manage_edit-post_columns', 'content_auto_manager_add_post_columns');

function content_auto_manager_add_post_columns($columns) {
    $columns['content_auto_manager'] = __('自动生成', 'content-auto-manager');
    return $columns;
}

// 显示管理列内容
add_action('manage_post_posts_custom_column', 'content_auto_manager_post_column_content', 10, 2);

function content_auto_manager_post_column_content($column_name, $post_id) {
    if ($column_name == 'content_auto_manager') {
        echo '<span class="dashicons dashicons-admin-generic"></span>';
    }
}

/**
 * 启用调试模式
 */
function content_auto_enable_debug_mode() {
    update_option('content_auto_debug_mode', true);
}

/**
 * 禁用调试模式
 */
function content_auto_disable_debug_mode() {
    update_option('content_auto_debug_mode', false);
}

/**
 * 检查调试模式是否启用
 */
function content_auto_is_debug_mode() {
    return get_option('content_auto_debug_mode', false);
}


