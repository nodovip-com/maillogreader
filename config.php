<?php
// Configura la ruta del archivo de logs.
// En producción, esto debería apuntar a /var/log/mail.log o similar.
// Se recomienda usar el archivo dummy_mail.log para pruebas locales.
define('LOG_FILE_PATH', __DIR__ . '/messages');

// Configuración de usuarios para acceso al sistema.
// Formato: 'usuario' => 'contraseña'
$users = [
    'admin' => 'secret123',
    'support' => 'mailuser',
    'rafa' => 'rafa123'
];

// Configuración de la aplicación
define('APP_NAME', 'Mail Log Reader Pro');
define('VERSION', '1.0.0');
