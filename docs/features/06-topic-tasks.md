# 主题任务（Topic Tasks）

## 功能概述
主题任务模块用于批量生成内容主题（文章标题/提纲起点），实现从规则到主题库的自动化生成。核心类 `ContentAuto_TopicTaskManager` 位于 `topic-management/class-topic-task-manager.php`，结合任务队列系统按规则逐条生成主题。

## 业务逻辑
1. **并行任务架构**：
   - 主题任务采用分段（Subtask）执行模式。一个任务被拆分为多个子任务（对应规则中的每一个 `rule_item`）。
   - 核心调度器 `ContentAuto_TopicTaskManager` 协调子任务的分发与汇总。
2. **生成与解析链路**：
   - 调用 `ContentAuto_TopicApiHandler` 执行 API 请求。
   - 使用 `ContentAuto_JsonParser` 对 AI 返回的非结构化或半结构化 JSON 进行强效解析（处理 Markdown 块、异常引号等问题）。
   - 解析出的字段包括：标题（Title）、创作视角（Angle）、SEO 关键词、用户价值（User Value）以及推荐分类。
3. **状态追溯与进度控制**：
   - `subtask_results` 字段详细记录每一段执行的成功/失败情况及原始响应。
   - `ContentAuto_TaskStatusManager` 实时计算当前任务的整体完成百分比。
4. **异常恢复（Self-Healing）**：
   - `ContentAuto_TaskRecoveryHandler` 监听超过 10 分钟无进度的"僵尸"子任务。
   - 自动检测并重置处于 `processing` 状态但未实际执行的任务，确保队列流通。
5. **任务级联策略**：
   - 任务完成后，主题将带有 `matched_category`（AI 推荐）。
   - 支持设置任务优先级，影响后续文章生成任务的执行顺序。

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
