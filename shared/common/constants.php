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
define('YALI_AI_WRITER_STATUS_PENDING', 'pending');
define('YALI_AI_WRITER_STATUS_RUNNING', 'running');
define('YALI_AI_WRITER_STATUS_COMPLETED', 'completed');
define('YALI_AI_WRITER_STATUS_FAILED', 'failed');
define('YALI_AI_WRITER_STATUS_PAUSED', 'paused');
define('YALI_AI_WRITER_STATUS_CANCELLED', 'cancelled');
define('YALI_AI_WRITER_STATUS_RETRY', 'retry');
define('YALI_AI_WRITER_STATUS_PROCESSING', 'processing');

// ==============================================
// 主题状态常量
// ==============================================
define('YALI_AI_WRITER_TOPIC_UNUSED', 'unused');
define('YALI_AI_WRITER_TOPIC_QUEUED', 'queued');
define('YALI_AI_WRITER_TOPIC_USED', 'used');
define('YALI_AI_WRITER_TOPIC_EXPIRED', 'expired');

// ==============================================
// 文章状态常量
// ==============================================
define('YALI_AI_WRITER_ARTICLE_PENDING', 'pending');
define('YALI_AI_WRITER_ARTICLE_SUCCESS', 'success');
define('YALI_AI_WRITER_ARTICLE_FAILED', 'failed');
define('YALI_AI_WRITER_ARTICLE_DUPLICATE', 'duplicate');
define('YALI_AI_WRITER_ARTICLE_INVALID', 'invalid');

// ==============================================
// 任务类型常量
// ==============================================
define('YALI_AI_WRITER_JOB_TYPE_TOPIC', 'topic');
define('YALI_AI_WRITER_JOB_TYPE_ARTICLE', 'article');
define('YALI_AI_WRITER_JOB_TYPE_BATCH', 'batch');
define('YALI_AI_WRITER_JOB_TYPE_SCHEDULED', 'scheduled');

// ==============================================
// 规则类型常量
// ==============================================
define('YALI_AI_WRITER_RULE_TYPE_CATEGORY', 'category');
define('YALI_AI_WRITER_RULE_TYPE_KEYWORD', 'keyword');
define('YALI_AI_WRITER_RULE_TYPE_TEMPLATE', 'template');
define('YALI_AI_WRITER_RULE_TYPE_SCHEDULE', 'schedule');
define('YALI_AI_WRITER_RULE_TYPE_MIXED', 'mixed');

// ==============================================
// API配置常量
// ==============================================
define('YALI_AI_WRITER_API_TYPE_OPENAI', 'openai');
define('YALI_AI_WRITER_API_TYPE_CUSTOM', 'custom');
define('YALI_AI_WRITER_API_TYPE_PREDEFINED', 'predefined');
define('YALI_AI_WRITER_API_TYPE_CLAUDE', 'claude');
define('YALI_AI_WRITER_API_TYPE_GEMINI', 'gemini');

// ==============================================
// 发布状态常量
// ==============================================
define('YALI_AI_WRITER_PUBLISH_STATUS_DRAFT', 'draft');
define('YALI_AI_WRITER_PUBLISH_STATUS_PUBLISH', 'publish');
define('YALI_AI_WRITER_PUBLISH_STATUS_SCHEDULE', 'schedule');
define('YALI_AI_WRITER_PUBLISH_STATUS_PENDING_REVIEW', 'pending_review');

// ==============================================
// 队列状态常量
// ==============================================
define('YALI_AI_WRITER_QUEUE_STATUS_WAITING', 'waiting');
define('YALI_AI_WRITER_QUEUE_STATUS_PROCESSING', 'processing');
define('YALI_AI_WRITER_QUEUE_STATUS_COMPLETED', 'queue_completed');
define('YALI_AI_WRITER_QUEUE_STATUS_FAILED', 'queue_failed');
define('YALI_AI_WRITER_QUEUE_STATUS_CANCELLED', 'queue_cancelled');

// ==============================================
// 默认值常量
// ==============================================
define('YALI_AI_WRITER_DEFAULT_TEMPERATURE', 0.7);
define('YALI_AI_WRITER_DEFAULT_MAX_TOKENS', 1000);
define('YALI_AI_WRITER_DEFAULT_TOPIC_COUNT', 5);
define('YALI_AI_WRITER_DEFAULT_TIMEOUT', 30);
define('YALI_AI_WRITER_DEFAULT_RETRY_COUNT', 3);
define('YALI_AI_WRITER_ITEMS_PER_PAGE', 20);
define('YALI_AI_WRITER_MAX_RETRIES', 3);

