# 日志系统（Logging System）

## 功能概述
日志系统模块为插件提供统一的日志记录能力，支持记录调试、信息、警告、错误等不同级别的日志，输出到插件目录下的 `logs/` 文件夹，供调试工具与运维人员使用。核心类包括 `ContentAuto_PluginLogger` 和 `ContentAuto_LoggingSystem`，位于 `shared/logging/` 目录。

## 业务逻辑
1. **日志记录**：
   - `ContentAuto_PluginLogger::log($message, $level, $context)` 将日志写入每天的文件（`logs/YYYY-MM-DD.log`）。
   - 支持上下文信息，以JSON格式输出；如果包含提示词内容会完整记录，供调试分析。
   - 提供快捷方法：`debug()`, `info()`, `warning()`, `error()`。
2. **日志读取**：
   - `get_recent_logs($limit)` 读取最近的日志条目（默认100条），按时间倒序返回。
   - 解析日志行，分离时间、级别、消息、上下文，供调试工具展示。
3. **日志清理**：
   - `clear_log()` 删除 `logs/` 目录下所有 `.log` 文件，用于清空历史记录。
4. **高级日志服务**：
   - `ContentAuto_LoggingSystem` 封装更高层的日志逻辑，提供模块化日志输出、性能记录、错误上报等功能。
   - 在主题任务、文章任务、向量服务等模块中注入 `ContentAuto_LoggingSystem`，统一日志格式。
5. **调试模式联动**：
   - 当 `content_auto_debug_mode` 启用时，日志系统记录更详细的上下文数据。
   - 调试工具通过AJAX调用 `get_recent_logs()` 与 `clear_log()` 进行展示与管理。

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
