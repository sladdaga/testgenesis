<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

$id = (int)($_GET['id'] ?? 0);
$materia = q('SELECT * FROM materias WHERE id=?', [$id])->fetch();
if (!$materia) { flash('Materia no encontrada.', 'error'); redirect('materias.php'); }

$examenes = q('SELECT id, titulo FROM quizzes WHERE materia_id=? ORDER BY created_at, id', [$id])->fetchAll();

// Alumnos por email (minúsculas); por examen se queda el último envío.
$alumnos = [];
$subs = q('SELECT s.* FROM submissions s JOIN quizzes z ON z.id=s.quiz_id WHERE z.materia_id=? ORDER BY s.created_at, s.id', [$id])->fetchAll();
foreach ($subs as $s) {
    $k = mb_strtolower(trim($s['email']));
    $alumnos[$k]['apellido'] = $s['apellido'];   // el último envío define el nombre mostrado
    $alumnos[$k]['nombre'] = $s['nombre'];
    $alumnos[$k]['email'] = $k;
    $alumnos[$k]['notas'][(int)$s['quiz_id']] = $s;
}
uasort($alumnos, fn($a, $b) => strcasecmp($a['apellido'] . ' ' . $a['nombre'], $b['apellido'] . ' ' . $b['nombre']));
foreach ($alumnos as &$a) {
    $vals = array_map(fn($s) => nota10($s['puntaje'], $s['total']), $a['notas']);
    $a['promedio'] = $vals ? rtrim(rtrim(number_format(array_sum($vals) / count($vals), 1, ',', ''), '0'), ',') : '';
}
unset($a);

if (isset($_GET['csv'])) {
    $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', $materia['nombre']) ?: 'materia';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="notas_' . $nombre . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['Apellido y nombre', 'Email'];
    foreach ($examenes as $z) $head[] = $z['titulo'];
    $head[] = 'Promedio';
    fputcsv($out, array_map('csv_safe', $head), ';');
    foreach ($alumnos as $a) {
        $row = [$a['apellido'] . ', ' . $a['nombre'], $a['email']];
        foreach ($examenes as $z) {
            $s = $a['notas'][(int)$z['id']] ?? null;
            $row[] = $s ? nota_fmt($s['puntaje'], $s['total']) : '';
        }
        $row[] = $a['promedio'];
        fputcsv($out, array_map('csv_safe', $row), ';');
    }
    fclose($out);
    exit;
}

page_head('Materia: ' . $materia['nombre'], true);
?>
<h1><?= e($materia['nombre']) ?></h1>
<p class="acciones">
  <a class="btn sm sec" href="materias.php">&larr; Materias</a>
  <a class="btn sm sec" href="index.php?materia=<?= $id ?>">Ver exámenes</a>
  <?php if ($alumnos): ?><a class="btn sm" href="materia.php?id=<?= $id ?>&amp;csv=1">Exportar CSV</a><?php endif; ?>
</p>

<h2>Exámenes</h2>
<?php if (!$examenes): ?><p>Esta materia todavía no tiene exámenes.</p><?php else: ?>
<ul>
  <?php foreach ($examenes as $z): ?><li><a href="results.php?id=<?= (int)$z['id'] ?>"><?= e($z['titulo']) ?></a></li><?php endforeach; ?>
</ul>

<h2>Notas por alumno</h2>
<?php if (!$alumnos): ?><p>Todavía no hay envíos.</p><?php else: ?>
<div class="tw"><table>
  <tr><th>Apellido y nombre</th><th>Email</th>
    <?php foreach ($examenes as $z): ?><th><?= e($z['titulo']) ?></th><?php endforeach; ?>
    <th>Promedio</th></tr>
  <?php foreach ($alumnos as $a): ?>
  <tr>
    <td><?= e($a['apellido'] . ', ' . $a['nombre']) ?></td>
    <td><?= e($a['email']) ?></td>
    <?php foreach ($examenes as $z): $s = $a['notas'][(int)$z['id']] ?? null; ?>
    <td><?= $s ? e(nota_fmt($s['puntaje'], $s['total'])) : '-' ?></td>
    <?php endforeach; ?>
    <td><strong><?= e($a['promedio']) ?></strong></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<p class="desc">Si un alumno envió un examen más de una vez, se muestra el último envío. El promedio considera solo los exámenes rendidos.</p>
<?php endif; endif;
page_foot(true);
