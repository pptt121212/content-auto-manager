# 关键词工具（Keyword Tool）

## 功能概述
关键词工具用于辅助选题与SEO优化，基于多个搜索引擎的联想接口对输入关键词进行扩展，输出可用于主题任务与文章生成的关键词列表。界面定义在 `keyword-research-tool/keyword-research-admin-page.php`，AJAX逻辑在 `ajax-handler.php` 中实现。

## 业务逻辑
1. **深度挖掘工作流**：
   - **分步式挖掘**：为避免长时请求超时，系统采用 `keyword_research_segmented_mine` 分步获取各搜索引擎（Google, Bing, Baidu等）的建议词。
   - **任务状态维护**：通过 `session_id` 在服务器端临时存储（Temp Storage）挖掘进度，支持断点续发。
   - **结果聚合**：挖掘完成后调用 `keyword_research_finalize_mine` 汇总并通过向量去重，输出最终词库。
2. **趋势分析（Google Trends）**：
   - 调用 `keyword_research_trend` 接口获取指定关键词的搜索热度。
   - 实现两阶段数据获取：首先获取 Explore 访问令牌，随后请求具体的 Widget Data 绘制趋势曲线。
3. **多数据源集成**：
   - 在 `free_keyword_apis.php` 中实现了多源并行请求：
     - `Google Suggest`：获取核心长尾建议词。
     - `Baidu Suggest`：优化中文关键词联想。
     - `Amazon/E-commerce`：适用于购物选题。
4. **语言与地域适应性**：
   - 支持多国语言参数（如 `cn-zh-CN`、`us-en`），影响各 API 的语料库反馈。
5. **本地优化处理**：
   - 对结果进行自动去重、去除根词、以及按相关度排序。
   - 临时文件自动清理机制，保证服务器存储安全。

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
