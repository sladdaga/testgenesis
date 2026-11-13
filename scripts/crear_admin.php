<?php
// Uso: php scripts/crear_admin.php email clave
if (PHP_SAPI !== 'cli') exit('Solo CLI.');
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/db.php';

[$_, $email, $clave] = $argv + [null, null, null];
if (!$email || !$clave || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($clave) < 8) {
    exit("Uso: php scripts/crear_admin.php email clave(min. 8 caracteres)\n");
}
$hash = password_hash($clave, PASSWORD_DEFAULT);
q('INSERT INTO admins (email, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)', [$email, $hash]);
echo "Admin $email creado/actualizado.\n";
