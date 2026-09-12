<?php
/**
 * 客服系统 - 管理后台
 */

// 错误处理：在 AJAX 请求中禁用错误输出
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    (isset($_POST['action']) || isset($_FILES['image']))) {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/includes/functions.php';

// 初始化数据库
try {
    $db = getDB();
    $db->initTables();
} catch (Exception $e) {
    die('数据库错误: ' . $e->getMessage());
}

// 管理员密码
$adminPassword = 'change_me'; // 修改为你的密码

// 处理登录
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $adminPassword) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
    } else {
        $loginError = '密码错误';
    }
}

// 处理AJAX请求
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 检查 action 参数，支持 FormData 提交
        $action = isset($_POST['action']) ? $_POST['action'] : (isset($_FILES['image']) ? 'send_message' : '');
        
        if ($action) {
            header('Content-Type: application/json');
            
            switch ($action) {
                case 'get_clients':
                    echo json_encode(getClientsList($db));
                    break;
                case 'get_messages':
                    echo getClientMessages($db, $_POST['client_id']);
                    break;
                case 'send_message':
                    echo json_encode(sendAdminMessage($db, $_POST['client_id'], $_POST['message'] ?? ''));
                    break;
                case 'delete_message':
                    deleteMessage($db, $_POST['client_id'], $_POST['message_id']);
                    echo json_encode(['success' => true]);
                    break;
                case 'mark_read':
                    markAllRead($db, $_POST['client_id']);
                    echo json_encode(['success' => true]);
                    break;
                case 'check_new':
                    echo json_encode(checkNewAdminMessages($db, $_POST['client_id'] ?? null));
                    break;
                case 'delete_client':
                    deleteClient($db, $_POST['client_id']);
                    echo json_encode(['success' => true]);
                    break;
                case 'update_settings':
                    updateSettings($db, $_POST);
                    echo json_encode(['success' => true]);
                    break;
                case 'get_settings':
                    echo json_encode(getSettings($db));
                    break;
                case 'upload_avatar':
                    echo json_encode(handleAvatarUpload());
                    break;
                case 'change_password':
                    echo json_encode(handlePasswordChange($_POST['new_password']));
                    break;
                default:
                    echo json_encode(['error' => '未知操作']);
            }
            exit;
        }
    }
}

// 退出登录
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

/**
 * 获取客户端列表
 */
