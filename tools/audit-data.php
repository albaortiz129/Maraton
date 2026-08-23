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
$db->exec('PRAGMA query_only=ON');
$integrity = (string) $db->query('PRAGMA integrity_check')->fetchColumn();
$foreignKeys = count($db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC));
$progressMismatches = (int) $db->query(
    'SELECT COUNT(*) FROM show_tracking AS tracking
     WHERE tracking.progress_count != (
       SELECT COUNT(*) FROM watched_episodes AS episodes
       WHERE episodes.user_id=tracking.user_id AND episodes.show_id=tracking.show_id
     )'
)->fetchColumn();
$users = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$shows = (int) $db->query('SELECT COUNT(*) FROM saved_shows')->fetchColumn();
$episodes = (int) $db->query('SELECT COUNT(*) FROM watched_episodes')->fetchColumn();
$trackingColumns = array_column($db->query('PRAGMA table_info(show_tracking)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$trackingColumn = in_array('is_tracking', $trackingColumns, true);
$trackingRows = $trackingColumn ? (int) $db->query('SELECT COUNT(*) FROM show_tracking WHERE is_tracking=1')->fetchColumn() : -1;
$watchLaterOnly = $trackingColumn ? (int) $db->query('SELECT COUNT(*) FROM show_tracking WHERE is_tracking=0 AND in_watchlist=1')->fetchColumn() : -1;

printf(
    "integridad=%s claves_foraneas=%d progresos_incorrectos=%d usuarios=%d series_guardadas=%d capitulos_marcados=%d columna_seguimiento=%s series_seguidas=%d solo_ver_mas_tarde=%d\n",
    $integrity,
    $foreignKeys,
    $progressMismatches,
    $users,
    $shows,
    $episodes,
    $trackingColumn ? 'ok' : 'falta',
    $trackingRows,
    $watchLaterOnly
);
