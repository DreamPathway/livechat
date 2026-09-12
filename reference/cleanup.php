<?php
/**
 * LiveChat 消息清理脚本
 * 保留每个客户端最新 20% 的消息，删除旧消息和僵尸客户端
 * 
 * 用法：php cleanup.php [--dry-run]
 * cron: 0 3 * * * php /path/to/your-site/livechat/cleanup.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$dryRun = in_array('--dry-run', $argv ?? []);

echo "[" . date('Y-m-d H:i:s') . "] LiveChat Cleanup " . ($dryRun ? "(DRY RUN)" : "") . "\n";

try {
    $db = getDB();

    // ── 1. 统计 ──
    $msgCount = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('messages')}")['c'];
    $clientCount = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('clients')}")['c'];
    $keepRatio = 0.2;  // 保留 20%
    $minKeep = 50;      // 最少保留 50 条
    $deletedMsgs = 0;
    $deletedClients = 0;

    echo "Before: {$msgCount} messages, {$clientCount} clients\n";

    // ── 2. 按客户端清理旧消息（保留最新 20%，最少 50 条） ──
    $activeClients = $db->fetchAll("
        SELECT client_id, COUNT(*) as total
        FROM {$db->table('messages')}
        GROUP BY client_id
        HAVING total > {$minKeep}
    ");

    foreach ($activeClients as $c) {
        $keep = max($minKeep, intval($c['total'] * $keepRatio));
        $deleteCount = $c['total'] - $keep;

        if ($deleteCount <= 0) continue;

        // 找到第 $keep 条最新消息的 timestamp（即保留的截止点）
        $cutoff = $db->fetch("
            SELECT timestamp FROM {$db->table('messages')}
            WHERE client_id = ?
            ORDER BY timestamp DESC
            LIMIT 1 OFFSET {$keep}
        ", [$c['client_id']]);

        if (!$cutoff) continue;

        if (!$dryRun) {
            $db->delete(
                $db->table('messages'),
                "client_id = ? AND timestamp < ?",
                [$c['client_id'], $cutoff['timestamp']]
            );
        }

        $deletedMsgs += $deleteCount;
        echo "  Client {$c['client_id']}: {$c['total']} messages → keep {$keep}, delete {$deleteCount}\n";
    }

    // ── 3. 清理僵尸客户端（无消息且 90 天未活跃） ──
    $cutoffTime = time() - (90 * 86400);
    $zombieClients = $db->fetchAll("
        SELECT c.client_id
        FROM {$db->table('clients')} c
        LEFT JOIN {$db->table('messages')} m ON c.client_id = m.client_id
        WHERE m.client_id IS NULL
          AND c.last_active < {$cutoffTime}
    ");

    foreach ($zombieClients as $zc) {
        if (!$dryRun) {
            $db->delete($db->table('clients'), "client_id = ?", [$zc['client_id']]);
        }
        $deletedClients++;
    }

    // ── 4. 结果 ──
    if (!$dryRun) {
        $msgCountAfter = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('messages')}")['c'];
        $clientCountAfter = $db->fetch("SELECT COUNT(*) as c FROM {$db->table('clients')}")['c'];
        echo "After: {$msgCountAfter} messages, {$clientCountAfter} clients\n";
    }

    echo "Cleanup complete: {$deletedMsgs} messages, {$deletedClients} clients " . ($dryRun ? "(DRY RUN)" : "deleted") . "\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
