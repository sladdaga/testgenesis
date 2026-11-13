<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

$id = (int)($_GET['id'] ?? 0);
$quiz = q('SELECT * FROM quizzes WHERE id=?', [$id])->fetch();
if (!$quiz) { http_response_code(404); exit('No encontrado'); }

$preguntas = q('SELECT id, texto FROM questions WHERE quiz_id=? ORDER BY orden, id', [$id])->fetchAll();
$resp = [];
foreach (q('SELECT a.submission_id, a.question_id, o.texto FROM answers a JOIN options o ON o.id=a.option_id JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=?', [$id]) as $r) {
    $resp[$r['submission_id']][$r['question_id']] = $r['texto'];
}

$nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', $quiz['titulo']) ?: 'cuestionario';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="resultados_' . $nombre . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

$head = ['Apellido', 'Nombre', 'Email', 'Curso', 'Nota (0-10)', 'Correctas', 'Total', 'Fecha'];
foreach ($preguntas as $i => $p) $head[] = 'P' . ($i + 1) . ': ' . $p['texto'];
fputcsv($out, array_map('csv_safe', $head), ';');

foreach (q('SELECT * FROM submissions WHERE quiz_id=? ORDER BY created_at', [$id]) as $s) {
    $row = [$s['apellido'], $s['nombre'], $s['email'], $s['curso'], nota_fmt($s['puntaje'], $s['total']), $s['puntaje'], $s['total'], $s['created_at']];
    foreach ($preguntas as $p) $row[] = $resp[$s['id']][$p['id']] ?? '';
    fputcsv($out, array_map('csv_safe', $row), ';');
}
fclose($out);
