# 发布规则（Publish Rules）

## 功能概述
发布规则模块定义文章生成后的发布策略，包括发布状态、分类归属、标签设定、特色图片、自动配图、站点品牌信息等。页面位于 `publish-settings/views/publish-rules.php`，后台逻辑在 `class-publish-settings-admin.php` 与 `class-category-filter.php` 中实现。

## 业务逻辑
1. **规则配置**：
   - 支持设置默认发布状态（草稿/立即发布）、作者、文章类型、分类、标签、关键词、特色图片策略等。
   - 配置文章结构模板、提示词变量、角色描述等内容增强选项。
   - 可定义自动配图规则，包括提示词模板、插入位置、风格要求。
2. **分类过滤与同步**：
   - `ContentAuto_Category_Filter` 支持将生成的文章映射到指定分类，并提供分类缓存刷新功能。
   - 提供分类管理子页面（`action=manage-categories`），可配置过滤策略，如只展示某些顶级分类。
3. **授权验证**：
   - 使用 `ContentAuto_License_Manager` 验证高级功能授权（如多分类映射、高级配图）。
   - 未授权状态下限制修改发布规则并给出提示。
4. **数据存储**：
   - 发布规则存储在 `content_auto_publish_rules` 表，每条规则可与文章生成规则关联。
   - 支持角色描述（`role_description`）、发布语言、SEO信息等字段。
5. **与文章生成联动**：
   - 文章生成完成后，根据对应发布规则设置文章属性。
   - 自动为文章添加分类、标签、摘要、特色图片，并可触发远程推送等扩展动作。

## 使用场景
- 多站点运营：不同站点或分类使用不同的发布策略。
- 自动化发布：生成文章后立即发布并分配正确的分类与标签。
- 质量控制：生成文章先保存为草稿，人工审核后再发布。
- 品牌统一：通过统一的角色描述和提示词模板保持品牌语调一致。

## 技术实现
- 管理页面：依托WordPress Settings API呈现复杂表单，多Tab布局。
- 配置保存：通过 `update_option` 或数据库写入，将规则保存到 `content_auto_publish_rules`。
- 安全机制：使用 `wp_verify_nonce` 验证表单提交是否合法。
- 分类缓存：调用 `content_auto_refresh_category_cache()` 生成向量缓存。
- 集成：文章生成流程中调用 `ContentAuto_Publish_Settings`（位于文章生成器）以应用规则。

## 相关文件
- `publish-settings/views/publish-rules.php`（页面模板）
- `publish-settings/class-publish-settings-admin.php`（后台逻辑）
- `publish-settings/class-category-filter.php`（分类过滤）
- `shared/database/class-database.php`（数据库操作）
- `article-tasks/class-article-generator.php`（应用发布规则）
