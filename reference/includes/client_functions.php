<?php
/**
 * 客户端函数 - 处理客户端请求
 */

$clientIP = getClientIP();

// 检查IP封禁
if (isIPBlocked($clientIP)) {
    die(json_encode(array('success' => false, 'error' => '您的IP已被封禁')));
}

// 获取当前用户信息
$currentUser = getCurrentUser();

// 生成客户端ID
$clientID = generateClientID($currentUser, $clientIP);

// 检查并处理AJAX请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $db = getDB();
        
        switch ($_POST['action']) {
            case 'send':
                handleSendMessage($db, $clientID, $currentUser);
                break;
            case 'get_messages':
                handleGetMessages($db, $clientID);
                break;
            case 'check_new':
                handleCheckNewMessages($db, $clientID);
                break;
            default:
                echo json_encode(array('success' => false, 'error' => '未知操作'));
        }
    } catch (Exception $e) {
        error_log("Client API error: " . $e->getMessage());
        echo json_encode(array('success' => false, 'error' => '服务器错误'));
    }
    exit;
}

/**
 * 处理发送消息
 */
function handleSendMessage($db, $clientID, $currentUser) {
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $imageUrl = null;
    
    // 处理图片上传
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleImageUpload($_FILES['image']);
        if (!$uploadResult['success']) {
            echo json_encode(array('success' => false, 'error' => $uploadResult['error']));
            return;
        }
        $imageUrl = $uploadResult['file_name'];
    }
    
    // 检查消息是否为空
    if (empty($message) && empty($imageUrl)) {
        echo json_encode(array('success' => false, 'error' => '消息不能为空'));
        return;
    }
    
    $ip = getClientIP();
    $nickname = !$currentUser['is_guest'] ? $currentUser['nickname'] : $ip;
    
    // 确保客户端存在
    ensureClientExists($db, $clientID, $currentUser, $ip);
    
    // 生成消息ID
    $messageId = uniqid('msg_');
    
    // 保存消息
    $db->insert($db->table('messages'), array(
        'message_id' => $messageId,
        'client_id' => $clientID,
        'sender' => 'client',
        'sender_name' => $nickname,
        'message' => htmlspecialchars($message ? $message : '[图片消息]'),
        'image_url' => $imageUrl,
        'is_admin' => 0,
        'is_read' => 1,
        'timestamp' => time()
    ));
    
    // 更新最后活跃时间
    $db->update($db->table('clients'), 
        array('last_active' => time()),
        'client_id = :client_id',
        array('client_id' => $clientID)
    );
    
    // 发送Telegram通知
    if (!empty($message) || !empty($imageUrl)) {
        sendTelegramNotification($ip, $message, !empty($imageUrl));
    }
    
    echo json_encode(array(
        'success' => true,
        'message_id' => $messageId,
        'image_url' => $imageUrl
    ));
}

/**
 * 处理获取消息
 */
function handleGetMessages($db, $clientID) {
    $messages = $db->fetchAll("
        SELECT * FROM {$db->table('messages')} 
        WHERE client_id = ? 
        ORDER BY timestamp ASC 
        LIMIT " . MAX_MESSAGE_HISTORY, 
        array($clientID)
    );
    
    if (empty($messages)) {
        echo '<div class="empty-chat">暂无消息，开始聊天吧</div>';
        return;
    }
    
    // Note: Client-side read status tracking is mainly for admin reference
    // Marking client's own messages as read when they view them
    $db->update($db->table('messages'),
        array('is_read' => 1),
        "client_id = :client_id AND is_admin = 1 AND is_read = 0",
        array('client_id' => $clientID)
    );
    
    // 生成HTML
    $output = '';
    foreach ($messages as $msg) {
        $output .= renderMessage($msg);
    }
    
    echo $output;
}

/**
 * 处理检查新消息
 */
function handleCheckNewMessages($db, $clientID) {
    $unread = $db->fetch("
        SELECT COUNT(*) as count FROM {$db->table('messages')} 
        WHERE client_id = ? AND is_admin = 1 AND is_read = 0",
        array($clientID)
    );
    
    echo json_encode(array(
        'has_new' => $unread['count'] > 0,
        'count' => $unread['count']
    ));
}

/**
 * 确保客户端记录存在
 */
function ensureClientExists($db, $clientID, $currentUser, $ip) {
    $existing = $db->fetch("SELECT id FROM {$db->table('clients')} WHERE client_id = ?", array($clientID));
    
    if (!$existing) {
        $db->insert($db->table('clients'), array(
            'client_id' => $clientID,
            'user_id' => $currentUser['is_guest'] ? null : $currentUser['user_id'],
            'nickname' => $currentUser['nickname'],
            'email' => isset($currentUser['email']) ? $currentUser['email'] : null,
            'phone' => isset($currentUser['phone']) ? $currentUser['phone'] : null,
            'ip' => $ip,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            'first_visit' => time(),
            'last_active' => time(),
            'is_online' => 1
        ));
    } else {
        // 更新活跃时间
        $db->update($db->table('clients'),
            array('last_active' => time()),
            'client_id = :client_id',
            array('client_id' => $clientID)
        );
    }
}

/**
 * 渲染消息HTML
 */
function renderMessage($msg) {
    $content = makeLinksClickable(nl2br(htmlspecialchars($msg['message'])));
    $imageHtml = '';
    
    if (!empty($msg['image_url'])) {
        $imagePath = LC_UPLOAD_DIR . $msg['image_url'];
        if (file_exists($imagePath)) {
            $imageUrl = LC_UPLOAD_URL . $msg['image_url'];
            $imageHtml = '<div class="chat-image-container" data-image="' . htmlspecialchars($msg['image_url']) . '">';
            $imageHtml .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="图片" class="chat-image">';
            $imageHtml .= '<div class="image-overlay">🖼️ 点击查看</div>';
            $imageHtml .= '</div>';
        }
    }
    
    $isAdmin = !empty($msg['is_admin']);
    $time = date('H:i', $msg['timestamp']);
    $sender = $isAdmin ? '👨‍💼 ' . ADMIN_NAME : '👤 您';
    
    return '<div class="message ' . ($isAdmin ? 'admin-msg' : 'client-msg') . '" data-message-id="' . $msg['message_id'] . '">'
        . '<div class="msg-header">'
        . '<span class="sender">' . $sender . '</span>'
        . '<span class="time">' . $time . '</span>'
        . '</div>'
        . '<div class="msg-content">' . $content . '</div>'
        . $imageHtml
        . '</div>';
}
