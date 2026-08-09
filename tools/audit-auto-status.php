<?php
declare(strict_types=1);

$dataDir = rtrim((string) getenv('MARATON_DATA_DIR'), DIRECTORY_SEPARATOR);
$database = $dataDir.DIRECTORY_SEPARATOR.'maraton.sqlite';
if ($dataDir === '' || !is_file($database)) {
    fwrite(STDERR, "No se encontro la base de datos.\n");
    exit(1);
}

$db = new PDO('sqlite:'.$database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('PRAGMA query_only=ON');

$query = $db->query(
    'SELECT tracking.user_id, tracking.show_id, tracking.status, tracking.progress_count, saved.payload,
      (SELECT COUNT(*) FROM watched_episodes AS episodes
       WHERE episodes.user_id=tracking.user_id AND episodes.show_id=tracking.show_id) AS watched_count
     FROM show_tracking AS tracking
     JOIN saved_shows AS saved
       ON saved.user_id=tracking.user_id AND saved.show_id=tracking.show_id
     ORDER BY tracking.user_id, tracking.show_id'
);

$shouldComplete = [];
$shouldReopen = [];
foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
    $watched = (int) $row['watched_count'];
    $official = strtolower(trim((string) ($show['status'] ?? '')));
    $finished = in_array($official, ['ended', 'canceled', 'cancelled'], true);
    $item = [
        'user' => (int) $row['user_id'],
        'show' => (int) $row['show_id'],
        'title' => (string) ($show['title'] ?? ''),
        'status' => (string) $row['status'],
        'official' => $official,
        'watched' => $watched,
        'expected' => $expected,
        'checked' => (int) ($show['metadataCheckedAt'] ?? 0),
    ];
    if ($row['status'] === 'watching' && $expected > 0 && $watched >= $expected) {
        $shouldComplete[] = $item;
    }
    if ($row['status'] === 'completed' && $expected > 0 && $watched < $expected) {
        $shouldReopen[] = $item;
    }
}

echo json_encode([
    'should_complete' => $shouldComplete,
    'should_reopen' => $shouldReopen,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
