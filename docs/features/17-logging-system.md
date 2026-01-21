# 日志系统（Logging System）

## 功能概述
日志系统模块为插件提供统一的日志记录能力，支持记录调试、信息、警告、错误等不同级别的日志，输出到插件目录下的 `logs/` 文件夹，供调试工具与运维人员使用。核心类包括 `ContentAuto_PluginLogger` 和 `ContentAuto_LoggingSystem`，位于 `shared/logging/` 目录。

## 业务逻辑
1. **层级化日志架构**：
   - **底层驱动 (`ContentAuto_PluginLogger`)**：执行物理 IO，按日期（`logs/YYYY-MM-DD.log`）滚动存储，支持 `LOCK_EX` 并发锁。
   - **业务适配器 (`ContentAuto_LoggingSystem`)**：核心逻辑层，提供 `log_success`, `log_error`, `log_warning` 等语义化接口。
2. **结构化上下文 (Structured Context)**：
   - 支持自动组装业务指纹，如 `rule_id`、`topic_id`、`job_id` 等核心标识。
   - 通过 `build_context()` 辅助方法，确保所有异步操作产生的日志均可追溯至原始配置项。
3. **响应净化与调试**：
   - 调试模式激活后，系统利用 `ContentAuto_UnifiedApiHandler` 在日志中捕获完整的 `Prompt Payload` 与 API 原始 JSON。
   - 内置响应净化，记录日志前会自动移除冗余的大模型思考（`<think>`）标记及重复的 XML 标签。
4. **日志运维管理**：
   - 支持 AJAX 异步滚动取回（`get_recent_logs`），具备大容量数据截断逻辑，防止调试界面因大模型输出过长而崩溃。
   - 提供物理删除接口（`clear_log`），用于重置运维环境。

## 使用场景
- 任务排查：任务失败时记录错误原因、API响应、上下文信息。
- 性能分析：记录任务执行耗时、子任务数量、重试次数等。
- 安全追踪：记录敏感操作（如规则修改、批量任务）的管理员信息。
- 运维调试：与调试工具页面配合，实时查看系统状态。

## 技术实现
- 日志文件：按日期拆分，每条日志包含时间戳、级别、消息、上下文。
- 文件写入：使用 `file_put_contents` 带 `LOCK_EX` 避免并发写入冲突。
- 目录权限：插件初始化时创建 `logs/` 目录并确保权限为0755。
- 上下文处理：上下文数组以 `json_encode` 输出，采用 `JSON_UNESCAPED_UNICODE` 保留中文。
- 日志解析：通过正则表达式解析 `[timestamp] [level] message` 结构。

## 相关文件
- `shared/logging/class-plugin-logger.php`（文件级日志记录）
- `shared/logging/class-logging-system.php`（高层日志服务）
- `debug-tools/ajax-handler.php`（日志获取与清理）
- `debug-tools/assets/js/debug-tools.js`（前端展示）
- `content-auto-manager.php`（logs目录初始化与调试模式控制）
