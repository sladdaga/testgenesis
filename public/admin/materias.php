<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

/** Valida un nombre de materia; devuelve mensaje de error o null. */
function materia_error(string $nombre, int $excluirId = 0): ?string
{
    if ($nombre === '' || mb_strlen($nombre) > 150) return 'El nombre es obligatorio (máx. 150 caracteres).';
    if (q('SELECT COUNT(*) FROM materias WHERE nombre=? AND id<>?', [$nombre, $excluirId])->fetchColumn() > 0) return 'Ya existe una materia con ese nombre.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    if ($accion === 'crear') {
        if ($err = materia_error($nombre)) flash($err, 'error');
        else { q('INSERT INTO materias (nombre) VALUES (?)', [$nombre]); flash('Materia creada.'); }
    } elseif ($accion === 'renombrar') {
        if (!q('SELECT id FROM materias WHERE id=?', [$id])->fetch()) flash('Materia no encontrada.', 'error');
        elseif ($err = materia_error($nombre, $id)) flash($err, 'error');
        else { q('UPDATE materias SET nombre=? WHERE id=?', [$nombre, $id]); flash('Materia renombrada.'); }
    } elseif ($accion === 'borrar') {
        if (q('SELECT COUNT(*) FROM quizzes WHERE materia_id=?', [$id])->fetchColumn() > 0) flash('No se puede eliminar una materia con exámenes.', 'error');
        else { q('DELETE FROM materias WHERE id=?', [$id]); flash('Materia eliminada.'); }
    }
    redirect('materias.php');
}

$materias = q('SELECT m.*, (SELECT COUNT(*) FROM quizzes z WHERE z.materia_id=m.id) nexam FROM materias m ORDER BY m.nombre')->fetchAll();

page_head('Materias', true);
echo '<h1>Materias / módulos</h1>';
?>
<form method="post" class="card"><?= csrf_field() ?>
  <input type="hidden" name="accion" value="crear">
  <label>Nueva materia / módulo<input type="text" name="nombre" maxlength="150" required></label>
  <button class="btn" data-once>Crear</button>
</form>
<?php if (!$materias) echo '<p>Todavía no hay materias.</p>';
foreach ($materias as $m): ?>
  <div class="card">
    <strong><?= e($m['nombre']) ?></strong>
    <div class="desc"><?= (int)$m['nexam'] ?> exámenes</div>
    <form method="post" class="acciones" style="margin-top:10px"><?= csrf_field() ?>
      <input type="hidden" name="accion" value="renombrar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
      <input type="text" name="nombre" maxlength="150" required value="<?= e($m['nombre']) ?>" aria-label="Nuevo nombre" style="width:auto;flex:1;min-width:180px;margin:0">
      <button class="btn sm sec">Renombrar</button>
    </form>
    <div class="acciones" style="margin-top:8px">
      <a class="btn sm" href="materia.php?id=<?= (int)$m['id'] ?>">Ver notas</a>
      <a class="btn sm sec" href="index.php?materia=<?= (int)$m['id'] ?>">Exámenes</a>
      <?php if ((int)$m['nexam'] === 0): ?>
      <form method="post" class="inline" data-confirm="¿Eliminar la materia?"><?= csrf_field() ?>
        <input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <button class="btn sm danger">Eliminar</button></form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach;
page_foot(true);
