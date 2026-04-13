# Yali AI Writer 知识库

## 这套知识库的真正用途

这不是一份“功能目录”，也不是一份只给技术人员看的实现说明。它的目标有两个，而且这两个目标必须同时成立。

第一，它要让**实际使用插件的人**学会怎么把 Yali AI Writer 用起来：先做什么，后做什么，每个模块为什么存在，页面里哪些设置会影响后续结果，什么时候该进入下一步，遇到问题时应该先检查哪里。

第二，它要让**AI 与技术读者**能够系统理解这个写作插件：它有哪些对象，哪些页面是入口，哪些配置是全局约束，哪些流程会产生主题、文章、草稿和参考资料，哪些模块之间存在显式依赖，哪些能力适合在什么阶段引入。

所以，这套知识库会同时按两个维度组织：

1. **按业务流程学习**：适合第一次接手插件、想快速跑通内容生产的人。
2. **按功能模块深入**：适合需要精确理解单个模块的人，也适合 AI 做结构化理解。

---

## 如果你是第一次接触这个插件，先记住一件事

Yali AI Writer 不是一个“填一句话就自动吐出一篇文章”的单点工具。它更像一套围绕内容生产搭建出来的后台系统：

- 前面先接能力
- 中间再定规则
- 然后生产主题
- 再从主题进入文章与深度写作
- 最后用素材、品牌、结构、关键词和 GSC 持续优化

这意味着，大多数问题都不是出在某一个按钮上，而是出在**上游没有准备好**。例如：

- API 没配好，后面的生成能力就不稳定
- 发布规则没定好，产出的文章风格和落地方式会漂移
- 规则和主题链路没打通，再强的写作能力也只能盲写
- 参考资料、品牌资料和结构策略没接好，文章质量就很难稳定

因此，这套知识库会反复强调“前置条件”和“上下游关系”，因为这才是用户真正能学会系统的关键。

---

## 推荐阅读路径

### 路径一：我要尽快学会怎么用

如果你的目标是尽快接手插件并开始生产内容，建议按下面顺序阅读：

1. [快速开始总览](00-quick-start/index.md)
2. [系统概览：这套插件到底在做什么](00-quick-start/01-system-overview.md)
3. [从接手到产出的第一条上手路径](00-quick-start/02-first-use-roadmap.md)
4. [安装、接入与初始配置](00-quick-start/03-setup-and-initial-configuration.md)
5. [业务流程文档](01-business-workflows/index.md)
6. [核心模块文档](02-core-modules/index.md)
7. [支撑模块文档](03-supporting-modules/index.md)

### 路径二：我要精确理解系统结构

如果你的目标是建立 AI 可读的结构化理解，或者你要做文档、自动化、培训和技术协作，建议按下面顺序阅读：

1. [快速开始总览](00-quick-start/index.md)
2. [系统概览：这套插件到底在做什么](00-quick-start/01-system-overview.md)
3. [业务流程总览](01-business-workflows/index.md)
4. [核心模块总览](02-core-modules/index.md)
5. [支撑模块总览](03-supporting-modules/index.md)

---

## 知识库结构

### 00 快速开始

这部分负责建立统一认知，让读者先知道：系统是什么、应该怎么学、第一次接手时应该按什么顺序推进。

- [快速开始总览](00-quick-start/index.md)
- [系统概览：这套插件到底在做什么](00-quick-start/01-system-overview.md)
- [从接手到产出的第一条上手路径](00-quick-start/02-first-use-roadmap.md)
- [安装、接入与初始配置](00-quick-start/03-setup-and-initial-configuration.md)

### 01 业务流程文档

这部分按实际业务操作顺序组织，重点解决“我到底应该怎么一步一步使用这套插件”。

- [业务流程总览](01-business-workflows/index.md)
- [从零开始到稳定产出的完整流程](01-business-workflows/01-end-to-end-content-production.md)
- [规则创建与主题生产流程](01-business-workflows/02-rule-to-topic-production.md)
- [主题筛选、补料与进入写作流程](01-business-workflows/03-topic-curation-and-preparation.md)
- [标准文章生成流程](01-business-workflows/04-standard-article-generation.md)
- [深度写作与浏览器扩展流程](01-business-workflows/05-deep-writing-workflow.md)
- [搜索素材、品牌资料与质量增强流程](01-business-workflows/06-materials-brand-and-quality-enhancement.md)

### 02 核心模块文档

这部分按主生产链路拆解系统中的核心页面和核心能力，重点解决“某个模块具体怎么用，它在整个系统中的位置是什么”。

- [核心模块总览](02-core-modules/index.md)
- [仪表盘](02-core-modules/01-dashboard.md)
- [API 设置](02-core-modules/02-api-settings.md)
- [发布规则](02-core-modules/03-publish-rules.md)
- [提示词模板](02-core-modules/04-prompt-templates.md)
- [规则管理](02-core-modules/05-rule-management.md)
- [主题任务](02-core-modules/06-topic-jobs.md)
- [主题管理](02-core-modules/07-topic-management.md)
- [文章任务](02-core-modules/08-article-tasks.md)

### 03 支撑模块文档

这部分覆盖会显著影响内容质量、效率、品牌一致性和持续优化能力的增强模块。

- [支撑模块总览](03-supporting-modules/index.md)
- [图像 API 与自动配图](03-supporting-modules/01-image-api-and-auto-images.md)
- [编辑器 AI 助手](03-supporting-modules/02-editor-assistant.md)
- [品牌资料](03-supporting-modules/03-brand-profiles.md)
- [搜索素材](03-supporting-modules/04-search-materials.md)
- [关键词工具](03-supporting-modules/05-keyword-research-tool.md)
- [文章结构、向量与智能优化](03-supporting-modules/06-structures-vectors-and-optimization.md)
- [GSC 数据洞察](03-supporting-modules/07-gsc-insights.md)

---

## 一个最容易理解的主流程

```text
先接入能力
  ↓
再确定全局发布规则
  ↓
再建立规则和主题来源
  ↓
生成主题
  ↓
筛选主题、补充参考资料
  ↓
选择标准文章生成 或 深度写作
  ↓
再引入图片、品牌资料、结构优化等增强能力
  ↓
最后用关键词与 GSC 形成持续优化闭环
```

如果你把这条顺序理解清楚，后面每一篇文档都会更容易读懂。

---

## 这套知识库每篇文档都会怎么写

为了同时服务“真实用户学习”和“AI 结构化理解”，后续文档会尽量遵循同一套结构：

1. 这个功能或流程在业务里解决什么问题
2. 谁最常使用它，通常在什么阶段使用
3. 开始前你要先准备什么
4. 页面里你会看到什么，哪些区域最重要
5. 标准操作步骤应该怎么走
6. 每一步做完之后，你应该看到什么结果
7. 常见分支、常见误区和排查思路
8. 它与上下游模块是什么关系
9. 给 AI / 技术读者的结构化理解

这意味着：技术细节不会消失，但它们会放在更合适的位置，而不是一开始就压在用户面前。

---

## 你下一步该读什么

如果你现在还没有真正接手这套插件，请先从下面两篇开始：

- [系统概览：这套插件到底在做什么](00-quick-start/01-system-overview.md)
- [从接手到产出的第一条上手路径](00-quick-start/02-first-use-roadmap.md)

如果你已经知道这套插件的大体用途，只是想尽快开始配置和生产，那么直接继续：

- [安装、接入与初始配置](00-quick-start/03-setup-and-initial-configuration.md)
- [业务流程总览](01-business-workflows/index.md)
