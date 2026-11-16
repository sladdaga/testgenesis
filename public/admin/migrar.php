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

    // 9) Columna quizzes.duracion_min (minutos por examen; vacío = duración de la configuración)
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['quizzes', 'duracion_min'])) {
        $log[] = 'La columna «quizzes.duracion_min» ya existía.';
    } else {
        $pdo->exec('ALTER TABLE quizzes ADD COLUMN duracion_min SMALLINT UNSIGNED NULL AFTER mostrar_resultado');
        $log[] = 'Se agregó la columna «quizzes.duracion_min».';
    }

    // 9b) Imagen opcional por pregunta (guardada en la base: sobrevive a los deploys)
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['questions', 'imagen_mime'])) {
        $log[] = 'Las columnas de imagen de «questions» ya existían.';
    } else {
        $pdo->exec('ALTER TABLE questions ADD COLUMN imagen MEDIUMBLOB NULL, ADD COLUMN imagen_mime VARCHAR(30) NULL');
        $log[] = 'Se agregaron las columnas de imagen en «questions».';
    }

    // 11) Tipos de pregunta (tanda 1: desplegable, multiple, cuadricula) y tabla answer_items
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['questions', 'tipo'])) {
        $log[] = 'Las columnas de tipo de «questions» ya existían.';
    } else {
        $pdo->exec("ALTER TABLE questions ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'unica' AFTER texto, ADD COLUMN config TEXT NULL");
        $log[] = 'Se agregaron «questions.tipo» y «questions.config».';
    }
    if ($existe('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', ['options', 'rol'])) {
        $log[] = 'Las columnas de rol de «options» ya existían.';
    } else {
        $pdo->exec("ALTER TABLE options ADD COLUMN rol VARCHAR(10) NOT NULL DEFAULT 'op', ADD COLUMN correcta_id INT UNSIGNED NULL");
        $log[] = 'Se agregaron «options.rol» y «options.correcta_id».';
    }
    if ($existe('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['answer_items'])) {
        $log[] = 'La tabla «answer_items» ya existía.';
    } else {
        $pdo->exec('CREATE TABLE answer_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submission_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            row_id INT UNSIGNED NULL,
            option_id INT UNSIGNED NULL,
            texto TEXT NULL,
            INDEX (submission_id, question_id),
            FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
            FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
            FOREIGN KEY (option_id) REFERENCES options(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $log[] = 'Se creó la tabla «answer_items».';
    }

    // 10) Exámenes predefinidos en scripts/examenes/*.php: se crean solo si no existe uno con el mismo título
    foreach (glob(__DIR__ . '/../../scripts/examenes/*.php') ?: [] as $f) {
        $ex = require $f;
        if (!is_array($ex) || empty($ex['titulo']) || empty($ex['preguntas'])) continue;
        if ($existe('SELECT COUNT(*) FROM quizzes WHERE titulo=?', [$ex['titulo']])) {
            $log[] = 'El examen «' . $ex['titulo'] . '» ya existía.';
            continue;
        }
        $pdo->beginTransaction();
        try {
            $mid = q('SELECT id FROM materias WHERE nombre=?', [$ex['materia']])->fetchColumn();
            if (!$mid) {
                q('INSERT INTO materias (nombre) VALUES (?)', [$ex['materia']]);
                $mid = (int)$pdo->lastInsertId();
                $log[] = 'Se creó la materia «' . $ex['materia'] . '».';
            }
            q('INSERT INTO quizzes (materia_id, titulo, descripcion, token, activo, mostrar_resultado, duracion_min) VALUES (?,?,?,?,1,1,?)',
                [(int)$mid, $ex['titulo'], $ex['descripcion'] ?? null, bin2hex(random_bytes(8)), $ex['duracion_min'] ?? null]);
            $qid = (int)$pdo->lastInsertId();
            $iq = $pdo->prepare('INSERT INTO questions (quiz_id, texto, justificacion, orden) VALUES (?,?,?,?)');
            $io = $pdo->prepare('INSERT INTO options (question_id, texto, es_correcta, orden) VALUES (?,?,?,?)');
            foreach ($ex['preguntas'] as $i => $p) {
                $iq->execute([$qid, $p[0], $p[3] ?? null, $i + 1]);
                $pid = (int)$pdo->lastInsertId();
                // Imagen opcional: quinto elemento = nombre de archivo en scripts/examenes/img/
                $arch = isset($p[4]) ? realpath(__DIR__ . '/../../scripts/examenes/img/' . basename((string)$p[4])) : false;
                $inf = $arch ? @getimagesize($arch) : false;
                if ($inf && in_array($inf['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                    q('UPDATE questions SET imagen=?, imagen_mime=? WHERE id=?', [file_get_contents($arch), $inf['mime'], $pid]);
                }
                foreach ($p[1] as $j => $texto) $io->execute([$pid, $texto, $j === $p[2] ? 1 : 0, $j + 1]);
            }
            $pdo->commit();
            $log[] = 'Se creó el examen «' . $ex['titulo'] . '» con ' . count($ex['preguntas']) . ' preguntas.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
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
