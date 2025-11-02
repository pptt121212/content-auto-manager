# 关键词工具（Keyword Tool）

## 功能概述
关键词工具用于辅助选题与SEO优化，基于多个搜索引擎的联想接口对输入关键词进行扩展，输出可用于主题任务与文章生成的关键词列表。界面定义在 `keyword-research-tool/keyword-research-admin-page.php`，AJAX逻辑在 `ajax-handler.php` 中实现。

## 业务逻辑
1. **关键词挖掘流程**：
   - 用户输入基础关键词并选择数据源（谷歌、YouTube、Baidu、购物、DuckDuckGo等）。
   - 前端通过 `admin-ajax.php` 调用 `content_auto_keyword_research`，后端聚合多个API返回。
   - 结果按数据源分组展示，支持复制、导出等操作。
2. **多数据源支持**：
   - `free_keyword_apis.php` 内定义了多个免费API的请求实现，如Google Suggest、YouTube Suggest、Baidu Suggest等。
   - `BaiduSuggestion` 类对百度联想词接口做了特殊处理，保证中文关键词效果。
3. **语言与地区选择**：
   - 页面提供语言/地区下拉框，传入参数影响API请求语言或市场（如 `us-en`、`cn-zh-CN`）。
4. **缓存与限速控制**：
   - `ajax-handler.php` 中对相同请求进行短期缓存，避免过快重复调用触发API限制。
   - 对单次请求的关键词数量进行限制，并对超时和错误进行容错处理。

## 使用场景
- SEO策略制定：在创建规则前先挖掘长尾关键词，形成主题列表。
- 内容选题：结合搜索引擎联想词找到热门话题，输入到主题任务模块。
- 视频/购物内容策划：使用YouTube或购物数据源获取垂直领域关键词。
- 竞品研究：针对特定地区市场，分析热门搜索词。

## 技术实现
- 前端使用WordPress后台标准UI，配合 `keyword-research-tool/assets/js/keyword-research.js` 处理交互。
- AJAX接口 `content_auto_keyword_research` 在 `keyword-research-tool/ajax-handler.php` 中定义。
- 通过 `wp_remote_get` 向各个外部API发起HTTP请求，返回JSON或JSONP数据。
- 结果统一整理为数组，按字母排序后发送给前端渲染。

## 相关文件
- `keyword-research-tool/keyword-research-admin-page.php`（页面模板）
- `keyword-research-tool/ajax-handler.php`（AJAX逻辑）
- `keyword-research-tool/free_keyword_apis.php`（数据源实现）
- `keyword-research-tool/BaiduSuggestion.php`（百度联想词支持）
- `keyword-research-tool/assets/js/keyword-research.js`（前端脚本）
- `keyword-research-tool/assets/css/keyword-research.css`（样式）
