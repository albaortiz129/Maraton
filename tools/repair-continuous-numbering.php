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

$shows = [];
foreach ($db->query('SELECT user_id, show_id, payload FROM saved_shows')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $show = json_decode((string) $row['payload'], true);
    if (!is_array($show) || !is_array($show['seasonMeta'] ?? null)) {
        continue;
    }

    $counts = [];
    foreach ($show['seasonMeta'] as $season) {
        $number = (int) ($season['number'] ?? 0);
        $count = (int) ($season['count'] ?? 0);
        if ($number > 0 && $count > 0) {
            $counts[$number] = $count;
        }
    }
    if ($counts !== []) {
        ksort($counts, SORT_NUMERIC);
        $shows[(int) $row['show_id']]['counts'] = $counts;
        $shows[(int) $row['show_id']]['rows'][] = [
            'user_id' => (int) $row['user_id'],
            'show' => $show,
        ];
    }
}

$continuous = [];
$probe = $db->prepare('SELECT 1 FROM watched_episodes WHERE show_id=? AND season=? AND episode>? LIMIT 1');
foreach ($shows as $showId => $info) {
    foreach ($info['counts'] as $season => $count) {
        $probe->execute([$showId, $season, $count]);
        if ($probe->fetchColumn()) {
            $continuous[$showId] = $info;
            break;
        }
    }
}

$deletedEpisodes = 0;
$deletedHistory = 0;
$updatedShows = 0;
$affectedPairs = [];
$deleteEpisode = $db->prepare('DELETE FROM watched_episodes WHERE user_id=? AND show_id=? AND season=? AND episode=?');
$deleteHistory = $db->prepare('DELETE FROM watch_history WHERE id=?');
$updatePayload = $db->prepare('UPDATE saved_shows SET payload=? WHERE user_id=? AND show_id=?');
$history = $db->prepare('SELECT id, user_id, episode_label FROM watch_history WHERE show_id=?');
$episodes = $db->prepare('SELECT user_id, season, episode FROM watched_episodes WHERE show_id=?');

$db->beginTransaction();
try {
    foreach ($continuous as $showId => $info) {
        $ranges = [];
        $numbers = [];
        $first = 1;
        foreach ($info['counts'] as $season => $count) {
            $last = $first + $count - 1;
            $ranges[$season] = [$first, $last];
            $numbers[(string) $season] = range($first, $last);
            $first = $last + 1;
        }

        $episodes->execute([$showId]);
        foreach ($episodes->fetchAll(PDO::FETCH_ASSOC) as $episodeRow) {
            $season = (int) $episodeRow['season'];
            $episode = (int) $episodeRow['episode'];
            $valid = isset($ranges[$season]) && $episode >= $ranges[$season][0] && $episode <= $ranges[$season][1];
            if (!$valid) {
                $deleteEpisode->execute([(int) $episodeRow['user_id'], $showId, $season, $episode]);
                $deletedEpisodes += $deleteEpisode->rowCount();
                $affectedPairs[(int) $episodeRow['user_id'].':'.$showId] = [(int) $episodeRow['user_id'], $showId];
            }
        }

        $history->execute([$showId]);
        foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {
            if (!preg_match('/^T(\d+)\s*[^0-9A-Za-z]\s*E(\d+)$/u', (string) $historyRow['episode_label'], $match)) {
                continue;
            }
            $season = (int) $match[1];
            $episode = (int) $match[2];
            $valid = isset($ranges[$season]) && $episode >= $ranges[$season][0] && $episode <= $ranges[$season][1];
            if (!$valid) {
                $deleteHistory->execute([(int) $historyRow['id']]);
                $deletedHistory += $deleteHistory->rowCount();
            }
        }

        foreach ($info['rows'] as $savedRow) {
            $show = $savedRow['show'];
            $show['seasonEpisodeNumbers'] = $numbers;
            $payload = json_encode($show, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new RuntimeException('No se pudo codificar una serie');
            }
            $updatePayload->execute([$payload, $savedRow['user_id'], $showId]);
            $updatedShows += $updatePayload->rowCount();
        }
    }

    $updateProgress = $db->prepare('UPDATE show_tracking SET progress_count=(SELECT COUNT(*) FROM watched_episodes WHERE user_id=? AND show_id=?) WHERE user_id=? AND show_id=?');
    foreach ($affectedPairs as [$userId, $showId]) {
        $updateProgress->execute([$userId, $showId, $userId, $showId]);
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Reparacion cancelada: '.$error->getMessage().PHP_EOL);
    exit(1);
}

$integrity = (string) $db->query('PRAGMA integrity_check')->fetchColumn();
printf("series_continuas=%d episodios_borrados=%d historial_borrado=%d series_actualizadas=%d integridad=%s\n", count($continuous), $deletedEpisodes, $deletedHistory, $updatedShows, $integrity);
