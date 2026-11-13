<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$quiz = q('SELECT * FROM quizzes WHERE id=?', [$id])->fetch();
if (!$quiz) { flash('Cuestionario no encontrado.', 'error'); redirect('index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'borrar_envio') {
        q('DELETE FROM submissions WHERE id=? AND quiz_id=?', [(int)($_POST['sub'] ?? 0), $id]);
        flash('Envío eliminado.');
    }
    redirect('results.php?id=' . $id);
}

$preguntas = q('SELECT id, texto FROM questions WHERE quiz_id=? ORDER BY orden, id', [$id])->fetchAll();

// ---- Detalle de un envío ----
if (isset($_GET['sub'])) {
    $s = q('SELECT * FROM submissions WHERE id=? AND quiz_id=?', [(int)$_GET['sub'], $id])->fetch();
    if (!$s) { flash('Envío no encontrado.', 'error'); redirect('results.php?id=' . $id); }
    page_head('Detalle del envío', true);
    echo '<h1>' . e($s['nombre'] . ' ' . $s['apellido']) . '</h1>'
        . '<p class="desc">' . e($s['email']) . ' · ' . e($s['curso']) . ' · ' . e(fecha($s['created_at']))
        . ' · Nota: <strong>' . e(nota_fmt($s['puntaje'], $s['total'])) . ' / 10</strong> (' . (int)$s['puntaje'] . ' / ' . (int)$s['total'] . ')</p>';
    $rows = q('SELECT qu.texto, o.texto elegida, o.es_correcta,
                (SELECT GROUP_CONCAT(o2.texto SEPARATOR " / ") FROM options o2 WHERE o2.question_id=qu.id AND o2.es_correcta=1) correcta
               FROM questions qu LEFT JOIN answers a ON a.question_id=qu.id AND a.submission_id=?
               LEFT JOIN options o ON o.id=a.option_id WHERE qu.quiz_id=? ORDER BY qu.orden, qu.id', [$s['id'], $id])->fetchAll();
    foreach ($rows as $i => $r) {
        $ok = (bool)$r['es_correcta'];
        echo '<div class="card"><p class="preg">' . ($i + 1) . '. ' . nl2br(e($r['texto'])) . '</p>'
            . '<p class="' . ($ok ? 'bien' : 'mal') . '">Respondió: ' . e($r['elegida'] ?? '(sin respuesta)') . ($ok ? ' ✔' : ' ✘') . '</p>';
        if (!$ok) echo '<p class="bien">Correcta: ' . e($r['correcta']) . '</p>';
        echo '</div>';
    }
    echo '<p><a class="btn sec" href="results.php?id=' . $id . '">Volver</a></p>';
    page_foot(true);
    exit;
}

// ---- Dashboard ----
$subs = q('SELECT * FROM submissions WHERE quiz_id=? ORDER BY created_at DESC', [$id])->fetchAll();
$n = count($subs);
$prom = 0;
if ($n) {
    $sum = 0;
    foreach ($subs as $s) $sum += $s['total'] > 0 ? $s['puntaje'] / $s['total'] : 0;
    $prom = $sum / $n * 10;
}
$cuentas = [];
foreach (q('SELECT a.question_id, a.option_id, COUNT(*) c FROM answers a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=? GROUP BY a.question_id, a.option_id', [$id]) as $r) {
    $cuentas[$r['question_id']][$r['option_id']] = (int)$r['c'];
}
$opts = [];
foreach (q('SELECT o.* FROM options o JOIN questions qu ON qu.id=o.question_id WHERE qu.quiz_id=? ORDER BY o.orden, o.id', [$id]) as $o) $opts[$o['question_id']][] = $o;

page_head('Resultados', true);
?>
<h1><?= e($quiz['titulo']) ?></h1>
<p class="acciones">
  <a class="btn sm sec" href="index.php">&larr; Volver</a>
  <a class="btn sm" href="export.php?id=<?= $id ?>">Exportar CSV</a>
  <a class="btn sm sec" href="quiz_edit.php?id=<?= $id ?>">Editar</a>
</p>
<div class="stats">
  <div class="stat"><b><?= $n ?></b>Envíos</div>
  <div class="stat"><b><?= $n ? number_format($prom, 1, ',', '') . ' / 10' : '-' ?></b>Promedio</div>
</div>

<h2>Alumnos</h2>
<?php if (!$n): ?><p>Todavía no hay envíos.</p><?php else: ?>
<div class="tw"><table>
  <tr><th>Apellido y nombre</th><th>Email</th><th>Curso</th><th>Nota</th><th>Fecha</th><th></th></tr>
  <?php foreach ($subs as $s): ?>
  <tr>
    <td><?= e($s['apellido'] . ', ' . $s['nombre']) ?></td>
    <td><?= e($s['email']) ?></td>
    <td><?= e($s['curso']) ?></td>
    <td><strong><?= e(nota_fmt($s['puntaje'], $s['total'])) ?></strong> / 10 <small>(<?= (int)$s['puntaje'] ?>/<?= (int)$s['total'] ?>)</small></td>
    <td><?= e(fecha($s['created_at'])) ?></td>
    <td class="acciones">
      <a class="btn sm sec" href="results.php?id=<?= $id ?>&amp;sub=<?= (int)$s['id'] ?>">Detalle</a>
      <form method="post" class="inline" data-confirm="¿Eliminar este envío?"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="sub" value="<?= (int)$s['id'] ?>">
        <input type="hidden" name="accion" value="borrar_envio"><button class="btn sm danger">Eliminar</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>

<h2>Por pregunta</h2>
<?php foreach ($preguntas as $i => $p):
  $tot = array_sum($cuentas[$p['id']] ?? []); ?>
  <div class="card">
    <p class="preg"><?= $i + 1 ?>. <?= nl2br(e($p['texto'])) ?></p>
    <?php foreach ($opts[$p['id']] ?? [] as $o):
      $c = $cuentas[$p['id']][$o['id']] ?? 0; $pct = $tot ? round($c * 100 / $tot) : 0; ?>
      <div class="barra <?= $o['es_correcta'] ? 'ok' : '' ?>">
        <span class="t"><?= e($o['texto']) ?><?= $o['es_correcta'] ? ' ✔' : '' ?></span>
        <progress max="100" value="<?= $pct ?>"></progress><span><?= $pct ?>% (<?= $c ?>)</span>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; endif; ?>
<?php page_foot(true);
