# 数据库服务（Database Services）

## 功能概述
数据库服务模块封装了插件与WordPress数据库的所有交互逻辑，提供统一的增删改查接口，并负责创建和维护插件自定义数据表。核心类 `ContentAuto_Database` 位于 `shared/database/class-database.php`，为整个插件提供数据持久化能力。

## 业务逻辑
1. **数据表创建**：
   - `create_tables()` 在插件激活时被调用，创建所有必需的数据表：
     - `content_auto_api_configs`：大模型API配置
     - `content_auto_rules`：内容生产规则
     - `content_auto_rule_items`：规则项目（子规则）
     - `content_auto_topics`：主题库
     - `content_auto_topic_tasks`：主题生成任务
     - `content_auto_articles`：文章内容
     - `content_auto_article_tasks`：文章生成任务
     - `content_auto_job_queue`：任务队列
     - `content_auto_publish_rules`：发布规则
     - `content_auto_article_structures`：文章结构模板
     - `content_auto_brand_profiles`：品牌资料
     - 其他辅助表（外部访问统计、日志等）
   - 使用 `dbDelta()` 实现幂等创建，支持升级场景。
2. **CRUD操作**：
   - `insert($table, $data)`：插入记录，返回新记录ID。
   - `update($table, $data, $where)`：更新符合条件的记录。
   - `delete($table, $where)`：删除符合条件的记录。
   - `get_row($table, $where)`：查询单条记录。
   - `get_results($table, $where, $order_by, $limit)`：查询多条记录。
3. **统计查询**：
   - `get_dashboard_stats()`：聚合统计信息，供仪表盘展示。
   - 查询任务进度、成功率、待处理数量等衍生数据。
4. **数据校验与清理**：
   - `ContentAuto_DatabaseWrapper` 提供额外的数据验证层，确保写入数据符合字段定义。
   - 配合 `ContentAuto_DataValidator` 进行跨表数据一致性检查。
5. **SQL转义与安全**：
   - 所有查询使用 `$wpdb->prepare()` 进行参数化，防止SQL注入。
   - 使用 `sanitize_text_field()` 和 `wp_kses_post()` 清理用户输入。

## 使用场景
- 插件安装/升级：通过 `create_tables()` 初始化数据库结构。
- 数据持久化：所有业务模块（规则、任务、主题、文章等）通过Database类读写数据。
- 统计分析：仪表盘通过 `get_dashboard_stats()` 获取系统整体运行情况。
- 数据迁移：使用封装的CRUD接口批量导入导出数据。

## 技术实现
- 数据表前缀：使用 `$wpdb->prefix`（默认 `wp_`）拼接插件表名，兼容多站点环境。
- 字符集与校对：通过 `$wpdb->get_charset_collate()` 获取当前WordPress数据库字符集。
- 事务支持：关键操作可通过 `$wpdb->query('START TRANSACTION')` 包装，确保原子性。
- 错误处理：失败时返回 `false` 或记录错误日志，供上层调用者处理。
- 性能优化：
  - 在高频查询字段上创建索引（如 `rule_id`, `status`, `is_active`）。
  - 使用 `LIMIT` 控制查询结果集大小。

## 相关文件
- `shared/database/class-database.php`（核心数据库类）
- `shared/database/class-database-wrapper.php`（数据验证包装层）
- `shared/services/class-data-validator.php`（数据一致性验证）
- `content-auto-manager.php`（插件激活时调用 `create_tables()`）
- 各功能模块的管理类（依赖Database类进行数据操作）
