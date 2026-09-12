<?php
/**
 * 客服系统 - 通用工具函数
 */
 
/**
 * 获取客户端IP地址
 */
function getClientIP() {
    $ip = '127.0.0.1';
    
    // 代理服务器传递的真实IP
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    // 验证IP格式
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ip = '127.0.0.1';
    }
    
    return $ip;
}

/**
 * 检查IP是否被封禁
 */
function isIPBlocked($ip) {
    $blocked = explode(',', BLOCKED_IPS);
    $blocked = array_map('trim', $blocked);
    return in_array($ip, $blocked);
}

/**
 * 获取当前用户信息
 * 用于集成网站现有登录系统
 * 
 * @return array ['user_id', 'nickname', 'email'] 或 ['guest']
 */
function getCurrentUser() {
    $user = array(
        'user_id' => null,
        'nickname' => null,
        'email' => null,
        'phone' => null,
        'is_guest' => true
    );
    
    // 检查主系统的会话
    if (function_exists('currentUser')) {
        $currentUser = currentUser();
        if ($currentUser !== null) {
            $user['user_id'] = $currentUser['id'];
            $user['nickname'] = $currentUser['username'];
            $user['email'] = null;
            $user['phone'] = null;
            $user['is_guest'] = false;
            return $user;
        }
    }
    
    // 备用方案：直接检查 $_SESSION['user']
    if (isset($_SESSION['user']) && !empty($_SESSION['user'])) {
        $user['user_id'] = $_SESSION['user']['id'];
        $user['nickname'] = $_SESSION['user']['username'];
        $user['email'] = null;
        $user['phone'] = null;
        $user['is_guest'] = false;
        return $user;
    }
    
    // 默认：访客
    if ($user['is_guest']) {
        $ip = getClientIP();
        $user['user_id'] = 'guest_' . md5($ip);
        $user['nickname'] = $ip;
    }
    
    return $user;
}

/**
 * 生成客户端ID
 */
function generateClientID($user, $ip) {
    if (!empty($user['user_id']) && !$user['is_guest']) {
        return 'user_' . md5($user['user_id']);
    }
    return 'guest_' . md5($ip);
}

/**
 * 格式化文件大小
 */
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 B';
    $sizes = array('B', 'KB', 'MB', 'GB');
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $sizes[$i];
}

/**
 * 将链接转换为可点击
 */
function makeLinksClickable($text) {
    $pattern = '/(https?:\/\/[^\s<]+)/i';
    $replacement = '<a href="$1" target="_blank" rel="noopener noreferrer" class="chat-link">$1</a>';
    return preg_replace($pattern, $replacement, $text);
}

/**
 * 发送Telegram通知
 */
