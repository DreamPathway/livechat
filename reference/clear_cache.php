<?php
/**
 * 清除 PHP OPcache 并重新加载配置
 * 访问此脚本后会显示当前 OPcache 状态
 */

echo "=== OPcache 状态 ===\n\n";

// 检查 OPcache 是否启用
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "OPcache 启用: 是\n";
    echo "内存使用: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB / " . round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) . " MB\n";
    echo "缓存文件数: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
    echo "命中率: " . round($status['opcache_statistics']['opcache_hit_rate'], 1) . "%\n\n";
    
    // 清除 OPcache
    if (function_exists('opcache_reset')) {
        $reset = opcache_reset();
        echo "OPcache 已清除: " . ($reset ? '成功' : '失败') . "\n";
    }
} else {
    echo "OPcache 启用: 否\n";
}

// 清除配置的缓存
$configFile = dirname(__FILE__) . '/config.php';
clearstatcache(true, $configFile);
echo "\n文件缓存已清除: {$configFile}\n";
echo "文件修改时间: " . date('Y-m-d H:i:s', filemtime($configFile)) . "\n";

// 验证 DEBUG_MODE 当前值
require_once $configFile;
echo "\n当前 DEBUG_MODE: " . (DEBUG_MODE ? 'true (已开启调试)' : 'false (已关闭调试)') . "\n";

echo "\n=== 操作完成 ===\n";
echo "请重新在聊天框发送消息测试。\n";
