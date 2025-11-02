# 主题任务（Topic Tasks）

## 功能概述
主题任务模块用于批量生成内容主题（文章标题/提纲起点），实现从规则到主题库的自动化生成。核心类 `ContentAuto_TopicTaskManager` 位于 `topic-management/class-topic-task-manager.php`，结合任务队列系统按规则逐条生成主题。

## 业务逻辑
1. **任务创建**：
   - `create_topic_task($rule_id, $topic_count_per_item)` 根据规则创建任务。
   - 校验规则是否启用、具有分类项，并生成唯一的 `topic_task_id`。
   - 计算总预期主题数量，插入 `content_auto_topic_tasks` 表。
2. **任务调度**：
   - 创建成功后调用 `add_to_queue()` 将任务写入后台队列表（`content_auto_job_queue`）。
   - 队列处理器 `ContentAuto_JobQueue::process_next_job()` 负责逐步执行任务。
3. **主题生成**：
   - 调用 `ContentAuto_TopicApiHandler` 与选定的API交互，根据规则项生成主题列表。
   - 使用 `ContentAuto_JsonParser` 解析模型返回的JSON结构，确保格式正确。
   - 解析后的主题写入 `content_auto_topics` 表，包含分类、状态信息。
4. **状态管理**：
   - `ContentAuto_TaskStatusManager` 负责更新任务状态、记录错误信息、统计进度。
   - `subtask_status` 字段以JSON保存每个规则项的执行状况。
5. **异常恢复**：
   - `ContentAuto_TaskRecoveryHandler` 定时检测卡顿或失败的子任务，并自动重试。
   - `auto_recover_hanging_tasks()` 在 `content_auto_manager_recover_tasks` cron事件中被调用。

## 使用场景
- 批量生成选题：按分类/品牌一次性生成大量主题。
- 自动化内容流水线：与文章任务配合，实现全流程自动生成。
- 定时更新：通过WP-Cron定期触发主题任务，保持主题库新鲜。
- 异常监控：当API失败时，自动重试并记录日志。

## 技术实现
- 数据表：`content_auto_topic_tasks`（任务）、`content_auto_topics`（主题列表）。
- 核心类：
  - `ContentAuto_TopicTaskManager`（任务主流程）
  - `ContentAuto_TopicApiHandler`（API通信）
  - `ContentAuto_JsonParser`（模型响应解析）
  - `ContentAuto_TaskStatusManager`（状态维护）
  - `ContentAuto_TaskRecoveryHandler`（任务恢复）
- 任务队列：通过 `shared/queue/class-job-queue.php` 调度执行。
- 前端页面：`topic-management/views/topic-jobs.php` 展示任务列表与操作按钮。

## 相关文件
- `topic-management/class-topic-task-manager.php`
- `topic-management/class-topic-api-handler.php`
- `topic-management/class-json-parser.php`
- `topic-management/class-task-status-manager.php`
- `topic-management/class-task-recovery-handler.php`
- `topic-management/views/topic-jobs.php`
- `shared/queue/class-job-queue.php`
- `shared/logging/class-logging-system.php`
