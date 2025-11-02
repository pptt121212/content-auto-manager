# 变量说明（Variable Guide）

## 功能概述
变量说明模块提供提示词模板中所有可用变量的详细说明文档，帮助管理员理解每个变量的数据来源、业务含义和使用场景。页面位于 `variable-guide/views/variable-guide.php`，为发布规则、文章结构等模块配置提示词模板时提供参考。

## 业务逻辑
1. **主题生成变量**：
   - `CURRENT_DATE`：动态注入当前日期，为AI提供时间上下文。
   - `LANGUAGE_INSTRUCTION` 和 `LANGUAGE_NAME`：根据发布语言设置生成语言指令。
   - `REFERENCE_CONTENT_BLOCK`：通过 `RuleManager::get_content_by_rule_item_id()` 获取规则项目的源材料（上传文本、关键词、分类名称等），构建XML结构。
   - `EXISTING_TOPICS_BLOCK`：查询数据库中最近生成的主题（状态为unused/queued），通过向量相似度去重，避免生成重复主题。
   - `SITE_CATEGORIES_BLOCK`：使用 `ContentAuto_Category_Filter` 或 WordPress `get_categories()` 获取分类列表。
   - `{N}`：任务创建时指定的每规则项生成数量。
2. **文章生成变量**：
   - **核心内容变量**：`TITLE`（文章标题）、`SOURCE_ANGLE`（内容角度）等，来自 `content_auto_topics` 表。
   - **时间与语言变量**：与主题生成类似，提供日期和语言指令。
   - **结构指导变量**：`ARTICLE_STRUCTURE`（文章结构模板）、`CONTENT_ANGLES`（内容角度）等，来自 `content_auto_article_structures` 表。
   - **品牌与优化变量**：`BRAND_PROFILE`（品牌资料）、`SEO_KEYWORDS`、`IMAGE_PROMPT`（自动配图指令）等。
   - **引用与示例变量**：`REFERENCE_ARTICLES`（参考文章）、`SIMILAR_CONTENT`（相似内容）等，辅助内容质量。
3. **变量分类**：
   - 页面将变量按类别组织（核心内容、时间语言、结构指导、品牌优化、引用示例等），便于查阅。
   - 每个变量包含：名称、描述、数据来源、业务含义、示例、使用建议、重要性级别等信息。
4. **前端呈现**：
   - 使用可折叠的卡片式布局，支持搜索和过滤。
   - 页面提供复制变量名功能，快速粘贴到提示词模板中。

## 使用场景
- 配置发布规则：在编辑提示词模板时参考变量说明，选择合适的变量组合。
- 优化内容质量：了解变量的数据来源和去重逻辑，调整规则以减少重复内容。
- 调试提示词：当生成内容不符合预期时，查看变量数据源确认问题根源。
- 团队培训：为运营团队提供变量使用参考，标准化提示词配置流程。

## 技术实现
- 数据定义：页面内硬编码两个数组 `$topic_variables` 和 `$article_variables`，包含所有变量的元信息。
- 数据来源追溯：每个变量的 `description` 字段注明数据表和字段、获取方法等细节。
- 前端渲染：使用 PHP 循环输出变量列表，配合 `variable-guide/assets/css/variable-guide.css` 美化显示。
- JavaScript交互：`variable-guide/assets/js/variable-guide.js` 提供搜索、折叠、复制等功能。

## 相关文件
- `variable-guide/views/variable-guide.php`（页面模板与数据定义）
- `variable-guide/assets/js/variable-guide.js`（前端交互）
- `variable-guide/assets/css/variable-guide.css`（样式）
- `publish-settings/views/publish-rules.php`（提示词模板编辑）
- `topic-management/class-topic-task-manager.php`（变量实际填充逻辑）
- `article-tasks/class-article-generator.php`（变量实际填充逻辑）
