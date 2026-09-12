<?php
/**
 * 客服系统 - 数据库安装脚本
 * 运行此文件来创建数据库表
 */
session_start();

require_once dirname(__FILE__) . '/config.php';

$message = '';
$error = '';

// 创建数据库
if (isset($_POST['install'])) {
    try {
        // 使用表单输入的密码或config.php中的密码
        $dbPass = !empty($_POST['db_pass']) ? $_POST['db_pass'] : DB_PASS;
        
        // 连接MySQL（不指定数据库）
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        
        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . DB_NAME . "`");
        
        $prefix = DB_PREFIX;
        
        // 创建客户端表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$prefix}clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(64) NOT NULL UNIQUE,
                user_id VARCHAR(64) DEFAULT NULL,
                nickname VARCHAR(128) NOT NULL,
                email VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(32) DEFAULT NULL,
                ip VARCHAR(45) NOT NULL,
                user_agent TEXT,
                first_visit INT NOT NULL,
                last_active INT NOT NULL,
                is_online TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_client_id (client_id),
                INDEX idx_user_id (user_id),
                INDEX idx_last_active (last_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // 创建消息表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$prefix}messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id VARCHAR(32) NOT NULL UNIQUE,
                client_id VARCHAR(64) NOT NULL,
                sender VARCHAR(64) NOT NULL,
                sender_name VARCHAR(128) NOT NULL,
                message TEXT,
                image_url VARCHAR(255) DEFAULT NULL,
                is_admin TINYINT(1) DEFAULT 0,
                is_read TINYINT(1) DEFAULT 0,
                timestamp INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client_id (client_id),
                INDEX idx_timestamp (timestamp),
                INDEX idx_sender (sender),
                FOREIGN KEY (client_id) REFERENCES {$prefix}clients(client_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // 创建设置表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$prefix}settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(64) NOT NULL UNIQUE,
                setting_value TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // 创建上传目录
        if (!is_dir(dirname(__FILE__) . '/uploads')) {
            mkdir(dirname(__FILE__) . '/uploads', 0755, true);
            file_put_contents(dirname(__FILE__) . '/uploads/.htaccess', "Options -Indexes\ndeny from all\n");
        }
        
        $message = '✅ 数据库安装成功！<br>请将 config.php 中的密码改为你自己的密码';
        
    } catch (PDOException $e) {
        $error = '安装失败: ' . $e->getMessage();
    }
}
?>