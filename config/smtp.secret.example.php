<?php
/**
 * Archivo privado de credenciales SMTP. Copia este archivo como
 * config/smtp.secret.php y completa los valores reales.
 *
 * Está excluido de Git (.gitignore). No compartas su contenido ni lo
 * incluyas en el repositorio, capturas o mensajes de error.
 *
 * Para Gmail:
 *   1. Activa la verificación en dos pasos de la cuenta.
 *   2. Genera una "contraseña de aplicación" (16 caracteres).
 *   3. Usa la dirección completa de Gmail como usuario.
 *
 * El archivo config/smtp.secret.php solo se lee si existe. Las variables de
 * entorno ALERTA_SMTP_USER y ALERTA_SMTP_PASSWORD tienen prioridad sobre este
 * archivo.
 */

return [
    // Dirección completa de Gmail, por ejemplo: 'reportes.sistema@gmail.com'
    'usuario' => '',

    // Contraseña de aplicación de Gmail (no la contraseña normal de la cuenta).
    'password' => '',
];
