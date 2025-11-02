# 向量聚类（Vector Clustering）

## 功能概述
向量聚类模块负责对主题的语义向量进行聚类分析，用于提高主题检索、自动分类和相似度匹配的准确性。管理页面位于 `admin/class-clustering-admin-page.php`，核心算法在 `shared/content-processing/` 目录实现。

## 业务逻辑
1. **聚类管理页面**：
   - `ContentAuto_ClusteringAdminPage::render_page()` 提供聚类训练入口和相似标题调试工具。
   - 页面显示当前向量数量，并根据数量自动计算推荐的聚类数（每100个向量约一个簇）。
2. **聚类流程**：
   - 点击“开始生成/重新校准所有聚类”后，调用 `class-vector-clustering.php` 中的K-Means算法。
   - 流程步骤：读取所有向量 → 解码 → 初始化聚类中心 → 迭代计算 → 更新每个主题的簇ID。
   - 处理完成后，将聚类中心保存到数据库供增量分类使用。
3. **相似检索**：
   - 页面提供“相似标题调试工具”，输入文章ID后计算余弦相似度，找出最相似的20个主题。
   - 依赖共享服务 `class-vector-search-service.php` 实现向量相似度计算。
4. **后台增量聚类**：
   - 新增主题向量时，后台任务使用 `ContentAuto_IncrementalClustering` 将其分配到最近簇，保持聚类效果。

## 使用场景
- 新系统冷启动：积累足够向量后执行第一次聚类，为后续检索提供基线。
- 定期校准：每隔数周或主题量显著增加时重新训练，确保中心精准。
- 主题相似度分析：快速定位重复或相似主题，辅助去重。
- 调试评估：通过相似标题列表检验聚类与向量效果。

## 技术实现
- 算法实现：`shared/content-processing/class-vector-clustering.php` 使用K-Means聚类，支持多次迭代和随机初始化。
- 数据来源：`content_auto_topics.vector_embedding` 字段存储Base64编码的向量。
- 性能优化：聚类前设置较高的执行时间和内存限制（2小时/1GB）。
- 安全验证：提交聚类请求时，通过 `wp_verify_nonce` 校验表单合法性。
- 辅助服务：`shared/services/class-incremental-clustering.php` 负责增量向量处理。

## 相关文件
- `admin/class-clustering-admin-page.php`（管理页面）
- `shared/content-processing/class-vector-clustering.php`（聚类算法）
- `shared/services/class-incremental-clustering.php`（增量聚类服务）
- `shared/services/class-vector-search-service.php`（相似度检索）
- `shared/logging/class-logging-system.php`（日志输出）
- `shared/database/class-database.php`（数据库操作）
