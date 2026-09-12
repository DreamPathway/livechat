<?php
/**
 * 初始化数据库并迁移JSON数据
 */

require_once dirname(__FILE__) . '/config.php';

echo "正在初始化数据库...\n";

try {
    $db = getDB();
    $db->initTables();
    echo "✅ 数据库初始化成功！\n";
    
    // 检查是否有备份文件
    $backupFile = dirname(__FILE__) . '/chat_data.json.bak.' . date('Ymd');
    if (file_exists($backupFile)) {
        echo "✅ JSON数据已自动迁移到数据库\n";
        echo "   原文件已备份为: chat_data.json.bak." . date('Ymd') . "\n";
    } else if (file_exists(dirname(__FILE__) . '/chat_data.json')) {
        echo "ℹ️  chat_data.json 存在但未迁移，可能是新文件\n";
    } else {
        echo "ℹ️  没有找到 chat_data.json 文件\n";
    }
    
    echo "\n🎉 完成！现在聊天数据已经存储在数据库中了。\n";
    echo "   可以安全删除此 init_db.php 文件。\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
}
