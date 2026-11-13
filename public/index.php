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
    echo '<div class="card center res">';
    echo '<h1>¡Gracias!</h1><p class="desc">Tu respuesta fue enviada correctamente.</p>';
    if ($sid) {
        $s = q('SELECT puntaje, total FROM submissions WHERE id=? AND quiz_id=?', [$sid, $qid])->fetch();
    } else {
        $s = false;
    }
    if ($s) {
        $pj = (int)$s['puntaje'];
        $tt = (int)$s['total'];
        $pct = $tt > 0 ? (int)round($pj / $tt * 100) : 0;
        $rango = $pct >= 70 ? 'alta' : ($pct >= 40 ? 'media' : 'baja');
        echo '<div class="nota-box ' . $rango . '">'
            . '<div class="aro"><svg viewBox="0 0 36 36" aria-hidden="true" focusable="false">'
            . '<circle class="aro-bg" cx="18" cy="18" r="15.9155" pathLength="100"/>'
            . '<circle class="aro-fg" cx="18" cy="18" r="15.9155" pathLength="100" stroke-dasharray="' . $pct . ' 100"/></svg>'
            . '<span class="aro-num">' . e(nota_fmt($s['puntaje'], $s['total'])) . '<small>/ 10</small></span></div>'
            . '<p class="nota-tit">Tu nota</p>'
            . '<div class="resumen"><span class="ok-n"><b>' . $pj . '</b> correctas</span>'
            . '<span class="ko-n"><b>' . max(0, $tt - $pj) . '</b> incorrectas</span></div>'
            . '<p class="nota-det">' . $pj . ' de ' . $tt . ' respuestas correctas</p></div>';
    }
    echo '</div>';
    if ($s && $quiz['mostrar_resultado']) {
        $rows = q('SELECT qu.id qid, qu.texto, a.option_id,
                    (SELECT GROUP_CONCAT(o2.texto SEPARATOR " / ") FROM options o2 WHERE o2.question_id=qu.id AND o2.es_correcta=1) correcta,
                    o.es_correcta, o.texto elegida
                   FROM questions qu
                   LEFT JOIN answers a ON a.question_id=qu.id AND a.submission_id=?
                   LEFT JOIN options o ON o.id=a.option_id
                   WHERE qu.quiz_id=? ORDER BY qu.orden, qu.id', [$sid, $qid])->fetchAll();
        echo '<h2 class="rev-tit">Revisión</h2>';
        foreach ($rows as $i => $r) {
            $ok = (bool)$r['es_correcta'];
            echo '<div class="card rev ' . ($ok ? 'rev-ok' : 'rev-ko') . '">'
                . '<p class="preg"><span class="num">' . ($i + 1) . '</span><span>' . nl2br(e($r['texto'])) . '</span></p>'
                . '<p class="' . ($ok ? 'bien' : 'mal') . '"><span class="lbl">Tu respuesta</span> ' . e($r['elegida']) . ($ok ? ' ✔' : ' ✘') . '</p>';
            if (!$ok) echo '<p class="bien corr"><span class="lbl">Correcta</span> ' . e($r['correcta']) . '</p>';
            echo '</div>';
        }
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

// ---- Temporizador (servidor): el reloj arranca al abrir el enlace y sobrevive a recargas ----
$limite = max(0, (int)(config()['duracion_minutos'] ?? 90)) * 60;
$gracia = 60; // segundos de margen por demoras de conexión
if (!isset($_SESSION['inicio'][$qid])) $_SESSION['inicio'][$qid] = time();
$transcurrido = time() - (int)$_SESSION['inicio'][$qid];
if ($limite && $transcurrido > $limite + $gracia && !isset($_SESSION['enviado'][$qid])) {
    aviso('Tiempo agotado', 'El tiempo para resolver este examen (' . intdiv($limite, 60) . ' minutos) terminó y la respuesta no se pudo enviar. Consultá con tu docente.', 403);
}
$restante = $limite ? max(0, $limite - $transcurrido) : 0;
$tiempoAgotado = $limite && $transcurrido >= $limite - 15; // envío al vencer: se acepta con preguntas sin responder

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
                if ($tiempoAgotado) continue; // sin responder al vencer el tiempo: cuenta como incorrecta
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
                unset($_SESSION['nonce'][$qid], $_SESSION['inicio'][$qid]);
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
<?php if ($limite): ?>
<div class="reloj" id="reloj" data-restante="<?= (int)$restante ?>" role="timer" aria-live="off">
  <span class="reloj-t">Tiempo restante</span> <strong id="reloj-v"><?= sprintf('%02d:%02d', intdiv($restante, 60), $restante % 60) ?></strong>
  <span class="reloj-n">Tenés <?= intdiv($limite, 60) ?> min. Al llegar a cero se envía lo respondido.</span>
</div>
<?php endif; ?>
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
