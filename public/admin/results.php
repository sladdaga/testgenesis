<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$quiz = q('SELECT z.*, m.nombre materia FROM quizzes z LEFT JOIN materias m ON m.id=z.materia_id WHERE z.id=?', [$id])->fetch();
if (!$quiz) { flash('Cuestionario no encontrado.', 'error'); redirect('index.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['accion'] ?? '') === 'borrar_envio') {
        q('DELETE FROM submissions WHERE id=? AND quiz_id=?', [(int)($_POST['sub'] ?? 0), $id]);
        flash('Envío eliminado.');
    }
    redirect('results.php?id=' . $id);
}

[$preguntas, $opts] = cargar_preguntas($id);

// ---- Detalle de un envío ----
if (isset($_GET['sub'])) {
    $s = q('SELECT * FROM submissions WHERE id=? AND quiz_id=?', [(int)$_GET['sub'], $id])->fetch();
    if (!$s) { flash('Envío no encontrado.', 'error'); redirect('results.php?id=' . $id); }
    page_head('Detalle del envío', true);
    echo '<h1>' . e($s['nombre'] . ' ' . $s['apellido']) . '</h1>'
        . '<p class="desc">' . e($s['email']) . ' · ' . e($s['curso']) . ' · ' . e(fecha($s['created_at']))
        . ' · Nota: <strong>' . e(nota_fmt($s['puntaje'], $s['total'])) . ' / 10</strong> (' . (int)$s['puntaje'] . ' / ' . (int)$s['total'] . ')</p>';
    $resS = cargar_respuestas($id, $preguntas, (int)$s['id'])[(int)$s['id']] ?? [];
    foreach ($preguntas as $i => $p) {
        $ops = $opts[$p['id']] ?? [];
        $resp = $resS[$p['id']] ?? null;
        $cal = calificar($p, $ops, $resp);
        $ok = $cal['ok'];
        $txt = resp_texto($p, $ops, $resp);
        echo '<div class="card"><p class="preg">' . ($i + 1) . '. ' . nl2br(e($p['texto'])) . ' <small>[' . e(TIPOS_PREGUNTA[$p['tipo']] ?? $p['tipo']) . ']</small></p>';
        if ($p['tipo'] === 'cuadricula') {
            echo '<p class="' . ($ok ? 'bien' : 'mal') . '">Puntos: ' . $cal['pts'] . '/' . $cal['total'] . ' filas</p><div class="tw"><table><tr><th>Fila</th><th>Respondió</th><th>Correcta</th></tr>';
            foreach (opciones_rol($ops, 'fila') as $fid => $f) {
                $fok = isset($resp[$fid]) && (int)$resp[$fid] === (int)$f['correcta_id'];
                echo '<tr><td>' . e($f['texto']) . '</td><td class="' . ($fok ? 'bien' : 'mal') . '">' . e(isset($resp[$fid]) ? ($ops[$resp[$fid]]['texto'] ?? '') : '(sin respuesta)') . ($fok ? ' ✔' : ' ✘') . '</td><td>' . e($ops[(int)$f['correcta_id']]['texto'] ?? '') . '</td></tr>';
            }
            echo '</table></div>';
        } else {
            echo '<p class="' . ($ok ? 'bien' : 'mal') . '">Respondió: ' . e($txt === '' ? '(sin respuesta)' : $txt) . ($ok ? ' ✔' : ' ✘') . '</p>';
            if (!$ok) echo '<p class="bien">Correcta: ' . e(correcta_texto($p, $ops)) . '</p>';
        }
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
$cuentas = [];  // pregunta => opción => cantidad (única, desplegable, múltiple)
$cuentasF = []; // pregunta => fila => columna => cantidad (cuadrícula)
$nResp = [];    // pregunta => envíos que la respondieron
foreach (q('SELECT a.question_id, a.option_id, COUNT(*) c FROM answers a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=? GROUP BY a.question_id, a.option_id', [$id]) as $r) {
    $cuentas[$r['question_id']][$r['option_id']] = (int)$r['c'];
}
foreach (q('SELECT a.question_id, a.row_id, a.option_id, COUNT(*) c FROM answer_items a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=? GROUP BY a.question_id, a.row_id, a.option_id', [$id]) as $r) {
    if ($r['row_id'] === null) $cuentas[$r['question_id']][$r['option_id']] = (int)$r['c'];
    else $cuentasF[$r['question_id']][$r['row_id']][$r['option_id']] = (int)$r['c'];
}
foreach (q('SELECT a.question_id, COUNT(DISTINCT a.submission_id) c FROM answer_items a JOIN submissions s ON s.id=a.submission_id WHERE s.quiz_id=? GROUP BY a.question_id', [$id]) as $r) {
    $nResp[$r['question_id']] = (int)$r['c'];
}

page_head('Resultados', true);
?>
<h1><?= e($quiz['titulo']) ?></h1>
<?php if ($quiz['materia_id']): ?><p class="desc">Materia / módulo: <a href="materia.php?id=<?= (int)$quiz['materia_id'] ?>"><?= e($quiz['materia']) ?></a></p><?php endif; ?>
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
  $tot = $p['tipo'] === 'multiple' ? ($nResp[$p['id']] ?? 0) : array_sum($cuentas[$p['id']] ?? []);
  $ops = $opts[$p['id']] ?? []; ?>
  <div class="card">
    <p class="preg"><?= $i + 1 ?>. <?= nl2br(e($p['texto'])) ?> <small>[<?= e(TIPOS_PREGUNTA[$p['tipo']] ?? $p['tipo']) ?>]</small></p>
    <?php if ($p['tipo'] === 'cuadricula'): foreach (opciones_rol($ops, 'fila') as $f):
      $cf = $cuentasF[$p['id']][$f['id']] ?? []; $totF = array_sum($cf); ?>
      <p class="mt8"><strong><?= e($f['texto']) ?></strong></p>
      <?php foreach (opciones_rol($ops, 'col') as $o):
        $c = $cf[$o['id']] ?? 0; $pct = $totF ? round($c * 100 / $totF) : 0; $es = (int)$f['correcta_id'] === (int)$o['id']; ?>
        <div class="barra <?= $es ? 'ok' : '' ?>">
          <span class="t"><?= e($o['texto']) ?><?= $es ? ' ✔' : '' ?></span>
          <progress max="100" value="<?= $pct ?>"></progress><span><?= $pct ?>% (<?= $c ?>)</span>
        </div>
      <?php endforeach; endforeach; else: foreach (opciones_rol($ops, 'op') as $o):
      $c = $cuentas[$p['id']][$o['id']] ?? 0; $pct = $tot ? round($c * 100 / $tot) : 0; ?>
      <div class="barra <?= $o['es_correcta'] ? 'ok' : '' ?>">
        <span class="t"><?= e($o['texto']) ?><?= $o['es_correcta'] ? ' ✔' : '' ?></span>
        <progress max="100" value="<?= $pct ?>"></progress><span><?= $pct ?>% (<?= $c ?>)</span>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php page_foot(true);
