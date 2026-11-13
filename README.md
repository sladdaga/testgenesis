# Cuestionarios

Web simple en PHP 8.1+ y MySQL, sin frameworks. El docente crea cuestionarios de opción múltiple, comparte el enlace `/?q=<token>` y los alumnos responden sin registrarse (nombre, apellido, email y curso obligatorios).

## Local (Laragon)
1. Crear base `cuestionarios` (utf8mb4) e importar `sql/schema.sql`.
2. Copiar `config.sample.php` a `config.php` y completar datos de DB y `salt`.
3. Crear el admin: `php scripts/crear_admin.php docente@correo.com MiClaveSegura`
4. Apuntar el virtual host (o abrir) a `public/`. Admin: `/admin/`.

## Hostinger
1. Crear base MySQL y usuario; importar `sql/schema.sql` desde phpMyAdmin.
2. Subir el proyecto de modo que el docroot sea `public/` (idealmente `src/`, `sql/`, `config.php` fuera del docroot). Si el docroot es la raíz, el `.htaccess` raíz bloquea esas carpetas y redirige a `public/`.
3. Crear `config.php` en el servidor (host `127.0.0.1`) con `base_url` si hace falta.
4. Crear el admin por SSH con `scripts/crear_admin.php`, o insertar el hash a mano en `admins`.

`config.php` está en `.gitignore`; nunca subir credenciales.
