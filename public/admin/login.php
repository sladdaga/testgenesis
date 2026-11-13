<?php
require __DIR__ . '/../../src/bootstrap.php';
if (admin_logged()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (login_bloqueado()) {
        $error = 'Demasiados intentos. Esperá 15 minutos.';
    } elseif (admin_login(trim((string)($_POST['email'] ?? '')), (string)($_POST['clave'] ?? ''))) {
        redirect('index.php');
    } else {
        $error = 'Email o clave incorrectos.';
    }
}
page_head('Ingresar', true);
?>
<div class="card login">
  <h1>Ingresar</h1>
  <?php if ($error) echo '<div class="msg error">' . e($error) . '</div>'; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required autocomplete="username"></label>
    <label>Clave<input type="password" name="clave" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Entrar</button>
  </form>
</div>
<?php page_foot(true);
