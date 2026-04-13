=== Yali AI Smart Article Writer ===
Contributors: yaliai
Tags: ai, article-writer, seo, content-generator, writing-assistant
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An intelligent content generation plugin that helps WordPress administrators automatically generate high-quality articles. Supports multiple AI APIs, smart content strategies, and browser extension integration.

== Description ==

**Yali AI Smart Article Writer** is a powerful WordPress plugin based on large language model technology, providing fully automated content production solutions for content creators, SEO professionals, and website operation teams.

Key Features:
* **Multi-Model Management Center**: Supports mainstream models including OpenAI, Claude, Gemini, and domestic models like Tongyi Qianwen, ERNIE Bot.
* **Free LLM Access**: Built-in free channels like Pollinations AI, no API Key required to start.
* **Browser Extension Collaboration**: (v1.0.8) Innovatively supports using browser cache or local documents as knowledge base.
* **SEO Keyword Research**: Integrated mainstream search engine autocomplete and trend analysis for precise topic selection.
* **Fully Automated Publishing Pipeline**: From topic selection, content generation, image matching to scheduled publishing, fully unattended.

**Data Privacy & External Services Declaration**:
To ensure proper functionality and compliance, this plugin exchanges data with the following external services:
1. **Core Communication**: Connects to authorization server for domain licensing, plugin version verification, and web search (RAG) material crawling.
2. **AI Generation**: Sends prompts to third-party LLMs (like OpenAI, Claude) or Vector Embedding services based on user configuration.
3. **Content Crawling**: Uses `jina.ai` to parse web content, or performs "active crawling" through browser extension to bypass firewalls.
4. **Browser Extension Collaboration**: Provides "AI Proxy Gateway" and "crawling task distribution" via REST API. Note: Local file content is never uploaded, only extracted text summaries are returned.
5. **Keyword Research**: Connects to Google (complete/trends) and Baidu Suggestion APIs for real-time topic popularity.
6. **Security Protection**: Keys are stored with local encryption, log directories are strictly protected by .htaccess from external access.

== External Services ==

This plugin relies on the following external services to provide its functionality:

**1. AI Model APIs (User-configured)**
The plugin sends prompts to third-party AI providers based on user configuration:
* OpenAI API (https://api.openai.com)
* Anthropic Claude API (https://api.anthropic.com)
* Google Gemini API (https://generativelanguage.googleapis.com)
* Pollinations AI (https://gen.pollinations.ai)
* SiliconFlow API (https://api.siliconflow.cn)
* Other user-configured OpenAI-compatible endpoints

**Data transmitted**: Prompt text, system instructions, temperature/settings parameters.
**When**: Only during article generation requests initiated by the user.

**2. Authorization & Update Server**
* Domain: https://key.kdjingpai.com
* Purpose: Plugin license verification, version update checks
* Data transmitted: License key, domain name, plugin version

**3. Content Crawling Services**
* Jina AI Reader (https://r.jina.ai): Web content parsing
* Google Suggest API: Search autocomplete data
* Baidu Suggest API: Search autocomplete data
* Google Trends API: Search trend data

**4. Image Generation APIs (User-configured)**
* OpenAI DALL-E
* Pollinations.AI
* ModelScope
* SiliconFlow
* Volcengine (ByteDance)

**5. Google Search Console API (Optional)**
For the "SEO Radar" dashboard feature:
* Google Search Console (https://www.googleapis.com/auth/webmasters.readonly)
* **Data transmitted**: Site URL, Search Performance metrics (clicks, impressions, positions).
* **When**: Only if the user explicitly authorizes via Gmail OAuth.

**6. Vector Embedding APIs (Optional)**
For semantic search and content clustering features:
* OpenAI Embedding API
* Jina AI Embedding API

== Privacy Policy ==

**Data Collection**:
* This plugin does not collect any personal data from website visitors.
* Administrative data (API keys, license keys) are stored locally with encryption.
* Generated content is stored in your WordPress database only.

**Data Transmission**:
* Content is only sent to external AI services when you explicitly initiate article generation.
* Keywords for research are sent to search APIs in real-time.
* No user data is sold or shared with third parties for marketing purposes.

**Data Retention**:
* Generated articles remain in your WordPress database until you delete them.
* API keys are stored encrypted in the WordPress options table.
* Temporary data (logs, caches) can be cleared through the plugin settings.

**User Rights**:
* You can delete all plugin data upon uninstallation.
* You can revoke API access at any time by removing the API key.

== Installation ==

1. Upload the `yali-ai-writer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the "Yali AI Writer" menu to configure API settings.

== Screenshots ==

1. Dashboard Overview
2. API Settings Interface
3. Article Task List

== Changelog ==

= 1.1.3 =
* Code refactoring and security hardening for WordPress.org compliance.
* Improved prefix consistency across all files.
* Enhanced data migration for existing users.

= 1.0.8 =
* Brand officially renamed to "Yali AI Smart Article Writer".
* Optimized browser extension collaboration logic, supporting local knowledge base calls.
* Fixed some UI label display issues.

= 1.0.0 =
* Initial release.