<?php
/**
 * Configuración del envío de correo del formulario de contacto.
 *
 * 1. Copia este archivo como  send.config.php  (en la misma carpeta).
 * 2. Rellena los datos SMTP del buzón de Hostinger.
 * 3. Sube  send.config.php  al servidor.  NO se sube al repositorio
 *    (está en .gitignore) porque contiene la contraseña.
 *
 * Los datos SMTP exactos aparecen en hPanel -> Correos electrónicos ->
 * (tu dominio) -> "Configuración" / "Conectar dispositivos".
 */

return array(

    // --- Servidor SMTP de Hostinger ---
    // Hostinger Email:            smtp.hostinger.com
    // Hostinger Business (Titan): smtp.titan.email
    'smtp_host'   => 'smtp.hostinger.com',

    // 465 con 'ssl'  (recomendado)   |   587 con 'tls'
    'smtp_port'   => 465,
    'smtp_secure' => 'ssl',

    // --- Cuenta que envía (buzón real, no el alias) ---
    'smtp_user'   => 'azalia.robolt@datarconsult.com',
    'smtp_pass'   => 'CONTRASEÑA_DEL_BUZON',

    // Remitente que verá quien reciba el correo.
    // Debe ser el mismo buzón autenticado (o un alias suyo).
    'from_email'  => 'azalia.robolt@datarconsult.com',
    'from_name'   => 'Sitio web DATAR Consulting',

    // Destino de los mensajes del formulario.
    'to_email'    => 'info@datarconsult.com',
    'to_name'     => 'DATAR Consulting',

    // true = escribe el diálogo SMTP en el log de PHP (solo para depurar).
    'debug'       => false,

    /* --------------------------------------------------------------
     * SEGURIDAD (opcional)
     * ------------------------------------------------------------ */

    // Token para poder abrir el diagnóstico:  send.php?diag=EL_TOKEN
    // Si se deja vacío, el diagnóstico queda deshabilitado (responde 404).
    'diag_token'  => '',

    // Cloudflare Turnstile (CAPTCHA invisible y gratuito).
    // Crea un widget en https://dash.cloudflare.com/  -> Turnstile
    // y pega aquí la "Secret Key". La "Site Key" va en index.html
    // (atributo data-turnstile-sitekey del <form>).
    'turnstile_secret' => '',

    // Ajustes finos del anti-abuso (opcional; estos son los valores por defecto).
    'security' => array(
        'min_seconds'    => 3,    // segundos mínimos para llenar el formulario
        'max_per_ip'     => 5,    // envíos por IP en la ventana
        'window_seconds' => 600,  // ventana del límite por IP (10 min)
        'global_per_min' => 30,   // tope de envíos por minuto de todo el sitio
        'max_message'    => 5000, // caracteres máximos del mensaje
        'max_links'      => 3,    // enlaces máximos permitidos en el texto
        'allowed_hosts'  => array('datarconsult.com', 'www.datarconsult.com'),
    ),
);
