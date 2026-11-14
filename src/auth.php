<?php
function admin_logged(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_require(): void
{
    if (!admin_logged()) redirect('login.php');
    // Tras actualizar el código, si falta la migración de materias, llevar al admin a ejecutarla.
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'migrar.php') {
        $hay = (int)q("SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND COLUMN_NAME='materia_id')
            + (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='intentos')
            + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='justificacion')
            + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND COLUMN_NAME='duracion_min')")->fetchColumn();
        if ($hay < 4) redirect('migrar.php');
    }
}

function login_bloqueado(): bool
{
    $max = config()['max_login_intentos'] ?? 5;
    $n = q('SELECT COUNT(*) FROM login_attempts WHERE ip_hash=? AND created_at > (NOW() - INTERVAL 15 MINUTE)', [client_ip_hash()])->fetchColumn();
    return $n >= $max;
}

function admin_login(string $email, string $pass): bool
{
    $a = q('SELECT id, password_hash FROM admins WHERE email=?', [$email])->fetch();
    $hash = $a['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuAbcdefghijklmnopqrstuvwxyz01234';
    $ok = password_verify($pass, $hash);
    if ($a && $ok) {
        q('DELETE FROM login_attempts WHERE ip_hash=?', [client_ip_hash()]);
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$a['id'];
        return true;
    }
    q('INSERT INTO login_attempts (ip_hash) VALUES (?)', [client_ip_hash()]);
    return false;
}

function admin_logout(): void
{
    $_SESSION = [];
    session_destroy();
}
