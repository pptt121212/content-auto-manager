# 任务队列（Job Queue）

## 功能概述
任务队列模块负责调度和执行所有后台任务，包括主题任务、文章任务和向量生成任务。核心类 `ContentAuto_JobQueue` 位于 `shared/queue/class-job-queue.php`，与 WordPress Cron 协同工作，实现任务的串行执行与失败恢复。

## 业务逻辑
1. **严格串行调度 (Strict Serialization)**：
   - **单发模式**：为了确保高并发下的稳定性，`process_next_job()` 每次 Cron 运行仅处理 **一个** Pending 任务（`max_jobs_per_run = 1`）。
   - **多重锁定**：通过 `acquire_global_task_lock`（全局处理器锁）和 `acquire_global_subtask_lock`（子任务原子锁）实现双层并发保护，防止任务抢跑。
2. **任务类型分发**：
   - `topic_task`：主题生成任务。
   - `article`：文章生成子任务。
   - `vector_generation`：向量化任务。
   - **material_search**：素材/参考资料搜索任务。
3. **素材搜索详解 (Material Search)**：
   - **模式识别**：支持 `extension_rag`（浏览器插件知识库）与 `search_engine`（搜索引擎）两种模式。
   - **异步挂起**：插件模式下，任务返回 `waiting` 状态。此时队列状态保持为 `processing` 但不阻塞后续 Cron 的安全检测，直到 `cam_extension_task_completed` 动作触发回调闭环。
   - **超时收割机制**：系统会对 `material_search` 任务执行 **300秒 (5分钟)** 的强制超时检查。若超过此阈值，系统会通过“收割逻辑”将任务标记为失败并释放队列，防止僵尸任务永久占用。
4. **数据完整性与自愈**：
   - **完整性验证**：`verify_queue_data_integrity()` 定期检查孤立任务（无对应文章 ID）或非法引用。
   - **自愈功能**：`fix_queue_data_integrity()` 可自动删除重复队列项及已失效的临时记录。
5. **失败重试策略**：
   - 记录详尽的 `error_message`。
   - 结合 `ContentAuto_TaskRecoveryHandler` 实现对卡死在 `processing` 状态任务的平滑迁移。

## 使用场景
- **异步 RAG 集成**：利用插件模式将耗周期的知识库检索任务放入队列，确保主线程不阻塞。
- **采集频率管控**：通过严格串行机制，自然控制对各搜素引擎或 AI 接口的请求频率。
- **无人值守运行**：依靠超时收割与自动重试，确保持续生产不受单点异常影响。

## 技术实现
- **核心类库**：`ContentAuto_JobQueue` 控制调度流。
- **关键钩子**：`cam_extension_task_completed` 连接浏览器插件与 WordPress 后端队列。
- **锁配置**：`CONTENT_AUTO_QUEUE_LOCK_TIMEOUT` 定义锁生命周期，默认配合 300s 保护。
- **存储架构**：`content_auto_job_queue` 表具备 `reference_id` 语义化字段，用于精确关联主题资源。

## 相关文件
- `shared/queue/class-job-queue.php`（核心引擎）
- `article-tasks/class-article-queue-processor.php`（文章子任务分发）
- `shared/services/class-vector-generator.php`（向量生成器）
- `search-materials/class-search-materials-service.php`（搜索引擎同步实现）
- `topic-management/class-task-recovery-handler.php`（任务状态监控）
