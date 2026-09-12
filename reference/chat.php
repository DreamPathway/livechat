<?php
/**
 * 客服系统 - 客户端入口
 * 集成到现有网站的入口文件
 */

// 启动会话（使用和主系统相同的会话名称）
if (session_status() === PHP_SESSION_NONE) {
    session_name('main_sess'); // 与主系统的会话名称一致
    session_start();
}

// 加载主系统的配置和函数（用于会话和 currentUser）
require_once dirname(__FILE__) . '/../includes/config.php';
require_once dirname(__FILE__) . '/../includes/functions.php';

require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/includes/functions.php';

$clientIP = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

if (isIPBlocked($clientIP)) {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"error":"您的IP已被封禁"}';
    exit;
}

// 生成客户端 ID（基于 IP，无论是否登录）
$clientID = 'guest_' . md5($clientIP);
$currentUser = getCurrentUser();

$dbError = false;
$dbErrorMsg = '';
$dbReady = false;

try {
    $db = getDB();
    $db->initTables();
    $dbReady = true;
} catch (PDOException $e) {
    $dbError = true;
    $dbErrorMsg = '聊天服务暂时不可用，请稍后再试';
    error_log("[LiveChat Init PDO Error] " . $e->getMessage());
} catch (Exception $e) {
    $dbError = true;
    $dbErrorMsg = '聊天服务暂时不可用，请稍后再试';
    error_log("[LiveChat Init Error] " . $e->getMessage());
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$chatPath = getChatPath();
$isGuest = true;
$nickname = htmlspecialchars($clientIP);
$headerTip = TELEGRAM_ENABLED ? 'Telegram' : '站内消息';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo CHAT_TITLE; ?></title>
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --bg-color: #f5f7fa;
            --white: #ffffff;
            --text-primary: #333;
            --text-secondary: #666;
            --border-color: #e0e0e0;
            --success-color: #4caf50;
            --danger-color: #ff4757;
            --warning-color: #ff9800;
            --shadow: 0 10px 40px rgba(0,0,0,0.1);
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
            --radius: 20px;
            --radius-sm: 10px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-color); min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 10px; }
        .chat-wrapper { width: 100%; height: 100vh; max-height: 100vh; background: var(--white); border-radius: 0; box-shadow: var(--shadow); display: flex; flex-direction: column; overflow: hidden; }
        @media (min-width: 768px) { .chat-wrapper { height: 85vh; max-width: 500px; border-radius: var(--radius); max-height: 800px; } }
        .chat-header { background: var(--primary-gradient); color: var(--white); padding: 15px 20px; display: flex; align-items: center; flex-shrink: 0; }
        .header-left { display: flex; align-items: center; gap: 12px; }
        .header-avatar { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); }
        .header-info h2 { font-size: 18px; font-weight: 600; }
        .header-info .status { font-size: 12px; opacity: 0.9; display: flex; align-items: center; gap: 5px; }
        .status-dot { width: 8px; height: 8px; background: var(--success-color); border-radius: 50%; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .header-tip { font-size: 12px; text-align: center; color: #333; padding: 8px; background: #fffde7; border-bottom: 1px solid #fff9c4; }
        .chat-messages { flex: 1; padding: 15px; overflow-y: auto; background: #fafafa; -webkit-overflow-scrolling: touch; }
        .message { margin-bottom: 15px; padding: 12px 15px; border-radius: 18px; max-width: 85%; animation: fadeIn 0.3s ease-out; box-shadow: var(--shadow-sm); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .client-msg { background: var(--primary-gradient); color: var(--white); margin-left: auto; border-bottom-right-radius: 5px; }
        .admin-msg { background: var(--white); margin-right: auto; border-bottom-left-radius: 5px; border: 1px solid var(--border-color); }
        .msg-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 12px; }
        .msg-header .sender { font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .msg-header .time { opacity: 0.7; }
        .client-msg .msg-header .time { color: rgba(255,255,255,0.8); }
        .msg-content { font-size: 15px; line-height: 1.4; word-wrap: break-word; }
        .empty-chat { text-align: center; color: var(--text-secondary); padding: 40px 20px; }
        .empty-chat::before { content: "💬"; font-size: 50px; display: block; margin-bottom: 15px; opacity: 0.5; }
        .chat-input { padding: 15px; background: var(--white); border-top: 1px solid var(--border-color); flex-shrink: 0; }
        .input-form { display: flex; gap: 10px; align-items: flex-end; }
        .input-form textarea { flex: 1; padding: 12px 15px; border: 2px solid var(--border-color); border-radius: 20px; font-size: 15px; outline: none; resize: none; min-height: 45px; max-height: 100px; font-family: inherit; transition: border-color 0.3s; }
        .input-form textarea:focus { border-color: var(--primary-color); }
        .upload-btn, .send-btn { width: 45px; height: 45px; border-radius: 50%; border: none; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; transition: transform 0.2s; flex-shrink: 0; }
        .upload-btn { background: #f0f0f0; border: 2px dashed #ccc; position: relative; overflow: hidden; }
        .upload-btn input { position: absolute; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .upload-btn:hover { border-color: var(--primary-color); background: #e8eaf6; }
        .send-btn { background: var(--primary-gradient); color: var(--white); box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4); }
        .send-btn:hover { transform: scale(1.05); }
        .send-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: white; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .chat-messages::-webkit-scrollbar { width: 4px; }
        .chat-messages::-webkit-scrollbar-track { background: #f1f1f1; }
        .chat-messages::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
        .drop-zone { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(102, 126, 234, 0.1); border: 3px dashed var(--primary-color); display: none; justify-content: center; align-items: center; z-index: 998; }
        .drop-zone.active { display: flex; }
        .drop-text { background: white; padding: 20px 30px; border-radius: 15px; font-size: 18px; color: var(--primary-color); box-shadow: var(--shadow); }
        .image-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 9999; justify-content: center; align-items: center; }
        .image-modal img { max-width: 95%; max-height: 95%; border-radius: 10px; }
        .close-modal { position: absolute; top: 20px; right: 20px; color: white; font-size: 40px; cursor: pointer; background: none; border: none; width: 50px; height: 50px; }
        .chat-image-container { margin-top: 10px; border-radius: 12px; overflow: hidden; cursor: pointer; max-width: 280px; position: relative; }
        .chat-image { width: 100%; height: auto; display: block; }
        .image-overlay { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.5); color: white; padding: 5px 10px; font-size: 12px; text-align: center; opacity: 0; transition: opacity 0.3s; }
        .chat-image-container:hover .image-overlay { opacity: 1; }
        .upload-preview { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #f5f5f5; border-radius: var(--radius-sm); margin-bottom: 10px; }
        .preview-image { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; }
        .preview-info { flex: 1; }
        .preview-info .name { font-size: 13px; color: var(--text-primary); }
        .preview-info .size { font-size: 11px; color: var(--text-secondary); }
        .remove-preview { background: none; border: none; color: var(--danger-color); cursor: pointer; font-size: 20px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: 50%; }
    </style>
</head>
<body>
    <?php if ($dbError): ?>
    <div class="chat-wrapper" style="justify-content: center; align-items: center;">
        <div class="empty-chat" style="padding: 60px 30px;">
            <p style="font-size: 48px; margin-bottom: 15px;">⚠️</p>
            <p style="font-size: 16px; color: var(--text-primary); margin-bottom: 8px;"><?php echo htmlspecialchars($dbErrorMsg); ?></p>
            <p style="font-size: 13px; color: var(--text-secondary);">如问题持续存在，请联系网站管理员</p>
        </div>
    </div>
    <script>window.CONFIG = { dbReady: false };</script>
    </body>
    </html>
    <?php exit; endif; ?>

    <div class="image-modal" id="imageModal">
        <button class="close-modal" onclick="closeImageModal()">×</button>
        <img id="modalImage" src="" alt="预览图片">
    </div>
    
    <div class="drop-zone" id="dropZone">
        <div class="drop-text">拖拽图片到此处上传</div>
    </div>
    
    <div class="chat-wrapper">
        <div class="chat-header">
            <div class="header-left">
                <img src='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"%3E%3Ccircle cx="50" cy="50" r="50" fill="%23fff"/%3E%3Ccircle cx="50" cy="40" r="15" fill="%23667eea"/%3E%3Cellipse cx="50" cy="80" rx="25" ry="20" fill="%23667eea"/%3E%3C/svg%3E' alt="头像" class="header-avatar">
                <div class="header-info">
                    <h2><?php echo ADMIN_NAME; ?></h2>
                    <div class="status">
                        <span class="status-dot"></span>
                        <span>在线</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="header-tip">💡 请勿发送隐私信息，若相同ip他人可见；建议微信联系</div>
        
        <div class="chat-messages" id="chatMessages">
            <div class="empty-chat">暂无消息，开始聊天吧 💬</div>
        </div>
        
        <div class="chat-input">
            <div id="uploadPreview"></div>
            <form class="input-form" id="chatForm" enctype="multipart/form-data">
                <textarea id="messageInput" placeholder="请输入您的问题..." rows="1" autocomplete="off"></textarea>
                <div class="upload-btn" title="发送图片">
                    <input type="file" id="fileInput" accept="image/*">
                    📎
                </div>
                <button type="submit" class="send-btn" id="sendBtn">➤</button>
            </form>
        </div>
    </div>

    <script>
        var CONFIG = {
            clientId: '<?php echo $clientID; ?>',
            userName: '<?php echo $nickname; ?>',
            isGuest: <?php echo $isGuest; ?>,
            uploadUrl: '<?php echo $chatPath; ?>/api/upload.php',
            messagesUrl: '<?php echo $chatPath; ?>/api/messages.php',
            pollInterval: 3000,
            dbReady: true
        };
        
        var isSubmitting = false;
        var selectedFile = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            initClient();
            loadMessages();
            setInterval(checkNewMessages, CONFIG.pollInterval);
        });
        
        // 处理需要登录的情况
        function handleNeedLogin(data) {
            if (data.need_login && data.login_url) {
                alert('登录已过期，请重新登录');
                window.location.href = data.login_url;
                return true;
            }
            return false;
        }
        
        function initClient() {
            var formData = new FormData();
            formData.append('action', 'init_client');
            formData.append('client_id', CONFIG.clientId);

            fetch(CONFIG.messagesUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                try {
                    var data = JSON.parse(text);
                    if (handleNeedLogin(data)) return;
                    if (data.success && data.client_id) {
                        CONFIG.clientId = data.client_id;
                        console.log('Client initialized with ID:', CONFIG.clientId);
                    }
                } catch (e) {
                    console.error('Init client parse error:', e);
                }
            })
            .catch(function(err) { console.error('Init client failed:', err); });
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        document.getElementById('chatMessages').addEventListener('click', function(e) {
            if (e.target.classList.contains('chat-image')) {
                document.getElementById('modalImage').src = e.target.src;
                document.getElementById('imageModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });
        
        var fileInput = document.getElementById('fileInput');
        var uploadPreview = document.getElementById('uploadPreview');
        
        fileInput.addEventListener('change', function() {
            if (this.files[0]) handleFileSelect(this.files[0]);
        });
        
        function handleFileSelect(file) {
            if (file.size > 5 * 1024 * 1024) { alert('文件大小不能超过5MB'); return; }
            if (!file.type.startsWith('image/')) { alert('只能上传图片文件'); return; }
            selectedFile = file;
            var reader = new FileReader();
            reader.onload = function(e) {
                uploadPreview.innerHTML = '<div class="upload-preview"><img src="' + e.target.result + '" class="preview-image"><div class="preview-info"><div class="name">' + file.name + '</div><div class="size">' + (file.size / 1024).toFixed(1) + ' KB</div></div><button type="button" class="remove-preview" onclick="removePreview()">×</button></div>';
            };
            reader.readAsDataURL(file);
        }
        
        function removePreview() {
            selectedFile = null;
            uploadPreview.innerHTML = '';
            fileInput.value = '';
        }
        
        var chatWrapper = document.querySelector('.chat-wrapper');
        var dropZone = document.getElementById('dropZone');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(event) {
            chatWrapper.addEventListener(event, function(e) { e.preventDefault(); e.stopPropagation(); });
        });
        
        ['dragenter', 'dragover'].forEach(function(event) {
            chatWrapper.addEventListener(event, function() { dropZone.classList.add('active'); });
        });
        
        ['dragleave', 'drop'].forEach(function(event) {
            chatWrapper.addEventListener(event, function() { dropZone.classList.remove('active'); });
        });
        
        chatWrapper.addEventListener('drop', function(e) {
            if (e.dataTransfer.files[0]) handleFileSelect(e.dataTransfer.files[0]);
        });
        
        var chatForm = document.getElementById('chatForm');
        var messageInput = document.getElementById('messageInput');
        var sendBtn = document.getElementById('sendBtn');
        
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('Form submitted, clientId:', CONFIG.clientId, 'userName:', CONFIG.userName);
            if (isSubmitting || (!messageInput.value.trim() && !selectedFile)) return;
            isSubmitting = true;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<div class="loading-spinner"></div>';

            var formData = new FormData();
            formData.append('action', 'send');
            formData.append('client_id', CONFIG.clientId);
            formData.append('nickname', CONFIG.userName);
            if (selectedFile) formData.append('image', selectedFile);
            if (messageInput.value.trim()) formData.append('message', messageInput.value.trim());

            console.log('Sending to:', CONFIG.messagesUrl, 'with client_id:', CONFIG.clientId);
            fetch(CONFIG.messagesUrl, { method: 'POST', body: formData })
            .then(function(r) {
                console.log('Response status:', r.status, 'Content-Type:', r.headers.get('content-type'));
                return r.text();
            })
            .then(function(text) {
                console.log('Response text:', text);
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    document.getElementById('chatMessages').innerHTML = '<div class="empty-chat">服务器错误，请刷新页面</div>';
                    return;
                }
                if (handleNeedLogin(data)) return;
                if (data.success) {
                    messageInput.value = '';
                    removePreview();
                    loadMessages();
                } else {
                    alert(data.error || '发送失败');
                }
            })
            .catch(function(err) { console.error('Fetch error:', err); alert('发送失败，请重试'); })
            .finally(function() {
                isSubmitting = false;
                sendBtn.disabled = false;
                sendBtn.innerHTML = '➤';
                messageInput.focus();
            });
        });
        
        messageInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.dispatchEvent(new Event('submit'));
            }
        });
        
        messageInput.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        function loadMessages() {
            console.log('loadMessages called, clientId:', CONFIG.clientId);
            var formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('client_id', CONFIG.clientId);

            fetch(CONFIG.messagesUrl, { method: 'POST', body: formData })
            .then(function(r) {
                console.log('loadMessages response status:', r.status);
                return r.text();
            })
            .then(function(html) {
                console.log('loadMessages response length:', html.length);
                try {
                    var data = JSON.parse(html);
                    console.log('loadMessages got JSON:', data);
                    if (handleNeedLogin(data)) return;
                } catch (e) {
                    console.log('loadMessages got HTML, length:', html.length);
                }
                document.getElementById('chatMessages').innerHTML = html;
                scrollToBottom();
            })
            .catch(function(err) { console.error('loadMessages failed:', err); });
        }
        
        function checkNewMessages() {
            var formData = new FormData();
            formData.append('action', 'check_new');
            formData.append('client_id', CONFIG.clientId);

            fetch(CONFIG.messagesUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                try {
                    var data = JSON.parse(text);
                    if (handleNeedLogin(data)) return;
                    if (data.has_new) loadMessages();
                } catch (e) {
                    console.error('checkNewMessages parse error:', e);
                }
            })
            .catch(function(err) { console.error('checkNewMessages failed:', err); });
        }
        
        function scrollToBottom() {
            var container = document.getElementById('chatMessages');
            container.scrollTop = container.scrollHeight;
        }
    </script>
</body>
</html>
