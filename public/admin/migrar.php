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

    // 6) Tabla intentos (inicio del examen por alumno, guardado en la base)
    if ($existe('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['intentos'])) {
        $log[] = 'La tabla «intentos» ya existía.';
    } else {
        $pdo->exec('CREATE TABLE intentos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(100) NOT NULL,
            apellido VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL,
            curso VARCHAR(120) NOT NULL,
            inicio DATETIME NOT NULL,
            submission_id INT UNSIGNED NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_intento (quiz_id, email),
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $log[] = 'Se creó la tabla «intentos».';
    }

    // 7) Columna questions.justificacion
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['questions', 'justificacion'])) {
        $log[] = 'La columna «questions.justificacion» ya existía.';
    } else {
        $pdo->exec('ALTER TABLE questions ADD COLUMN justificacion TEXT NULL AFTER texto');
        $log[] = 'Se agregó la columna «questions.justificacion».';
        // 8) Primera vez: cargar las justificaciones del examen Word 2019 y mostrar la revisión al alumno
        $f = __DIR__ . '/../../scripts/datos_word2019.php';
        if (is_file($f)) {
            $datos = require $f;
            $n = 0;
            $up = $pdo->prepare('UPDATE questions SET justificacion=? WHERE texto=? AND justificacion IS NULL');
            foreach ($datos as $d) {
                if (!empty($d[3])) { $up->execute([$d[3], $d[0]]); $n += $up->rowCount(); }
            }
            if ($n > 0) {
                q("UPDATE quizzes SET mostrar_resultado=1 WHERE titulo LIKE '%Word 2019%'");
                $log[] = "Se cargaron {$n} justificaciones del examen Word 2019 y se activó la revisión para el alumno.";
            }
        }
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
    . '<p class="desc">Agrega materias / módulos, el inicio del examen por alumno y las justificaciones de las respuestas. Es seguro ejecutarlo más de una vez.</p>';
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
