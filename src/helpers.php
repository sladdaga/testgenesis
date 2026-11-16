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

// ---------------------------------------------------------------------------
// Tipos de pregunta
// ---------------------------------------------------------------------------

/** Tipos admitidos (tanda 1). La tanda 2 sumará: escala, estrellas, fecha, texto. */
const TIPOS_PREGUNTA = [
    'unica' => 'Opción única',
    'desplegable' => 'Desplegable',
    'multiple' => 'Varias opciones',
    'cuadricula' => 'Cuadrícula',
];

function tipo_valido($t): bool
{
    return is_string($t) && isset(TIPOS_PREGUNTA[$t]);
}

/** true si la base ya tiene el esquema de tipos de pregunta (migración paso 11). */
function esquema_imagen(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = (int)q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='imagen_mime'")->fetchColumn() > 0;
    }
    return $ok;
}

function esquema_tipos(): bool
{
    static $ok = null;
    if ($ok === null) {
        $n = (int)q("SELECT (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='questions' AND COLUMN_NAME='tipo')
            + (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='options' AND COLUMN_NAME='rol')
            + (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='answer_items')")->fetchColumn();
        $ok = $n >= 3;
    }
    return $ok;
}

/**
 * Carga las preguntas de un examen y sus opciones.
 * Devuelve [preguntas, opciones[pregunta_id][opcion_id]]; sin migrar, todo es 'unica'/'op'.
 */
function cargar_preguntas(int $quizId): array
{
    $nuevo = esquema_tipos();
    $preguntas = q('SELECT id, texto, justificacion, ' . ($nuevo ? 'tipo' : "'unica' AS tipo") . ', ' . (esquema_imagen() ? '(imagen_mime IS NOT NULL)' : '0') . ' tiene_img
        FROM questions WHERE quiz_id=? ORDER BY orden, id', [$quizId])->fetchAll();
    $opts = [];
    $sel = $nuevo ? 'o.id, o.question_id, o.texto, o.es_correcta, o.rol, o.correcta_id'
        : "o.id, o.question_id, o.texto, o.es_correcta, 'op' AS rol, NULL AS correcta_id";
    foreach (q("SELECT $sel FROM options o JOIN questions qu ON qu.id=o.question_id WHERE qu.quiz_id=? ORDER BY o.orden, o.id", [$quizId]) as $o) {
        $opts[(int)$o['question_id']][(int)$o['id']] = $o;
    }
    return [$preguntas, $opts];
}

/** Opciones de una pregunta filtradas por rol ('op', 'fila' o 'col'). */
function opciones_rol(array $ops, string $rol): array
{
    return array_filter($ops, fn($o) => ($o['rol'] ?? 'op') === $rol);
}

/**
 * Carga las respuestas guardadas: [submission_id][question_id] => respuesta.
 * unica/desplegable: id de opción; multiple: [ids]; cuadricula: [fila_id => columna_id].
 */
function cargar_respuestas(int $quizId, array $preguntas, ?int $subId = null): array
{
    $tipos = [];
    foreach ($preguntas as $p) $tipos[(int)$p['id']] = $p['tipo'] ?? 'unica';
    $filtro = $subId !== null ? ' AND s.id=?' : '';
    $par = $subId !== null ? [$quizId, $subId] : [$quizId];
    $res = [];
    foreach (q('SELECT a.submission_id, a.question_id, a.option_id FROM answers a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=?' . $filtro, $par) as $r) {
        $res[(int)$r['submission_id']][(int)$r['question_id']] = (int)$r['option_id'];
    }
    if (esquema_tipos()) {
        foreach (q('SELECT a.submission_id, a.question_id, a.row_id, a.option_id FROM answer_items a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=?' . $filtro . ' ORDER BY a.id', $par) as $r) {
            $s = (int)$r['submission_id'];
            $pq = (int)$r['question_id'];
            if (($tipos[$pq] ?? '') === 'cuadricula') $res[$s][$pq][(int)$r['row_id']] = (int)$r['option_id'];
            else $res[$s][$pq][] = (int)$r['option_id'];
        }
    }
    return $res;
}

/**
 * Normaliza lo enviado por el alumno para una pregunta contra sus opciones.
 * Devuelve la respuesta limpia o null si falta/es inválida.
 * $parcial (tiempo agotado): acepta respuestas incompletas descartando lo inválido.
 */
function normalizar_resp(array $p, array $ops, $raw, bool $parcial = false)
{
    $tipo = $p['tipo'] ?? 'unica';
    $esId = fn($v) => (is_string($v) || is_int($v)) && ctype_digit((string)$v);
    if ($tipo === 'multiple') {
        $validas = opciones_rol($ops, 'op');
        if (!is_array($raw)) return null;
        $sel = [];
        foreach ($raw as $v) {
            if ($esId($v) && isset($validas[(int)$v])) $sel[(int)$v] = (int)$v;
            elseif (!$parcial) return null;
        }
        return $sel ? array_values($sel) : null;
    }
    if ($tipo === 'cuadricula') {
        $filas = opciones_rol($ops, 'fila');
        $cols = opciones_rol($ops, 'col');
        if (!is_array($raw)) return null;
        $out = [];
        foreach ($filas as $fid => $_) {
            $v = $raw[$fid] ?? null;
            if ($esId($v) && isset($cols[(int)$v])) $out[$fid] = (int)$v;
            elseif (!$parcial) return null;
        }
        return $out ?: null;
    }
    $validas = opciones_rol($ops, 'op');
    return ($esId($raw) && isset($validas[(int)$raw])) ? (int)$raw : null;
}

/**
 * Califica una pregunta. Devuelve ['pts' => puntos obtenidos, 'total' => unidades, 'ok' => todo correcto].
 * Unica/desplegable y multiple valen 1 unidad; cuadricula vale 1 por fila.
 */
function calificar(array $pregunta, array $opciones, $resp): array
{
    $tipo = $pregunta['tipo'] ?? 'unica';
    if ($tipo === 'cuadricula') {
        $filas = opciones_rol($opciones, 'fila');
        $pts = 0;
        foreach ($filas as $fid => $f) {
            if (is_array($resp) && isset($resp[$fid]) && (int)$f['correcta_id'] > 0 && (int)$resp[$fid] === (int)$f['correcta_id']) $pts++;
        }
        return ['pts' => $pts, 'total' => count($filas), 'ok' => count($filas) > 0 && $pts === count($filas)];
    }
    if ($tipo === 'multiple') {
        $corr = [];
        foreach (opciones_rol($opciones, 'op') as $oid => $o) if ($o['es_correcta']) $corr[] = (int)$oid;
        $sel = is_array($resp) ? array_values(array_unique(array_map('intval', $resp))) : [];
        sort($corr);
        sort($sel);
        $ok = $corr && $sel === $corr;
        return ['pts' => $ok ? 1 : 0, 'total' => 1, 'ok' => $ok];
    }
    $o = (is_int($resp) || (is_string($resp) && ctype_digit($resp))) ? ($opciones[(int)$resp] ?? null) : null;
    $ok = $o !== null && ($o['rol'] ?? 'op') === 'op' && (int)$o['es_correcta'] === 1;
    return ['pts' => $ok ? 1 : 0, 'total' => 1, 'ok' => $ok];
}

/** Texto plano de la respuesta del alumno ('' si no respondió). Multiple: "a | b"; cuadricula: "fila: col; ...". */
function resp_texto(array $p, array $ops, $resp): string
{
    $tipo = $p['tipo'] ?? 'unica';
    if ($tipo === 'multiple') {
        $t = [];
        foreach (opciones_rol($ops, 'op') as $oid => $o) if (is_array($resp) && in_array($oid, $resp, true)) $t[] = $o['texto'];
        return implode(' | ', $t);
    }
    if ($tipo === 'cuadricula') {
        $t = [];
        foreach (opciones_rol($ops, 'fila') as $fid => $f) {
            if (is_array($resp) && isset($resp[$fid], $ops[$resp[$fid]])) $t[] = $f['texto'] . ': ' . $ops[$resp[$fid]]['texto'];
        }
        return implode('; ', $t);
    }
    return is_int($resp) && isset($ops[$resp]) ? (string)$ops[$resp]['texto'] : '';
}

/** Texto plano de la respuesta correcta, con el mismo formato que resp_texto. */
function correcta_texto(array $p, array $ops): string
{
    $tipo = $p['tipo'] ?? 'unica';
    if ($tipo === 'cuadricula') {
        $t = [];
        foreach (opciones_rol($ops, 'fila') as $f) $t[] = $f['texto'] . ': ' . ($ops[(int)$f['correcta_id']]['texto'] ?? '?');
        return implode('; ', $t);
    }
    $t = [];
    foreach (opciones_rol($ops, 'op') as $o) if ($o['es_correcta']) $t[] = $o['texto'];
    return implode($tipo === 'multiple' ? ' | ' : ' / ', $t);
}
