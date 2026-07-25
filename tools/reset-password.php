<?php
declare(strict_types=1);

$input = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($input)) {
    fwrite(STDERR, "No se recibieron datos válidos.\n");
    exit(1);
}

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
    fwrite(STDERR, "El nombre de usuario no es válido.\n");
    exit(1);
}
if (strlen($password) < 8 || strlen($password) > 1024) {
    fwrite(STDERR, "La contraseña debe tener al menos 8 caracteres.\n");
    exit(1);
}

$database = dirname(__DIR__).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'maraton.sqlite';
if (!is_file($database)) {
    fwrite(STDERR, "No se encontró la base de datos.\n");
    exit(1);
}

$db = new PDO('sqlite:'.$database, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('PRAGMA busy_timeout=5000');
$query = $db->prepare('UPDATE users SET password = ? WHERE username = ? COLLATE NOCASE');
$query->execute([password_hash($password, PASSWORD_DEFAULT), $username]);

if ($query->rowCount() !== 1) {
    fwrite(STDERR, "No existe una cuenta con ese nombre de usuario.\n");
    exit(1);
}

echo "Contraseña actualizada. Tus datos no se han modificado.\n";
