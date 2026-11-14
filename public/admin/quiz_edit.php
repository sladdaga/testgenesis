<?php
require __DIR__ . '/../../src/bootstrap.php';
admin_require();

function render_o($qk, $ok, array $o, bool $checked): string
{
    $n = "preguntas[$qk][opciones][$ok]";
    return '<div class="obox"><input type="radio" name="preguntas[' . e($qk) . '][correcta]" value="' . e($ok) . '" title="Correcta"' . ($checked ? ' checked' : '') . ' required>'
        . '<input type="hidden" name="' . e($n) . '[id]" value="' . (int)($o['id'] ?? 0) . '">'
        . '<input type="text" name="' . e($n) . '[texto]" maxlength="500" placeholder="Opción" value="' . e($o['texto'] ?? '') . '">'
        . '<button type="button" class="btn sm sec del-o" title="Quitar opción">&times;</button></div>';
}

function render_q($qk, array $p, int $num, string $token = ''): string
{
    $maxk = 99;
    foreach ($p['opciones'] as $k => $_) if (is_int($k) && $k > $maxk) $maxk = $k;
    $h = '<div class="card qbox" data-q="' . e($qk) . '" data-nexto="' . ($maxk + 1) . '">'
        . '<input type="hidden" name="preguntas[' . e($qk) . '][id]" value="' . (int)($p['id'] ?? 0) . '">'
        . '<label>Pregunta <span class="qnum">' . $num . '</span>'
        . '<textarea name="preguntas[' . e($qk) . '][texto]" maxlength="2000">' . e($p['texto'] ?? '') . '</textarea></label>'
        . '<label>Justificación de la respuesta correcta <small>(opcional, se muestra al alumno al corregir)</small>'
        . '<textarea name="preguntas[' . e($qk) . '][justificacion]" maxlength="2000" rows="2">' . e($p['justificacion'] ?? '') . '</textarea></label>'
        . '<div class="imgbox">' . (!empty($p['tiene_img']) && !empty($p['id'])
            ? '<img class="qimg" src="../img.php?t=' . e($token) . '&amp;p=' . (int)$p['id'] . '" alt="Imagen actual"><label class="chk"><input type="checkbox" name="preguntas[' . e($qk) . '][quitar_img]" value="1"> Quitar la imagen actual</label>'
            : '')
        . '<label>Imagen (opcional) <small>JPG, PNG, WebP o GIF, hasta 2 MB. Si subís una, reemplaza a la actual.</small>'
        . '<input type="file" name="imagen[' . e($qk) . ']" accept="image/jpeg,image/png,image/webp,image/gif"></label></div>'
        . '<p class="desc">Marcá con el círculo la opción correcta (2 a 6 opciones).</p><div class="olist">';
    foreach ($p['opciones'] as $ok => $o) $h .= render_o($qk, $ok, $o, (string)$ok === (string)($p['correcta'] ?? ''));
    return $h . '</div><div class="acciones"><button type="button" class="btn sm sec add-o">+ Opción</button>'
        . '<button type="button" class="btn sm danger del-q">Quitar pregunta</button></div></div>';
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$quiz = null;
if ($id) {
    $quiz = q('SELECT * FROM quizzes WHERE id=?', [$id])->fetch();
    if (!$quiz) { flash('Cuestionario no encontrado.', 'error'); redirect('index.php'); }
}
$nEnvios = $id ? (int)q('SELECT COUNT(*) FROM submissions WHERE quiz_id=?', [$id])->fetchColumn() : 0;

// Datos actuales (BD) como valor por defecto
$form = ['titulo' => $quiz['titulo'] ?? '', 'descripcion' => $quiz['descripcion'] ?? '',
         'duracion_min' => isset($quiz['duracion_min']) && $quiz['duracion_min'] !== null ? (string)(int)$quiz['duracion_min'] : '', 'mostrar_resultado' => (int)($quiz['mostrar_resultado'] ?? 0), 'activo' => (int)($quiz['activo'] ?? 1), 'preguntas' => []];
$form['materia_id'] = (int)($quiz['materia_id'] ?? ($_GET['materia'] ?? 0));
$form['materia_nueva'] = '';
$materias = q('SELECT id, nombre FROM materias ORDER BY nombre')->fetchAll();
$dbQ = []; // id pregunta => [opciones ids]
$dbImg = []; // id pregunta => tiene imagen
if ($id) {
    $opsByQ = [];
    foreach (q('SELECT o.* FROM options o JOIN questions qu ON qu.id=o.question_id WHERE qu.quiz_id=? ORDER BY o.orden, o.id', [$id]) as $o) $opsByQ[$o['question_id']][] = $o;
    foreach (q('SELECT id, texto, justificacion, (imagen_mime IS NOT NULL) tiene_img FROM questions WHERE quiz_id=? ORDER BY orden, id', [$id]) as $i => $p) {
        $dbImg[(int)$p['id']] = (bool)$p['tiene_img'];
        $item = ['tiene_img' => (bool)$p['tiene_img'], 'id' => (int)$p['id'], 'texto' => $p['texto'], 'justificacion' => (string)($p['justificacion'] ?? ''), 'opciones' => [], 'correcta' => null];
        foreach ($opsByQ[$p['id']] ?? [] as $k => $o) {
            $item['opciones'][$k] = ['id' => (int)$o['id'], 'texto' => $o['texto']];
            if ($o['es_correcta']) $item['correcta'] = $k;
            $dbQ[(int)$p['id']][] = (int)$o['id'];
        }
        $dbQ[(int)$p['id']] = $dbQ[(int)$p['id']] ?? [];
        $form['preguntas'][$i] = $item;
    }
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['titulo'] = trim((string)($_POST['titulo'] ?? ''));
    $form['descripcion'] = trim((string)($_POST['descripcion'] ?? ''));
    $form['mostrar_resultado'] = empty($_POST['mostrar_resultado']) ? 0 : 1;
    $form['duracion_min'] = trim((string)($_POST['duracion_min'] ?? ''));
    $form['activo'] = empty($_POST['activo']) ? 0 : 1;
    $form['materia_id'] = (int)($_POST['materia_id'] ?? 0);
    $form['materia_nueva'] = trim((string)($_POST['materia_nueva'] ?? ''));
    $form['preguntas'] = [];
    $raw = is_array($_POST['preguntas'] ?? null) ? $_POST['preguntas'] : [];
    foreach ($raw as $qk => $p) {
        if (!is_array($p)) continue;
        $pid = (int)($p['id'] ?? 0);
        if ($pid && !isset($dbQ[$pid])) $pid = 0;
        $item = ['tiene_img' => $pid && !empty($dbImg[$pid]), 'quitar_img' => !empty($p['quitar_img']), 'id' => $pid, 'texto' => trim((string)($p['texto'] ?? '')), 'justificacion' => mb_substr(trim((string)($p['justificacion'] ?? '')), 0, 2000), 'opciones' => [], 'correcta' => $p['correcta'] ?? null];
        foreach ((is_array($p['opciones'] ?? null) ? $p['opciones'] : []) as $ok => $o) {
            if (!is_array($o)) continue;
            $oid = (int)($o['id'] ?? 0);
            if ($oid && !in_array($oid, $dbQ[$pid] ?? [], true)) $oid = 0;
            $item['opciones'][$ok] = ['id' => $oid, 'texto' => trim((string)($o['texto'] ?? ''))];
        }
        $form['preguntas'][$qk] = $item;
    }

    if ($form['titulo'] === '' || mb_strlen($form['titulo']) > 200) $errores[] = 'El título es obligatorio (máx. 200 caracteres).';
    if ($form['duracion_min'] !== '' && (!ctype_digit($form['duracion_min']) || (int)$form['duracion_min'] < 1 || (int)$form['duracion_min'] > 600)) $errores[] = 'La duración debe ser un número de minutos entre 1 y 600, o quedar vacía.';
    if ($form['materia_nueva'] !== '') {
        if (mb_strlen($form['materia_nueva']) > 150) $errores[] = 'El nombre de la materia no puede superar 150 caracteres.';
    } elseif (!q('SELECT id FROM materias WHERE id=?', [$form['materia_id']])->fetch()) {
        $errores[] = 'Elegí una materia / módulo o escribí el nombre de una nueva.';
    }
    if (!$form['preguntas']) $errores[] = 'Agregá al menos una pregunta.';
    $keepQ = [];
    $keepO = [];
    $n = 0;
    $imgs = []; // qk => [datos binarios, mime]
    $permitidas = ['image/jpeg' => 1, 'image/png' => 1, 'image/webp' => 1, 'image/gif' => 1];
    foreach ($form['preguntas'] as $qk => &$p) {
        $n++;
        $f = $_FILES['imagen'] ?? null;
        $err = is_array($f) ? ($f['error'][$qk] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $errores[] = "La imagen de la pregunta $n es demasiado grande (máx. 2 MB).";
        } elseif ($err !== UPLOAD_ERR_NO_FILE) {
            $tmp = $f['tmp_name'][$qk] ?? '';
            $bin = ($err === UPLOAD_ERR_OK && is_uploaded_file($tmp)) ? file_get_contents($tmp) : false;
            $info = $bin !== false ? @getimagesizefromstring($bin) : false;
            if ($bin === false) $errores[] = "No se pudo subir la imagen de la pregunta $n.";
            elseif (strlen($bin) > 2 * 1024 * 1024) $errores[] = "La imagen de la pregunta $n supera los 2 MB.";
            elseif (!$info || !isset($permitidas[$info['mime']])) $errores[] = "El archivo de la pregunta $n no es una imagen JPG, PNG, WebP o GIF.";
            else $imgs[$qk] = [$bin, $info['mime']];
        }
        // Quitar opciones en blanco (las que tengan respuestas se detectan abajo por ausencia)
        $p['opciones'] = array_filter($p['opciones'], fn($o) => $o['texto'] !== '');
        if ($p['texto'] === '') $errores[] = "La pregunta $n no tiene texto.";
        $c = count($p['opciones']);
        if ($c < 2 || $c > 6) $errores[] = "La pregunta $n debe tener entre 2 y 6 opciones con texto.";
        if ($p['correcta'] === null || !array_key_exists($p['correcta'], $p['opciones'])) $errores[] = "Marcá la opción correcta de la pregunta $n.";
        if ($p['id']) $keepQ[] = $p['id'];
        foreach ($p['opciones'] as $o) if ($o['id']) $keepO[] = $o['id'];
    }
    unset($p);

    // No borrar preguntas/opciones con respuestas
    if ($id) {
        foreach ($dbQ as $pid => $oids) {
            if (!in_array($pid, $keepQ, true)) {
                if (q('SELECT COUNT(*) FROM answers WHERE question_id=?', [$pid])->fetchColumn() > 0) $errores[] = 'No se puede quitar una pregunta que ya tiene respuestas.';
                continue;
            }
            foreach ($oids as $oid) if (!in_array($oid, $keepO, true)
                && q('SELECT COUNT(*) FROM answers WHERE option_id=?', [$oid])->fetchColumn() > 0) {
                $errores[] = 'No se puede quitar una opción que ya tiene respuestas.';
            }
        }
    }

    if (!$errores) {
        $errores = array_unique($errores);
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $mid = $form['materia_id'];
            if ($form['materia_nueva'] !== '') {
                $ex = q('SELECT id FROM materias WHERE nombre=?', [$form['materia_nueva']])->fetchColumn();
                if ($ex) $mid = (int)$ex;
                else {
                    q('INSERT INTO materias (nombre) VALUES (?)', [$form['materia_nueva']]);
                    $mid = (int)$pdo->lastInsertId();
                }
            }
            if ($id) {
                q('UPDATE quizzes SET materia_id=?, titulo=?, descripcion=?, mostrar_resultado=?, duracion_min=?, activo=? WHERE id=?',
                    [$mid, $form['titulo'], $form['descripcion'] ?: null, $form['mostrar_resultado'], $form['duracion_min'] === '' ? null : (int)$form['duracion_min'], $form['activo'], $id]);
                foreach ($dbQ as $pid => $oids) {
                    if (!in_array($pid, $keepQ, true)) { q('DELETE FROM questions WHERE id=?', [$pid]); continue; }
                    foreach ($oids as $oid) if (!in_array($oid, $keepO, true)) q('DELETE FROM options WHERE id=?', [$oid]);
                }
            } else {
                q('INSERT INTO quizzes (materia_id, titulo, descripcion, token, activo, mostrar_resultado, duracion_min) VALUES (?,?,?,?,?,?,?)',
                    [$mid, $form['titulo'], $form['descripcion'] ?: null, bin2hex(random_bytes(8)), $form['activo'], $form['mostrar_resultado'], $form['duracion_min'] === '' ? null : (int)$form['duracion_min']]);
                $id = (int)$pdo->lastInsertId();
            }
            $qo = 0;
            foreach ($form['preguntas'] as $qk => $p) {
                $qo++;
                if ($p['id']) {
                    q('UPDATE questions SET texto=?, justificacion=?, orden=? WHERE id=? AND quiz_id=?', [$p['texto'], $p['justificacion'] ?: null, $qo, $p['id'], $id]);
                    $pid = $p['id'];
                } else {
                    q('INSERT INTO questions (quiz_id, texto, justificacion, orden) VALUES (?,?,?,?)', [$id, $p['texto'], $p['justificacion'] ?: null, $qo]);
                    $pid = (int)$pdo->lastInsertId();
                }
                if (isset($imgs[$qk])) q('UPDATE questions SET imagen=?, imagen_mime=? WHERE id=? AND quiz_id=?', [$imgs[$qk][0], $imgs[$qk][1], $pid, $id]);
                elseif (!empty($p['quitar_img'])) q('UPDATE questions SET imagen=NULL, imagen_mime=NULL WHERE id=? AND quiz_id=?', [$pid, $id]);
                $oo = 0;
                foreach ($p['opciones'] as $ok => $o) {
                    $oo++;
                    $corr = ((string)$ok === (string)$p['correcta']) ? 1 : 0;
                    if ($o['id']) q('UPDATE options SET texto=?, es_correcta=?, orden=? WHERE id=? AND question_id=?', [$o['texto'], $corr, $oo, $o['id'], $pid]);
                    else q('INSERT INTO options (question_id, texto, es_correcta, orden) VALUES (?,?,?,?)', [$pid, $o['texto'], $corr, $oo]);
                }
            }
            $pdo->commit();
            flash('Cuestionario guardado.');
            redirect('index.php');
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errores[] = 'No se pudo guardar (error de base de datos).';
        }
    }
}

