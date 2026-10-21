<?php
/**
 * Ejemplo de configuración. Copia este archivo como config.php y complétalo.
 * El archivo config.php real está ignorado por Git para evitar fugas de datos.
 *
 * Para Gmail NO escribas la contraseña aquí. Colócala en el archivo privado
 * config/smtp.secret.php (también ignorado por Git) o en variables de entorno:
 *   ALERTA_SMTP_USER, ALERTA_SMTP_PASSWORD
 *
 * Ver config/smtp.secret.example.php.
 */

return [
    'timezone' => 'America/Bogota',
    'max_por_hora' => 5,

    // Destinatarios de la notificación. Obligatorio para activar el correo.
    'destinatarios' => [
        // 'responsable@dominio.gov.co',
    ],

    // Remitente del mensaje.
    'remite' => [
        'nombre' => 'Sistema de Alerta',
        'email'  => '',
    ],

    // Servidor SMTP.
    //   - auth  = true  -> envío autenticado con PHPMailer (Gmail: 587/STARTTLS).
    //   - auth  = false -> se conserva el comportamiento con mail() de PHP.
    'smtp' => [
        'host'   => 'smtp.gmail.com',
        'puerto' => 587,
        'auth'   => true,
        'secure' => 'tls',   // 'tls' (STARTTLS) o 'ssl' (SMTPS).
        'usuario' => '',     // dirección completa de Gmail (sin contraseña aquí).
        'password' => '',    // dejar vacío; usar smtp.secret.php o variable de entorno.
    ],
];
