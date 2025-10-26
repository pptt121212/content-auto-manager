<?php
/**
 * 常量定义文件 - 完整版
 */

if (!defined('ABSPATH')) {
    exit;
}

// ==============================================
// 任务状态常量
// ==============================================
define('CONTENT_AUTO_STATUS_PENDING', 'pending');
define('CONTENT_AUTO_STATUS_RUNNING', 'running');
define('CONTENT_AUTO_STATUS_COMPLETED', 'completed');
define('CONTENT_AUTO_STATUS_FAILED', 'failed');
define('CONTENT_AUTO_STATUS_PAUSED', 'paused');
define('CONTENT_AUTO_STATUS_CANCELLED', 'cancelled');
define('CONTENT_AUTO_STATUS_RETRY', 'retry');
define('CONTENT_AUTO_STATUS_PROCESSING', 'processing');

// ==============================================
// 主题状态常量
// ==============================================
define('CONTENT_AUTO_TOPIC_UNUSED', 'unused');
define('CONTENT_AUTO_TOPIC_QUEUED', 'queued');
define('CONTENT_AUTO_TOPIC_USED', 'used');
define('CONTENT_AUTO_TOPIC_EXPIRED', 'expired');

// ==============================================
// 文章状态常量
// ==============================================
define('CONTENT_AUTO_ARTICLE_PENDING', 'pending');
define('CONTENT_AUTO_ARTICLE_SUCCESS', 'success');
define('CONTENT_AUTO_ARTICLE_FAILED', 'failed');
define('CONTENT_AUTO_ARTICLE_DUPLICATE', 'duplicate');
define('CONTENT_AUTO_ARTICLE_INVALID', 'invalid');

// ==============================================
// 任务类型常量
// ==============================================
define('CONTENT_AUTO_JOB_TYPE_TOPIC', 'topic');
define('CONTENT_AUTO_JOB_TYPE_ARTICLE', 'article');
define('CONTENT_AUTO_JOB_TYPE_BATCH', 'batch');
define('CONTENT_AUTO_JOB_TYPE_SCHEDULED', 'scheduled');

// ==============================================
// 规则类型常量
// ==============================================
define('CONTENT_AUTO_RULE_TYPE_CATEGORY', 'category');
define('CONTENT_AUTO_RULE_TYPE_KEYWORD', 'keyword');
define('CONTENT_AUTO_RULE_TYPE_TEMPLATE', 'template');
define('CONTENT_AUTO_RULE_TYPE_SCHEDULE', 'schedule');
define('CONTENT_AUTO_RULE_TYPE_MIXED', 'mixed');

// ==============================================
// API配置常量
// ==============================================
define('CONTENT_AUTO_API_TYPE_OPENAI', 'openai');
define('CONTENT_AUTO_API_TYPE_CUSTOM', 'custom');
define('CONTENT_AUTO_API_TYPE_PREDEFINED', 'predefined');
define('CONTENT_AUTO_API_TYPE_CLAUDE', 'claude');
define('CONTENT_AUTO_API_TYPE_GEMINI', 'gemini');

// ==============================================
// 发布状态常量
// ==============================================
define('CONTENT_AUTO_PUBLISH_STATUS_DRAFT', 'draft');
define('CONTENT_AUTO_PUBLISH_STATUS_PUBLISH', 'publish');
define('CONTENT_AUTO_PUBLISH_STATUS_SCHEDULE', 'schedule');
define('CONTENT_AUTO_PUBLISH_STATUS_PENDING_REVIEW', 'pending_review');

// ==============================================
// 队列状态常量
// ==============================================
define('CONTENT_AUTO_QUEUE_STATUS_WAITING', 'waiting');
define('CONTENT_AUTO_QUEUE_STATUS_PROCESSING', 'processing');
define('CONTENT_AUTO_QUEUE_STATUS_COMPLETED', 'queue_completed');
define('CONTENT_AUTO_QUEUE_STATUS_FAILED', 'queue_failed');
define('CONTENT_AUTO_QUEUE_STATUS_CANCELLED', 'queue_cancelled');

// ==============================================
// 默认值常量
// ==============================================
define('CONTENT_AUTO_DEFAULT_TEMPERATURE', 0.7);
define('CONTENT_AUTO_DEFAULT_MAX_TOKENS', 1000);
define('CONTENT_AUTO_DEFAULT_TOPIC_COUNT', 5);
define('CONTENT_AUTO_DEFAULT_TIMEOUT', 30);
define('CONTENT_AUTO_DEFAULT_RETRY_COUNT', 3);
define('CONTENT_AUTO_ITEMS_PER_PAGE', 20);
define('CONTENT_AUTO_MAX_RETRIES', 3);

