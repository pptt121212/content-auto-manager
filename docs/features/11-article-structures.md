# 文章结构（Article Structures）

## 功能概述
文章结构模块用于管理和生成文章大纲模板，为AI内容生成提供结构化指导。通过定义章节标题、内容角度（content angles）和参考文献等，确保生成内容符合预期结构。管理页面由 `ContentAuto_ArticleStructureAdminPage` 实现，位于 `article-structures/` 目录。

## 业务逻辑
1. **结构生成**：
   - 基于分类或主题，调用AI API生成文章大纲结构。
   - AJAX端点 `generate_article_structures` 接收生成请求，调用 `ContentAuto_StructureGenerator` 完成生成。
   - 生成后的结构保存在 `content_auto_article_structures` 表，包含章节列表（JSON）、内容角度（JSON）等。
2. **结构列表管理**：
   - `ajax_get_article_structures` 提供结构列表查询接口，支持分页、搜索、排序。
   - 管理员可删除不合适的结构（`ajax_delete_article_structure`）。
3. **内容角度（Content Angles）**：
   - 每个结构可定义多个写作视角，帮助AI从不同维度展开内容。
   - `ajax_get_content_angles` 获取与某结构关联的角度列表，`ajax_delete_dynamic_angle` 删除角度。
4. **关联文章与受欢迎度统计**：
   - `ajax_get_associated_articles` 查询使用某结构生成的已发布文章列表。
   - 通过外部访问统计（`_external_visit_count` postmeta）计算平均访问量和受欢迎度指数。
   - 高受欢迎度结构可优先复用，提升内容转化率。
5. **结构应用**：
   - 文章任务创建时，可选择某个结构作为提示词模板组成部分。
   - 生成器读取结构数据，将章节标题、内容角度注入到提示词中。

## 使用场景
- 标准化内容：为特定主题定义标准结构，确保生成文章结构统一。
- A/B测试：对同一主题使用不同结构，通过受欢迎度统计找出最佳模板。
- 多角度覆盖：为行业文章定义专业视角、用户视角、技术视角等多个角度。
- 高质量优化：根据外部访问数据优先使用高转化率结构。

## 技术实现
- 数据表：`content_auto_article_structures`，存储结构ID、名称、章节（JSON）、角度（JSON）等。
- 核心类：
  - `ContentAuto_ArticleStructureAdminPage`（管理页面与AJAX处理）
  - `ContentAuto_StructureGenerator`（调用AI生成结构，可能位于共享服务）
- AJAX端点：
  - `get_article_structures`（列表查询）
  - `generate_article_structures`（生成结构）
  - `delete_article_structure`（删除结构）
  - `get_content_angles`（获取角度）
  - `get_associated_articles`（关联文章）
  - `get_structure_popularity_stats`（受欢迎度统计）
- 前端资源：`article-structures/assets/js/` 和 `css/` 提供交互界面。
- 与生成流程集成：文章生成器读取结构后组装提示词模板。

## 相关文件
- `article-structures/class-article-structure-admin-page.php`（管理页面）
- `article-structures/views/article-structure-form.php`（表单页面）
- `article-structures/assets/js/article-structures.js`（前端交互）
- `shared/services/class-structure-generator.php`（结构生成服务）
- `shared/database/class-database.php`（数据库操作）
- `article-tasks/class-article-generator.php`（应用结构生成文章）
