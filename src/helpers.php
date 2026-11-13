<?php
function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $f = __DIR__ . '/../config.php';
        if (!is_file($f)) {
            http_response_code(500);
            exit('Falta config.php (copiar config.sample.php).');
        }
        $cfg = require $f;
    }
    return $cfg;
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $tipo = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(403);
        exit('Solicitud no valida (CSRF). Volve atras y recarga la pagina.');
    }
}

function client_ip_hash(): string
{
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', config()['salt'] ?? '');
}

function app_url(): string
{
    $b = config()['base_url'] ?? '';
    if ($b !== '') return rtrim($b, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $dir = preg_replace('#/admin$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function quiz_link(string $token): string
{
    return app_url() . '/?q=' . $token;
}

function fecha(string $d): string
{
    return date('d/m/Y H:i', strtotime($d));
}

function csv_safe($v): string
{
    $v = (string)$v;
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
    return $v;
}

/** Nota de 0 a 10 (un decimal) a partir de respuestas correctas y total. */
function nota10($puntaje, $total): float
{
    return (int)$total > 0 ? round((int)$puntaje * 10 / (int)$total, 1) : 0.0;
}

function nota_fmt($puntaje, $total): string
{
    return rtrim(rtrim(number_format(nota10($puntaje, $total), 1, ',', ''), '0'), ',');
}
