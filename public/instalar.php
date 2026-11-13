<?php
declare(strict_types=1);
// Instalador de una sola vez: crea config.php, importa el esquema, crea el admin y (opcional) carga el examen Word 2019.
// Se bloquea solo cuando existe config.php. Borrar este archivo después de instalar.
header('X-Frame-Options: DENY');
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

if (is_file($cfgFile)) {
    http_response_code(403);
    exit('Ya instalado. Borrá public/instalar.php del servidor.');
}

$err = '';
$ok = '';
$v = ['host' => '127.0.0.1', 'name' => '', 'user' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['host', 'name', 'user', 'email'] as $k) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $clave = (string)($_POST['clave'] ?? '');
    try {
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email de administrador inválido.');
        if (strlen($clave) < 8) throw new RuntimeException('La clave de administrador debe tener al menos 8 caracteres.');
        $pdo = new PDO(sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', $v['host'], $v['name']), $v['user'], $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        foreach (array_filter(array_map('trim', explode(';', (string)file_get_contents($root . '/sql/schema.sql')))) as $sql) {
            $pdo->exec($sql);
        }
        $pdo->prepare('INSERT INTO admins (email, password_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')
            ->execute([$v['email'], password_hash($clave, PASSWORD_DEFAULT)]);

        $token = '';
        if (!empty($_POST['cargar_word'])) {
            $datos = require $root . '/scripts/datos_word2019.php';
            $pdo->beginTransaction();
            $token = bin2hex(random_bytes(8));
            $pdo->prepare('INSERT INTO quizzes (titulo, descripcion, token, activo, mostrar_resultado) VALUES (?,?,?,1,0)')
                ->execute(['Examen Cuestionario Integral: Curso Microsoft Word 2019', 'Examen de opción múltiple (' . count($datos) . ' preguntas).', $token]);
            $qid = (int)$pdo->lastInsertId();
            $iq = $pdo->prepare('INSERT INTO questions (quiz_id, texto, orden) VALUES (?,?,?)');
            $io = $pdo->prepare('INSERT INTO options (question_id, texto, es_correcta, orden) VALUES (?,?,?,?)');
            foreach ($datos as $i => [$texto, $ops, $okIdx]) {
                $iq->execute([$qid, $texto, $i + 1]);
                $pid = (int)$pdo->lastInsertId();
                foreach ($ops as $j => $o) $io->execute([$pid, $o, $j === $okIdx ? 1 : 0, $j + 1]);
            }
            $pdo->commit();
        }

        $cfg = "<?php\nreturn " . var_export([
            'db' => ['host' => $v['host'], 'port' => 3306, 'name' => $v['name'], 'user' => $v['user'], 'pass' => $pass],
            'salt' => bin2hex(random_bytes(24)),
            'base_url' => '',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'max_envios_por_ip' => 10,
            'max_login_intentos' => 5,
        ], true) . ";\n";
        if (file_put_contents($cfgFile, $cfg, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir config.php.');
        @chmod($cfgFile, 0640);
        $ok = 'Instalación lista. Entrá a /admin/login.php con tu email y clave. Después borrá public/instalar.php.';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $err = 'Error: ' . $e->getMessage();
    }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalar</title><link rel="stylesheet" href="assets/style.css"></head>
<body><main class="card" style="max-width:480px;margin:2rem auto">
<h1>Instalación</h1>
<?php if ($ok): ?><p class="bien"><?= $h($ok) ?></p>
<?php else: ?>
<?php if ($err): ?><p class="mal"><?= $h($err) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
  <label>Servidor de base de datos<input name="host" value="<?= $h($v['host']) ?>" required></label>
  <label>Nombre de la base<input name="name" value="<?= $h($v['name']) ?>" required></label>
  <label>Usuario de la base<input name="user" value="<?= $h($v['user']) ?>" required></label>
  <label>Clave de la base<input name="pass" type="password" required></label>
  <hr>
  <label>Email del administrador<input name="email" type="email" value="<?= $h($v['email']) ?>" required></label>
  <label>Clave del administrador (mín. 8)<input name="clave" type="password" minlength="8" required></label>
  <label><input type="checkbox" name="cargar_word" value="1" checked> Cargar el examen de Word 2019 (31 preguntas)</label>
  <button type="submit">Instalar</button>
</form>
<?php endif; ?>
</main></body></html>
