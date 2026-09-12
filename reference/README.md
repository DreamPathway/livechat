# 在线客服系统

一个可集成到任意PHP网站的在线客服系统，支持用户登录认证、消息管理、数据库存储、Telegram通知。

## 功能特性

- ✅ 用户认证集成 - 自动识别已登录用户，显示历史聊天记录
- ✅ 数据库存储 - 支持MySQL，比JSON更稳定高效
- ✅ 实时消息 - 长轮询实现实时聊天
- ✅ 图片上传 - 支持图片上传和预览
- ✅ Telegram通知 - 新消息实时推送到Telegram
- ✅ 管理后台 - 完整的客户管理和消息回复功能
- ✅ 响应式设计 - 完美适配PC和移动端

## 目录结构

```
livechat/
├── config.php          # 配置文件（核心）
├── database.php        # 数据库类
├── chat.php            # 客户端聊天页面
├── admin..php          # 管理后台
├── install.php         # 安装向导
├── api/
│   ├── messages.php    # 消息API
│   └── upload.php      # 上传API
├── includes/
│   ├── functions.php   # 通用函数
│   └── client_functions.php  # 客户端函数
├── uploads/            # 上传目录
├── README.md           # 说明文档
└── DEMO.php            # 集成示例
```

## 快速开始

### 1. 配置数据库

复制 `config.php` 为 `config.local.php`，或直接修改配置：

```php
// config.php
define('DB_HOST', 'localhost');      // 数据库地址
define('DB_NAME', 'livechat');       // 数据库名
define('DB_USER', 'root');           // 用户名
define('DB_PASS', 'your_password');  // 密码
define('DB_PREFIX', 'lc_');          // 表前缀
```

### 2. 安装数据库

访问 `install.php` 按提示完成安装：
```
http://你的域名/livechat/install.php
```

### 3. 配置用户认证

编辑 `includes/functions.php` 中的 `getCurrentUser()` 函数，根据你的网站修改：

```php
// 方式1: 原生PHP Session
if (isset($_SESSION['user_id'])) {
    $user['user_id'] = $_SESSION['user_id'];
    $user['nickname'] = $_SESSION['nickname'];
    $user['email'] = $_SESSION['email'] ?? null;
    $user['is_guest'] = false;
}

// 方式2: ThinkPHP
if (session('?user_id')) {
    $user['user_id'] = session('user_id');
    $user['nickname'] = session('nickname');
    // ...
}

// 方式3: Laravel
if (Auth::check()) {
    $user['user_id'] = Auth::id();
    $user['nickname'] = Auth::user()->name;
    // ...
}
```

### 4. 集成到网站

**方式A: 直接嵌入**
```php
<?php include '/path/to/livechat/chat.php'; ?>
```

**方式B: Iframe嵌入**
```html
<iframe src="/livechat/chat.php" style="width:100%;height:600px;border:none;"></iframe>
```

**方式C: 弹窗形式**
```html
<button onclick="window.open('/livechat/chat.php','chat','width=450,height=600')">联系客服</button>
```

### 5. 配置Telegram通知

在 `config.php` 中配置：

```php
define('TELEGRAM_ENABLED', true);
define('TELEGRAM_BOT_TOKEN', '你的BotToken');
define('TELEGRAM_ADMIN_ID', '你的TelegramID');
```

获取BotToken: @BotFather
获取Admin ID: @userinfobot

### 6. 管理后台

访问：`http://你的域名/livechat/admin.php`

默认密码：`admin123`（请修改）

## 配置说明

### config.php 完整配置

```php
// 数据库
define('DB_HOST', 'localhost');
define('DB_NAME', 'livechat');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PREFIX', 'lc_');

// Telegram
define('TELEGRAM_ENABLED', true);
define('TELEGRAM_ BOT_TOKEN', '');
define('TELEGRAM_ ADMIN_ID', '');
define('TELEGRAM_ NOTIFY_INTERVAL', 600);

// 系统
define('CHAT_ TITLE', '在线客服');
define('ADMIN_ NAME', '客服');
define('MAX_ MESSAGE_ HISTORY', 100);
define('MAX_ UPLOAD_ SIZE', 5 * 1024 * 1024);
define('SESSION_ TIMEOUT', 86400);

// IP黑名单（逗号分隔）
define('BLOCKED_IPS', '');
```

## 管理后台功能

- 📋 客户列表 - 显示所有咨询客户
- 💬 消息管理 - 查看、回复、删除消息  
- ✅ 标记已读 - 一键标记客户消息为已读
- ⚙️ 系统设置 - 修改客服名称、配置Telegram
- 🔔 实时推送 - 新消息Telegram通知

## 部署到宝塔面板

1. **创建网站**: 在宝塔中创建PHP站点
2. **上传文件**: 将整个livechat文件夹上传到网站根目录
3. **创建数据库**: 宝塔面板 → 数据库 → 创建数据库
4. **修改配置**: 编辑config.php填入数据库信息
5. **访问安装**: 访问 http://域名/livechat/install.php
6. **测试使用**: 访问 http://域名/livechat/chat.php

## 常见问题

### Q: 如何修改管理员密码？
A: 编辑 `admin.php`，找到 `$adminPassword = 'admin123';` 修改

### Q: 图片上传失败怎么办？
A: 检查 uploads 目录是否有写权限，检查PHP上传限制

### Q: Telegram通知收不到？
A: 检查Bot Token和管理员ID是否正确，确认服务器能访问telegram.org

### Q: 如何迁移到新服务器？
A: 导出数据库，将整个文件夹复制到新服务器，修改config.php中的数据库配置

## 更新日志

### v2.0 (2026-04-08)
- 全新MySQL数据库架构
- 模块化API设计
- 完善的管理后台
- 支持用户登录认证集成

### v1.x
- 基于JSON存储的旧版本
