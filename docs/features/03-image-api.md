# 图像API（Image API）

## 功能概述
图像API模块负责配置和管理文章配图所需的图像生成服务。该模块位于 `image-api-settings/` 目录，支持多种图像生成API（如通义万相 ModelScope、Pollinations 等），为文章自动配图提供基础能力。

## 业务逻辑
1. **API配置管理**：
   - 通过 `CAM_Image_API_Admin_Page` 管理图像生成服务的配置界面。
   - 配置项包含：API 提供商选择、API 密钥、模型名称、以及针对不同厂商的特定参数（如 Token、模型 ID）。
   - 配置存储在 WordPress Options 表中，key 为 `cam_image_api_settings`。
2. **多服务商支持**：
   - **ModelScope (魔搭)**：支持异步任务模式。插件通过 `start_modelscope_task` 提交并使用 `check_modelscope_task` 轮询，通过同步包装器实现阻塞式生成或集成在异步队列中。针对 OSS 图片下载提供编码修正处理。
   - **SiliconFlow (硅基流动)**：支持高度兼容的图像生成接口，默认尺寸统一为 1024x576 (16:9)。
   - **OpenAI (DALL-E)**：支持标准 OpenAI 图像生成接口。
   - **Pollinations.AI**：免费、无需 API 密钥（可选 Token 提升限速）的快速生成方案，支持 Flux 等模型。
3. **连接测试**：
   - AJAX 接口 `cam_test_image_api_handler` 提供 API 可用性测试。
   - 测试时使用预设提示词生成 base64 图像并即时预览。
4. **自动配图集成**：
   - 与文章任务模块深度协作。在 `ContentAuto_ArticleGenerator` 处理过程中，根据提取出的图片描述词调用 `CAM_Image_API_Handler::generate_image_from_saved_settings()` 生成配图。
   - 支持生成特色图片及插入正文插图。

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
