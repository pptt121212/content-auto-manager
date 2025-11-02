# 任务队列（Job Queue）

## 功能概述
任务队列模块负责调度和执行所有后台任务，包括主题任务、文章任务和向量生成任务。核心类 `ContentAuto_JobQueue` 位于 `shared/queue/class-job-queue.php`，与 WordPress Cron 协同工作，实现任务的串行执行与失败恢复。

## 业务逻辑
1. **任务入队**：
   - 主题任务和文章任务创建后，会调用 `ContentAuto_JobQueue::add_job()`（或内部方法）将任务写入 `content_auto_job_queue` 数据表。
   - 任务记录包含 `job_type`, `payload`, `priority`, `status`, `retry_count`, `last_error` 等字段。
2. **任务调度**：
   - `content_auto_manager_start_queue_processor()` 在插件初始化时注册 `content_auto_manager_process_queue` Cron 事件，每分钟运行。
   - `ContentAuto_JobQueue::process_next_job()` 从队列中取出一条待处理任务，根据类型分发给相应处理器：
     - `topic_task` → `ContentAuto_TopicTaskManager`
     - `article` → `ContentAuto_ArticleQueueProcessor`
     - `vector_generation` → `ContentAuto_VectorGenerator`
   - 若队列为空，则调用 `process_simple_topic_task()` 直接执行待处理主题任务，提升效率。
3. **锁机制**：
   - 使用 WordPress Transient 实现全局任务锁与子任务锁，防止并发执行：
     - `content_auto_global_task_lock`
     - `content_auto_global_subtask_lock`
   - 锁超时时间由常量 `CONTENT_AUTO_QUEUE_LOCK_TIMEOUT` 控制。
4. **失败重试与恢复**：
   - 任务失败时记录错误信息，增加 `retry_count`，遵循指数退避策略延迟重试。
   - 主题任务和文章任务分别由各自的恢复处理器（`ContentAuto_TaskRecoveryHandler`、`ContentAuto_ArticleTaskTimeoutHandler`）定时检查卡顿任务并重新入队。
5. **向量生成调度**：
   - 队列在空闲时调用 `start_vector_generation_scheduler()` 启动向量生成任务，维持主题向量与品牌向量的同步。

## 使用场景
- 自动化流水线：主题生成 → 文章生成 → 向量更新，全流程由任务队列驱动。
- 高峰保护：通过串行执行控制API调用频率，避免触发限流。
- 异常恢复：任务失败后自动重试，减少人工介入。
- 扩展任务：可向队列新增自定义任务类型（如图片生成、外链推送等）。

## 技术实现
- 数据表：`content_auto_job_queue`，字段包括 `id`, `job_type`, `payload`, `status`, `priority`, `retry_count`, `scheduled_at`, `locked_at` 等。
- 核心方法：
  - `process_next_job()`：取出并执行下一个任务。
  - `execute_job()`：根据类型调度具体处理器。
  - `schedule_retry()`：失败后重新安排任务。
  - `cleanup_stale_locks()`：清理过期锁。
- 常量：
  - `CONTENT_AUTO_SUBTASK_INTERVAL`（子任务间隔）
  - `CONTENT_AUTO_MAX_JOBS_PER_RUN`（单次Cron处理任务上限）
  - `CONTENT_AUTO_QUEUE_LOCK_TIMEOUT`（锁超时时间）
- 集成：任务处理器位于 `topic-management/`、`article-tasks/`、`shared/services/` 等目录，通过依赖注入在构造函数中初始化。

## 相关文件
- `shared/queue/class-job-queue.php`（任务队列核心）
- `content-auto-manager.php`（Cron注册与启动）
- `topic-management/class-topic-task-manager.php`（主题任务处理）
- `article-tasks/class-article-queue-processor.php`（文章任务处理）
- `shared/services/class-vector-generator.php`（向量任务处理）
- `topic-management/class-task-recovery-handler.php`（任务恢复）
- `article-tasks/class-article-task-timeout-handler.php`（超时恢复）
