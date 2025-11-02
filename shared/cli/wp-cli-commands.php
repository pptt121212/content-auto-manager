<?php
/**
 * WP-CLI命令加载器
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_CLI') && WP_CLI) {
    // 加载文章任务超时处理命令
    $article_timeout_command_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/cli/commands/class-article-timeout-command.php';
    if (file_exists($article_timeout_command_file)) {
        require_once $article_timeout_command_file;
    }
}