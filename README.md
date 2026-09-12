# LiveChat — Cloudflare Workers 在线客服系统

基于 **Cloudflare Workers** 的轻量在线客服系统：访客聊天（文字 + 图片）、管理后台、Telegram / 企业微信群机器人新消息通知、定时数据清理。原生 Hono + TypeScript 实现，无服务器、零运维。

> 本项目由同名 PHP 版在线客服系统（见 [`reference/`](reference/)）迁移重构而来。

## 功能特性

- 💬 **访客聊天** — 文字 + 图片上传（存 R2），长轮询实时收发，链接自动可点击
- 👨‍💼 **管理后台** — 客户列表 / 消息回复 / 标记已读 / 删除 / 系统设置 / 修改密码
- 🔐 **安全认证** — PBKDF2-SHA256(100k) 密码哈希 + HMAC-SHA256 会话 Cookie（30 天），改密自动轮换会话密钥
- 📱 **Telegram 通知** — 新消息实时推送到管理员（600 秒/客户端节流）
- 💼 **企业微信通知** — 群机器人 webhook 推送（与 Telegram 并行、独立节流）
- 🧹 **定时清理** — 每日 03:00 UTC：每客户端保留最新 20% 消息（最少 50 条），删除 90 天无消息的僵尸客户端
- 📦 **零配置部署** — `npm run setup` 一键创建 D1 + R2、回填配置、执行迁移
- 📲 **PWA 管理后台** — 可安装到桌面 / 手机主屏，离线可用，独立窗口运行

## 技术栈

| 层 | 技术 |
|---|---|
| 运行时 | Cloudflare Workers（`nodejs_compat`） |
| 框架 | Hono（TypeScript） |
| 数据库 | D1（SQLite，`lc-db`） |
| 对象存储 | R2（`lc-media`，聊天图片 / 管理员头像） |
| 静态资源 | Workers Assets（`static/`，聊天页 + 管理后台） |
| 定时任务 | Cron `0 3 * * *`（UTC） |

## 目录结构

```
livechat-system-worker/
├── src/                 # Worker 源码
│   ├── index.ts         # 入口：路由 / 图片 / 定时清理
│   ├── chat.ts          # 客户端聊天 API（init/send/get_messages/check_new）
│   ├── admin.ts         # 管理后台 API（登录/客户/消息/设置）
│   ├── auth.ts          # PBKDF2 密码哈希 + HMAC 会话
│   ├── db.ts            # D1 访问与设置表工具
│   ├── telegram.ts      # Telegram 通知（节流）
│   ├── wecom.ts         # 企业微信机器人通知（节流）
│   ├── cleanup.ts       # 每日数据清理逻辑
│   └── util.ts          # 通用小工具
├── static/              # Workers Assets 静态页面
│   ├── index.html       # 访客聊天页
│   ├── admin.html       # 管理后台页（PWA 入口）
│   ├── manifest.webmanifest  # PWA 清单（安装到桌面/主屏）
│   ├── sw.js            # Service Worker（管理后台离线缓存）
│   └── icons/           # PWA 图标（SVG）
├── migrations/          # D1 迁移（幂等）
├── scripts/
│   ├── setup.mjs        # 一键初始化（建 D1/R2、回填 ID、迁移、生成密码）
│   ├── formal_secret_scan.mjs  # 敏感信息扫描（提交前运行）
│   └── validate-jsonc.mjs      # wrangler.jsonc 语法校验
├── reference/           # 原 PHP 版参考实现（仅参考；uploads/ 含真实数据不发布）
├── wrangler.jsonc       # Worker 配置（D1 / R2 / Assets / Cron）
├── AGENTS.md            # AI / 开发者部署指南（必读）
└── .dev.vars.example    # 本地环境变量示例
```

## 快速开始

```bash
# 1) 安装依赖
npm install

# 2) 一键初始化（需 CLOUDFLARE_API_TOKEN，权限见 AGENTS.md 第 3 节）
#    创建 D1 lc-db + R2 lc-media → 回填 database_id → 执行迁移 → 生成 ADMIN_PASSWORD
npm run setup

# 3) 本地预览
npm run dev            # http://localhost:8787（后台：/admin）

# 4) 部署到 workers.dev
npm run deploy

# 5) 设置管理员初始密码（setup 未写入成功时才需要）
npx wrangler secret put ADMIN_PASSWORD
```

部署后打开 `https://<your-worker>.<your-account>.workers.dev/admin`，用 `ADMIN_PASSWORD` 首次登录（自动写入 PBKDF2 哈希），然后**立即在后台修改密码**。

### 可选配置（不填则功能降级，聊天/图片/后台不受影响）

```bash
# Telegram 通知
npx wrangler secret put TELEGRAM_BOT_TOKEN   # @BotFather 创建机器人获取
npx wrangler secret put TELEGRAM_ADMIN_ID    # @userinfobot 查询

# 企业微信机器人通知（完整 URL 或仅 key）
npx wrangler secret put WECOM_WEBHOOK_URL

# 通知消息里管理后台链接的基础 URL（默认取请求来源）
npx wrangler secret put APP_BASE_URL
```

也可以在后台 `/admin → 系统设置` 填写（env secret 优先级更高）。

> 📖 完整部署指南（Token 权限清单 / secrets 清单表 / 报错对照表）见 **[`AGENTS.md`](AGENTS.md)**。
>
> 📚 **图文部署教程**：https://opcgrow.org/article.php?id=130

## API 概览

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/chat` | `init_client` / `send`（含图片）/ `get_messages` / `check_new` |
| POST | `/api/admin` | 登录 / 客户列表 / 消息 / 发送 / 删除 / 设置 / 改密码 |
| GET | `/api/meta` | 站点元信息（聊天页标题 / 客服名） |
| GET | `/api/health` | 健康检查 |
| GET | `/media/*` | 从 R2 读取聊天图片 |
| GET | `/` | 访客聊天页（Assets） |
| GET | `/admin` | 管理后台（Assets） |

## 定时清理

每日 03:00 UTC 自动执行（`wrangler.jsonc` → `triggers.crons`）：
- 每客户端消息超 50 条时，仅保留最新 20%，删除更旧记录
- 删除 90 天无消息且未活跃的僵尸客户端

## reference/ 目录说明

`reference/` 保留原 PHP 版实现（`config.php` / `database.php` / `chat.php` / `admin.php` / `api/*` 等），用于对照迁移逻辑。其中 `config.php` 等文件的敏感配置已替换为占位符；`reference/uploads/` 含真实聊天数据，**已通过 `.gitignore` 排除，不随本仓库发布**。

## 开发命令

| 命令 | 说明 |
|---|---|
| `npm run dev` | 本地开发（端口 8787） |
| `npm run deploy` | 部署到 Cloudflare |
| `npm run setup` | 一键初始化资源（幂等） |
| `npm run scan:secrets` | 敏感信息扫描（提交前运行，应 0 命中） |
| `npm run check:config` | 校验 wrangler.jsonc |
| `npm run db:migrate:local` / `:remote` | 执行 D1 迁移 |

## License

MIT
