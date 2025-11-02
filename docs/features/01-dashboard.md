# 仪表盘（Dashboard）

## 功能概述
仪表盘是插件的中枢页面，用于展示内容生产系统的总体运行情况。页面在 `dashboard/views/enhanced-dashboard.php` 中实现，汇总任务队列、主题与文章生成、向量聚类以及分类缓存等关键指标，帮助运营人员快速了解系统健康度与产出效率。

## 业务逻辑
1. **统计聚合**：
   - 调用 `ContentAuto_Database::get_dashboard_stats()` 获取主题、文章、系统运行等统计数据。
   - 通过 `ContentAuto_JobQueue::get_queue_status()` 和 `get_vector_generation_stats()` 获取任务队列与向量生成情况。
2. **分类向量缓存管理**：
   - 提供刷新分类向量缓存的操作，提交后触发 `content_auto_refresh_category_cache()` 重新计算最子级分类的向量信息。
   - 统计分类覆盖率、主题匹配率等数据，辅助评估分类体系质量。
3. **任务监控**：
   - 展示任务处理速度、失败重试情况、手动主题匹配状态等信息。
   - 调用 `ContentAuto_Category_Filter`（若启用）筛选分类，计算叶子分类数量。
4. **可视化展示**：
   - 通过页面内置的卡片、图表展示每日产出、成功率、待处理队列等信息。

## 使用场景
- 日常运营巡检：了解今日产出、成功率、任务积压情况。
- 故障排查：发现任务处理阻塞、分类向量计算失败等问题。
- 策略优化：根据成功率、主题利用率评估规则配置是否需要调整。

## 技术实现
- 页面使用原生 PHP 模板渲染，基于 WordPress 管理后台的标准 UI。
- 数据来自数据库封装层（`shared/database`）与任务队列服务（`shared/queue`）。
- 刷新分类缓存时通过 `wp_verify_nonce` 校验安全性，调用共享服务完成实际计算。
- 使用 `wp_enqueue_style` 在页面加载增强样式 `dashboard/assets/css/enhanced-dashboard.css`。

## 相关文件
- `dashboard/views/enhanced-dashboard.php`
- `shared/database/class-database.php`（统计数据来源）
- `shared/queue/class-job-queue.php`（队列状态接口）
- `publish-settings/class-category-filter.php`（分类筛选辅助）
