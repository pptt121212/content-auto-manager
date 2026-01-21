# 搜索物料 (Material Search & Scraper)

## 功能概述
搜索物料模块是内容生产的核心增强引擎，负责为文章创作提供高质量的上下文参考。它支持从两个维度搜集素材：
1. **自动化网络搜索**：实时爬取互联网内容并由 AI 总结。
2. **边缘知识库搜索**：通过已连接的浏览器插件从本地私有文档中提取信息。
该功能可以手动触发（调试/测试），也会在文章生成任务中被自动调用。

## 业务逻辑
### 1. 自动化网络搜索流程 (Automated Pipeline)
系统集成了一套复杂的“搜索-筛选-总结”流水线：
- **搜索引擎联动**：调用 DuckDuckGo 或 Jina Search 获取原始搜索结果（前 10-20 条）。
- **智能筛选 (Rerank)**：利用 LLM 评估搜索结果标题与正文摘要的相关性，剔除广告和无关内容。
- **深度处理 (Jina Reader)**：对选中的高价值 URL 调用 Jina Reader API 或内置抓取引擎，获取清洁的 Markdown 正文。
- **AI 汇总 (Summarization)**：根据文章标题和 SEO 意图，将多源抓取的文字碎片整理为逻辑连贯的“参考物料”。

### 2. 边缘知识库 RAG
- **指派模式**：当任务被标记为 `extension_rag` 时，网站向浏览器插件同步接口发送消息。
- **分布式检索**：浏览器插件捕获任务后，执行本地向量检索 -> 查询扩展 -> AI 预处理。
- **结果回传**：插件完成处理后自动将结果 POST 回网站 REST 接口，更新主题关联的参考资料。

### 3. 后台队列排程
- 搜索物料是一项耗时操作（通常 10-60 秒），系统通过 `JobQueue` 的 `material_search` 类型进行管理。
- **超时保护**：具备 300s 的硬超时限制。对于长时间未响应的插件任务，系统会自动标记为失败以免阻塞队列。

## 使用场景
- **热点文章写作**：对突发新闻或实时技术进行全网搜集，确保 AI 写作的时效性。
- **专业领域创作**：利用知识库搜索功能，将公司内部 PDF、私密文档等非公开数据转化为文章素材。
- **物料质量检查**：在正式排程生成前，通过“搜索物料”管理页面手动输入主题 ID，查看 AI 能获取到哪些原始参考。

## 技术实现
- **核心类库**：`search-materials/class-search-materials-service.php`
- **抓取服务**：集成 `Jina API` 实现 Markdown 极速转化。
- **异步回调**：使用 WordPress AJAX (搜索引擎模式) 和 REST API `callback` (浏览器插件模式)。
- **数据存储**：物料结果持久化于 `content_auto_topics` 表的 `reference_material` 字段。

## 相关文件
- `search-materials/class-search-materials-admin-page.php`（管理界面）
- `search-materials/class-search-materials-service.php`（核心逻辑引擎）
- `shared/services/class-unified-api-handler.php`（API 调用中转）
- `docs/features/19-browser-extension.md`（插件执行端说明）
