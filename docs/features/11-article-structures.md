# 文章结构（Article Structures）

## 功能概述
文章结构模块用于管理和生成文章大纲模板，为AI内容生成提供结构化指导。通过定义章节标题、内容角度（content angles）和参考文献等，确保生成内容符合预期结构。管理页面由 `ContentAuto_ArticleStructureAdminPage` 实现，位于 `article-structures/` 目录。

## 业务逻辑
1. **结构生成与管理**：
   - **AI 辅助生成**：基于内容角度（Fixed/Dynamic Angles），调用 LLM 构筑标准文章大纲。
   - **结构存储**：结果持久化于 `content_auto_article_structures` 表，存储章节、内容角度及语义向量。
2. **智能优化 (Smart Optimization)**：
   - **数据驱动提取**：`ContentAuto_StructureExtractor` 可分析已发布的“高表现文章”（基于 `_external_visit_count`），反向提取其结构特征并保存为新模板。
   - **受欢迎度指数**：`ContentAuto_PopularityCalculator` 为每个结构计算权重分值，不仅考虑平均访问量，还引入了针对文章数量的效率调整因子。
   - **多样性控制 (Diversity Control)**：`ContentAuto_DiversityController` 通过 **信息熵 (Entropy)** 监测，防止推荐算法陷入“茧房”。系统会自动对过度使用的结构施加惩罚（Penalty），并对冷门或新生成的结构进行激励（Boost）。
   - **冷启动管理**：在站点运营初期，`ContentAuto_ColdStartManager` 负责平衡随机探索与数据利用，确保新结构能获得种子流量。
3. **内容角度关联**：
   - 预设 10 个核心内容角度（如“知识科普”、“实操指导”），并支持在生成过程中动态扩展。
   - 提供动态角度清理功能，可将非核心角度关联的内容安全平移至固定角度。
4. **与文章任务联动**：
   - **智能匹配**：`ContentAuto_SmartStructureSelector` 根据分类、主题及当前多样性状态，自动为新文章匹配最合适的结构。
   - **提示词注入**：生成器将选定结构的章节要求、写作视角及受欢迎的数据指标作为上下文注入 Prompt。

## 使用场景
- **精品内容矩阵**：利用高表现结构复刻成功经验，打造内容护城河。
- **差异化写作**：通过多样性机制，确保针对同一主题的不同文章具有完全不同的叙事结构。
- **冷启动加速**：新站点快速通过 AI 生成的基础结构完成数据积累，随后平滑过渡到数据驱动阶段。

## 技术实现
- **核心类库**：
  - `ContentAuto_ArticleStructureAdminPage`：基础结构管理。
  - `ContentAuto_SmartOptimizationAdminPage`：性能监控与优化配置。
  - `shared/services/` 下的 `ArticleAnalyzer`、`StructureExtractor` 及 `SmartStructureSelector`：负责后端数据处理。
- **存储架构**：`usage_count` 实时统计使用量，`meta_key = '_article_structure_id'` 记录文章与结构的归属。
- **性能优化**：支持手动或通过任务调度执行批量分析，利用缓存（Transients）降低数据库压力。

## 相关文件
- `article-structures/class-article-structure-admin-page.php`（结构管理）
- `article-structures/class-smart-optimization-admin-page.php`（智能优化管理）
- `shared/services/class-structure-extractor.php`（特征提取服务）
- `shared/services/class-smart-structure-selector.php`（智能匹配调度）
- `shared/services/class-diversity-controller.php`（多样性均衡算法）
- `shared/services/class-popularity-calculator.php`（受欢迎度算法）
