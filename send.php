<?php
/**
 * Envío del formulario de contacto de DATAR Consulting + protección anti-abuso.
 *
 * - POST con los campos del formulario -> valida, filtra bots y envía el correo
 * - GET  ?diag=TOKEN                    -> diagnóstico (solo si send.config.php
 *                                          define 'diag_token')
 *
 * Capas de seguridad (todas activas sin configuración extra):
 *   1. Honeypot (_gotcha)                 -> campo oculto que solo llenan los bots
 *   2. Time-trap (_ts)                    -> rechaza envíos demasiado rápidos
 *   3. Comprobación de origen             -> Origin/Referer del propio dominio
 *   4. Límite por IP y límite global      -> evita saturar el servidor de correo
 *   5. Heurística de spam                 -> enlaces / longitud / BBCode
 *   6. Cloudflare Turnstile (opcional)    -> si send.config.php trae las claves
 *
 * Envío: SMTP autenticado con PHPMailer si existe send.config.php; si no, mail().
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$DIR              = __DIR__;
$ASUNTO           = 'Nuevo contacto desde el sitio web - DATAR Consulting';
$DESTINO_FALLBACK = 'info@datarconsult.com';

$cfg = null;
if (is_file($DIR . '/send.config.php')) {
    $cfg = require $DIR . '/send.config.php';
}
$usaSMTP = is_array($cfg) && !empty($cfg['smtp_host']) && !empty($cfg['smtp_user']);
$DESTINO = $usaSMTP && !empty($cfg['to_email']) ? $cfg['to_email'] : $DESTINO_FALLBACK;

// Parámetros de seguridad (con valores por defecto sensatos).
$SEC = array(
    'min_seconds'    => 3,     // tiempo mínimo para llenar el formulario
    'max_per_ip'     => 5,     // envíos por IP dentro de la ventana
    'window_seconds' => 600,   // ventana de límite por IP (10 min)
    'global_per_min' => 30,    // tope de envíos por minuto de todo el sitio
    'max_message'    => 5000,  // caracteres máximos del mensaje
    'max_links'      => 3,     // enlaces máximos en nombre + mensaje
    'allowed_hosts'  => array('datarconsult.com', 'www.datarconsult.com'),
);
if (is_array($cfg) && !empty($cfg['security']) && is_array($cfg['security'])) {
    $SEC = array_merge($SEC, $cfg['security']);
}

/* ------------------------------------------------------------------ */

$esAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function responder($ok, $mensaje, $codigo, $esAjax, $extra = array()) {
    http_response_code($codigo);
    if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(array('ok' => $ok, 'message' => $mensaje), $extra));
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8">';
        echo '<script>alert(' . json_encode($mensaje) . ');window.location.href="index.html";</script>';
        echo '<noscript>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
           . ' <a href="index.html">Volver al inicio</a>.</noscript>';
    }
    exit;
}

function clientIp() {
    // Cloudflare / proxy inverso -> IP real del visitante.
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function httpPost($url, $params, $timeout = 10) {
    $body = http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $out = curl_exec($ch);
        curl_close($ch);
        return $out;
    }
    $ctx = stream_context_create(array('http' => array(
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body,
        'timeout' => $timeout,
    )));
    return @file_get_contents($url, false, $ctx);
}

/**
 * Límite de peticiones por IP y global. Devuelve false si se permite,
 * o 'ip' / 'global' si se bloquea. Falla en abierto si no puede escribir.
 */
