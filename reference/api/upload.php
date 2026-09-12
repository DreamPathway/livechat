<?php
/**
 * 客服系统 - 图片上传接口
 */

// 加载主系统的配置和函数
require_once dirname(dirname(__FILE__)) . '/../includes/config.php';
require_once dirname(dirname(__FILE__)) . '/../includes/functions.php';

// 启动会话
startSession();

// 加载配置
require_once dirname(__FILE__) . '/../config.php';
require_once dirname(__FILE__) . '/../includes/functions.php';

// 检查请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(array('success' => false, 'error' => 'Method not allowed')));
}

$clientIP = getClientIP();

if (isIPBlocked($clientIP)) {
    die(json_encode(array('success' => false, 'error' => '您的IP已被封禁')));
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(array('success' => false, 'error' => '没有上传文件'));
    exit;
}

try {
    $result = handleImageUpload($_FILES['image']);
    if ($result['success']) {
        echo json_encode(array(
            'success' => true,
            'file_name' => $result['file_name'],
            'url' => LC_UPLOAD_URL . $result['file_name']
        ));
    } else {
        echo json_encode(array('success' => false, 'error' => $result['error']));
    }
} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    echo json_encode(array('success' => false, 'error' => '上传失败: ' . $e->getMessage()));
}
