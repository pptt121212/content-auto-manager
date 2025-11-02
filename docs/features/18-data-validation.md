# 数据一致性验证（Data Validation）

## 功能概述
数据一致性验证模块用于检查插件运行过程中数据的正确性和完整性，确保任务状态、配置参数、规则项目等数据符合业务规则。核心类 `ContentAuto_DataValidator` 位于 `shared/services/class-data-validator.php`，配合 `ContentAuto_DatabaseWrapper` 提供多层验证保障。

## 业务逻辑
1. **字段验证规则**：
   - `validation_rules` 属性定义了各字段的验证规则，包括：
     - 数据类型（integer/string/array）
     - 必填性（required）
     - 取值范围（min/max/enum）
     - 长度限制（max_length）
   - 支持常见字段如 `task_id`, `status`, `progress`, `error_message` 等。
2. **验证流程**：
   - `validate_field($field_name, $field_value, $rules)` 验证单个字段是否符合规则。
   - 必填验证 → 类型验证 → 范围验证 → 枚举验证 → 长度验证。
   - 返回错误数组，空数组表示通过。
3. **批量验证**：
   - `validate_data($data, $fields)` 验证多个字段组合，适用于表单提交、API调用等场景。
4. **与数据库集成**：
   - `ContentAuto_DatabaseWrapper::validate_insert_data()` 在插入前校验数据完整性。
   - 配合 `ContentAuto_DataValidator` 在写入层面阻止无效数据进入数据库。
5. **状态一致性检查**（可扩展）：
   - 检查任务状态是否合法（pending → processing → completed/failed）。
   - 检查规则是否在使用中时被修改。
   - 检查队列任务的payload合法性。

## 使用场景
- 任务创建：验证 `topic_count`, `rule_id` 等参数是否合法。
- 状态更新：确保状态转换符合流程（如不能从completed回到pending）。
- 配置提交：验证API密钥长度、URL格式、模型名称等。
- 数据导入：批量导入规则或主题时校验数据格式。

## 技术实现
- 验证规则定义：使用数组存储验证规则，便于扩展。
- 错误收集：验证失败时返回错误信息数组，支持多错误同时提示。
- 类型校验：使用 `is_int()`, `is_string()`, `is_array()` 进行PHP类型检查。
- 枚举校验：通过 `in_array()` 检查值是否在允许范围内。
- 集成点：在 `ContentAuto_Database` 和业务管理类（如 `ContentAuto_RuleManager`, `ContentAuto_TopicTaskManager`）中调用。

## 相关文件
- `shared/services/class-data-validator.php`（验证器核心）
- `shared/database/class-database-wrapper.php`（数据库写入层验证）
- `shared/database/class-database.php`（数据库操作）
- `rule-management/class-rule-handler.php`（表单验证示例）
- `topic-management/class-topic-task-manager.php`（任务创建验证）
- `article-tasks/class-article-task-manager.php`（任务创建验证）
