# 图像API（Image API）

## 功能概述
图像API模块负责配置和管理文章配图所需的图像生成服务。该模块位于 `image-api-settings/` 目录，支持多种图像生成API（如通义万相 ModelScope、Pollinations 等），为文章自动配图提供基础能力。

## 业务逻辑
1. **API配置管理**：
   - 通过 `CAM_Image_API_Admin_Page` 管理图像生成服务的配置界面。
   - 配置项包括：API类型（ModelScope/Pollinations等）、API密钥、模型名称、默认尺寸等参数。
   - 配置存储在WordPress Options表中，key为 `cam_image_api_settings`。
2. **多服务商支持**：
   - `CAM_Image_API_Handler` 封装不同图像API的调用逻辑。
   - 支持同步生成（Pollinations）和异步任务（ModelScope）两种模式。
   - 异步模式需先提交任务获取task_id，再轮询查询任务状态直到生成完成。
3. **连接测试**：
   - AJAX接口 `cam_test_image_api_handler` 提供API可用性测试。
   - 测试时使用默认提示词生成示例图片，验证配置正确性。
4. **自动配图集成**：
   - 与文章任务模块协作，在文章生成过程中自动为特色图片或段落内容生成配图。
   - 通过 `image-tasks/auto-image-integration.php` 集成到文章生成流程。

## 使用场景
- 初始配置：接入通义万相API，为后续文章配图做准备。
- 多场景适配：配置不同风格的图像API，用于不同类型文章。
- 成本优化：使用免费API（如Pollinations）作为备选方案。
- 批量配图：通过后台任务为历史文章批量生成特色图片。

## 技术实现
- 配置存储：使用WordPress Options API，通过 `get_option('cam_image_api_settings')` 读取配置。
- 异步任务：ModelScope API采用先提交后查询的模式，需处理任务状态轮询。
- HTTP请求：使用 `wp_remote_post` 和 `wp_remote_get` 发送API请求。
- 界面渲染：`image-api-settings/views/image-api-settings.php` 提供配置表单。
- AJAX处理：`image-api-settings/ajax-handler.php` 处理测试和任务状态查询请求。

## 相关文件
- `image-api-settings/class-image-api-admin-page.php`（管理页面）
- `image-api-settings/class-image-api-handler.php`（API调用封装）
- `image-api-settings/ajax-handler.php`（AJAX处理）
- `image-api-settings/views/image-api-settings.php`（配置界面）
- `image-tasks/auto-image-integration.php`（自动配图集成）
- `content-auto-manager.php`（初始化入口 `content_auto_init_auto_image_feature()`）
