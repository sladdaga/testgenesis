<?php
require __DIR__ . '/../../src/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    admin_logout();
}
redirect('login.php');