// ==============================================
// 日志级别常量
// ==============================================
define('YALI_AI_WRITER_LOG_LEVEL_DEBUG', 'debug');
define('YALI_AI_WRITER_LOG_LEVEL_INFO', 'info');
define('YALI_AI_WRITER_LOG_LEVEL_WARNING', 'warning');
define('YALI_AI_WRITER_LOG_LEVEL_ERROR', 'error');
define('YALI_AI_WRITER_LOG_LEVEL_CRITICAL', 'critical');

// ==============================================
// 权限级别常量
// ==============================================
define('YALI_AI_WRITER_PERMISSION_ADMIN', 'admin');
define('YALI_AI_WRITER_PERMISSION_EDITOR', 'editor');
define('YALI_AI_WRITER_PERMISSION_AUTHOR', 'author');
define('YALI_AI_WRITER_PERMISSION_CONTRIBUTOR', 'contributor');

// ==============================================
// 错误代码常量
// ==============================================
define('YALI_AI_WRITER_ERROR_SUCCESS', 0);
define('YALI_AI_WRITER_ERROR_INVALID_CONFIG', 1001);
define('YALI_AI_WRITER_ERROR_API_FAILURE', 1002);
define('YALI_AI_WRITER_ERROR_VALIDATION', 1003);
define('YALI_AI_WRITER_ERROR_DATABASE', 1004);
define('YALI_AI_WRITER_ERROR_PERMISSION', 1005);
define('YALI_AI_WRITER_ERROR_TIMEOUT', 1006);

// ==============================================
// 数据库表名常量
// ==============================================
define('YALI_AI_WRITER_TABLE_TOPICS', 'yali_ai_writer_topics');
define('YALI_AI_WRITER_TABLE_ARTICLES', 'yali_ai_writer_articles');
define('YALI_AI_WRITER_TABLE_JOBS', 'yali_ai_writer_jobs');
define('YALI_AI_WRITER_TABLE_RULES', 'yali_ai_writer_rules');
define('YALI_AI_WRITER_TABLE_API_CONFIGS', 'yali_ai_writer_api_configs');

// ==============================================
// 选项名称常量
// ==============================================
define('YALI_AI_WRITER_OPTION_VERSION', 'yali_ai_writer_manager_version');
define('YALI_AI_WRITER_OPTION_SETTINGS', 'yali_ai_writer_manager_settings');
define('YALI_AI_WRITER_OPTION_LICENSE', 'yali_ai_writer_manager_license');
define('YALI_AI_WRITER_OPTION_LAST_RUN', 'yali_ai_writer_manager_last_run');

// ==============================================
// 时间间隔常量
// ==============================================
define('YALI_AI_WRITER_INTERVAL_MINUTE', 60);
define('YALI_AI_WRITER_INTERVAL_HOUR', 3600);
define('YALI_AI_WRITER_INTERVAL_DAY', 86400);
define('YALI_AI_WRITER_INTERVAL_WEEK', 604800);

// ==============================================
// API时间配置常量
// ==============================================
define('YALI_AI_WRITER_MIN_API_INTERVAL', 30);              // 最小API间隔（秒）
define('YALI_AI_WRITER_DEFAULT_RETRY_DELAY', 2);            // 默认重试延迟（秒）
define('YALI_AI_WRITER_RATE_LIMIT_DELAY', 300);            // 速率限制延迟（秒）
define('YALI_AI_WRITER_DEFAULT_API_TIMEOUT', 120);         // 默认API超时（秒）
define('YALI_AI_WRITER_QUEUE_LOCK_TIMEOUT', 600);          // 队列锁定超时（秒）

// ==============================================
// 选项名称常量
// ==============================================
define('YALI_AI_WRITER_OPTION_MIN_API_INTERVAL', 'yali_ai_writer_api_min_interval');
define('YALI_AI_WRITER_OPTION_RETRY_DELAY', 'yali_ai_writer_retry_delay');

// ==============================================
// 文件类型常量
// ==============================================
define('YALI_AI_WRITER_FILE_TYPE_LOG', 'log');
define('YALI_AI_WRITER_FILE_TYPE_EXPORT', 'export');
define('YALI_AI_WRITER_FILE_TYPE_IMPORT', 'import');
define('YALI_AI_WRITER_FILE_TYPE_BACKUP', 'backup');

// ==============================================
// 通知类型常量
// ==============================================
define('YALI_AI_WRITER_NOTIFICATION_TYPE_SUCCESS', 'success');
define('YALI_AI_WRITER_NOTIFICATION_TYPE_ERROR', 'error');
define('YALI_AI_WRITER_NOTIFICATION_TYPE_WARNING', 'warning');
define('YALI_AI_WRITER_NOTIFICATION_TYPE_INFO', 'info');