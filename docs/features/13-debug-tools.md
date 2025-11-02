# 调试工具（Debug Tools）

## 功能概述
调试工具模块提供系统运行状态排查能力，包括启用/关闭调试模式、查看调试日志、清空日志等操作。页面位于 `debug-tools/views/debug-tools.php`，AJAX逻辑在 `debug-tools/ajax-handler.php` 中实现。通过该模块运维人员可以快速定位任务失败原因、API异常等问题。

## 业务逻辑
1. **调试模式开关**：
   - `content_auto_handle_toggle_debug_mode()` 根据用户操作更新 `content_auto_debug_mode` 选项。
   - 调试模式开启后，系统会记录更详细的日志并可能增加提示词预览等调试信息。
2. **日志查看**：
   - `content_auto_handle_get_debug_logs()` 调用 `ContentAuto_PluginLogger::get_recent_logs(50)` 获取最近50条日志。
   - 对包含提示词数据的日志进行截断预览，避免长内容阻塞界面。
3. **日志清空**：
   - `content_auto_handle_clear_debug_logs()` 调用 `ContentAuto_PluginLogger::clear_log()` 清空日志文件。
4. **安全控制**：
   - 所有操作均需管理员权限 `manage_options`。
   - 使用 `wp_verify_nonce` 校验操作请求的有效性。
5. **前端界面**：
   - 使用 `debug-tools/assets/js/debug-tools.js` 发起AJAX请求，更新界面显示。
   - `debug-tools/assets/css/debug-tools.css` 提供日志展示区域的样式。

## 使用场景
- API异常排查：查看任务执行时的提示词、响应状态、错误信息。
- 队列故障诊断：观察任务处理日志，确认是否有卡顿或超时。
- 调试优化：开启调试模式后重放任务，观察性能数据。
- 日志清理：调试完成后清空日志，保持记录清晰。

## 技术实现
- 日志系统：依赖 `shared/logging/class-plugin-logger.php` 读写插件日志文件。
- 调试模式：更新 `content_auto_debug_mode` 选项后，在 `content_auto_manager_init()` 中定义常量 `CONTENT_AUTO_DEBUG_MODE`。
- AJAX端点：
  - `content_auto_toggle_debug_mode`
  - `content_auto_get_debug_logs`
  - `content_auto_clear_debug_logs`
- 权限控制：使用 WordPress capability checks 和 nonce 验证。

## 相关文件
- `debug-tools/views/debug-tools.php`（页面模板）
- `debug-tools/ajax-handler.php`（AJAX逻辑）
- `debug-tools/assets/js/debug-tools.js`（前端交互）
- `debug-tools/assets/css/debug-tools.css`（样式）
- `shared/logging/class-plugin-logger.php`（日志读写）
- `content-auto-manager.php`（调试模式全局逻辑）