function getClientsList($db) {
    $clients = $db->fetchAll("
        SELECT c.*, 
               (SELECT COUNT(*) FROM {$db->table('messages')} m WHERE m.client_id = c.client_id AND m.is_admin = 0 AND m.is_read = 0) as unread,
               (SELECT m.message FROM {$db->table('messages')} m WHERE m.client_id = c.client_id ORDER BY m.timestamp DESC LIMIT 1) as last_message,
               (SELECT m.timestamp FROM {$db->table('messages')} m WHERE m.client_id = c.client_id ORDER BY m.timestamp DESC LIMIT 1) as last_time
        FROM {$db->table('clients')} c
        ORDER BY c.last_active DESC
        LIMIT 200
    ");
    
    return array_map(function($c) {
        $c['last_message'] = $c['last_message'] ? mb_substr($c['last_message'], 0, 30) . (mb_strlen($c['last_message']) > 30 ? '...' : '') : '无消息';
        return $c;
    }, $clients);
}

/**
 * 获取客户端消息
 */
function getClientMessages($db, $clientID) {
    $messages = $db->fetchAll("
        SELECT * FROM {$db->table('messages')} 
        WHERE client_id = ? 
        ORDER BY timestamp DESC LIMIT 100", 
        [$clientID]
    );
    
    // 标记为已读
    $db->update($db->table('messages'), 
        ['is_read' => 1], 
        "client_id = :client_id AND is_admin = 0 AND is_read = 0", 
        ['client_id' => $clientID]
    );
    
    $output = '';
    foreach ($messages as $msg) {
        $output .= renderAdminMessage($msg);
    }
    
    return $output ?: '<div class="empty-msg">暂无消息</div>';
}

/**
 * 渲染管理员消息
 */
function renderAdminMessage($msg) {
    $content = makeLinksClickable(nl2br(htmlspecialchars($msg['message'])));
    $imageHtml = '';
    
    if (!empty($msg['image_url'])) {
        $imagePath = LC_UPLOAD_DIR . $msg['image_url'];
        if (file_exists($imagePath)) {
            $imageUrl = LC_UPLOAD_URL . $msg['image_url'];
            $imageHtml = '<div class="msg-image" onclick="viewImage(\'' . $imageUrl . '\')">';
            $imageHtml .= '<img src="' . $imageUrl . '" alt="图片">';
            $imageHtml .= '<span>🖼️ 点击查看</span></div>';
        }
    }
    
    $isAdmin = !empty($msg['is_admin']);
    $sender = $isAdmin ? '👨‍💼 管理员' : '👤 ' . htmlspecialchars($msg['sender_name']);
    $msgClass = $isAdmin ? 'admin-msg' : 'client-msg';
    $unreadClass = (!$isAdmin && empty($msg['is_read'])) ? 'unread' : '';
    
    return '<div class="message ' . $msgClass . ' ' . $unreadClass . '" data-id="' . $msg['message_id'] . '">'
        . '<div class="msg-hd">'
        . '<span class="sender">' . $sender . '</span>'
        . '<span class="time">' . date('H:i', $msg['timestamp']) . '</span>'
        . '<button class="del-btn" onclick="deleteMsg(\'' . $msg['message_id'] . '\')">×</button>'
        . '</div>'
        . '<div class="msg-ct">' . $content . '</div>'
        . $imageHtml
        . '</div>';
}

/**
 * 发送管理员消息
 */
function sendAdminMessage($db, $clientID, $message) {
    $messageId = 'msg_' . time() . '_' . bin2hex(generateRandomBytes(4));
    
    // 处理图片上传
    $imageUrl = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleImageUpload($_FILES['image']);
        if (!$uploadResult['success']) {
            return ['success' => false, 'error' => $uploadResult['error']];
        }
        $imageUrl = $uploadResult['file_name'];
    }
    
    // 如果没有消息内容且没有图片
    if (empty($message) && empty($imageUrl)) {
        return ['success' => false, 'error' => '消息不能为空'];
    }
    
    // 如果只有图片没有文字
    $messageContent = !empty($message) ? htmlspecialchars($message) : '[图片消息]';
    
    try {
        $db->insert($db->table('messages'), [
            'message_id' => $messageId,
            'client_id' => $clientID,
            'sender' => 'admin',
            'sender_name' => ADMIN_NAME,
            'message' => $messageContent,
            'image_url' => $imageUrl,
            'is_admin' => 1,
            'is_read' => 0,
            'timestamp' => time()
        ]);
        
        return ['success' => true, 'message_id' => $messageId];
    } catch (Exception $e) {
        return ['success' => false, 'error' => '发送失败: ' . $e->getMessage()];
    }
}

/**
 * 删除消息
 */
function deleteMessage($db, $clientID, $messageID) {
    $db->delete($db->table('messages'), "message_id = ? AND client_id = ?", [$messageID, $clientID]);
}

/**
 * 标记已读
 */
function markAllRead($db, $clientID) {
    $db->update($db->table('messages'), 
        ['is_read' => 1], 
        "client_id = :client_id AND is_admin = 0", 
        ['client_id' => $clientID]
    );
}

/**
 * 检查新消息
 */
function checkNewAdminMessages($db, $clientID = null) {
    if ($clientID) {
        $count = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('messages')} WHERE client_id = ? AND is_admin = 0 AND is_read = 0", [$clientID]);
        return ['has_new' => $count['c'] > 0, 'client_unread' => $count['c']];
    }
    
    $total = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('messages')} WHERE is_admin = 0 AND is_read = 0");
    return ['has_new' => $total['c'] > 0, 'total_unread' => $total['c']];
}

/**
 * 删除客户端
 */
function deleteClient($db, $clientID) {
    $db->delete($db->table('messages'), "client_id = ?", [$clientID]);
    $db->delete($db->table('clients'), "client_id = ?", [$clientID]);
}

/**
 * 获取设置
 */