function sendTelegramNotification($clientIP, $message, $hasImage = false) {
    if (!defined('TELEGRAM_ENABLED') || !TELEGRAM_ENABLED || !defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN) || !defined('TELEGRAM_ADMIN_ID') || empty(TELEGRAM_ADMIN_ID)) {
        return false;
    }
    
    // 防重复通知
    $lastNotificationKey = 'last_tg_' . md5($clientIP);
    if (isset($_SESSION[$lastNotificationKey])) {
        $timePassed = time() - $_SESSION[$lastNotificationKey];
        $interval = defined('TELEGRAM_NOTIFY_INTERVAL') ? TELEGRAM_NOTIFY_INTERVAL : 600;
        if ($timePassed < $interval) {
            return false;
        }
    }
    
    $botToken = TELEGRAM_BOT_TOKEN;
    $adminChatId = TELEGRAM_ADMIN_ID;
    
    // 截断消息
    $truncatedMsg = $hasImage ? '[图片消息] ' : '';
    $truncatedMsg .= mb_strlen($message) > 100 ? mb_substr($message, 0, 100) . '...' : $message;
    
    // 管理员面板URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = isset($_SERVER['REQUEST_URI']) ? dirname($_SERVER['REQUEST_URI']) : '';
    $adminUrl = $protocol . '://' . $host . $path . '/admin.php';
    
    $text = "📱 *新客服消息*" . "\n";
    $text .= "─────────────────" . "\n";
    $text .= "👤 *IP:* `" . $clientIP . "`" . "\n";
    $text .= "⏰ *时间:* " . date('Y-m-d H:i:s') . "\n";
    $text .= "💬 *内容:* " . $truncatedMsg . "\n";
    $text .= "─────────────────" . "\n";
    $text .= "[👨‍💻 进入管理后台](" . $adminUrl . ")";

    $data = array(
        'chat_id' => $adminChatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => false
    );
    
    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    
    $options = array(
        'http' => array(
            'header' => "Content-type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 10
        )
    );
    
    try {
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result !== false) {
            $_SESSION[$lastNotificationKey] = time();
            return true;
        }
    } catch (Exception $e) {
        error_log("Telegram notification error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * 处理图片上传
 */
function handleImageUpload($file) {
    $uploadDir = defined('LC_UPLOAD_DIR') ? LC_UPLOAD_DIR : dirname(__FILE__) . '/../uploads/';
    $maxSize = defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : 5 * 1024 * 1024;
    $allowedTypes = defined('ALLOWED_IMAGE_TYPES') ? ALLOWED_IMAGE_TYPES : array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp');
    
    $result = array('success' => false, 'error' => '', 'file_name' => '');
    
    // 检查上传错误
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors = array(
            UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '临时目录不存在',
            UPLOAD_ERR_CANT_WRITE => '无法写入文件',
            UPLOAD_ERR_EXTENSION => '文件上传被阻止'
        );
        $result['error'] = isset($errors[$file['error']]) ? $errors[$file['error']] : '上传错误: ' . (isset($file['error']) ? $file['error'] : '未知');
        return $result;
    }
    
    // 检查文件大小
    if ($file['size'] > $maxSize) {
        $result['error'] = '文件过大 (最大 ' . formatFileSize($maxSize) . ')';
        return $result;
    }
    
    // 检查文件类型
    $mimeType = '';
    
    // 方法1: 使用 fileinfo 扩展（推荐）
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }
    
    // 方法2: 使用 mime_content_type（备用）
    if (empty($mimeType) && function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file['tmp_name']);
    }
    
    // 方法3: 使用文件扩展名（最后手段）
    if (empty($mimeType)) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeMap = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        );
        $mimeType = isset($mimeMap[$ext]) ? $mimeMap[$ext] : '';
    }
    
    // 如果仍然无法检测类型，使用 $_FILES 提供的类型
    if (empty($mimeType) && !empty($file['type'])) {
        $mimeType = $file['type'];
    }
    
    if (!in_array($mimeType, $allowedTypes)) {
        $result['error'] = '不支持的文件类型: ' . ($mimeType ?: '未知');
        return $result;
    }
    
    // 创建上传目录
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        // 添加.htaccess保护
        @file_put_contents($uploadDir . '.htaccess', "Options -Indexes\ndeny from all\n");
    }
    
    // 生成唯一文件名（只生成一次，确保一致性）
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (empty($extension)) {
        $extension = 'jpg';
    }
    $timestamp = time();
    $randomBytes = bin2hex(generateRandomBytes(4));
    $fileName = 'img_' . $timestamp . '_' . $randomBytes . '.' . $extension;
    $filePath = $uploadDir . $fileName;
    
    // 尝试压缩图片
    $compressedPath = $uploadDir . 'compressed_' . $fileName;
    $compressionSuccess = false;
    $savedFileName = null;
    
    // 调试日志
    $debugLog = "[Image Upload] fileName: $fileName, filePath: $filePath, compressedPath: $compressedPath\n";
    
    if (compressImage($file['tmp_name'], $compressedPath)) {
        $debugLog .= "[Image Upload] compressImage returned true\n";
        $debugLog .= "[Image Upload] file_exists(compressedPath): " . (file_exists($compressedPath) ? 'true' : 'false') . "\n";
        $debugLog .= "[Image Upload] filesize(compressedPath): " . (file_exists($compressedPath) ? filesize($compressedPath) : 'N/A') . "\n";
        
        if (file_exists($compressedPath) && filesize($compressedPath) > 0) {
            // 压缩成功，使用压缩后的文件
            $compressionSuccess = true;
            $savedFileName = 'compressed_' . $fileName;
            $debugLog .= "[Image Upload] Using compressed file: $savedFileName\n";
        } else {
            // 压缩后文件为空或不存在，删除并使用原始文件
            @unlink($compressedPath);
            $debugLog .= "[Image Upload] Compressed file invalid, using original\n";
        }
    } else {
        $debugLog .= "[Image Upload] compressImage returned false\n";
    }
    
    // 如果压缩失败或压缩后文件无效，使用原始文件
    if (!$compressionSuccess) {
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            $result['error'] = '文件保存失败';
            error_log($debugLog . "[Image Upload] move_uploaded_file failed\n");
            return $result;
        }
        $savedFileName = $fileName;
        $debugLog .= "[Image Upload] Using original file: $savedFileName\n";
    }
    
    // 验证文件确实存在
    $finalPath = $uploadDir . $savedFileName;
    $debugLog .= "[Image Upload] finalPath: $finalPath\n";
    $debugLog .= "[Image Upload] file_exists(finalPath): " . (file_exists($finalPath) ? 'true' : 'false') . "\n";
    
    if ($savedFileName && file_exists($finalPath)) {
        $result['success'] = true;
        $result['file_name'] = $savedFileName;
        error_log($debugLog . "[Image Upload] SUCCESS: $savedFileName\n");
    } else {
        $result['error'] = '文件保存失败：文件不存在';
        error_log($debugLog . "[Image Upload] FAILED: file does not exist\n");
    }
    
    return $result;
}

