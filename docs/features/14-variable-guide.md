# 变量说明（Variable Guide）

## 功能概述
变量说明模块提供提示词模板中所有可用变量的详细说明文档，帮助管理员理解每个变量的数据来源、业务含义和使用场景。页面位于 `variable-guide/views/variable-guide.php`，为发布规则、文章结构等模块配置提示词模板时提供参考。

## 业务逻辑
1. **主题生成变量**：
   - `CURRENT_DATE`：注入当前日期，帮助 AI 生成具备时效性的标题。
   - `LANGUAGE_NAME`：目标输出语言（如“简体中文”）。
   - `REFERENCE_CONTENT_BLOCK`：规则项的原始素材（XML 封装），支持关键词、上传文本等。
   - `EXISTING_TOPICS_BLOCK`：通过向量检索获取的已有主题列表（XML），用于实现语义去重。
   - `SITE_CATEGORIES_BLOCK`：当前站点的可用分类列表，用于 AI 智能分类建议。
2. **文章生成变量**：
   - **内容核心**：
     - `{{TITLE}}`：文章主标题。
     - `{{USER_VALUE}}`：主题关联的用户价值/动机。
     - `{{MATCHED_CATEGORY}}`：预指派的分类名称。
   - **策略与受众**：
     - `{{CONTENT_STRATEGY_BLOCK}}`：包含知识深度要求的指令集（浅层普及/深度分析等）。
     - `{{TARGET_AUDIENCE_BLOCK}}`：针对受众角色（决策者/潜在客户等）的写作建议。
     - `{{ROLE_DESCRIPTION}}`：管理员定义的 AI 角色设定（如“资深科技编辑”）。
   - **结构与指导**：
     - `{{STRUCTURE_BLOCK}}`：由 `SmartStructureSelector` 智能选定的 XML 章节大纲。
     - `{{STRUCTURE_USAGE_GUIDANCE}}`：关于如何通过 `<section>` 展开正文的逻辑引导。
   - **参考与物料**：
     - `{{REFERENCE_MATERIAL_BLOCK}}`：搜索获取的参考资料内容（XML）。
     - `{{REFERENCE_MATERIAL_STRATEGY}}`：指导 AI 如何在“以标题为纲”的前提下利用参考资料。
     - `{{INTERNAL_LINKING_INSTRUCTIONS}}`：包含相关文章标题与 URL 的内链注入指令。
   - **参数类**：
     - `{{TARGET_LENGTH}}`：限定输出字数范围（如 800-1500 字）。
     - `{{IMAGE_INSTRUCTIONS}}`：根据自动配图策略生成的配图提示词模板。
3. **变量填充引擎**：
   - 由 `ContentAuto_XmlTemplateProcessor` 统一负责。
   - 在生成提示词前，处理器会调用各服务模块（向量检索、规则管理、分类过滤器）拉取实时数据并进行 XML 转义处理。

## 使用场景
- **提示词工程调优**：通过组合不同的 `{{STRATEGY}}` 和 `{{PRINCIPLE}}` 变量，精细化控制 AI 的写作习惯。
- **差异化内容输出**：利用 `{{TARGET_AUDIENCE_BLOCK}}` 实现同一主题针对不同人群的差异化表达。
- **SEO 深度优化**：配合 `{{SEO_KEYWORDS}}` 与 `{{INTERNAL_LINKING_INSTRUCTIONS}}` 实现搜索友好度提升。

## 技术实现
- **模板处理**：`prompt-templating/class-xml-template-processor.php` 定义了所有 `{{VARIABLE}}` 到具体数据源的映射逻辑。
- **动态填充**：处理器根据 `topic_id` 实时反查数据库中的 `vector_embedding` 和 `source_angle`。
- **多语言适配**：由 `language-mappings.php` 提供各语言环境下指令的本地化输出。

## 相关文件
- `prompt-templating/class-xml-template-processor.php`（核心填充逻辑）
- `article-tasks/class-article-generator.php`（文章生成入口）
- `shared/services/class-smart-structure-selector.php`（结构变量来源）
- `variable-guide/views/variable-guide.php`（管理端文档呈现）
