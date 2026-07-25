<?php
declare(strict_types=1);

$dataDir = rtrim((string)getenv('MARATON_DATA_DIR'), DIRECTORY_SEPARATOR);
$database = $dataDir.DIRECTORY_SEPARATOR.'maraton.sqlite';
if ($dataDir === '' || !is_file($database) || filesize($database) === 0) {
    exit(0);
}

try {
    $backupDir = $dataDir.DIRECTORY_SEPARATOR.'backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException('No se pudo crear el directorio de copias');
    }

    $backup = $backupDir.DIRECTORY_SEPARATOR.'maraton-'.gmdate('Ymd-His').'.sqlite';
    $db = new PDO('sqlite:'.$database, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('VACUUM INTO '.$db->quote($backup));

    $files = glob($backupDir.DIRECTORY_SEPARATOR.'maraton-*.sqlite') ?: [];
    usort($files, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
    foreach (array_slice($files, 5) as $oldBackup) {
        if (is_file($oldBackup)) {
            unlink($oldBackup);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'No se pudo crear la copia automática: '.$error->getMessage().PHP_EOL);
}