if (!$form['preguntas']) {
    $form['preguntas'][0] = ['id' => 0, 'texto' => '', 'correcta' => null, 'opciones' => [0 => ['texto' => ''], 1 => ['texto' => '']]];
}
$maxq = 99;
foreach ($form['preguntas'] as $k => $_) if (is_int($k) && $k > $maxq) $maxq = $k;

page_head($id ? 'Editar cuestionario' : 'Nuevo cuestionario', true);
echo '<h1>' . ($id ? 'Editar cuestionario' : 'Nuevo cuestionario') . '</h1>';
if ($nEnvios) echo '<div class="aviso">Este cuestionario ya tiene <strong>' . $nEnvios . '</strong> envíos. Podés corregir textos y agregar preguntas u opciones, pero no quitar preguntas u opciones que tengan respuestas. Cambiar la opción correcta no recalcula las notas ya guardadas.</div>';
if ($errores) echo '<div class="msg error">' . implode('<br>', array_map('e', array_unique($errores))) . '</div>';
?>
<form method="post" enctype="multipart/form-data" action="quiz_edit.php<?= $id ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <label>Título *<input type="text" name="titulo" maxlength="200" required value="<?= e($form['titulo']) ?>"></label>
    <?php if ($materias): ?>
    <label>Materia / módulo *<select name="materia_id">
      <option value="">— Elegir —</option>
      <?php foreach ($materias as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === $form['materia_id'] ? ' selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
    </select></label>
    <label>…o crear una nueva (opcional, tiene prioridad)<input type="text" name="materia_nueva" maxlength="150" value="<?= e($form['materia_nueva']) ?>"></label>
    <?php else: ?>
    <input type="hidden" name="materia_id" value="0">
    <label>Materia / módulo * (todavía no hay ninguna: escribí el nombre para crearla)<input type="text" name="materia_nueva" maxlength="150" required value="<?= e($form['materia_nueva']) ?>"></label>
    <?php endif; ?>
    <label>Descripción (opcional)<textarea name="descripcion" maxlength="2000"><?= e($form['descripcion']) ?></textarea></label>
    <label>Duración en minutos <small>(vacío = usar la duración general de la configuración)</small><input type="number" name="duracion_min" min="1" max="600" step="1" value="<?= e($form['duracion_min']) ?>"></label>
    <label><input type="checkbox" name="mostrar_resultado" value="1" <?= $form['mostrar_resultado'] ? 'checked' : '' ?>> Mostrar nota y respuestas correctas al alumno al terminar</label>
    <label><input type="checkbox" name="activo" value="1" <?= $form['activo'] ? 'checked' : '' ?>> Abierto (recibe respuestas)</label>
  </div>
  <div id="preguntas" data-next="<?= $maxq + 1 ?>">
    <?php $i = 0; foreach ($form['preguntas'] as $qk => $p) echo render_q($qk, $p, ++$i, (string)($quiz['token'] ?? '')); ?>
  </div>
  <p class="acciones"><button type="button" id="add-q" class="btn sec">+ Agregar pregunta</button>
  <button type="submit" class="btn" data-once>Guardar</button>
  <a class="btn sec" href="index.php">Cancelar</a></p>
</form>
<template id="tpl-q"><?= render_q('__Q__', ['id' => 0, 'texto' => '', 'correcta' => null, 'opciones' => [0 => ['texto' => ''], 1 => ['texto' => '']]], 0) ?></template>
<template id="tpl-o"><?= render_o('__Q__', '__O__', ['texto' => ''], false) ?></template>
<?php page_foot(true);
