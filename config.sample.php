<?php
// Copiar a config.php y completar. config.php NO se sube al repositorio.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'cuestionarios',
        'user' => 'root',
        'pass' => '',
    ],
    // Cadena aleatoria larga (para hashear IPs). Cambiar.
    'salt' => 'cambiar-por-una-cadena-aleatoria-larga',
    // URL publica base sin barra final (opcional, ej: https://midominio.com). Vacio = autodetectar.
    'base_url' => '',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'duracion_minutos' => 90,   // tiempo para resolver el examen (0 = sin límite)
    'max_envios_por_ip' => 10,     // por ventana de 10 minutos
    'max_login_intentos' => 5,     // por ventana de 15 minutos
];
