# 调试工具（Debug Tools）

## 功能概述
调试工具模块提供系统运行状态排查能力，包括启用/关闭调试模式、查看调试日志、清空日志等操作。页面位于 `debug-tools/views/debug-tools.php`，AJAX逻辑在 `debug-tools/ajax-handler.php` 中实现。通过该模块运维人员可以快速定位任务失败原因、API异常等问题。

## 业务逻辑
1. **调试模式控制**：
   - **全局开关**：更新 `content_auto_debug_mode` 选项。激活后，系统定义 `CONTENT_AUTO_DEBUG_MODE` 常量。
   - **深度追踪**：开启后，`ContentAuto_UnifiedApiHandler` 及各模块执行器会记录完整的 Prompt 内容、API 原始响应 JSON 以及详细的性能指标（耗时/内存）。
2. **多层级日志系统**：
   - **核心适配器**：`ContentAuto_LoggingSystem` 包装了底层的 `PluginLogger`，提供 `log_success`, `log_error`, `log_info` 等标准化接口。
   - **结构化上下文**：支持绑定 `rule_id`、`rule_item_index` 等业务标识，实现日志的精准过滤与关联排查。
   - **分模块日志**：如文章结构生成（`log_structure_prompt_to_file`）在调试模式下会将完整的 XML 提示词独立存证。
3. **运维操作**：
   - **实时查询**：支持 AJAX 获取最近 50 条日志，对长文本（如大模型输出）进行智能截断预览。
   - **安全熔断**：所有日志操作均需 `manage_options` 权限并经过 Nonce 校验。
   - **一键清理**：支持物理删除日志文件，回收系统空间。

## 使用场景
- **提示词调优**：在调试模式下观察注入到 LLM 的完整 Payload，优化变量占位符效果。
- **连接重置排查**：捕获 cURL error 56 等底层通信错误，判断是否需要启用 HTTP 1.1 降级。
- **业务逻辑溯源**：通过日志中的“规则ID”快速定位哪条任务规则导致了异常。
- **性能监控**：观察各段逻辑消耗的内存与时间，识别系统瓶颈。

## 技术实现
- **日志引擎**：
  - `shared/logging/class-plugin-logger.php`：实现基于文件的滚动日志。
  - `shared/logging/class-logging-system.php`：业务层封装，处理上下文组装。
- **常量定义**：在核心入口 `content-auto-manager.php` 中根据数据库设置注入调试常量。
- **前端交互**：`debug-tools/assets/js/debug-tools.js` 实现日志的分页展示与状态切换。

## 相关文件
- `debug-tools/views/debug-tools.php`（管理页面）
- `debug-tools/ajax-handler.php`（后端处理）
- `shared/logging/class-logging-system.php`（统一日志适配器）
- `shared/logging/class-plugin-logger.php`（底层文件读写）
- `content-auto-manager.php`（全局初始化配置）
