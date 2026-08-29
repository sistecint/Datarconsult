<?php
/**
 * Envío del formulario de contacto de DATAR Consulting.
 *
 * - POST con los campos del formulario -> envía el correo
 * - GET  ?diag=1                       -> diagnóstico (SMTP / mail())
 *
 * Método de envío:
 *   1. Si existe send.config.php  -> SMTP autenticado con PHPMailer
 *      (necesario en Hostinger: el correo del dominio está fuera del
 *       servidor web, así que mail() local no entrega).
 *   2. Si no existe               -> función mail() de PHP (respaldo).
 *
 * Responde JSON cuando la petición es AJAX (fetch desde index.html);
 * si no, muestra un aviso y regresa al inicio.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$DIR       = __DIR__;
$ASUNTO    = 'Nuevo contacto desde el sitio web - DATAR Consulting';
$DESTINO_FALLBACK = 'info@datarconsult.com';

// Configuración SMTP (si el archivo existe).
$cfg = null;
if (is_file($DIR . '/send.config.php')) {
    $cfg = require $DIR . '/send.config.php';
}
$usaSMTP = is_array($cfg) && !empty($cfg['smtp_host']) && !empty($cfg['smtp_user']);

$DESTINO = $usaSMTP && !empty($cfg['to_email']) ? $cfg['to_email'] : $DESTINO_FALLBACK;

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

/**
 * Envía el correo. Devuelve true si se entregó al servidor de correo,
 * o una cadena con el error si falló.
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

    // Respaldo: mail() nativo.
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
    header('Content-Type: application/json; charset=UTF-8');

    $resultado = enviarCorreo(
        'Prueba de diagnóstico - DATAR',
        "Correo de prueba enviado desde send.php?diag=1 el " . date('d/m/Y H:i') . ".\n"
            . "Método: " . ($usaSMTP ? 'SMTP (' . $cfg['smtp_host'] . ')' : 'mail() nativo') . "\n",
        null, null, $cfg, $usaSMTP, $DESTINO_FALLBACK
    );

    echo json_encode(array(
        'php_version'      => phpversion(),
        'metodo'           => $usaSMTP ? 'SMTP' : 'mail()',
        'smtp_config'      => $usaSMTP ? array(
            'host'   => $cfg['smtp_host'],
            'port'   => isset($cfg['smtp_port']) ? $cfg['smtp_port'] : 465,
            'secure' => isset($cfg['smtp_secure']) ? $cfg['smtp_secure'] : 'ssl',
            'user'   => $cfg['smtp_user'],
            'from'   => !empty($cfg['from_email']) ? $cfg['from_email'] : $cfg['smtp_user'],
        ) : null,
        'config_encontrada'=> $cfg !== null,
        'phpmailer'        => is_file($DIR . '/lib/PHPMailer/PHPMailer.php'),
        'mail_disponible'  => function_exists('mail'),
        'destino'          => $DESTINO,
        'envio_prueba_ok'  => $resultado === true,
        'error'            => $resultado === true ? null : $resultado,
        'nota'             => $resultado === true
            ? 'El correo de prueba se envió. Revisa la bandeja de ' . $DESTINO . ' (y spam).'
            : 'El envío falló. Revisa el campo "error" y send.config.php.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* --------------------------- Envío (POST) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log('send.php: petición ' . $_SERVER['REQUEST_METHOD'] . ' (se esperaba POST).');
    responder(false, 'Método no permitido (se recibió ' . $_SERVER['REQUEST_METHOD'] . ', se esperaba POST).', 405, $esAjax,
        array('detail' => 'Abre esta URL solo con ?diag=1 para probar; el envío real llega por POST desde el formulario.'));
}

// Trampa anti-spam.
if (!empty($_POST['_gotcha'])) {
    responder(true, 'Gracias, tu información fue enviada.', 200, $esAjax);
}

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

$cuerpo  = "Nuevo mensaje desde el formulario de contacto\n";
$cuerpo .= "-------------------------------------------\n\n";
$cuerpo .= "Nombre:   $nombre\n";
$cuerpo .= "Correo:   $email\n";
$cuerpo .= "Teléfono: " . ($telefono !== '' ? $telefono : '(no indicado)') . "\n";
$cuerpo .= "Servicio: $servicio\n\n";
$cuerpo .= "Mensaje:\n$mensaje\n\n";
$cuerpo .= "-------------------------------------------\n";
$cuerpo .= "Enviado el " . date('d/m/Y H:i') . "\n";
if (!empty($_SERVER['REMOTE_ADDR'])) {
    $cuerpo .= "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
}

$resultado = enviarCorreo($asunto, $cuerpo, $email, $nombre, $cfg, $usaSMTP, $DESTINO_FALLBACK);

if ($resultado === true) {
    responder(true, 'Tu información fue enviada, pronto nos ponemos en contacto.', 200, $esAjax);
} else {
    responder(false, 'No se pudo enviar el mensaje. Escríbenos a ' . $DESTINO . '.', 500, $esAjax,
        array('detail' => $resultado));
}
