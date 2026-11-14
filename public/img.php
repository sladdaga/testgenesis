<?php
// Sirve la imagen de una pregunta. Requiere el token del examen (no basta con adivinar el id).
require __DIR__ . '/../src/bootstrap.php';

$t = $_GET['t'] ?? '';
$p = (int)($_GET['p'] ?? 0);
if (!is_string($t) || !preg_match('/^[A-Za-z0-9]{8,32}$/', $t) || $p < 1) { http_response_code(404); exit; }

$r = q('SELECT qu.imagen, qu.imagen_mime FROM questions qu JOIN quizzes z ON z.id=qu.quiz_id
        WHERE qu.id=? AND z.token=? AND qu.imagen_mime IS NOT NULL', [$p, $t])->fetch();
if (!$r) { http_response_code(404); exit; }

session_write_close();
header('Content-Type: ' . $r['imagen_mime']);
header('Content-Length: ' . strlen($r['imagen']));
header('Cache-Control: private, max-age=3600');
echo $r['imagen'];
