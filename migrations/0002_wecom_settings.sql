-- LiveChat D1 迁移 0002：新增企业微信机器人通知设置
-- 幂等，可安全重复执行
INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES
  ('wecom_enabled', '0'),
  ('wecom_webhook', '');
