<?php
/**
 * 客服系统配置文件
 */
!defined('DEBUG_MODE') && define('DEBUG_MODE', false);
// 数据库配置（与主系统保持一致）
!defined('DB_HOST') && define('DB_HOST', 'localhost');
!defined('DB_NAME') && define('DB_NAME', 'livechat');
!defined('DB_USER') && define('DB_USER', 'your_db_user');
!defined('DB_PASS') && define('DB_PASS', 'your_db_password');
!defined('DB_PREFIX') && define('DB_PREFIX', 'lc_');

!defined('TELEGRAM_ENABLED') && define('TELEGRAM_ENABLED', true);
!defined('TELEGRAM_BOT_TOKEN') && define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN');
!defined('TELEGRAM_ADMIN_ID') && define('TELEGRAM_ADMIN_ID', 'YOUR_TELEGRAM_ADMIN_ID');
!defined('TELEGRAM_NOTIFY_INTERVAL') && define('TELEGRAM_NOTIFY_INTERVAL', 600);

!defined('CHAT_TITLE') && define('CHAT_TITLE', '在线客服');
!defined('ADMIN_NAME') && define('ADMIN_NAME', '客服 Live chat');
!defined('MAX_MESSAGE_HISTORY') && define('MAX_MESSAGE_HISTORY', 100);
!defined('MAX_UPLOAD_SIZE') && define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);
!defined('ALLOWED_IMAGE_TYPES') && define('ALLOWED_IMAGE_TYPES', array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'));

!defined('SESSION_TIMEOUT') && define('SESSION_TIMEOUT', 86400);
!defined('BLOCKED_IPS') && define('BLOCKED_IPS', '');

// 登录页面 URL（用于强制登录跳转）
!defined('LOGIN_URL') && define('LOGIN_URL', '/login.php');

!defined('IN_LIVECHAT') && define('IN_LIVECHAT', true);
!defined('LC_UPLOAD_DIR') && define('LC_UPLOAD_DIR', dirname(__FILE__) . '/uploads/');

function getChatPath($full_url = true) {
    $protocol = 'http';
    $host = 'localhost';
    $script_dir = '';

    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    }
    if (isset($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    }
    if (isset($_SERVER['SCRIPT_NAME'])) {
        $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    }

    if (basename($script_dir) === 'api') {
        $script_dir = dirname($script_dir);
    }

    $path = rtrim($script_dir, '/');

    if ($full_url) {
        return $protocol . '://' . $host . $path;
    }
    return $path;
}

!defined('LC_SITE_URL') && define('LC_SITE_URL', getChatPath(true));
!defined('LC_UPLOAD_URL') && define('LC_UPLOAD_URL', LC_SITE_URL . '/uploads/');

require_once dirname(__FILE__) . '/database.php';
