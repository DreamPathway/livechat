// 定时清理：每天运行，逻辑与原版 cleanup.php 一致
// 1) 每客户端保留最新 20% 消息（最少 50 条），删除更旧的
// 2) 删除 90 天无消息且未活跃的僵尸客户端
export async function runCleanup(db: D1Database): Promise<string> {
  const keepRatio = 0.2;
  const minKeep = 50;
  let deletedMsgs = 0;
  let deletedClients = 0;

  const msgRow = await db.prepare('SELECT COUNT(*) as c FROM messages').first<{ c: number }>();
  const clientRow = await db.prepare('SELECT COUNT(*) as c FROM clients').first<{ c: number }>();
  const beforeMsg = msgRow?.c ?? 0;
  const beforeClient = clientRow?.c ?? 0;

  // 每个有消息的客户端：按时间倒序取第 keep 条的时间戳作为截止点
  const { results: clients } = await db
    .prepare(
      `SELECT client_id, COUNT(*) as total FROM messages GROUP BY client_id HAVING total > ?`
    )
    .bind(minKeep)
    .all<{ client_id: string; total: number }>();

  for (const cl of clients) {
    const keep = Math.max(minKeep, Math.floor(Number(cl.total) * keepRatio));
    const deleteCount = Number(cl.total) - keep;
    if (deleteCount <= 0) continue;

    const cutoff = await db
      .prepare(
        'SELECT timestamp FROM messages WHERE client_id = ? ORDER BY timestamp DESC LIMIT 1 OFFSET ?'
      )
      .bind(cl.client_id, keep)
      .first<{ timestamp: number }>();
    if (!cutoff) continue;

    const del = await db
      .prepare('DELETE FROM messages WHERE client_id = ? AND timestamp < ?')
      .bind(cl.client_id, cutoff.timestamp)
      .run();
    deletedMsgs += del.meta.changes ?? deleteCount;
  }

  // 僵尸客户端：无消息且 90 天未活跃
  const cutoffTime = Math.floor(Date.now() / 1000) - 90 * 86400;
  const { results: zombies } = await db
    .prepare(
      `SELECT c.client_id FROM clients c
       LEFT JOIN messages m ON c.client_id = m.client_id
       WHERE m.client_id IS NULL AND c.last_active < ?`
    )
    .bind(cutoffTime)
    .all<{ client_id: string }>();

  for (const z of zombies) {
    const del = await db.prepare('DELETE FROM clients WHERE client_id = ?').bind(z.client_id).run();
    deletedClients += del.meta.changes ?? 1;
  }

  const afterMsgRow = await db.prepare('SELECT COUNT(*) as c FROM messages').first<{ c: number }>();
  const afterClientRow = await db.prepare('SELECT COUNT(*) as c FROM clients').first<{ c: number }>();

  const summary =
    `[LiveChat Cleanup] before: ${beforeMsg} messages, ${beforeClient} clients | ` +
    `deleted ${deletedMsgs} messages, ${deletedClients} clients | ` +
    `after: ${afterMsgRow?.c ?? 0} messages, ${afterClientRow?.c ?? 0} clients`;
  console.log(summary);
  return summary;
}
