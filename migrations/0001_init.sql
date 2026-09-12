-- LiveChat D1 初始化迁移
-- 与原版 PHP 表结构保持一致（clients / messages / settings）

CREATE TABLE IF NOT EXISTS clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id TEXT NOT NULL UNIQUE,
  user_id TEXT,
  nickname TEXT NOT NULL,
  email TEXT,
  phone TEXT,
  ip TEXT NOT NULL,
  user_agent TEXT,
  first_visit INTEGER NOT NULL,
  last_active INTEGER NOT NULL,
  is_online INTEGER DEFAULT 1,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_clients_client_id ON clients(client_id);
CREATE INDEX IF NOT EXISTS idx_clients_user_id ON clients(user_id);
CREATE INDEX IF NOT EXISTS idx_clients_last_active ON clients(last_active);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id TEXT NOT NULL UNIQUE,
  client_id TEXT NOT NULL,
  sender TEXT NOT NULL,
  sender_name TEXT NOT NULL,
  message TEXT,
  image_url TEXT,
  is_admin INTEGER DEFAULT 0,
  is_read INTEGER DEFAULT 0,
  timestamp INTEGER NOT NULL,
  created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_messages_client_id ON messages(client_id);
CREATE INDEX IF NOT EXISTS idx_messages_timestamp ON messages(timestamp);
CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender);

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  setting_key TEXT NOT NULL UNIQUE,
  setting_value TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  updated_at TEXT DEFAULT (datetime('now'))
);

-- 默认设置（与后台可改项一致；admin_password_hash 由首次登录时写入）
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
  ('chat_title', '在线客服'),
  ('admin_name', '客服 Live chat'),
  ('telegram_enabled', '1'),
  ('telegram_token', ''),
  ('telegram_admin_id', ''),
  ('wecom_enabled', '0'),
  ('wecom_webhook', '');
