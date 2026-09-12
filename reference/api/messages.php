<?php
/**
 * 客服系统 - 消息API接口
 * 处理客户端的消息请求
 */

// 启动会话（使用和主系统相同的会话名称）
if (session_status() === PHP_SESSION_NONE) {
    session_name('main_sess'); // 与主系统的会话名称一致
    session_start();
}

// 加载主系统的配置和函数（用于会话和 currentUser）
require_once dirname(dirname(__FILE__)) . '/../includes/config.php';
require_once dirname(dirname(__FILE__)) . '/../includes/functions.php';

// 加载配置和函数
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../includes/functions.php';

// 获取客户端IP
$clientIP = getClientIP();

// 检查IP封禁
if (isIPBlocked($clientIP)) {
    die(json_encode(array('success' => false, 'error' => '您的IP已被封禁')));
}

// 生成客户端ID（基于IP）
$clientID = 'guest_' . md5($clientIP);
$currentUser = getCurrentUser();

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(array('success' => false, 'error' => 'Method not allowed')));
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    $db = getDB();
    $db->initTables();

    switch ($action) {
        case 'send':
            handleSendMessage($db);
            break;
        case 'get_messages':
            handleGetMessages($db);
            break;
        case 'check_new':
            handleCheckNew($db);
            break;
        case 'init_client':
            handleInitClient($db);
            break;
        default:
            echo json_encode(array('success' => false, 'error' => '未知操作: ' . $action));
    }
} catch (PDOException $e) {
    $msg = $e->getMessage();
    error_log("[LiveChat PDO Error] " . $msg);
    if (stripos($msg, 'access denied') !== false || stripos($msg, 'connection') !== false || stripos($msg, 'refused') !== false) {
        http_response_code(503);
        echo json_encode(array('success' => false, 'error' => '数据库连接失败，请联系管理员'));
    } elseif (stripos($msg, "table") !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, "base table") !== false) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'error' => '数据表缺失，请联系管理员执行安装'));
    } elseif (stripos($msg, 'mixed') !== false || stripos($msg, 'parameter') !== false) {
        http_response_code(500);
        echo json_encode(array('success' => false, 'error' => '数据库参数错误: ' . $msg));
    } else {
        http_response_code(500);
        $detail = (defined('DEBUG_MODE') && DEBUG_MODE) ? $msg : '服务器内部错误，请稍后重试';
        echo json_encode(array('success' => false, 'error' => $detail));
    }
} catch (Exception $e) {
    error_log("[LiveChat Error] " . $e->getMessage());
    echo json_encode(array('success' => false, 'error' => '数据库错误: ' . $e->getMessage()));
}

/**
 * 初始化客户端
 */
function handleInitClient($db) {
    global $clientIP, $clientID;

    $existing = $db->fetch("SELECT id FROM {$db->table('clients')} WHERE client_id = ?", array($clientID));

    if (!$existing) {
        $db->insert($db->table('clients'), array(
            'client_id' => $clientID,
            'user_id' => null,
            'nickname' => $clientIP,
            'email' => null,
            'phone' => null,
            'ip' => $clientIP,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            'first_visit' => time(),
            'last_active' => time(),
            'is_online' => 1
        ));
    } else {
        $db->update($db->table('clients'),
            array('last_active' => time(), 'is_online' => 1),
            'client_id = :id',
            array('id' => $clientID)
        );
    }

    echo json_encode(array(
        'success' => true,
        'client_id' => $clientID
    ));
}

/**
 * 发送消息
 */
function handleSendMessage($db) {
    global $clientIP, $clientID;

    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $nickname = isset($_POST['nickname']) ? $_POST['nickname'] : $clientIP;

    if (empty($clientID)) {
        echo json_encode(array('success' => false, 'error' => '缺少客户端ID'));
        return;
    }

    $imageUrl = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = handleImageUpload($_FILES['image']);
        if (!$uploadResult['success']) {
            echo json_encode(array('success' => false, 'error' => $uploadResult['error']));
            return;
        }
        $imageUrl = $uploadResult['file_name'];
    }

    if (empty($message) && empty($imageUrl)) {
        echo json_encode(array('success' => false, 'error' => '消息不能为空'));
        return;
    }

    ensureClient($db, $clientID, $nickname, $clientIP);

    $messageId = 'msg_' . time() . '_' . bin2hex(generateRandomBytes(4));

    $db->insert($db->table('messages'), array(
        'message_id' => $messageId,
        'client_id' => $clientID,
        'sender' => 'client',
        'sender_name' => htmlspecialchars($nickname),
        'message' => htmlspecialchars($message ? $message : '[图片消息]'),
        'image_url' => $imageUrl,
        'is_admin' => 0,
        'is_read' => 1,
        'timestamp' => time()
    ));

    $db->update($db->table('clients'),
        array('last_active' => time()),
        'client_id = :id',
        array('id' => $clientID)
    );

    $textContent = !empty($message) ? $message : '[图片消息]';
    sendTelegramNotification($clientIP, $textContent, !empty($imageUrl));

    echo json_encode(array(
        'success' => true,
        'message_id' => $messageId,
        'image_url' => $imageUrl
    ));
}

