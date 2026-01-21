# 主题管理（Topic Management）

## 功能概述
主题管理页面用于查看、搜索、编辑、删除已生成的主题列表，是主题库的可视化管理界面。页面位于 `topic-management/views/topics-list.php`，展示所有存储在 `content_auto_topics` 表中的主题。

## 业务逻辑
1. **主题池生命周期管理**：
   - 全局主题数据存储在 `content_auto_topics` 表。
   - 包含三种状态：`unused`（待产）、`queued`（已载入文章任务队列）、`used`（已生成文章）。
   - 自动追溯主题来源（任务 ID、规则名称、项目索引）。
2. **高级筛选与语义过滤**：
   - 提供多维筛选器：支持按标题关键词、任务 ID、分类、优先级（1-5 星）、向量状态及参考资料有无进行精准过滤。
   - 分页与实时统计：展示当前筛选条件下的记录总数。
3. **重复性检测与清洗**：
   - **标题查重**：快速检测完全相同的标题。
   - **向量查重**：基于向量相似度计算（可调阈值，如 90%），发现语义重合的主题。
   - **一键清理**：支持批量删除重复项，保留最早的一条记录。
4. **批量操作（Bulk Actions）**：
   - **生成文章**：选中多个主题一键创建文章生成任务。
   - **批量删除**：快速清理筛选后的 unused 主题。
   - **参考资料补全**：为选中的主题批量触发背景资料抓取/搜索。
5. **智能归类与向量化**：
   - 主题生成后自动参与向量化（Vectorization），关联 `vector_embedding`。
   - 支持"召回测试"：输入一段话，测试主题库中语义最接近的项（相似度度量）。
6. **手工主题维护**：
   - 支持通过 AJAX `ajax-manual-add-handler.php` 手动录入主题。手工主题通常标记为 `task_id=0`，可通过向量分析自动匹配分类。

## 使用场景
- **选题库优化**：使用向量查重功能删除 1000 个长尾词中 15% 的语义重复项，节省生成成本。
- **精品内容筛选**：按 5 星优先级筛选主题，优先生成高质量文章。
- **分类纠偏**：通过"推荐分类"列查看 AI 的归类逻辑，手动纠正不准确的分类。
- **素材加持**：对选中的核心主题批量点击"生成参考资料"，确保生成文章有据可依。

## 技术实现
- 数据表：`content_auto_topics`（主题表），包含 `topic`, `category`, `rule_id`, `is_used`, `matched_category` 等字段。
- 主题向量：当启用向量聚类功能时，主题会关联向量表 `content_auto_topic_vectors`。
- 前端交互：页面使用标准WordPress管理UI，通过表单或AJAX实现编辑、删除等操作。
- 筛选与分页：使用WordPress `WP_Query` 或直接SQL查询实现分类筛选与分页。

## 相关文件
- `topic-management/views/topics-list.php`（主题列表页面）
- `shared/database/class-database.php`（数据库操作）
- `topic-management/assets/css/topic-management.css`（样式）
- `shared/services/class-vector-service.php`（向量相关）
