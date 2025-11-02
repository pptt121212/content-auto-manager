# 主题管理（Topic Management）

## 功能概述
主题管理页面用于查看、搜索、编辑、删除已生成的主题列表，是主题库的可视化管理界面。页面位于 `topic-management/views/topics-list.php`，展示所有存储在 `content_auto_topics` 表中的主题。

## 业务逻辑
1. **主题列表展示**：
   - 分页展示所有主题，包括：主题标题、分类、状态（已用/未用）、创建时间等。
   - 支持按分类、规则ID、状态等筛选查询。
2. **主题状态管理**：
   - 每个主题具有 `is_used` 字段（0=未使用、1=已生成文章）。
   - 文章任务完成后，对应主题自动标记为"已用"，避免重复使用。
3. **主题搜索**：
   - 支持关键词搜索主题标题，前端通过AJAX调用 `content_auto_manager_search_articles`。
4. **主题编辑与删除**：
   - 管理员可手动修改主题标题、调整分类或删除不合适的主题。
   - 删除主题时同时删除其向量数据和聚类关联。
5. **手工主题添加**：
   - 在某些情况下，用户可手工添加主题（rule_id=0），这些主题需人工匹配分类或通过向量聚类自动分类。

## 使用场景
- 内容审核：在生成文章前审核主题质量，删除不符合要求的主题。
- 主题补充：手动添加编辑未覆盖的热门话题。
- 分类调整：迁移主题到更合适的分类。
- 数据清理：删除过时或无效的主题记录。

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
