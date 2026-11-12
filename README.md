# 族谱传承 · 家谱管理系统

> 凡国必有史，有家必有谱。数字化传承家族记忆，让每一份血脉相连永不遗忘。

一个功能完善、安全加固的开源家谱管理系统。基于 PHP + MySQL 构建，支持家谱创建、世系管理、成员记录、数据导出等核心功能，配套完整的后台管理面板。

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](https://github.com)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7+-4479a1.svg)](https://mysql.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

---

## 目录

- [功能特性](#功能特性)
- [系统架构](#系统架构)
- [技术栈](#技术栈)
- [环境要求](#环境要求)
- [部署指南](#部署指南)
- [目录结构](#目录结构)
- [数据库设计](#数据库设计)
- [安全特性](#安全特性)
- [后台管理](#后台管理)
- [API 接口](#api-接口)
- [常见问题](#常见问题)
- [更新日志](#更新日志)
- [贡献指南](#贡献指南)
- [License](#license)

---

## 功能特性

### 👨‍👩‍👧‍👦 家谱管理

| 功能 | 说明 |
|------|------|
| 创建家谱 | 填写姓氏、谱名、起源、简介等信息，一键创建数字家谱 |
| 世系录入 | 支持父子/母子/配偶关系，逐代录入成员信息（姓名、性别、生卒、生平） |
| 世系树 | 可视化树状展示家族世代传承关系，支持横向滚动 |
| 封面生成 | 自动生成姓氏古风封面图，支持自定义上传 |
| 权限控制 | 公开/私密家谱设置，可选是否允许他人协作编辑 |
| 搜索检索 | 按姓氏、谱名、地区等多维度搜索家谱 |

### 📊 数据管理

| 功能 | 说明 |
|------|------|
| 成员管理 | 添加、编辑、删除家族成员，支持头像上传和裁剪 |
| 数据统计 | 家谱数量、成员总数、注册用户数实时展示 |
| 导出功能 | 支持 Excel (.xlsx)、PDF 两种格式导出，一键编目成书 |
| 批量操作 | 后台批量管理家谱和成员数据 |

### 🔐 安全体系

| 防护层 | 措施 |
|--------|------|
| 密码安全 | bcrypt (cost=10) 加密存储 |
| Session 安全 | 指纹绑定 + HttpOnly + SameSite=Lax + Strict Mode |
| CSRF 保护 | 所有数据修改操作校验 Token |
| SQL 注入防护 | 全量 PDO 预处理语句 + 参数绑定 |
| XSS 防御 | 输出自动转义 + HTML 白名单过滤 |
| 暴力破解防护 | 5次/30min 限流 + IP 自动封禁 + 失败记录 |
| 文件上传安全 | MIME 类型验证 + 图片真实性检测 + 扩展名双重检查 |
| 安全响应头 | CSP + X-Frame-Options + X-Content-Type-Options 等 |
| 输入净化 | NULL 字节和控制字符递归过滤 |

### 🖥️ 后台管理面板

| 模块 | 功能 |
|------|------|
| 控制台 | 系统概览、快捷操作入口 |
| 会员管理 | 用户列表、搜索、启停、角色分配、密码重置、详情查看 |
| 家谱管理 | 家谱审核、编辑、启停、删除 |
| 系统设置 | 站点名称、主题色、验证码开关、邮件配置、SEO 设置 |
| 首页设置 | Hero 区域、统计数据、功能卡片、核心价值观、精选家谱展示 |
| 文章管理 | 分类、发布、置顶、浏览量统计 |
| 导航管理 | 自定义前台顶部/底部导航链接 |
| 自定义页面 | 创建独立页面，支持富文本编辑 |
| 友情链接 | 管理首页底部友情链接 |
| 操作日志 | 记录管理员关键操作，支持筛选和查看 |
| 邮件系统 | SMTP 配置、测试发送、密码重置邮件 |

### 👤 用户中心

| 功能 | 说明 |
|------|------|
| 注册登录 | 用户名/邮箱登录，支持"记住我" |
| 密码找回 | 邮箱验证码重置密码 |
| 个人资料 | 修改昵称、手机、微信、QQ、生日、地区、头像裁剪 |
| 我的家谱 | 管理自己创建的家谱列表 |

---

## 系统架构

```
┌─────────────────────────────────────────────────┐
│                    浏览器 / 客户端                 │
└─────────────────────┬───────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────┐
│                index.php (入口)                   │
│   ┌─────────────────────────────────────────┐   │
│   │         header_pre.php (预加载)           │   │
│   │  config.php → Env.php (.env 配置)        │   │
│   │  db.php (PDO + 预处理缓存)               │   │
│   │  auth.php (认证 + 授权 + 限流)            │   │
│   │  logger.php (操作日志)                     │   │
│   │  functions.php (工具函数)                  │   │
│   │  Flash.php (消息提示)                      │   │
│   │  Security.php (安全加固)                   │   │
│   └─────────────────────────────────────────┘   │
│                      │                          │
│   ┌──────────────────┼──────────────────┐       │
│   │                  │                  │       │
│   ▼                  ▼                  ▼       │
│  前台页面         后台管理            工具页面    │
│  (13个)           (13个)              (5个)     │
└─────────────────────────────────────────────────┘
                      │
┌─────────────────────▼───────────────────────────┐
│                  MySQL 数据库                      │
│   users | families | members | site_settings     │
│   logs | articles | slides | links               │
│   custom_pages | nav_items | password_resets     │
│   login_attempts | ip_blacklist                   │
└─────────────────────────────────────────────────┘
```

**架构模式**: 传统 MVC 简化版（无框架依赖）  
**数据库**: MySQL 5.7+ InnoDB + utf8mb4  
**安全层**: 多层防御（认证 → 授权 → 输入净化 → SQL 预处理 → 输出转义 → CSP）  
**缓存策略**: 站点设置请求级静态缓存 + PDO Statement 缓存  

---

## 技术栈

| 层级 | 技术 |
|------|------|
| 后端语言 | PHP 7.4+ |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| Web 服务器 | Nginx（推荐）/ Apache |
| 密码加密 | bcrypt（PHP password_hash） |
| 导出引擎 | PhpSpreadsheet (Excel) + TCPDF/FPDF (PDF) |
| 前端框架 | 无框架，原生 HTML5 + CSS3 + Vanilla JS |
| 图标 | SVG 内联图标 |
| 字体 | 得意黑（Smiley Sans）本地嵌入 |

---

## 环境要求

- **PHP** ≥ 7.4（推荐 8.0+）
- **MySQL** ≥ 5.7（推荐 8.0）
- **Nginx** / Apache
- **PHP 扩展**: `pdo`, `pdo_mysql`, `mbstring`, `gd`, `curl`, `openssl`, `fileinfo`

---

## 部署指南

### 一、本地开发（PhpStudy）

1. **克隆项目**
   ```bash
   git clone https://github.com/yourname/family-tree.git
   cd family-tree
   ```

2. **创建数据库**
   - 打开 PhpStudy → 数据库 → 新建数据库 `family_tree`，排序规则 `utf8mb4_unicode_ci`
   - 导入 `database.sql`

3. **配置环境**
   - 复制 `.env` 为生产配置，修改数据库连接信息：
   ```ini
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=family_tree
   DB_USER=root
   DB_PASS=你的密码
   DB_CHARSET=utf8mb4

   SITE_URL=http://127.0.0.2
   SITE_NAME=族谱传承
   APP_DEBUG=true

   # ⚠️ 生产环境务必修改
   SECURITY_SALT=随机生成一个长字符串
   ```

4. **启动服务**
   - PhpStudy → 启动 Nginx + MySQL
   - 访问 `http://127.0.0.2`

### 二、生产服务器部署

1. **上传文件** — 将所有文件上传到网站根目录
2. **创建数据库** — 在服务器 MySQL 中创建数据库并导入 `database.sql`
3. **修改 .env** — 填入服务器的数据库连接信息（特别注意 DB_HOST 通常为 `localhost`）
4. **设置权限** — `uploads/` 和 `logs/` 目录设置为可写（755 或 777）
5. **Nginx 配置** — 将 `nginx_security.conf` 内容加入站点配置
6. **删除文件** — 删除 `database.sql`（已导入，保留有安全风险）

### 三、默认管理员账号

| 项目 | 值 |
|------|-----|
| 用户名 | `admin` |
| 密码 | `admin123` |

> ⚠️ **登录后请立即修改默认密码！**

---

## 目录结构

```
├── .env                      # 环境配置（数据库、站点、安全盐值）
├── config.php                # 核心配置（常量定义、Session初始化、安全头）
├── database.sql              # 数据库初始化脚本（13张表 + 默认数据）
├── nginx_security.conf       # Nginx 安全配置参考
│
├── index.php                 # 首页（Hero + 统计 + 功能卡片 + 文章 + 精选家谱）
├── login.php                 # 用户登录（验证码 + 记住我 + 防暴力破解）
├── register.php              # 用户注册（邮箱格式校验 + 密码强度）
├── forgot_password.php       # 密码找回（邮箱验证码）
├── reset_password.php        # 重置密码
├── logout.php                # 安全登出
│
├── family.php                # 家谱馆（搜索、浏览、热门姓氏）
├── family_view.php           # 家谱详情（世系树 + 成员列表 + 封面预览）
├── family_create.php         # 创建新家谱
├── family_edit.php           # 编辑家谱信息
├── member_add.php            # 添加家族成员
├── member_edit.php           # 编辑成员信息
│
├── article.php               # 文章列表 + 单篇文章详情
├── page.php                  # 自定义页面路由
├── agreement.php             # 用户注册协议
├── privacy.php               # 隐私保护政策
├── cover_preview.php         # 封面预览生成
├── captcha.php               # 验证码图片生成
│
├── my.php                    # 用户中心 - 我的家谱
├── profile.php               # 个人资料编辑（含头像裁剪）
├── export.php                # 导出预览页面
├── export_download.php       # 实际导出下载
│
├── admin/                    # 后台管理面板
│   ├── index.php             # 控制台首页
│   ├── sidebar.php           # 侧边栏导航
│   ├── users.php             # 会员管理（搜素、启停、角色、密码重置）
│   ├── user_detail.php       # 会员详情（AJAX弹窗）
│   ├── families.php          # 家谱管理
│   ├── articles.php          # 文章管理（CRUD + 发布/置顶）
│   ├── settings.php          # 系统设置（站点配置、邮件、验证码）
│   ├── homepage.php          # 首页内容配置（Hero/统计/功能/价值观）
│   ├── nav_items.php         # 导航菜单管理
│   ├── custom_pages.php      # 自定义页面管理
│   ├── links.php             # 友情链接管理
│   ├── logs.php              # 操作日志查看
│   └── email.php             # 邮件系统配置与测试
│
├── includes/                 # 核心类库
│   ├── header_pre.php        # 预加载入口（所有页面前置）
│   ├── header.php            # HTML 头部 + 导航栏 + 页脚
│   ├── footer.php            # HTML 页脚 + 公共 JS
│   ├── db.php                # 数据库类（PDO封装 + 预处理缓存 + 慢查询日志 + 事务）
│   ├── auth.php              # 认证授权类（登录/注册/权限/限流）
│   ├── logger.php            # 操作日志记录
│   ├── Security.php          # 安全加固类（Session/CSRF/输入净化/上传验证/IP封禁）
│   ├── functions.php         # 通用工具函数（HTML转义/安全输出/分页/文件上传/站点设置）
│   ├── Flash.php             # Session 消息提示
│   ├── Env.php               # .env 环境变量加载器
│   ├── Mailer.php            # SMTP 邮件发送
│   ├── ExportEngine.php      # 导出数据引擎（聚合家族数据）
│   ├── CoverGenerator.php    # 家谱封面自动生成
│   └── CaptchaGenerator.php  # 验证码图片生成
│
├── assets/                   # 静态资源
│   ├── css/                  # 样式文件
│   │   ├── admin.css         # 后台管理样式
│   │   ├── front.css         # 前台整体样式
│   │   └── home.css          # 首页专属样式
│   ├── js/                   # JavaScript 脚本
│   │   └── *.js              # 裁剪/轮播/文件上传等工具函数
│   ├── img/                  # 图片资源
│   └── fonts/                # 得意黑（Smiley Sans）字体
│
├── uploads/                  # 用户上传文件目录（.htaccess 保护）
│   ├── avatar/               # 头像图片
│   ├── cover/                # 家谱封面
│   └── article/              # 文章配图
│
└── logs/                     # 日志目录（.htaccess 保护）
    └── php_error.log         # PHP 错误日志
```

---

## 数据库设计

### 核心表结构

#### users — 用户表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT UNSIGNED AUTO_INCREMENT | 主键 |
| username | VARCHAR(50) UNIQUE | 用户名 |
| email | VARCHAR(100) UNIQUE | 邮箱 |
| password | VARCHAR(255) | bcrypt 哈希 |
| nickname | VARCHAR(50) | 昵称 |
| avatar | VARCHAR(255) | 头像路径 |
| phone | VARCHAR(20) | 手机号 |
| wechat | VARCHAR(50) | 微信号 |
| qq | VARCHAR(20) | QQ号 |
| birthday | DATE | 生日 |
| region | VARCHAR(100) | 地区 |
| role | ENUM('user','admin') | 角色 |
| status | TINYINT | 1正常 0禁用 |
| last_login | DATETIME | 最后登录时间 |

#### families — 家谱表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT UNSIGNED AUTO_INCREMENT | 主键 |
| user_id | INT UNSIGNED | 创建者 |
| surname | VARCHAR(20) | 姓氏 |
| title | VARCHAR(100) | 谱名 |
| origin | TEXT | 起源 |
| description | TEXT | 简介 |
| cover_image | VARCHAR(255) | 封面图 |
| is_public | TINYINT | 是否公开 |
| allow_collaborate | TINYINT | 允许协作 |
| status | TINYINT | 1正常 0禁用 |

#### members — 成员表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT UNSIGNED AUTO_INCREMENT | 主键 |
| family_id | INT UNSIGNED | 所属家谱 |
| parent_id | INT UNSIGNED NULL | 父成员ID |
| name | VARCHAR(50) | 姓名 |
| gender | ENUM('male','female','unknown') | 性别 |
| birth_date | VARCHAR(20) | 生年 |
| death_date | VARCHAR(20) | 卒年 |
| spouse_name | VARCHAR(50) | 配偶名 |
| generation | SMALLINT | 世次 |
| biography | TEXT | 生平简介 |

### 完整数据表清单（13张）

| 表名 | 用途 |
|------|------|
| `users` | 用户账号 |
| `families` | 家谱信息 |
| `members` | 家族成员 |
| `site_settings` | 站点配置 |
| `articles` | 文章内容 |
| `slides` | 首页轮播图 |
| `links` | 友情链接 |
| `custom_pages` | 自定义页面 |
| `nav_items` | 导航菜单 |
| `logs` | 操作日志 |
| `password_resets` | 密码重置记录 |
| `login_attempts` | 登录尝试记录 |
| `ip_blacklist` | IP 黑名单 |

### 索引策略
- 高选择性字段（email, username, slug）使用 UNIQUE 索引
- 频繁查询的 WHERE 条件字段（status, is_published, role）使用 INDEX
- 外键关联字段（user_id, family_id, parent_id）使用 INDEX

---

## 安全特性

### 纵深防御体系

```
┌──────────────────────────────────────────────┐
│  第1层: 网络安全                               │
│  • Nginx 安全配置（隐藏版本、限制方法、防盗链）    │
│  • HTTPS 强制（生产环境推荐）                    │
│  • 目录保护（.htaccess 禁止执行 PHP）            │
├──────────────────────────────────────────────┤
│  第2层: 认证与授权                              │
│  • bcrypt 密码哈希 (cost=10)                    │
│  • Session 指纹绑定（IP + UA）                  │
│  • HttpOnly + SameSite=Lax Cookie              │
│  • 管理员页面强制鉴权 (Auth::requireAdmin)       │
│  • 登录频率限制 (5次/30min/IP)                   │
├──────────────────────────────────────────────┤
│  第3层: 输入安全                               │
│  • 全量 PDO 预处理 + 参数绑定                    │
│  • 递归清除 NULL 字节和控制字符                  │
│  • 文件上传 MIME 类型验证 + 图片真实性检测         │
│  • CSRF Token 校验（所有数据修改操作）            │
├──────────────────────────────────────────────┤
│  第4层: 输出安全                               │
│  • e() 函数默认 HTML 转义                       │
│  • safe_html() 白名单标签过滤 + 事件属性移除      │
│  • CSP 内容安全策略头                           │
│  • X-Frame-Options 防止点击劫持                 │
├──────────────────────────────────────────────┤
│  第5层: 监控与响应                              │
│  • IP 自动封禁（暴力破解检测）                    │
│  • 操作日志记录（关键操作审计追踪）               │
│  • 慢查询日志（自动记录超过1秒的SQL）             │
│  • 环境错误隐藏（生产模式不暴露细节）              │
└──────────────────────────────────────────────┘
```

---

## 后台管理

### 访问方式

```
http://你的域名/admin/
```

登录管理员账号后访问。

### 管理模块速览

| 路径 | 功能 |
|------|------|
| `/admin/` | 控制台 - 系统概览 |
| `/admin/users.php` | 会员管理 - 搜索、启停、角色、密码重置 |
| `/admin/families.php` | 家谱管理 - 审核、编辑 |
| `/admin/articles.php` | 文章管理 - 发布、编辑、置顶 |
| `/admin/settings.php` | 系统设置 - 站点信息、邮件、验证码 |
| `/admin/homepage.php` | 首页设置 - Hero/统计/功能卡片/价值观 |
| `/admin/nav_items.php` | 导航菜单 - 顶部/底部自定义链接 |
| `/admin/custom_pages.php` | 自定义页面 |
| `/admin/links.php` | 友情链接 |
| `/admin/logs.php` | 操作日志 - 审计追踪 |
| `/admin/email.php` | 邮件系统 - SMTP 配置与测试 |

---

## API 接口

系统为单体应用架构，数据交互通过表单 POST 和页面渲染完成，暂未提供独立 REST API。

如需 API 化改造，建议架构：
- `/api/v1/families` — 家谱资源
- `/api/v1/members` — 成员资源
- 使用现有 Auth 类做 JWT 鉴权改造

---

## 常见问题

<details>
<summary><strong>Q: 部署后显示"系统维护中，请稍后再试"？</strong></summary>

这是数据库连接失败。请检查 `.env` 文件中的数据库配置是否正确：
- **DB_HOST**: 云虚拟主机通常用 `localhost` 而非 `127.0.0.1`
- **DB_PORT**: 部分主机商使用非默认端口
- **DB_USER / DB_PASS**: 使用主机商分配的数据库账号
- **DB_NAME**: 确保已在服务器上创建了同名数据库

设置 `.env` 中 `APP_DEBUG=true` 可查看具体错误信息。
</details>

<details>
<summary><strong>Q: 验证码不显示？</strong></summary>

确保 PHP 已开启 `gd` 扩展。检查 `includes/CaptchaGenerator.php` 是否正确输出图片头。
</details>

<details>
<summary><strong>Q: 邮件发送失败？</strong></summary>

在后台 `管理员 → 邮件系统` 中配置正确的 SMTP 参数。常见云邮箱配置：
- QQ邮箱：smtp.qq.com:465，需开启授权码
- 163邮箱：smtp.163.com:465
</details>

<details>
<summary><strong>Q: 上传图片失败？</strong></summary>

1. 检查 `uploads/` 目录是否有写权限
2. 确保上传文件大小在限制内（默认 5MB，可在 `.env` 中修改 `UPLOAD_MAX_SIZE`）
3. 检查 `php.ini` 中 `upload_max_filesize` 和 `post_max_size`
</details>

<details>
<summary><strong>Q: 如何备份数据？</strong></summary>

1. **数据库备份**: 使用 phpMyAdmin 导出，或 `mysqldump -u root -p family_tree > backup.sql`
2. **文件备份**: 备份 `uploads/` 目录和 `.env` 文件
3. **推荐**: 设置定时任务每天自动备份
</details>

---

## 更新日志

### v2.0.0 (2026-06)

**安全加固**
- 全面重构安全体系：Session 指纹绑定、CSRF 保护、IP 封禁、输入净化
- 引入 Security.php 统一安全中间件
- 数据库 PDO 预处理缓存 + 慢查询日志 + 事务封装
- 安全响应头（CSP、X-Frame-Options 等）
- 文件上传 MIME 验证 + 图片真实性检测

**功能增强**
- 新增后台首页内容配置（Hero 区域、统计数据、功能卡片、核心价值观）
- 自定义导航菜单管理（支持顶部/底部/新窗口打开）
- 友情链接管理
- 自定义页面系统（独立路由，富文本编辑）
- 邮件系统配置与测试
- 家谱封面自动生成（姓氏古风设计）
- 数据导出：Excel + PDF 双格式
- 头像裁剪上传

**代码优化**
- 敏感配置移至 .env 文件
- 统一 header_pre.php 预加载入口
- 站点设置请求级静态缓存

### v1.0.0
- 家谱 CRUD + 世系树展示
- 用户注册登录 + 个人中心
- 文章发布系统
- 后台管理基础框架

---

## 贡献指南

欢迎提交 Issue 和 Pull Request！

### 开发流程

1. Fork 本仓库
2. 创建特性分支 `git checkout -b feature/amazing-feature`
3. 提交更改 `git commit -m 'Add amazing feature'`
4. 推送到分支 `git push origin feature/amazing-feature`
5. 提交 Pull Request

### 代码规范

- PHP 遵循 PSR-12 编码风格
- 所有用户输入必须经过参数绑定或安全过滤
- 新功能需同步更新数据库初始化脚本 `database.sql`
- 安全相关代码需经过 `Security` 类封装

---

## License

本项目基于 MIT 许可证开源。详见 [LICENSE](LICENSE) 文件。

---

<p align="center">
  <sub>Made with ❤️ for family heritage preservation</sub>
</p>
