# 文章任务（Article Tasks）

## 功能概述
文章任务模块用于将主题库中的主题转化为完整文章，实现从标题到正文内容的自动化生成。核心类 `ContentAuto_ArticleTaskManager` 位于 `article-tasks/class-article-task-manager.php`，通过任务队列逐篇生成文章内容。

## 业务逻辑
1. **任务创建**：
   - `create_article_task($topic_ids, $name)` 接收主题ID数组，创建文章生成任务。
   - 验证主题存在性，生成唯一 `article_task_id`，插入 `content_auto_article_tasks` 表。
   - 使用 `topic_ids` 字段（JSON数组）记录需要处理的所有主题。
2. **任务调度**：
   - 任务添加到 `content_auto_job_queue` 表，由 `ContentAuto_JobQueue` 调度执行。
   - 每次处理一个主题，生成完成后更新进度统计（`completed_topics`, `failed_topics`）。
3. **文章生成流程**：
   - 调用 `ContentAuto_ArticleGenerator` 根据主题标题、规则配置、发布规则生成正文内容。
   - 使用 `ContentAuto_ArticleApiHandler` 与AI API交互，获取文章内容。
   - 解析模型返回的内容，处理 HTML/Markdown 格式，提取特色图片描述。
4. **文章发布**：
   - 根据发布规则（`content_auto_publish_rules`）将文章插入WordPress文章表（`wp_posts`）。
   - 设置文章标题、内容、分类、标签、作者、发布状态（草稿/发布）等。
   - 如启用自动配图，调用图像API生成特色图片并附加到文章。
5. **状态管理与恢复**：
   - `ContentAuto_TaskStatusManager` 维护任务状态、记录错误信息。
   - `ContentAuto_ArticleTaskTimeoutHandler` 定时检测超时任务并自动恢复。
   - `class-article-queue-processor.php` 负责队列调度逻辑与子任务管理。
6. **性能监控**：
   - `ContentAuto_ArticlePerformanceMonitor` 记录每个环节的耗时、成功率、错误类型。

## 使用场景
- 批量文章生成：选中一批主题，一键生成完整文章。
- 自动化发布流水线：从主题生成到文章发布全流程自动化。
- 草稿预览：生成文章后先保存为草稿，人工审核后发布。
- 异常恢复：API超时或失败时，自动重试直到成功。

## 技术实现
- 数据表：
  - `content_auto_article_tasks`（任务）
  - `content_auto_articles`（生成的文章内容）
  - `wp_posts`（WordPress文章表）
- 核心类：
  - `ContentAuto_ArticleTaskManager`（任务管理）
  - `ContentAuto_ArticleGenerator`（文章生成逻辑）
  - `ContentAuto_ArticleApiHandler`（API通信）
  - `ContentAuto_ArticleQueueProcessor`（队列处理器）
  - `ContentAuto_ArticleTaskTimeoutHandler`（超时恢复）
  - `ContentAuto_ArticlePerformanceMonitor`（性能监控）
- 队列调度：通过 `shared/queue/class-job-queue.php` 调度执行。
- 前端页面：`article-tasks/views/article-tasks-list.php` 展示任务列表与详情。

## 相关文件
- `article-tasks/class-article-task-manager.php`
- `article-tasks/class-article-generator.php`
- `article-tasks/class-article-api-handler.php`
- `article-tasks/class-article-queue-processor.php`
- `article-tasks/class-article-task-timeout-handler.php`
- `article-tasks/class-article-performance-monitor.php`
- `article-tasks/views/article-tasks-list.php`
- `shared/queue/class-job-queue.php`
- `publish-settings/class-publish-settings-admin.php`（发布规则）
