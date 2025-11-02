# 插件功能说明文档索引

本目录包含了内容自动生成管家插件的所有功能模块的详细说明文档。每个功能都有独立的文档文件，便于查阅和维护。

## 文档结构说明

每个功能文档包含以下内容：
- **功能概述**：功能的基本介绍和作用
- **业务逻辑**：该功能的核心业务逻辑和工作流程
- **使用场景**：实际应用中的典型使用场景
- **技术实现**：关键的技术实现细节
- **相关文件**：涉及的主要代码文件

## 核心功能模块

### 1. 系统管理模块
- [仪表盘 (Dashboard)](./01-dashboard.md) - 系统总览和数据统计
- [调试工具 (Debug Tools)](./13-debug-tools.md) - 系统调试和问题排查工具

### 2. API配置模块
- [API设置 (API Settings)](./02-api-settings.md) - AI大模型API配置管理
- [图像API (Image API)](./03-image-api.md) - 图像生成API配置管理

### 3. 内容生产配置模块
- [规则管理 (Rule Management)](./05-rule-management.md) - 内容生产规则配置
- [发布规则 (Publish Rules)](./09-publish-rules.md) - 文章发布规则配置
- [文章结构 (Article Structures)](./11-article-structures.md) - 文章结构模板管理
- [品牌资料 (Brand Profiles)](./12-brand-profiles.md) - 品牌信息和风格配置
- [变量说明 (Variable Guide)](./14-variable-guide.md) - 提示词变量说明

### 4. 内容生产执行模块
- [主题任务 (Topic Tasks)](./06-topic-tasks.md) - 主题生成任务管理
- [主题管理 (Topic Management)](./07-topic-management.md) - 主题库管理
- [文章任务 (Article Tasks)](./08-article-tasks.md) - 文章生成任务管理

### 5. 内容优化模块
- [关键词工具 (Keyword Tool)](./04-keyword-tool.md) - 关键词研究和分析工具
- [向量聚类 (Vector Clustering)](./10-vector-clustering.md) - 主题向量聚类分析

### 6. 核心服务模块
- [任务队列 (Job Queue)](./15-job-queue.md) - 后台任务队列处理
- [数据库服务 (Database Services)](./16-database-services.md) - 数据库操作封装
- [日志系统 (Logging System)](./17-logging-system.md) - 系统日志记录
- [数据一致性验证 (Data Validation)](./18-data-validation.md) - 数据一致性检查

## 快速导航

### 新用户入门
1. 先阅读 [仪表盘](./01-dashboard.md) 了解系统整体结构
2. 配置 [API设置](./02-api-settings.md) 连接AI服务
3. 创建 [规则管理](./05-rule-management.md) 定义内容生产规则
4. 查看 [主题任务](./06-topic-tasks.md) 和 [文章任务](./08-article-tasks.md) 开始生产内容

### 内容优化
1. 使用 [关键词工具](./04-keyword-tool.md) 研究目标关键词
2. 通过 [向量聚类](./10-vector-clustering.md) 优化主题分类
3. 配置 [品牌资料](./12-brand-profiles.md) 提升内容质量

### 问题排查
1. 查看 [调试工具](./13-debug-tools.md) 了解系统状态
2. 阅读 [日志系统](./17-logging-system.md) 查找错误信息
3. 使用 [数据一致性验证](./18-data-validation.md) 检查数据完整性

## 更新日志

- 2024-11-02: 初始化功能文档目录结构
