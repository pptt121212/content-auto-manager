# Content Auto Manager / 内容自动生成管家

A smart content generation plugin that helps WordPress administrators automatically generate high-quality articles. / 一款智能内容生成插件，帮助WordPress管理员自动生成高质量中文文章。

## Overview / 概述

The Content Auto Manager plugin is a powerful WordPress plugin that enables website administrators to automatically generate high-quality articles using AI technology. It's designed with SEO and content clustering in mind, making it ideal for websites that require frequent content updates.

内容自动生成管家是一款专业的WordPress插件，为管理和展示自动生成的内容提供高级功能。该主题专为SEO和内容聚类优化设计，非常适合依赖自动内容生成的网站。

This plugin is specifically designed to work as a companion plugin for the Content Manager Custom Theme. It cannot function independently and must be used together with the theme. / 本插件专为内容管家辅助主题的配套插件而设计。无法独立使用，必须与主题配合使用。

## Plugin Dependency / 插件依赖

**Required Theme / 必需主题**: Content Manager Custom Theme (内容管家辅助主题)  
**Theme Repository / 主题仓库**: [Content Manager Custom Theme](https://github.com/pptt121212/content-manager-custom-theme)  
**Theme Compatibility / 主题兼容性**: Version 1.0.4 or higher  
## Features / 功能特性

- **SEO Optimized**: Built with SEO best practices for content-rich sites / **SEO优化**：采用内容丰富型网站的SEO最佳实践
- **Responsive Design**: Fully responsive across all device sizes / **响应式设计**：适配各种设备尺寸
- **Content Clustering**: Built-in support for content clustering and related articles / **内容聚类**：内置内容聚类和相关文章支持
- **Custom Templates**: Specialized templates for different content types / **自定义模板**：针对不同内容类型的专门模板
- **Optimized Performance**: Fast loading times for content-heavy sites / **性能优化**：内容密集型网站的快速加载
- **Plugin Integration**: Seamless integration with Content Manager Custom Theme / **插件集成**：与内容管家辅助主题无缝集成
- **Theme Compatibility**: Designed to work exclusively with Content Manager Custom Theme / **主题兼容性**：专为内容管家辅助主题设计

## Installation Requirements / 安装要求

- WordPress 5.0 or higher / WordPress 5.0或更高版本
- PHP 7.4 or higher / PHP 7.4或更高版本
- MySQL 5.7 or higher / MySQL 5.7或更高版本
- Support for CURL extension / 支持CURL扩展
- Content Manager Custom Theme (v1.0.4 or newer) / 内容管家辅助主题（v1.0.4或更新版本）

## Installation Method / 安装方法

### Prerequisites / 前提条件
1. Install and activate the Content Manager Custom Theme first / 首先安装并激活内容管家辅助主题
2. Theme repository: https://github.com/pptt121212/content-manager-custom-theme / 主题仓库：https://github.com/pptt121212/content-manager-custom-theme

### Plugin Installation / 插件安装
1. Download the plugin ZIP file from the [Releases](https://github.com/pptt121212/content-auto-manager/releases) page / 从[发布页面](https://github.com/pptt121212/content-auto-manager/releases)下载插件ZIP文件
2. In your WordPress admin, go to Plugins > Add New > Upload Plugin / 在WordPress后台，进入 插件 > 安装插件 > 上传插件
3. Select the plugin zip file for uploading and installation / 选择插件zip文件进行上传安装
4. Click "Install Now" / 点击"立即安装"
5. After installation is complete, activate the plugin / 安装完成后激活插件

Or through FTP method: / 或通过FTP方式：

1. Extract the plugin files to the `/wp-content/plugins/` directory / 解压插件文件到 `/wp-content/plugins/` 目录
2. Activate "Content Auto Manager" in the WordPress backend / 在WordPress后台激活"内容自动生成管家"

## Configuration Steps / 配置步骤

1. After activating the plugin, find "Content Auto Manager" in the WordPress management menu / 激活插件后，在WordPress管理菜单中找到"内容自动生成管家"
2. Configure API settings (on the "API Settings" page) / 配置API设置（在"API设置"页面）
3. Set up article generation rules (on the "Rule Management" page) / 设置文章生成规则（在"规则管理"页面）
4. Configure publishing rules (on the "Publish Settings" page) / 配置发布规则（在"发布设置"页面）
5. Add article themes (on the "Theme Management" page) / 添加文章主题（在"主题管理"页面）
6. Start generating content / 开始生成内容

## Usage Instructions / 使用说明

### API Settings / API设置
Configure information about AI service providers on the "API Settings" page, including API keys, model selection, etc. / 在"API设置"页面配置AI服务提供商的相关信息，包括API密钥、模型选择等。

### Rule Management / 规则管理
Define content generation rules, including: / 定义内容生成的规则，包括：
- Category Rules: Specify which category the article should belong to / 分类规则：指定文章应该属于哪个分类
- Keyword Rules: Generate related content based on keywords / 关键词规则：基于关键词生成相关内容
- Template Rules: Generate content using predefined templates / 模板规则：使用预定义模板生成内容
- Timing Rules: Set generation time intervals / 定时规则：设置生成时间间隔

### Publish Settings / 发布设置
Configure article publishing options, including: / 配置文章发布选项，包括：
- Publish Status (Draft, Publish, Scheduled Publishing) / 发布状态（草稿、发布、定时发布）
- Publish Time Interval / 发布时间间隔
- Author Settings / 作者设置
- Category Settings / 分类设置

### Theme Management / 主题管理
Add and manage article themes. The system will automatically generate article content based on these themes. / 添加和管理文章主题，系统将基于这些主题自动生成文章内容。

## File Structure / 文件结构

```
content-auto-manager/
├── content-auto-manager.php     # Main plugin file / 主插件文件
├── admin/                      # Backend management related files / 后台管理相关文件
├── api-settings/               # API settings module / API设置模块
├── article-structures/         # Article structure management / 文章结构管理
├── article-tasks/              # Article task processing / 文章任务处理
├── brand-profiles/             # Brand configuration / 品牌配置
├── dashboard/                  # Dashboard / 仪表板
├── debug-tools/                # Debug tools / 调试工具
├── image-api-settings/         # Image API settings / 图片API设置
├── image-tasks/                # Image task processing / 图片任务处理
├── includes/                   # Include files / 包含文件
├── keyword-research-tool/      # Keyword research tool / 关键词研究工具
├── logs/                       # Log files / 日志文件
├── pinyin-converter/           # Pinyin conversion tool / 拼音转换工具
├── prompt-templating/          # Prompt word template / 提示词模板
├── publish-settings/           # Publish settings / 发布设置
├── rule-management/            # Rule management / 规则管理
├── shared/                     # Shared functionality modules / 共享功能模块
├── topic-management/           # Topic management / 主题管理
├── variable-guide/             # Variable guide / 变量指南
└── VERSION_CONTROL.md          # Version management documentation / 版本管理文档
```

## Non-Commercial Use Statement / 非商业用途声明

This plugin is for personal and non-commercial use only. It is strictly prohibited to use this plugin or any part of it for commercial purposes, including but not limited to:

此插件仅供个人和非商业用途使用。严禁将此插件或其任何部分用于商业目的，包括但不限于：

- Directly selling this plugin / 直接销售此插件
- Providing this plugin as part of a commercial service / 将此插件作为商业服务的一部分提供
- Charging fees for content generated using this plugin / 对使用此插件生成的内容收费
- Integrating this plugin into commercial products / 将此插件集成到商业产品中

Any violation of this statement will automatically terminate your rights to use this software.

违反本声明的任何行为将自动终止您使用此软件的权利。

## Authorization Instructions / 授权说明

The publishing rules feature of this plugin requires domain authorization to use the full functionality. To obtain domain authorization, please:

此插件的发布规则功能需要获取域名授权才能使用完整功能。如需获取域名授权，请：

1. Add the plugin author's WeChat: **qn006699** / 添加插件作者微信：**qn006699**
2. Send your website domain information / 发送您的网站域名信息
3. The author will provide you with a domain authorization code for free / 作者将免费为您提供域名授权码

The authorization code is only for use on the specified domain. Please do not share or resell it.

授权码仅限指定域名使用，请勿分享或转售。

## Development / 开发

This plugin adopts a modular design, but please abide by the non-commercial use statement.

此插件采用模块化设计，但请遵守非商业用途声明。

## Contribution / 贡献

We welcome contributions to improve this plugin, but please ensure that all contributions also follow the non-commercial use restrictions.

我们欢迎为此插件做出贡献，但请确保所有贡献也遵循非商业用途限制。

## License / 许可证

This plugin follows the "Content Auto Manager Restricted Open Source License" and is only for personal and non-commercial use. Commercialization is not allowed. For detailed terms, please see [LICENSE](LICENSE) file.

此插件遵循"内容自动生成管家限制性开源许可证"，仅供个人和非商业用途使用，不允许商业化。详细条款请参见 [LICENSE](LICENSE) 文件。

## Version Management / 版本管理

This plugin uses Git for version management. All version releases can be obtained through GitHub Releases.

本插件使用Git进行版本管理。所有版本发布都可通过GitHub Releases获取。

## Plugin Development / 插件开发

- For plugin iteration development, please refer to VERSION_CONTROL.md file instructions / 如需进行插件的迭代开发，请参考 VERSION_CONTROL.md 文件中的说明
- Each stable version will be tagged and released to Releases / 每个稳定版本都会打上标签并发布到Releases
- If you need to roll back versions, please use Git commands for version management / 如需回退版本，请使用Git命令进行版本管理

## Companion Theme / 配套主题

This plugin has an official companion theme: "Content Manager Custom Theme", which can be obtained from the following address:

本插件有一个官方配套主题："内容管家辅助主题"，可从以下地址获取：

- GitHub Repository: https://github.com/pptt121212/content-manager-custom-theme / GitHub仓库：https://github.com/pptt121212/content-manager-custom-theme
- Theme Download: https://github.com/pptt121212/content-manager-custom-theme/releases/download/v1.0.4/content-manager-custom-theme-v1.0.4.zip / 主题下载：https://github.com/pptt121212/content-manager-custom-theme/releases/download/v1.0.4/content-manager-custom-theme-v1.0.4.zip
- Theme Requirements: This theme is only for use with the Content Auto Manager plugin and cannot be used independently / 主题要求：此主题仅限与内容自动生成管家插件配套使用，无法独立使用

## Version Updates / 版本更新

- **Theme Latest Version**: v1.0.4 / **主题最新版本**: v1.0.4
- **Update Content**: / **更新内容**: 
  - Added comprehensive README with bilingual support / 添加支持中英双语的详细README
  - Clarified plugin dependency requirements / 明确插件依赖要求
  - Updated compatibility information / 更新兼容性信息

## Support / 支持

If you encounter problems or have suggestions, please submit an Issue in the GitHub repository: [Issues](https://github.com/pptt121212/content-auto-manager/issues)

如遇到问题或有建议，请在GitHub仓库提交Issue：[Issues](https://github.com/pptt121212/content-auto-manager/issues)

**Note: This plugin only works when used together with the Content Manager Custom Theme.** / **注意：此插件仅在与内容管家辅助主题配合使用时有效。**
## License Information / 许可证信息

This plugin is released under the GPL-2.0-or-later license. / 此插件基于GPL-2.0或更高版本许可证发布。

## Contributing / 贡献

We welcome contributions to improve this plugin. Please fork the repository and submit a pull request with your changes. / 我们欢迎为改进此插件做出贡献。请fork此仓库并提交您的修改。

## Important Notes / 重要说明

- This plugin is specifically designed to work with the Content Manager Custom Theme / 此插件专为内容管家辅助主题设计
- **The plugin cannot function independently and requires the theme to work properly** / **此插件无法独立运行，需要主题才能正常工作**
- Theme repository: https://github.com/pptt121212/content-manager-custom-theme / 主题仓库：https://github.com/pptt121212/content-manager-custom-theme
- For best results, use with the Content Manager Custom Theme mentioned above / 为获得最佳效果，请配合上述内容管家辅助主题使用
- Regular updates may be required to maintain compatibility with WordPress core and the theme / 可能需要定期更新以保持与WordPress核心和主题的兼容性
