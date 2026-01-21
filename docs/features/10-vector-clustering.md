# 向量聚类（Vector Clustering）

## 功能概述
向量聚类模块负责对主题的语义向量进行聚类分析，用于提高主题检索、自动分类和相似度匹配的准确性。管理页面位于 `admin/class-clustering-admin-page.php`，核心算法在 `shared/content-processing/` 目录实现。

## 业务逻辑
1. **全量聚类管理 (Cold Start & Calibration)**：
   - **手动执行**：通过 `ContentAuto_ClusteringAdminPage` 手动触发。系统自动计算聚类数（每 100 个向量约 1 个簇，上限 100），并开启 K-Means 训练。
   - **自动调度**：`ContentAuto_VectorClusteringManager` 每小时检查一次未归类向量，若超过 100 个（阈值）则自动触发全量重聚类。
   - **黄金中心点**：计算得出的聚类中心点保存于 `wp_options` 的 `content_auto_vector_centroids` 字段，作为后续检索和增量归类的基准。
2. **增量聚类归类 (Incremental Assignment)**：
   - `ContentAuto_IncrementalClustering` 每 5 分钟运行一次。
   - 将新生成的向量（`vector_cluster_id` 为空的主题）分配到与其 **余弦距离 (Cosine Distance)** 最近的黄金中心点所属的簇中。
3. **语义相似度搜索**：
   - 核心算法位于 `shared/common/functions.php` 的 `content_auto_find_similar_titles()`。
   - **工作流**：定位查询向量最近的 N 个簇 → 在这些簇内进行精细的 **余弦相似度 (Cosine Similarity)** 计算 → 按分值排序并返回结果。
   - 设定了 0.8 的相似度阈值（针对已发布的 WordPress 文章），确保去重和检索的高相关性。
4. **调试评估工具**：
   - 提供“相似标题调试工具”，输入文章 ID 后可实时查看基于聚类优化后的相似文章列表，用于评估向量模型与聚类质量。

## 使用场景
- **数据库冷启动**：积攒数千个主题后，执行首次聚类以建立语义索引基准。
- **动态去重**：在生成新文章前，利用余弦相似度检测是否存在高度重复的已发布内容。
- **语义关联推荐**：基于向量相似度为文章自动生成内链或推荐相关阅读。
- **自动分类映射**：利用中心点相似度将新主题精准投放到最匹配的 WordPress 分类。

## 技术实现
- **核心算法**：`shared/content-processing/class-vector-clustering.php` 实现 K-Means 算法，已全面转向 **余弦度量 (Cosine Metric)**。
- **数据流转**：
  - Base64 编码向量持久化于数据库。
  - `content_auto_decompress_vector_from_base64()` 负责反序列化为浮点数组进行计算。
- **性能调度**：大规模计算时设置了 1GB 内存和 2 小时执行限制；增量任务采用轻量级批量处理（每次 100 条）。

## 相关文件
- `admin/class-clustering-admin-page.php`（管理界面）
- `shared/content-processing/class-vector-clustering.php`（K-Means 算法核心）
- `shared/common/functions.php`（相似度搜索与向量解码函数）
- `shared/services/class-vector-clustering-manager.php`（全量自动调度服务）
- `shared/services/class-incremental-clustering.php`（增量归类服务）
