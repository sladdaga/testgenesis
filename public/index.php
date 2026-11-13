<?php
require __DIR__ . '/../src/bootstrap.php';

function aviso(string $titulo, string $texto, int $code = 200): never
{
    http_response_code($code);
    page_head($titulo);
    echo '<div class="card center"><h1>' . e($titulo) . '</h1><p>' . e($texto) . '</p></div>';
    page_foot();
    exit;
}

$token = $_GET['q'] ?? '';
if (!is_string($token) || !preg_match('/^[A-Za-z0-9]{8,32}$/', $token)) {
    aviso('Cuestionario no encontrado', 'El enlace no es válido. Pedile el enlace correcto a tu docente.', 404);
}
$quiz = q('SELECT * FROM quizzes WHERE token=?', [$token])->fetch();
if (!$quiz) aviso('Cuestionario no encontrado', 'El enlace no es válido o el cuestionario fue eliminado.', 404);

$qid = (int)$quiz['id'];
$selfUrl = '?q=' . $token;

// ---- Pantalla de gracias (tras redirección) ----
if (isset($_GET['gracias'])) {
    $sid = $_SESSION['enviado'][$qid] ?? null;
    page_head('Gracias');
    echo '<div class="card center"><h1>¡Gracias!</h1><p>Tu respuesta fue enviada correctamente.</p>';
    if ($sid) {
        $s = q('SELECT puntaje, total FROM submissions WHERE id=? AND quiz_id=?', [$sid, $qid])->fetch();
        if ($s) {
            echo '<p class="nota">Tu nota: <strong>' . e(nota_fmt($s['puntaje'], $s['total'])) . ' / 10</strong><br><small>' . (int)$s['puntaje'] . ' de ' . (int)$s['total'] . ' respuestas correctas</small></p></div>';
            if (!$quiz['mostrar_resultado']) { page_foot(); exit; }
            $rows = q('SELECT qu.id qid, qu.texto, a.option_id,
                        (SELECT GROUP_CONCAT(o2.texto SEPARATOR " / ") FROM options o2 WHERE o2.question_id=qu.id AND o2.es_correcta=1) correcta,
                        o.es_correcta, o.texto elegida
                       FROM questions qu
                       LEFT JOIN answers a ON a.question_id=qu.id AND a.submission_id=?
                       LEFT JOIN options o ON o.id=a.option_id
                       WHERE qu.quiz_id=? ORDER BY qu.orden, qu.id', [$sid, $qid])->fetchAll();
            foreach ($rows as $i => $r) {
                $ok = (bool)$r['es_correcta'];
                echo '<div class="card"><p class="preg">' . ($i + 1) . '. ' . nl2br(e($r['texto'])) . '</p>'
                    . '<p class="' . ($ok ? 'bien' : 'mal') . '">Tu respuesta: ' . e($r['elegida']) . ($ok ? ' ✔' : ' ✘') . '</p>';
                if (!$ok) echo '<p class="bien">Correcta: ' . e($r['correcta']) . '</p>';
                echo '</div>';
            }
        } else {
            echo '</div>';
        }
    } else {
        echo '</div>';
    }
    page_foot();
    exit;
}

if (!$quiz['activo']) aviso('Cuestionario cerrado', 'Este cuestionario ya no recibe respuestas.', 403);

$preguntas = q('SELECT id, texto FROM questions WHERE quiz_id=? ORDER BY orden, id', [$qid])->fetchAll();
$opts = [];
foreach (q('SELECT o.id, o.question_id, o.texto, o.es_correcta FROM options o JOIN questions qu ON qu.id=o.question_id WHERE qu.quiz_id=? ORDER BY o.orden, o.id', [$qid]) as $o) {
    $opts[$o['question_id']][$o['id']] = $o;
}
if (!$preguntas) aviso('Cuestionario sin preguntas', 'Este cuestionario todavía no está listo.', 404);

$errores = [];
$nombre = '';
$apellido = '';
$email = '';
$curso = '';
$resp = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $apellido = trim((string)($_POST['apellido'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $curso = trim((string)($_POST['curso'] ?? ''));
    $resp = is_array($_POST['r'] ?? null) ? $_POST['r'] : [];
    $nonce = $_POST['nonce'] ?? '';

    if (!isset($_SESSION['nonce'][$qid]) || !is_string($nonce) || !hash_equals($_SESSION['nonce'][$qid], $nonce)) {
        if (isset($_SESSION['enviado'][$qid])) redirect($selfUrl . '&gracias=1');
        $errores[] = 'La sesión del formulario venció. Revisá tus respuestas y volvé a enviar.';
        $_SESSION['nonce'][$qid] = bin2hex(random_bytes(16));
    } else {
        if ($nombre === '' || mb_strlen($nombre) > 100) $errores[] = 'Ingresá tu nombre (máx. 100 caracteres).';
        if ($apellido === '' || mb_strlen($apellido) > 100) $errores[] = 'Ingresá tu apellido (máx. 100 caracteres).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) $errores[] = 'Ingresá un email válido.';
        if ($curso === '' || mb_strlen($curso) > 120) $errores[] = 'Ingresá tu curso o comisión (máx. 120 caracteres).';
        $elegidas = [];
        foreach ($preguntas as $p) {
            $oid = $resp[$p['id']] ?? null;
            if (!is_string($oid) && !is_int($oid) || !isset($opts[$p['id']][(int)$oid])) {
                $errores[] = 'Respondé todas las preguntas.';
                break;
            }
            $elegidas[$p['id']] = (int)$oid;
        }
        if (!$errores) {
            $max = (int)(config()['max_envios_por_ip'] ?? 10);
            $n = q('SELECT COUNT(*) FROM submissions WHERE ip_hash=? AND created_at > (NOW() - INTERVAL 10 MINUTE)', [client_ip_hash()])->fetchColumn();
            if ($n >= $max) $errores[] = 'Demasiados envíos desde tu conexión. Esperá unos minutos.';
        }
        if (!$errores) {
            $puntaje = 0;
            foreach ($elegidas as $pid => $oid) $puntaje += (int)$opts[$pid][$oid]['es_correcta'];
            $pdo = db();
            try {
                $pdo->beginTransaction();
                q('INSERT INTO submissions (quiz_id, nombre, apellido, email, curso, puntaje, total, ip_hash) VALUES (?,?,?,?,?,?,?,?)',
                    [$qid, $nombre, $apellido, $email, $curso, $puntaje, count($preguntas), client_ip_hash()]);
                $sid = (int)$pdo->lastInsertId();
                $st = $pdo->prepare('INSERT INTO answers (submission_id, question_id, option_id) VALUES (?,?,?)');
                foreach ($elegidas as $pid => $oid) $st->execute([$sid, $pid, $oid]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errores[] = 'No se pudo guardar tu respuesta. Intentá de nuevo.';
            }
            if (!$errores) {
                unset($_SESSION['nonce'][$qid]);
                $_SESSION['enviado'][$qid] = $sid;
                redirect($selfUrl . '&gracias=1');
            }
        }
    }
}

if (empty($_SESSION['nonce'][$qid])) $_SESSION['nonce'][$qid] = bin2hex(random_bytes(16));

page_head($quiz['titulo']);
echo '<h1>' . e($quiz['titulo']) . '</h1>';
if ($quiz['descripcion']) echo '<p class="desc">' . nl2br(e($quiz['descripcion'])) . '</p>';
if ($errores) echo '<div class="msg error">' . implode('<br>', array_map('e', array_unique($errores))) . '</div>';
?>
<form method="post" action="<?= e($selfUrl) ?>" id="qform" class="quiz">
  <?= csrf_field() ?>
  <input type="hidden" name="nonce" value="<?= e($_SESSION['nonce'][$qid]) ?>">
  <div class="card">
    <label>Nombre *<input type="text" name="nombre" maxlength="100" required value="<?= e($nombre) ?>" autocomplete="given-name"></label>
    <label>Apellido *<input type="text" name="apellido" maxlength="100" required value="<?= e($apellido) ?>" autocomplete="family-name"></label>
    <label>Email *<input type="email" name="email" maxlength="190" required value="<?= e($email) ?>" autocomplete="email"></label>
    <label>Curso / comisión *<input type="text" name="curso" maxlength="120" required value="<?= e($curso) ?>"></label>
  </div>
  <?php foreach ($preguntas as $i => $p): ?>
    <fieldset class="card">
      <legend class="preg"><?= $i + 1 ?>. <?= nl2br(e($p['texto'])) ?></legend>
      <?php foreach ($opts[$p['id']] ?? [] as $o): ?>
        <label class="opt"><input type="radio" name="r[<?= (int)$p['id'] ?>]" value="<?= (int)$o['id'] ?>" required
          <?= ((string)($resp[$p['id']] ?? '') === (string)$o['id']) ? 'checked' : '' ?>> <span><?= e($o['texto']) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit" class="btn" data-once>Enviar respuestas</button>
</form>
<?php page_foot();
