<?php
function admin_logged(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_require(): void
{
    if (!admin_logged()) redirect('login.php');
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
