# API设置（API Settings）

## 功能概述
API设置模块用于管理大语言模型（LLM）服务的接口配置，包括自定义API端点、预定义服务商（如OpenAI、Gemini、Qwen等）、向量嵌入服务以及渠道管理。该模块位于 `api-settings/` 目录，是整个内容生产流程的基础设施层。

## 业务逻辑
1. **API配置存储**：
   - 通过 `ContentAuto_ApiConfig` 类管理配置的增删改查。
   - 配置项包括：API名称、端点URL、模型名称、API密钥、角色描述、向量模型等。
   - 支持自定义API和预定义API（从 `class-predefined-api.php` 读取常见服务商模板）。
2. **渠道管理**：
   - 实现官方渠道（`class-official-channel.php`）和第三方渠道（如 `class-pollinations-channel.php`）的抽象层。
   - 基类 `class-api-channel.php` 定义调用接口规范，子类实现具体请求逻辑。
3. **连接测试**：
   - 提供测试按钮，AJAX调用 `content_auto_manager_test_api_connection` 或 `content_auto_manager_test_predefined_api` 验证API可用性。
   - 测试时发送简单提示词，检查返回状态和响应内容。
4. **多配置管理**：
   - 支持同时维护多个API配置，每个规则可选择使用哪个API。
   - 启用/禁用开关允许快速切换配置，无需删除。

## 使用场景
- 初始化插件：配置第一个LLM API，如OpenAI GPT-4或国内Qwen服务。
- 多服务商策略：配置多个API，分别用于高质量文章生成和快速主题批量生成。
- 备用冗余：主API不可用时，规则自动切换到备用API。
- 成本优化：低成本API用于主题生成，高性能API用于正式文章内容生成。

## 技术实现
- 数据表：`{prefix}_content_auto_api_configs`，包含 `config_name`, `api_endpoint`, `model_name`, `api_key`, `role_description`, `vector_model` 等字段。
- 参数类：`class-api-config-params.php` 定义参数验证与封装。
- AJAX处理器：`shared/ajax-handlers.php` 中注册测试连接和配额查询接口。
- 表单页面：`api-settings/views/api-config-form.php` 渲染编辑界面。

## 相关文件
- `api-settings/class-api-config.php`（核心配置管理类）
- `api-settings/class-api-config-params.php`（参数验证）
- `api-settings/class-api-channel.php`（渠道抽象基类）
- `api-settings/class-official-channel.php`（官方API渠道实现）
- `api-settings/class-predefined-api.php`（预定义服务商模板）
- `api-settings/views/api-config-form.php`（界面渲染）
- `shared/ajax-handlers.php`（AJAX处理）