/**
 * 获取消息
 */
function handleGetMessages($db) {
    global $clientID;

    if (empty($clientID)) {
        echo '<div class="empty-chat">无法加载消息</div>';
        return;
    }

    $messages = $db->fetchAll("
        SELECT * FROM {$db->table('messages')}
        WHERE client_id = ?
        ORDER BY timestamp ASC
        LIMIT " . MAX_MESSAGE_HISTORY,
        array($clientID)
    );

    if (empty($messages)) {
        echo '<div class="empty-chat">暂无消息，开始聊天吧 💬</div>';
        return;
    }

    $db->update($db->table('messages'),
        array('is_read' => 1),
        "client_id = :client_id AND is_admin = 1 AND is_read = 0",
        array('client_id' => $clientID)
    );

    foreach ($messages as $msg) {
        echo renderClientMessage($msg);
    }
}

/**
 * 检查新消息
 */
function handleCheckNew($db) {
    global $clientID;

    if (empty($clientID)) {
        echo json_encode(array('has_new' => false, 'count' => 0));
        return;
    }

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
 * 确保客户端存在
 */
function ensureClient($db, $clientID, $nickname, $ip) {
    $existing = $db->fetch("SELECT id FROM {$db->table('clients')} WHERE client_id = ?", array($clientID));

    if (!$existing) {
        $db->insert($db->table('clients'), array(
            'client_id' => $clientID,
            'nickname' => $nickname,
            'ip' => $ip,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
            'first_visit' => time(),
            'last_active' => time(),
            'is_online' => 1
        ));
    }
}

/**
 * 渲染客户端消息HTML
 */
function renderClientMessage($msg) {
    $content = makeLinksClickable(nl2br(htmlspecialchars($msg['message'])));
    $imageHtml = '';

    if (!empty($msg['image_url'])) {
        $imagePath = LC_UPLOAD_DIR . $msg['image_url'];
        $imageUrl = LC_UPLOAD_URL . $msg['image_url'];
        
        if (file_exists($imagePath)) {
            $imageHtml = '<div class="chat-image-container" data-image="' . htmlspecialchars($msg['image_url']) . '">';
            $imageHtml .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="图片" class="chat-image">';
            $imageHtml .= '<div class="image-overlay">🖼️ 点击查看</div>';
            $imageHtml .= '</div>';
        } else {
            // 文件不存在时，仍然显示图片（使用 URL），让浏览器尝试加载
            $errorMsg = '图片加载失败: ' . htmlspecialchars($msg['image_url']);
            $imageHtml = '<div class="chat-image-container" data-image="' . htmlspecialchars($msg['image_url']) . '" style="border: 2px solid red;">';
            $imageHtml .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="图片" class="chat-image">';
            $imageHtml .= '<div class="image-overlay" style="opacity:1; background:rgba(255,0,0,0.7);">⚠️ 文件检查失败</div>';
            $imageHtml .= '</div>';
        }
    }

    $isAdmin = !empty($msg['is_admin']);
    $time = date('H:i', $msg['timestamp']);
    $sender = $isAdmin ? '👨‍💼 ' . ADMIN_NAME : '👤 ' . htmlspecialchars($msg['sender_name']);

    return '<div class="message ' . ($isAdmin ? 'admin-msg' : 'client-msg') . '" data-message-id="' . $msg['message_id'] . '">'
        . '<div class="msg-header">'
        . '<span class="sender">' . $sender . '</span>'
        . '<span class="time">' . $time . '</span>'
        . '</div>'
        . '<div class="msg-content">' . $content . '</div>'
        . $imageHtml
        . '</div>';
}
