<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

/** Migración idempotente: materias + quizzes.materia_id. Devuelve el log de lo hecho. */
function migrar_materias(): array
{
    $log = [];
    $pdo = db();
    $existe = fn(string $sql, array $p = []) => (int)q($sql, $p)->fetchColumn() > 0;

    // 1) Tabla materias
    if ($existe('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['materias'])) {
        $log[] = 'La tabla «materias» ya existía.';
    } else {
        $pdo->exec('CREATE TABLE materias (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $log[] = 'Se creó la tabla «materias».';
    }

    // 2) Columna quizzes.materia_id
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['quizzes', 'materia_id'])) {
        $log[] = 'La columna «quizzes.materia_id» ya existía.';
    } else {
        $pdo->exec('ALTER TABLE quizzes ADD COLUMN materia_id INT UNSIGNED NULL AFTER id');
        $log[] = 'Se agregó la columna «quizzes.materia_id».';
    }

    // 3) Clave foránea
    if ($existe('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=?', ['quizzes', 'materia_id', 'materias'])) {
        $log[] = 'La clave foránea ya existía.';
    } else {
        $pdo->exec('ALTER TABLE quizzes ADD CONSTRAINT fk_quizzes_materia FOREIGN KEY (materia_id) REFERENCES materias(id) ON DELETE RESTRICT');
        $log[] = 'Se agregó la clave foránea quizzes.materia_id → materias.id.';
    }

    // 4) Materia «General» si no hay ninguna
    if (!$existe('SELECT COUNT(*) FROM materias')) {
        q('INSERT INTO materias (nombre) VALUES (?)', ['General']);
        $log[] = 'No había materias: se creó «General».';
    } else {
        $log[] = 'Ya hay materias; no se creó «General».';
    }

    // 5) Asignar exámenes sin materia
    $sin = (int)q('SELECT COUNT(*) FROM quizzes WHERE materia_id IS NULL')->fetchColumn();
    if ($sin > 0) {
        $gen = q('SELECT id FROM materias WHERE nombre=?', ['General'])->fetchColumn();
        if (!$gen) $gen = q('SELECT id FROM materias ORDER BY id LIMIT 1')->fetchColumn();
        q('UPDATE quizzes SET materia_id=? WHERE materia_id IS NULL', [(int)$gen]);
        $nom = q('SELECT nombre FROM materias WHERE id=?', [(int)$gen])->fetchColumn();
        $log[] = "Se asignaron $sin examen(es) sin materia a «{$nom}».";
    } else {
        $log[] = 'No hay exámenes sin materia.';
    }
    return $log;
}

$log = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $log = migrar_materias();
    } catch (Throwable $ex) {
        $error = 'La migración falló: ' . $ex->getMessage();
    }
}

page_head('Actualizar base', true);
echo '<h1>Actualizar base de datos</h1>'
    . '<p class="desc">Agrega el soporte de materias / módulos. Es seguro ejecutarlo más de una vez.</p>';
if ($error) echo '<div class="msg error">' . e($error) . '</div>';
if ($log) {
    echo '<div class="card"><strong>Resultado</strong><ul>';
    foreach ($log as $l) echo '<li>' . e($l) . '</li>';
    echo '</ul></div>';
}
?>
<form method="post" action="migrar.php"><?= csrf_field() ?>
  <button class="btn" data-once>Ejecutar actualización</button>
  <a class="btn sec" href="index.php">Volver</a>
</form>
<?php page_foot(true);