function rateLimit($dir, $ip, $sec) {
    // Fuera del webroot (no accesible por URL). Si no se puede, usa ./data.
    $file = sys_get_temp_dir() . '/datar_ratelimit_' . md5($dir) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        $carpeta = $dir . '/data';
        if (!is_dir($carpeta)) { @mkdir($carpeta, 0755, true); }
        if (is_dir($carpeta) && !is_file($carpeta . '/.htaccess')) {
            @file_put_contents($carpeta . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $fp = @fopen($carpeta . '/ratelimit.json', 'c+');
    }
    if (!$fp) { return false; } // fail-open: no bloquea si no puede escribir

    $now = time();
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) { $data = array('ips' => array(), 'global' => array()); }

    $win = (int) $sec['window_seconds'];
    $keepGlobal = array();
    foreach ($data['global'] as $t) { if ($t > $now - $win) { $keepGlobal[] = $t; } }
    $data['global'] = $keepGlobal;

    foreach ($data['ips'] as $k => $arr) {
        $keep = array();
        foreach ((array) $arr as $t) { if ($t > $now - $win) { $keep[] = $t; } }
        if ($keep) { $data['ips'][$k] = $keep; } else { unset($data['ips'][$k]); }
    }

    $ipHits = isset($data['ips'][$ip]) ? count($data['ips'][$ip]) : 0;
    $globalUltimoMin = 0;
    foreach ($data['global'] as $t) { if ($t > $now - 60) { $globalUltimoMin++; } }

    $bloqueado = false;
    if ($ipHits >= (int) $sec['max_per_ip'])        { $bloqueado = 'ip'; }
    elseif ($globalUltimoMin >= (int) $sec['global_per_min']) { $bloqueado = 'global'; }

    if ($bloqueado === false) {
        $data['ips'][$ip][] = $now;
        $data['global'][]   = $now;
    }
    if (count($data['global']) > 2000) {
        $data['global'] = array_slice($data['global'], -2000);
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $bloqueado;
}

function mismoOrigen($hostsPermitidos) {
    $src = '';
    if (!empty($_SERVER['HTTP_ORIGIN']))       { $src = $_SERVER['HTTP_ORIGIN']; }
    elseif (!empty($_SERVER['HTTP_REFERER']))  { $src = $_SERVER['HTTP_REFERER']; }
    if ($src === '') { return true; } // algunos navegadores/proxies no lo envían

    $srcHost = strtolower((string) parse_url($src, PHP_URL_HOST));
    if ($srcHost === '') { return false; }

    $ok = array_map('strtolower', (array) $hostsPermitidos);
    if (!empty($_SERVER['HTTP_HOST'])) { $ok[] = strtolower($_SERVER['HTTP_HOST']); }
    return in_array($srcHost, $ok, true);
}

function largo($s) {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function pareceSpam($nombre, $mensaje, $sec) {
    if (largo($mensaje) > (int) $sec['max_message']) { return true; }
    if (largo($nombre) > 200)                        { return true; }
    $texto = $nombre . ' ' . $mensaje;
    if (preg_match_all('~https?://|www\.~i', $texto) > (int) $sec['max_links']) { return true; }
    if (preg_match('~\[/?(url|link|img|/?b)\]~i', $texto)) { return true; }
    if (preg_match('~https?://~i', $nombre))              { return true; }
    return false;
}

/**
 * Envía el correo. Devuelve true, o una cadena con el error.
 */
function enviarCorreo($asunto, $cuerpo, $replyToEmail, $replyToName, $cfg, $usaSMTP, $destinoFallback) {
    if ($usaSMTP) {
        $base = __DIR__ . '/lib/PHPMailer/';
        require_once $base . 'Exception.php';
        require_once $base . 'PHPMailer.php';
        require_once $base . 'SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $cfg['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $cfg['smtp_user'];
            $mail->Password   = $cfg['smtp_pass'];
            $mail->Port       = (int) (isset($cfg['smtp_port']) ? $cfg['smtp_port'] : 465);
            $secure           = isset($cfg['smtp_secure']) ? strtolower($cfg['smtp_secure']) : 'ssl';
            $mail->SMTPSecure = $secure === 'tls'
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 15;

            if (!empty($cfg['debug'])) {
                $mail->SMTPDebug   = 2;
                $mail->Debugoutput = function ($str, $level) { error_log("SMTP: $str"); };
            }

            $fromEmail = !empty($cfg['from_email']) ? $cfg['from_email'] : $cfg['smtp_user'];
            $fromName  = !empty($cfg['from_name'])  ? $cfg['from_name']  : 'Sitio web';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress(
                !empty($cfg['to_email']) ? $cfg['to_email'] : $destinoFallback,
                !empty($cfg['to_name']) ? $cfg['to_name'] : ''
            );
            if ($replyToEmail) {
                $mail->addReplyTo($replyToEmail, $replyToName);
            }

            $mail->Subject = $asunto;
            $mail->Body    = $cuerpo;
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            $err = $mail->ErrorInfo ? $mail->ErrorInfo : $e->getMessage();
            error_log('send.php SMTP: ' . $err);
            return $err;
        }
    }

    $destino = !empty($cfg['to_email']) ? $cfg['to_email'] : $destinoFallback;
    $from    = $destino;
    $asuntoMime = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    $cabeceras  = "From: DATAR Consulting <$from>\r\n";
    if ($replyToEmail) {
        $cabeceras .= "Reply-To: " . ($replyToName ? "$replyToName <$replyToEmail>" : $replyToEmail) . "\r\n";
    }
    $cabeceras .= "MIME-Version: 1.0\r\n";
    $cabeceras .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $cabeceras .= "Content-Transfer-Encoding: 8bit\r\n";

    $ok = @mail($destino, $asuntoMime, $cuerpo, $cabeceras, "-f$from");
    if (!$ok) { $ok = @mail($destino, $asuntoMime, $cuerpo, $cabeceras); }
    if ($ok) { return true; }
    error_log('send.php mail(): devolvió false para ' . $destino);
    return 'La función mail() del servidor no pudo entregar el mensaje.';
}

/* ----------------------- Diagnóstico (GET) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['diag'])) {
    $token = (is_array($cfg) && !empty($cfg['diag_token'])) ? (string) $cfg['diag_token'] : null;
    if ($token === null || !hash_equals($token, (string) $_GET['diag'])) {
        http_response_code(404);
        echo 'No encontrado.';
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    $resultado = enviarCorreo(
        'Prueba de diagnóstico - DATAR',
        "Correo de prueba enviado desde send.php (diag) el " . date('d/m/Y H:i') . ".\n"
            . "Método: " . ($usaSMTP ? 'SMTP (' . $cfg['smtp_host'] . ')' : 'mail() nativo') . "\n",
        null, null, $cfg, $usaSMTP, $DESTINO_FALLBACK
    );
    echo json_encode(array(
        'php_version'     => phpversion(),
        'metodo'          => $usaSMTP ? 'SMTP' : 'mail()',
        'config_encontrada' => $cfg !== null,
        'phpmailer'       => is_file($DIR . '/lib/PHPMailer/PHPMailer.php'),
        'turnstile'       => is_array($cfg) && !empty($cfg['turnstile_secret']),
        'rate_limit_escribible' => is_writable(sys_get_temp_dir()),
        'destino'         => $DESTINO,
        'envio_prueba_ok' => $resultado === true,
        'error'           => $resultado === true ? null : $resultado,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* --------------------------- Envío (POST) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método no permitido.', 405, $esAjax);
}

// (1) Honeypot: el campo oculto nunca debe venir lleno.
if (!empty($_POST['_gotcha'])) {
    error_log('send.php: honeypot activado desde ' . clientIp());
    responder(true, 'Gracias, tu información fue enviada.', 200, $esAjax); // éxito falso
}

// (2) Time-trap: si trae marca de tiempo, exige un mínimo realista.
if (!empty($_POST['_ts'])) {
    $edadMs = (int) round(microtime(true) * 1000) - (int) $_POST['_ts'];
    if ($edadMs < $SEC['min_seconds'] * 1000 || $edadMs > 3 * 86400 * 1000) {
        error_log('send.php: time-trap (' . $edadMs . ' ms) desde ' . clientIp());
        responder(true, 'Gracias, tu información fue enviada.', 200, $esAjax); // éxito falso
    }
}

// (3) Origen: el POST debe venir del propio sitio.
if (!mismoOrigen($SEC['allowed_hosts'])) {
    error_log('send.php: origen no permitido desde ' . clientIp());
    responder(false, 'Solicitud no permitida.', 403, $esAjax);
}

// (4) Límite por IP / global.
$bloqueo = rateLimit($DIR, clientIp(), $SEC);
if ($bloqueo !== false) {
    error_log('send.php: rate limit (' . $bloqueo . ') desde ' . clientIp());
    responder(false, 'Has enviado demasiados mensajes. Inténtalo de nuevo en unos minutos.', 429, $esAjax);
}

// (6) Cloudflare Turnstile (si está configurado).
if (is_array($cfg) && !empty($cfg['turnstile_secret'])) {
    $resp = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
    $verify = json_decode(httpPost(
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        array('secret' => $cfg['turnstile_secret'], 'response' => $resp, 'remoteip' => clientIp())
    ), true);
    if (empty($verify['success'])) {
        error_log('send.php: Turnstile falló desde ' . clientIp());
        responder(false, 'No pudimos verificar que no eres un robot. Recarga la página e inténtalo de nuevo.', 403, $esAjax);
    }
}

/* ------------------------- Datos y validación --------------------- */

function limpiar($valor) {
    return trim(str_replace(array("\r", "\n", "%0a", "%0d", "%0A", "%0D"), ' ', (string) $valor));
}

$nombre   = limpiar(isset($_POST['name'])     ? $_POST['name']     : '');
$email    = limpiar(isset($_POST['email'])    ? $_POST['email']    : '');
$telefono = limpiar(isset($_POST['phone'])    ? $_POST['phone']    : '');
$servicio = limpiar(isset($_POST['service'])  ? $_POST['service']  : 'Consulta general');
$mensaje  = trim(isset($_POST['message'])     ? $_POST['message']  : '');
$asunto   = limpiar(!empty($_POST['_subject']) ? $_POST['_subject'] : $ASUNTO);

$errores = array();
if ($nombre === '')  { $errores[] = 'el nombre'; }
if ($mensaje === '') { $errores[] = 'el mensaje'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'un correo válido';
}
if ($errores) {
    responder(false, 'Por favor completa ' . implode(', ', $errores) . '.', 422, $esAjax);
}

// (5) Heurística de spam.
if (pareceSpam($nombre, $mensaje, $SEC)) {
    error_log('send.php: descartado por heurística de spam desde ' . clientIp());
    responder(true, 'Gracias, tu información fue enviada.', 200, $esAjax); // éxito falso
}

/* --------------------------- Correo ------------------------------- */

$cuerpo  = "Nuevo mensaje desde el formulario de contacto\n";
$cuerpo .= "-------------------------------------------\n\n";
$cuerpo .= "Nombre:   $nombre\n";
$cuerpo .= "Correo:   $email\n";
$cuerpo .= "Teléfono: " . ($telefono !== '' ? $telefono : '(no indicado)') . "\n";
$cuerpo .= "Servicio: $servicio\n\n";
$cuerpo .= "Mensaje:\n$mensaje\n\n";
$cuerpo .= "-------------------------------------------\n";
$cuerpo .= "Enviado el " . date('d/m/Y H:i') . "\n";
$cuerpo .= "IP: " . clientIp() . "\n";

$resultado = enviarCorreo($asunto, $cuerpo, $email, $nombre, $cfg, $usaSMTP, $DESTINO_FALLBACK);

if ($resultado === true) {
    responder(true, 'Tu información fue enviada, pronto nos ponemos en contacto.', 200, $esAjax);
} else {
    responder(false, 'No se pudo enviar el mensaje. Escríbenos a ' . $DESTINO . '.', 500, $esAjax,
        array('detail' => $resultado));
}
