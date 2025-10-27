# Content Auto Manager

一款智能内容生成插件，帮助WordPress管理员自动生成高质量中文文章。

作者：[AI TOOL](https://www.kdjingpai.com/) | [访问插件主页](https://www.kdjingpai.com/)

## 功能特性

- **智能内容生成**：基于AI技术自动生成高质量中文文章
- **主题管理**：支持批量创建和管理文章主题
- **规则管理**：灵活的内容生成规则配置
- **发布设置**：自定义发布规则和时间安排
- **定时任务**：支持定时生成和发布文章
- **API集成**：支持多种AI API（OpenAI、Claude、Gemini等）
- **向量分析**：使用向量嵌入和聚类技术提升内容相关性
- **自动配图**：支持为文章自动生成配图

## 安装要求

- WordPress 5.0 或更高版本
- PHP 7.4 或更高版本
- MySQL 5.7 或更高版本
- 支持CURL扩展

## 安装方法

1. 下载插件压缩包
2. 在WordPress后台的插件页面点击"安装插件"
3. 选择插件zip文件进行上传安装
4. 安装完成后激活插件

或通过FTP方式：

1. 解压插件文件到 `/wp-content/plugins/` 目录
2. 在WordPress后台激活"Content Auto Manager"

## 配置步骤

1. 激活插件后，在WordPress管理菜单中找到"Content Auto Manager"
2. 配置API设置（在"API设置"页面）
3. 设置文章生成规则（在"规则管理"页面）
4. 配置发布规则（在"发布设置"页面）
5. 添加文章主题（在"主题管理"页面）
6. 开始生成内容

## 使用说明

### API设置
在"API设置"页面配置AI服务提供商的相关信息，包括API密钥、模型选择等。

### 规则管理
定义内容生成的规则，包括：
- 分类规则：指定文章应该属于哪个分类
- 关键词规则：基于关键词生成相关内容
- 模板规则：使用预定义模板生成内容
- 定时规则：设置生成时间间隔

### 发布设置
配置文章发布选项，包括：
- 发布状态（草稿、发布、定时发布）
- 发布时间间隔
- 作者设置
- 分类设置

### 主题管理
添加和管理文章主题，系统将基于这些主题自动生成文章内容。

## 文件结构

```
content-auto-manager/
├── content-auto-manager.php     # 主插件文件
├── admin/                      # 后台管理相关文件
├── api-settings/               # API设置模块
├── article-structures/         # 文章结构管理
├── article-tasks/              # 文章任务处理
├── brand-profiles/             # 品牌配置
├── dashboard/                  # 仪表板
├── debug-tools/                # 调试工具
├── image-api-settings/         # 图片API设置
├── image-tasks/                # 图片任务处理
├── includes/                   # 包含文件
├── keyword-research-tool/      # 关键词研究工具
├── logs/                       # 日志文件
├── pinyin-converter/           # 拼音转换工具
├── prompt-templating/          # 提示词模板
├── publish-settings/           # 发布设置
├── rule-management/            # 规则管理
├── shared/                     # 共享功能模块
├── topic-management/           # 主题管理
└── variable-guide/             # 变量指南
```

## 非商业用途声明

此插件仅供个人和非商业用途使用。严禁将此插件或其任何部分用于商业目的，包括但不限于：

- 直接销售此插件
- 将此插件作为商业服务的一部分提供
- 对使用此插件生成的内容收费
- 将此插件集成到商业产品中

违反本声明的任何行为将自动终止您使用此软件的权利。

## 授权说明

此插件的发布规则功能需要获取域名授权才能使用完整功能。如需获取域名授权，请：

1. 添加插件作者微信：**qn006699**
2. 发送您的网站域名信息
3. 作者将免费为您提供域名授权码

授权码仅限指定域名使用，请勿分享或转售。

## 开发

此插件采用模块化设计，但请遵守非商业用途声明。

## 贡献

欢迎提交Issue和Pull Request来帮助改进此插件，但请确保所有贡献也遵循非商业用途限制。

## 许可证

此插件遵循"Content Auto Manager限制性开源许可证"，仅供个人和非商业用途使用，不允许商业化。详细条款请参见 [LICENSE](LICENSE) 文件。

## 版本管理

本插件使用Git进行版本管理。所有版本发布都可通过GitHub Releases获取。

## 插件开发

- 如需进行插件的迭代开发，请参考 VERSION_CONTROL.md 文件中的说明
- 每个稳定版本都会打上标签并发布到Releases
- 如需回退版本，请使用Git命令进行版本管理

## 配套主题

本插件有一个官方配套主题："内容管家辅助主题"，可从以下地址获取：

- GitHub仓库：https://github.com/pptt121212/content-manager-custom-theme
- 主题下载：https://github.com/pptt121212/content-manager-custom-theme/releases/download/v1.0.3/content-manager-custom-theme-v1.0.3.zip
- 主题要求：此主题仅限与Content Auto Manager插件配套使用，无法独立使用

## 版本更新

- **主题最新版本**: v1.0.3
- **更新内容**: 
  - 更新了主题说明，明确说明仅限与插件配套使用
  - 添加了插件依赖关系信息
  - 在样式表中添加了插件依赖声明
  - 提供了插件项目的链接
  - 添加了使用限制说明
  - 更新了README文件，包含中英文版本
  - 添加了详细的THEME_INFO.md和USAGE_GUIDE.md说明文件
  - 添加了RELEASE_NOTES.md和VERSION文件
  - 创建了完整的项目文档体系

## 支持

如遇到问题或有建议，请在GitHub仓库提交Issue。