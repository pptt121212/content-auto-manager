# 品牌资料（Brand Profiles）

## 功能概述
品牌资料模块用于存储和管理品牌信息，包括品牌名称、网站、行业、描述、价值观、目标受众、语调风格、Logo等。这些信息在文章生成时作为上下文注入提示词，确保生成内容符合品牌调性。管理页面由 `ContentAuto_Brand_Profiles_Admin_Page` 实现，位于 `brand-profiles/` 目录。

## 业务逻辑
1. **品牌资料管理**：
   - 提供增删改查接口，包括 `ajax_add_brand_profile`、`ajax_update_brand_profile`、`ajax_delete_brand_profile`、`ajax_get_brand_profiles`、`ajax_get_brand_profile_details`。
   - 资料存储在 `content_auto_brand_profiles` 表，包含 `title`, `website`, `industry`, `description`, `values`, `target_audience`, `tone_of_voice`, `logo_url` 等字段。
2. **向量生成与关联**：
   - 创建或更新品牌时，调用 `ContentAuto_VectorApiHandler` 生成品牌标题的语义向量。
   - 向量存储在 `vector_embedding` 字段（Base64编码），用于与主题向量计算相似度。
   - 通过向量匹配，文章生成时可自动选择最相关的品牌资料。
3. **数据验证**：
   - `validate_brand_profile_data()` 确保必填字段完整（如品牌名称）。
   - `prepare_brand_profile_data()` 清理和格式化输入数据，确保数据一致性。
4. **前端交互**：
   - 使用Vue.js风格的AJAX管理界面（`brand-profiles/assets/js/brand-profiles.js`）。
   - 支持Logo上传（通过WordPress Media Library）。

## 使用场景
- 多品牌运营：为不同品牌配置不同的语调、价值观、目标受众。
- 内容一致性：确保所有生成内容符合品牌形象和语言风格。
- 自动化匹配：根据主题向量自动选择最相关的品牌资料。
- 品牌形象维护：统一管理品牌Logo、网站、描述等信息。

## 技术实现
- 数据表：`content_auto_brand_profiles`，包含品牌元信息和向量字段。
- AJAX端点：
  - `cam_get_brand_profiles`（列表查询）
  - `cam_get_brand_profile_details`（详情查询）
  - `cam_add_brand_profile`（新增品牌）
  - `cam_update_brand_profile`（更新品牌）
  - `cam_delete_brand_profile`（删除品牌）
- 向量服务：`shared/services/class-vector-api-handler.php` 调用向量API生成嵌入。
- 前端资源：
  - `brand-profiles/views/brand-profiles-management.php`（页面模板）
  - `brand-profiles/assets/js/brand-profiles.js`（交互逻辑）
  - `brand-profiles/assets/css/brand-profiles.css`（样式）
- 集成点：文章生成器根据主题向量查询相似品牌资料，注入到提示词中。

## 相关文件
- `brand-profiles/admin/class-brand-profiles-admin-page.php`（管理页面）
- `brand-profiles/views/brand-profiles-management.php`（页面模板）
- `brand-profiles/assets/js/brand-profiles.js`（前端交互）
- `brand-profiles/assets/css/brand-profiles.css`（样式）
- `shared/services/class-vector-api-handler.php`（向量生成）
- `shared/database/class-database.php`（数据库操作）
- `article-tasks/class-article-generator.php`（应用品牌资料）