// ==============================================
// 日志级别常量
// ==============================================
define('CONTENT_AUTO_LOG_LEVEL_DEBUG', 'debug');
define('CONTENT_AUTO_LOG_LEVEL_INFO', 'info');
define('CONTENT_AUTO_LOG_LEVEL_WARNING', 'warning');
define('CONTENT_AUTO_LOG_LEVEL_ERROR', 'error');
define('CONTENT_AUTO_LOG_LEVEL_CRITICAL', 'critical');

// ==============================================
// 权限级别常量
// ==============================================
define('CONTENT_AUTO_PERMISSION_ADMIN', 'admin');
define('CONTENT_AUTO_PERMISSION_EDITOR', 'editor');
define('CONTENT_AUTO_PERMISSION_AUTHOR', 'author');
define('CONTENT_AUTO_PERMISSION_CONTRIBUTOR', 'contributor');

// ==============================================
// 错误代码常量
// ==============================================
define('CONTENT_AUTO_ERROR_SUCCESS', 0);
define('CONTENT_AUTO_ERROR_INVALID_CONFIG', 1001);
define('CONTENT_AUTO_ERROR_API_FAILURE', 1002);
define('CONTENT_AUTO_ERROR_VALIDATION', 1003);
define('CONTENT_AUTO_ERROR_DATABASE', 1004);
define('CONTENT_AUTO_ERROR_PERMISSION', 1005);
define('CONTENT_AUTO_ERROR_TIMEOUT', 1006);

// ==============================================
// 数据库表名常量
// ==============================================
define('CONTENT_AUTO_TABLE_TOPICS', 'content_auto_topics');
define('CONTENT_AUTO_TABLE_ARTICLES', 'content_auto_articles');
define('CONTENT_AUTO_TABLE_JOBS', 'content_auto_jobs');
define('CONTENT_AUTO_TABLE_RULES', 'content_auto_rules');
define('CONTENT_AUTO_TABLE_API_CONFIGS', 'content_auto_api_configs');

// ==============================================
// 选项名称常量
// ==============================================
define('CONTENT_AUTO_OPTION_VERSION', 'content_auto_manager_version');
define('CONTENT_AUTO_OPTION_SETTINGS', 'content_auto_manager_settings');
define('CONTENT_AUTO_OPTION_LICENSE', 'content_auto_manager_license');
define('CONTENT_AUTO_OPTION_LAST_RUN', 'content_auto_manager_last_run');

// ==============================================
// 时间间隔常量
// ==============================================
define('CONTENT_AUTO_INTERVAL_MINUTE', 60);
define('CONTENT_AUTO_INTERVAL_HOUR', 3600);
define('CONTENT_AUTO_INTERVAL_DAY', 86400);
define('CONTENT_AUTO_INTERVAL_WEEK', 604800);

// ==============================================
// API时间配置常量
// ==============================================
define('CONTENT_AUTO_MIN_API_INTERVAL', 30);              // 最小API间隔（秒）
define('CONTENT_AUTO_DEFAULT_RETRY_DELAY', 2);            // 默认重试延迟（秒）
define('CONTENT_AUTO_RATE_LIMIT_DELAY', 300);            // 速率限制延迟（秒）
define('CONTENT_AUTO_DEFAULT_API_TIMEOUT', 120);         // 默认API超时（秒）
define('CONTENT_AUTO_QUEUE_LOCK_TIMEOUT', 600);          // 队列锁定超时（秒）

// ==============================================
// 选项名称常量
// ==============================================
define('CONTENT_AUTO_OPTION_MIN_API_INTERVAL', 'content_auto_api_min_interval');
define('CONTENT_AUTO_OPTION_RETRY_DELAY', 'content_auto_retry_delay');

// ==============================================
// 文件类型常量
// ==============================================
define('CONTENT_AUTO_FILE_TYPE_LOG', 'log');
define('CONTENT_AUTO_FILE_TYPE_EXPORT', 'export');
define('CONTENT_AUTO_FILE_TYPE_IMPORT', 'import');
define('CONTENT_AUTO_FILE_TYPE_BACKUP', 'backup');

// ==============================================
// 通知类型常量
// ==============================================
define('CONTENT_AUTO_NOTIFICATION_TYPE_SUCCESS', 'success');
define('CONTENT_AUTO_NOTIFICATION_TYPE_ERROR', 'error');
define('CONTENT_AUTO_NOTIFICATION_TYPE_WARNING', 'warning');
define('CONTENT_AUTO_NOTIFICATION_TYPE_INFO', 'info');