<?php
// Configura la ruta del archivo de logs.
// En producción, esto debería apuntar a /var/log/mail.log o similar.
// Se recomienda usar el archivo dummy_mail.log para pruebas locales.
define('LOG_FILE_PATH', __DIR__ . '/messages');

// Ruta del archivo de usuarios (JSON)
define('USERS_FILE_PATH', __DIR__ . '/users.json');

// Configuración de la aplicación
define('APP_NAME', 'Mail Log Reader Pro');
define('VERSION', '1.0.0');
