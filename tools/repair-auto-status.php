<?php
declare(strict_types=1);

$dataDir = rtrim((string) getenv('MARATON_DATA_DIR'), DIRECTORY_SEPARATOR);
$database = $dataDir.DIRECTORY_SEPARATOR.'maraton.sqlite';
if ($dataDir === '' || !is_file($database)) {
    fwrite(STDERR, "No se encontró la base de datos.\n");
    exit(1);
}

$db = new PDO('sqlite:'.$database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('PRAGMA busy_timeout=5000');

$rows = $db->query(
    'SELECT tracking.user_id, tracking.show_id, tracking.status, saved.payload,
      (SELECT COUNT(*) FROM watched_episodes AS episodes
       WHERE episodes.user_id=tracking.user_id AND episodes.show_id=tracking.show_id) AS watched_count
     FROM show_tracking AS tracking
     JOIN saved_shows AS saved
       ON saved.user_id=tracking.user_id AND saved.show_id=tracking.show_id
     WHERE tracking.status="watching"'
)->fetchAll(PDO::FETCH_ASSOC);

$updates = [];
foreach ($rows as $row) {
    $show = json_decode((string) $row['payload'], true);
    if (!is_array($show)) {
        continue;
    }
    $expected = 0;
    foreach (is_array($show['seasonMeta'] ?? null) ? $show['seasonMeta'] : [] as $season) {
        if ((int) ($season['number'] ?? 0) > 0) {
            $expected += max(0, (int) ($season['count'] ?? 0));
        }
    }
    if ($expected === 0) {
        $expected = max(0, (int) ($show['episodes'] ?? 0));
    }
    if ($expected > 0 && (int) $row['watched_count'] >= $expected) {
        $updates[] = [(int) $row['user_id'], (int) $row['show_id']];
    }
}

$db->beginTransaction();
try {
    $update = $db->prepare('UPDATE show_tracking SET status="completed", updated_at=? WHERE user_id=? AND show_id=? AND status="watching"');
    $now = gmdate('c');
    $changed = 0;
    foreach ($updates as [$userId, $showId]) {
        $update->execute([$now, $userId, $showId]);
        $changed += $update->rowCount();
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Reparacion cancelada: '.$error->getMessage().PHP_EOL);
    exit(1);
}

printf("estados_finalizados=%d integridad=%s\n", $changed, (string) $db->query('PRAGMA integrity_check')->fetchColumn());