function getSettings($db) {
    $rows = $db->fetchAll("SELECT * FROM {$db->table('settings')}");
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

/**
 * 更新设置
 */
function updateSettings($db, $data) {
    $keys = ['chat_title', 'admin_name', 'telegram_enabled', 'telegram_token', 'telegram_admin_id'];
    foreach ($keys as $key) {
        if (isset($data[$key])) {
            $db->query("INSERT INTO {$db->table('settings')} (setting_key, setting_value) VALUES (?, ?) 
                       ON DUPLICATE KEY UPDATE setting_value = ?", 
                [$key, $data[$key], $data[$key]]
            );
        }
    }
}

/**
 * 处理头像上传
 */
function handleAvatarUpload() {
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => '未收到上传文件或上传出错'];
    }
    
    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => '只支持 JPG, PNG, GIF, WEBP 格式'];
    }
    
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'error' => '文件大小不能超过 2MB'];
    }
    
    $uploadDir = dirname(__FILE__) . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $targetPath = $uploadDir . 'admin_avatar.jpg';
    
    // 如果是图片，尝试调整大小
    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo) {
        $srcImage = null;
        switch ($file['type']) {
            case 'image/jpeg':
                $srcImage = imagecreatefromjpeg($file['tmp_name']);
                break;
            case 'image/png':
                $srcImage = imagecreatefrompng($file['tmp_name']);
                break;
            case 'image/gif':
                $srcImage = imagecreatefromgif($file['tmp_name']);
                break;
            case 'image/webp':
                $srcImage = imagecreatefromwebp($file['tmp_name']);
                break;
        }
        
        if ($srcImage) {
            $width = imagesx($srcImage);
            $height = imagesy($srcImage);
            $size = max($width, $height);
            $newSize = min($size, 200); // 最大 200px
            
            $dstImage = imagecreatetruecolor($newSize, $newSize);
            
            // 居中裁剪为正方形
            $srcX = ($width - $size) / 2;
            $srcY = ($height - $size) / 2;
            
            imagecopyresampled($dstImage, $srcImage, 0, 0, $srcX, $srcY, $newSize, $newSize, $size, $size);
            imagejpeg($dstImage, $targetPath, 90);
            
            imagedestroy($srcImage);
            imagedestroy($dstImage);
            
            return ['success' => true];
        }
    }
    
    // 如果图片处理失败，直接移动文件
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => '文件保存失败'];
}

/**
 * 处理密码更改
 */
function handlePasswordChange($newPassword) {
    if (!$newPassword || strlen($newPassword) < 4) {
        return ['success' => false, 'error' => '密码长度至少4位'];
    }
    
    $adminFile = dirname(__FILE__) . '/admin.php';
    
    if (!file_exists($adminFile)) {
        return ['success' => false, 'error' => 'admin.php 文件不存在'];
    }
    
    if (!is_writable($adminFile)) {
        return ['success' => false, 'error' => '文件不可写，请检查文件权限'];
    }
    
    $content = file_get_contents($adminFile);
    
    // 转义密码中的单引号和反斜杠
    $escapedPassword = str_replace(['\\', "'"], ['\\\\', "\\'"], $newPassword);
    
    // 构建新的密码行
    $newPasswordLine = "\$adminPassword = '" . $escapedPassword . "'; // 修改为你的密码";
    
    // 使用更精确的模式，只匹配文件开头的密码定义（在注释后的第一个定义）
    // 匹配: // 管理员密码\n$adminPassword = 'xxx'; // 修改为你的密码
    $pattern = "/(\/\/\s*管理员密码\s*\n)\s*\\\$adminPassword\s*=\s*'[^']*';\s*\/\/.*$/m";
    
    if (preg_match($pattern, $content)) {
        $newContent = preg_replace($pattern, '$1' . $newPasswordLine, $content);
    } else {
        // 备用模式：直接匹配 $adminPassword = 'xxx'; // 修改为你的密码
        $pattern2 = "/\\\$adminPassword\s*=\s*'[^']*';\s*\/\/\s*修改为你的密码/m";
        $newContent = preg_replace($pattern2, $newPasswordLine, $content);
    }
    
    if ($newContent !== $content && file_put_contents($adminFile, $newContent)) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => '密码更新失败，请手动修改 admin.php 中的 $adminPassword 变量'];
}
?>