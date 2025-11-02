# Bug Fix: 关键词研究工具按钮无法点击

## 问题描述

关键词研究工具页面的所有按钮（开始挖掘、一键复制、清空等）无法点击，事件绑定失效。

## 根本原因

在 `shared/admin/class-admin-menu.php` 的 `enqueue_admin_scripts()` 方法中，使用了精确的 hook 名称匹配来加载页面特定的脚本和样式：

```php
if ($hook == 'content-auto-manager_page_content-auto-manager-keyword-tool') {
    // 加载关键词工具的脚本
}
```

但是，由于 `override_menu_titles()` 方法动态修改了菜单标题，WordPress 生成的实际 hook 名称可能与预期不同，导致条件判断失败，脚本未被加载，从而导致按钮事件未绑定。

## 解决方案

将所有精确的 hook 名称匹配改为使用 `strpos()` 进行灵活匹配，只检查是否包含子菜单的 slug：

```php
// 修改前
if ($hook == 'content-auto-manager_page_content-auto-manager-keyword-tool') {

// 修改后
if (strpos($hook, 'content-auto-manager-keyword-tool') !== false) {
```

这样无论 WordPress 如何生成 hook 名称（基于菜单标题的变化），只要包含子菜单的 slug，就能正确匹配并加载相应的脚本。

## 修改的文件

- `shared/admin/class-admin-menu.php`

## 受影响的页面

为了保持一致性并防止类似问题在其他页面出现，以下页面的 hook 检查都已更新：

1. **关键词工具** - `content-auto-manager-keyword-tool`（主要问题页面）
2. **规则管理** - `content-auto-manager-rules`
3. **主题任务** - `content-auto-manager-topic-jobs`
4. **主题管理** - `content-auto-manager-topics`
5. **文章任务** - `content-auto-manager-article-tasks`
6. **调试工具** - `content-auto-manager-debug-tools`
7. **变量说明** - `content-auto-manager-variable-guide`
8. **API设置** - `content-auto-manager-api`

## 额外优化

移除了规则管理页面中重复注册的 `content-auto-manager-admin-js` 脚本，因为该脚本已在所有插件页面上统一加载。

## 测试建议

1. 访问关键词研究工具页面
2. 验证"开始挖掘"按钮可以点击
3. 验证"一键复制"和"清空"按钮可以点击
4. 在浏览器控制台检查是否有 JavaScript 脚本加载
5. 验证其他管理页面的功能是否正常
