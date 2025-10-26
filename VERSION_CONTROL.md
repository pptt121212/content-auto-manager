# 版本管理说明

## 当前版本状态
- 最新版本提交: 50352cc - Add authorization information for domain licensing
- 版本标签: v1.0.2

## 版本回退操作指南

### 1. 查看提交历史
```bash
git log --oneline
```

### 2. 回退到指定提交
- 查看提交历史后，可以使用以下命令回退：
```bash
# 软回退（保留更改在工作区）
git reset --soft <提交哈希>

# 硬回退（丢弃指定提交之后的所有更改）
git reset --hard <提交哈希>
```

### 3. 回退示例
例如，如果要回退到初始提交：
```bash
git reset --hard 6519a0e
```

### 4. 撤销本地更改
- 如果想撤销未提交的更改：
```bash
# 撤销指定文件的更改
git checkout -- <文件名>

# 撤销所有更改
git checkout -- .
```

### 5. 标签管理
- 创建新标签：
```bash
git tag -a v1.0.3 -m "Version 1.0.3"
```

- 推送标签到远程仓库：
```bash
git push origin --tags
```

## 开发工作流程建议

1. 在进行新功能开发前，先创建新分支：
```bash
git checkout -b feature-branch-name
```

2. 完成开发后，测试无误再合并回主分支：
```bash
git checkout main
git merge feature-branch-name
```

3. 每个稳定版本都打上标签：
```bash
git tag v1.x.x
```

## 注意事项
- 使用 `--hard` 选项会永久删除未提交的更改，请谨慎使用
- 在执行版本回退前，建议先创建备份标签
- 如需撤销推送的更改，可能需要使用 `git push --force`（需谨慎）

## 发布新版本
当您完成插件迭代后：
1. 提交更改：`git add . && git commit -m "版本更新说明"`
2. 创建标签：`git tag -a v1.x.x -m "版本描述"`
3. 推送到远程：`git push origin main --tags`
4. 在GitHub上创建Release并上传新的zip文件