/**
 * 生成随机字节（兼容旧版PHP）
 */
function generateRandomBytes($length) {
    if (function_exists('random_bytes')) {
        return random_bytes($length);
    } elseif (function_exists('openssl_random_pseudo_bytes')) {
        return openssl_random_pseudo_bytes($length);
    } else {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }
}

/**
 * 压缩图片
 */
function compressImage($source, $destination, $quality = 75) {
    if (!extension_loaded('gd')) {
        return false;
    }
    
    $info = @getimagesize($source);
    if (!$info) {
        return false;
    }
    
    $image = null;
    $success = false;
    
    switch ($info['mime']) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = @imagecreatefromjpeg($source);
            if ($image) {
                $success = imagejpeg($image, $destination, $quality);
            }
            break;
        case 'image/png':
            $image = @imagecreatefrompng($source);
            if ($image) {
                imagesavealpha($image, true);
                $pngQuality = 9 - round($quality / 100 * 9);
                $success = imagepng($image, $destination, $pngQuality);
            }
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($source);
            if ($image) {
                $success = imagegif($image, $destination);
            }
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($source);
            if ($image) {
                $success = imagewebp($image, $destination, $quality);
            }
            break;
    }
    
    if ($image) {
        imagedestroy($image);
    }
    
    return $success && file_exists($destination);
}

/**
 * 时间友好显示
 * 注意：主站 /includes/functions.php 已定义 timeAgo()，此处仅作后备
 */
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $diff = time() - $timestamp;
        
        if ($diff < 60) return '刚刚';
        if ($diff < 3600) return floor($diff / 60) . '分钟前';
        if ($diff < 86400) return floor($diff / 3600) . '小时前';
        if ($diff < 604800) return floor($diff / 86400) . '天前';
        
        return date('Y-m-d', $timestamp);
    }
}
