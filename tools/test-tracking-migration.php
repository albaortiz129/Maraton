<?php
declare(strict_types=1);

$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maraton-tracking-'.bin2hex(random_bytes(4));
mkdir($directory, 0700, true);
$database = $directory.DIRECTORY_SEPARATOR.'maraton.sqlite';
$db = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE show_tracking(
    user_id INTEGER NOT NULL,
    show_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT "watching",
    in_watchlist INTEGER NOT NULL DEFAULT 0,
    rating INTEGER,
    progress_count INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    PRIMARY KEY(user_id,show_id)
)');
$db = null;

register_shutdown_function(static function () use ($database, $directory): void {
    $GLOBALS['db'] = null;
    $check = new PDO('sqlite:'.$database);
    $columns = array_column($check->query('PRAGMA table_info(show_tracking)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('is_tracking', $columns, true)) {
        fwrite(STDERR, "tracking_migration=failed\n");
        exit(1);
    }
    echo PHP_EOL."tracking_migration=ok".PHP_EOL;
    $check = null;
    foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($directory);
});

putenv('MARATON_DATA_DIR='.$directory);
$_GET['action'] = 'health';
require dirname(__DIR__).DIRECTORY_SEPARATOR.'api.php';
