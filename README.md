# Product Import 插件

## 简介

**Product Import** 是为 WordPress 设计的批量导入插件，支持导入 `components` 文章和 `product-category` 分类，包括 ACF 字段、图片、PDF、附件等资源。插件支持 Excel 模板导入、资源预处理、断点续传、失败重试、导入历史回滚等功能，适合大批量产品数据的高效迁移和管理。

---

## 主要特性

- **批量导入**：支持通过 Excel 文件批量导入产品和分类。
- **ACF 字段支持**：自动写入/更新自定义字段。
- **图片/附件自动导入**：支持本地文件和远程 URL，自动去重（MD5 标记）。
- **资源预处理**：Excel 资源 URL 可提前批量下载，支持并发、失败重试、进度反馈。
- **导入历史与回滚**：每次导入自动记录日志，可一键回滚。
- **失败资源管理**：失败的资源自动记录，导入时自动跳过，支持重试。
- **高兼容性**：兼容多种资源类型（图片、PDF、Word、Excel、ZIP等）。
- **安全性**：权限校验、URL 合法性校验，防止滥用。

---

## 使用方法

1. **上传插件并启用**
   - 将 `product-import` 文件夹上传到 `wp-content/plugins/`，后台启用插件。

2. **进入导入页面**
   - 后台菜单：`Components > Product Import`

3. **下载模板**
   - 页面顶部可下载产品、分类 Excel 模板及文件夹结构模板。

4. **上传文件**
   - 选择产品 Excel、分类 Excel、附件 ZIP（可选）。

5. **资源预处理**
   - 点击“资源预处理”按钮，自动解析 Excel 中的所有远程资源 URL，批量下载到媒体库，支持失败重试和进度反馈。

6. **正式导入**
   - 预处理完成后，点击“Start Import”开始正式导入，所有资源字段会优先复用已下载的媒体，未下载的自动跳过或补全。

7. **导入历史与回滚**
   - 可在“Import History”页面查看历史记录并一键回滚。

---

## 资源预处理说明

- 支持 Excel 中所有远程图片、PDF、附件等 URL 的批量预处理。
- 采用 AJAX 并发队列，支持失败重试、断点续传。
- 所有已处理资源自动写入媒体库并做唯一标记，导入时自动复用。
- 失败资源自动记录，后续可单独重试。

---

## 技术实现要点

- 资源唯一性通过 URL 的 MD5 标记（`_import_src_md5`）实现。
- 失败资源、成功资源、任务状态支持数据库表和 option 记录，便于管理和扩展。
- 支持多种资源类型，自动识别 content-type。
- 兼容 WordPress 多站点、不同 PHP 环境。

---

## 依赖

- [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) 插件（可选，推荐）
- [PhpSpreadsheet](https://phpspreadsheet.readthedocs.io/) 组件（已集成 vendor 目录）

---

## 常见问题

- **资源下载失败**：请检查服务器网络、目标站点防盗链、超时设置等。
- **大文件导入超时**：建议分批导入或使用 CLI 工具。
- **Excel 格式不兼容**：请使用官方模板或确保表头字段一致。

---

## 目录结构

```
product-import/
├── includes/
│   ├── converter.php
│   ├── functions.php
│   ├── import.php
│   ├── resource_preprocess.php
│   ├── rollback.php
├── main.js
├── style.css
├── product-import.php
├── README.md
└── templates/
    ├── products-template.xlsx
    ├── categories-template.xlsx
    ├── folder-template.zip
    └── user-manual.docx
```

---

## 许可证

MIT License

---

## 作者

Zendkee

---

如需定制开发、问题反馈或建议，请联系作者或提交 issue。
