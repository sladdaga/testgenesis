<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'toggle') {
        q('UPDATE quizzes SET activo = 1 - activo WHERE id=?', [$id]);
        flash('Estado actualizado.');
    } elseif ($accion === 'borrar') {
        $pdo = db();
        $pdo->beginTransaction();
        q('DELETE FROM submissions WHERE quiz_id=?', [$id]); // borra answers en cascada
        q('DELETE FROM quizzes WHERE id=?', [$id]);
        $pdo->commit();
        flash('Cuestionario eliminado.');
    }
    redirect('index.php');
}

$filtro = (int)($_GET['materia'] ?? 0);
$materias = q('SELECT id, nombre FROM materias ORDER BY nombre')->fetchAll();
$sql = 'SELECT z.*, m.nombre materia, (SELECT COUNT(*) FROM submissions s WHERE s.quiz_id=z.id) envios,
               (SELECT COUNT(*) FROM questions qu WHERE qu.quiz_id=z.id) npreg
        FROM quizzes z LEFT JOIN materias m ON m.id=z.materia_id'
     . ($filtro ? ' WHERE z.materia_id=?' : '') . ' ORDER BY (m.nombre IS NULL), m.nombre, z.created_at DESC';
$quizzes = q($sql, $filtro ? [$filtro] : [])->fetchAll();

page_head('Mis cuestionarios', true);
echo '<h1>Mis cuestionarios</h1><p><a class="btn" href="quiz_edit.php">+ Nuevo cuestionario</a></p>';
if ($materias): ?>
<form method="get" class="acciones mb12">
  <select name="materia" aria-label="Filtrar por materia" class="auto">
    <option value="">Todas las materias</option>
    <?php foreach ($materias as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === $filtro ? ' selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn sm sec">Filtrar</button>
</form>
<?php endif;
if (!$quizzes) echo '<p>' . ($filtro ? 'No hay exámenes en esta materia.' : 'Todavía no creaste cuestionarios.') . '</p>';
$grupoActual = false;
foreach ($quizzes as $z):
  $g = $z['materia_id'] === null ? 0 : (int)$z['materia_id'];
  if ($grupoActual !== $g):
    if ($grupoActual !== false) echo '</div>';
    $grupoActual = $g; ?>
  <h2><?= e($z['materia'] ?? 'Sin materia') ?>
    <?php if ($g): ?><a class="btn sm sec" href="materia.php?id=<?= $g ?>">Ver notas</a><?php endif; ?></h2>
  <div class="qlist">
<?php endif; ?>
  <div class="card">
    <strong><?= e($z['titulo']) ?></strong>
    <span class="badge <?= $z['activo'] ? '' : 'off' ?>"><?= $z['activo'] ? 'Abierto' : 'Cerrado' ?></span>
    <div class="desc"><?= (int)$z['npreg'] ?> preguntas · <?= (int)$z['envios'] ?> envíos · <?= e(fecha($z['created_at'])) ?></div>
    <div class="acciones mt10">
      <button class="btn sm sec" type="button" data-copy="<?= e(quiz_link($z['token'])) ?>">Copiar enlace</button>
      <a class="btn sm" href="results.php?id=<?= (int)$z['id'] ?>">Resultados</a>
      <a class="btn sm sec" href="quiz_edit.php?id=<?= (int)$z['id'] ?>">Editar</a>
      <form method="post" class="inline"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="accion" value="toggle">
        <button class="btn sm sec"><?= $z['activo'] ? 'Cerrar' : 'Activar' ?></button></form>
      <form method="post" class="inline" data-confirm="¿Eliminar el cuestionario y todas sus respuestas? No se puede deshacer."><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$z['id'] ?>"><input type="hidden" name="accion" value="borrar">
        <button class="btn sm danger">Eliminar</button></form>
    </div>
  </div>
<?php endforeach;
if ($grupoActual !== false) echo '</div>';
page_foot(true);
