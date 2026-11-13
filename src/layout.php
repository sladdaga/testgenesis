<?php
function page_head(string $titulo, bool $admin = false): void
{
    $base = $admin ? '../' : '';
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex">'
        . '<title>' . e($titulo) . '</title>'
        . '<link rel="stylesheet" href="' . $base . 'assets/style.css"></head><body>';
    if ($admin) {
        echo '<header class="top"><a class="brand" href="index.php">Cuestionarios</a>';
        if (admin_logged()) {
            echo '<nav><a href="index.php">Mis cuestionarios</a><a href="materias.php">Materias</a><a href="quiz_edit.php">Nuevo</a>'
                . '<a href="migrar.php" style="opacity:.55;font-size:.8rem">Actualizar base</a>'
                . '<form method="post" action="logout.php" class="inline">' . csrf_field()
                . '<button class="link">Salir</button></form></nav>';
        }
        echo '</header>';
    }
    echo '<main class="wrap">';
    if ($f = flash()) echo '<div class="msg ' . e($f[1]) . '">' . e($f[0]) . '</div>';
}

function page_foot(bool $admin = false): void
{
    $base = $admin ? '../' : '';
    echo '</main><script src="' . $base . 'assets/app.js"></script></body></html>';
}
