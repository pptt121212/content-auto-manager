# 发布规则（Publish Rules）

## 功能概述
发布规则模块定义文章生成后的发布策略，包括发布状态、分类归属、标签设定、特色图片、自动配图、站点品牌信息等。页面位于 `publish-settings/views/publish-rules.php`，后台逻辑在 `class-publish-settings-admin.php` 与 `class-category-filter.php` 中实现。

## 业务逻辑
1. **基础发布设置**：
   - **文章状态与间隔**：支持“立即发布”或“草稿”。启用发布状态时，可设置 `publish_interval_minutes`（发布间隔），系统根据最近发布时间自动计算并排程。
   - **默认作者与语言**：指定文章的发布账号及输出语言（中/英/繁等）。
2. **内容策略与增强**：
   - **字数与深度控制**：提供从 300 到 4000 字的长度选择，并支持设置内容深度（如“浅层普及”、“深度分析”），影响 AI 的写作风格。
   - **目标受众定位**：可针对潜在客户、决策者等不同角色定制内容侧重点。
   - **搜索意图推断**：开启后，AI 在生成前会推导用户的搜索意图，生成更符合 SEO 逻辑的标题。
3. **自动化集成选项**：
   - **自动配图 (Auto Image)**：控制配图的最大数量、风格，并支持“忽略首段落图片”以优化首屏视觉。
   - **品牌资料植入**：根据文章主题自动匹配相似度最高的品牌资料，并插入指定位置（第二段前或文末）。
   - **文章内链**：自动在文中融入已发布的、具有语义相关性的文章链接。
4. **参考物料逻辑**：
   - **多源召回**：支持网络搜索（Search Engine）和知识库搜索（Browser Extension RAG）。
   - **大模型精选 (AI Rerank)**：当相似度较低或候选物料较多时，调用 LLM 对前 10 条召回结果进行二次筛选，确保参考资料的相关性。
5. **分类管理与映射**：
   - **策略选择**：支持“手动选择”固定分类或“自动选择”（基于向量相似度匹配最相关的分类）。
   - **分类过滤**：通过 `ContentAuto_Category_Filter` 限制插件可访问的分类范围，提升管理安全性。
   - **分类缓存**：通过 `content_auto_refresh_category_cache()` 为所有分类生成向量索引。

## 使用场景
- **定时定量发布**：通过发布间隔实现站点的平稳更新。
- **SEO 精准投放**：利用搜索意图推断和内链功能提升搜索排名。
- **差异化内容生产**：针对不同受众（决策者 vs 泛流量）生成差异风格的文案。
- **知识库自动化**：利用浏览器插件 RAG 功能，将本地知识库内容自动转化为博文。

## 技术实现
- **存储架构**：规则持久化于 `content_auto_publish_rules` 数据库表。
- **向量匹配**：分类自动选择及内链功能依赖 `ContentAuto_Vector_Generator` 提供的语义计算能力。
- **任务分发**：发布间隔由 `class-article-generator.php` 在执行发布动作前计算 `post_date` 实现。
- **安全验证**：集成 `ContentAuto_License_Manager` 以确保高级功能（如 AI 精选、RAG 搜索）仅在授权状态下可用。

## 相关文件
- `publish-settings/views/publish-rules.php`（管理界面）
- `publish-settings/class-category-filter.php`（分类过滤逻辑）
- `article-tasks/class-article-generator.php`（规则执行引擎）
- `shared/services/class-vector-generator.php`（向量映射支持）
- `includes/class-license-manager.php`（功能授权管控）
