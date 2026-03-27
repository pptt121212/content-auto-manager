=== 鸭梨AI文章智能写手 ===
Contributors: yaliai
Tags: ai, article-writer, seo, content-generator, writing-assistant
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

一款智能内容生成插件，帮助WordPress管理员自动生成高质量中文文章。支持多API接口、智能内容策略及浏览器扩展集成。

== Description ==

**鸭梨AI文章智能写手**是一个功能强大的WordPress插件，基于AI大语言模型技术，为内容创作者、SEO优化人员、网站运营团队提供全自动化的内容生产解决方案。

主要功能：
* **多模型管理中心**：支持 OpenAI, Claude, Gemini 以及国内通义千问、文心一言等主流模型。
* **免费大语言模型**：内置 Pollinations AI 等免费通道，无需 API Key 即可启动。
* **浏览器插件协同**：(v1.0.8) 创新性支持调用浏览器缓存或本地文档作为知识库。
* **SEO关键词研究**：集成主流搜索引擎下拉词挖掘，辅助精准选题。
* **全自动发布流**：从选题、生成、配图到定时发布，全流程无人值守。

**数据隐私与外部服务声明**：
为了保证功能的正常运行及合规性，本插件会与以下外部服务进行数据交换：
1. **核心通信**：连接授权服务器进行域名授权、插件版本验证及联网搜索(RAG)素材抓取。
2. **AI 生成**：根据用户配置，将提示词发送至第三方 LLM (如 OpenAI, Claude) 或 Vector Embedding 服务商。
3. **内容抓取**：使用 `jina.ai` 解析网页内容，或通过浏览器扩展进行“主动抓取”以穿透防火墙。
4. **浏览器插件协同**：通过 REST API 提供“AI 代理网关”及“抓取任务下发”。注意：本地文件内容绝不上传，仅回传提取后的文本摘要。
5. **关键词研究**：连接 Google (complete/trends) 及百度建议接口以获取实时选题热度。
6. **安全保护**：密钥本地加密存储，日志目录由 .htaccess 严格屏蔽外部访问。

== Installation ==

1. 将 `yali-ai-writer` 文件夹上传到 `/wp-content/plugins/` 目录。
2. 通过 WordPress 的“插件”菜单激活插件。
3. 进入“鸭梨AI写手”菜单进行 API 配置。

== Screenshots ==

1. 仪表盘总览
2. API 设置界面
3. 文章任务列表

== Changelog ==

= 1.0.8 =
* 品牌正式更名为“鸭梨AI文章智能写手”。
* 优化浏览器插件协同逻辑，支持本地知识库调用。
* 修复了部分 UI 标签显示异常的问题。

= 1.0.0 =
* 首次发布。
