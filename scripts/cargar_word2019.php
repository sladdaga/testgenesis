<?php
// Uso: php scripts/cargar_word2019.php   (crea el cuestionario "Word 2019" con sus 31 preguntas)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Solo CLI\n");
require __DIR__ . '/../src/helpers.php';
require __DIR__ . '/../src/db.php';

$datos = require __DIR__ . '/datos_word2019.php';
$pdo = db();
$pdo->beginTransaction();
$token = bin2hex(random_bytes(8));
q('INSERT INTO quizzes (titulo, descripcion, token, activo, mostrar_resultado) VALUES (?,?,?,?,?)',
  ['Examen Cuestionario Integral: Curso Microsoft Word 2019', 'Examen de opción múltiple (30 preguntas).', $token, 1, 0]);
$qid = (int)$pdo->lastInsertId();
foreach ($datos as $i => [$texto, $ops, $ok]) {
    q('INSERT INTO questions (quiz_id, texto, orden) VALUES (?,?,?)', [$qid, $texto, $i + 1]);
    $pid = (int)$pdo->lastInsertId();
    foreach ($ops as $j => $o) {
        q('INSERT INTO options (question_id, texto, es_correcta, orden) VALUES (?,?,?,?)', [$pid, $o, $j === $ok ? 1 : 0, $j + 1]);
    }
}
$pdo->commit();
echo "Cuestionario #$qid creado con " . count($datos) . " preguntas. Token: $token\n";
