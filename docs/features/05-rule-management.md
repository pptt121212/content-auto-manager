# 规则管理（Rule Management）

## 功能概述
规则管理模块定义内容生产的核心规则，包括主题生成规则和文章生成规则。每条规则指定使用哪个API配置、生成哪种类型的内容、以及每次生成多少主题/文章。该模块是整个自动化流程的配置中枢，位于 `rule-management/` 目录。

## 业务逻辑
1. **规则创建与编辑**：
   - 通过 `ContentAuto_RuleManager` 完成规则的增删改查。
   - 规则包含：规则名称、类型（topic/article）、API配置选择、生成数量、分类选择等。
   - 规则创建后存储在 `content_auto_rules` 和 `content_auto_rule_items` 表中。
2. **规则类型**：
   - **主题规则（topic）**：定义如何为指定分类批量生成主题标题。
   - **文章规则（article）**：定义如何从主题生成完整文章正文。
3. **规则锁定机制**：
   - 调用 `is_rule_in_use()` 检查规则是否正被任务使用。
   - 正在使用的规则无法修改或删除，避免任务执行出错。
4. **规则验证**：
   - `validate_rule_data()` 确保必填字段完整（如规则名称、API配置ID等）。
   - `class-rule-params.php` 提供参数封装与验证辅助。
5. **关联数据管理**：
   - `class-rule-handler.php` 处理前端提交，协调规则与分类的多对多关系。
   - 删除规则时级联删除 `content_auto_rule_items`。

## 使用场景
- 新站启动：创建第一条主题规则和文章规则，自动产出首批内容。
- 分类策略：针对不同分类配置不同API和生成策略（高质量vs高产量）。
- API切换：规则修改时，更换API配置实现服务商迁移。
- 批量调整：暂停或删除不符合要求的规则，快速调整策略。

## 技术实现
- 数据表：`content_auto_rules`（规则主表）、`content_auto_rule_items`（规则与分类关系表）。
- 核心类：
  - `ContentAuto_RuleManager`：规则管理逻辑。
  - `ContentAuto_RuleHandler`：前端表单处理与验证。
  - `ContentAuto_RuleParams`：参数封装类。
- 界面：
  - `rule-management/views/rules-list.php`（规则列表）
  - `rule-management/views/rule-management.php`（规则编辑表单）
- JavaScript：`rule-management/assets/js/rule-management.js` 处理AJAX和表单交互。

## 相关文件
- `rule-management/class-rule-manager.php`（规则管理核心）
- `rule-management/class-rule-handler.php`（表单处理）
- `rule-management/class-rule-params.php`（参数封装）
- `rule-management/views/rules-list.php`（规则列表页）
- `rule-management/views/rule-management.php`（规则编辑页）
- `rule-management/assets/js/rule-management.js`（前端交互）
- `shared/database/class-database.php`（数据库操作）
