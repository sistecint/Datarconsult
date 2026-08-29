<?php
/**
 * Envío del formulario de contacto de DATAR Consulting.
 *
 * - POST con los campos del formulario  -> envía el correo a $DESTINO
 * - GET  ?diag=1                        -> diagnóstico del entorno de correo
 *
 * Responde JSON cuando la petición es AJAX (fetch desde index.html);
 * si no, muestra un aviso y regresa al inicio.
 */

$DESTINO   = 'info@datarconsult.com';
// El remitente DEBE ser una dirección del propio dominio que exista en el
// servidor; si no, muchos hostings rechazan el envío o cae en spam.
$REMITENTE = 'info@datarconsult.com';
$ASUNTO    = 'Nuevo contacto desde el sitio web - DATAR Consulting';

// Registrar errores en el log de PHP (no mostrarlos al visitante).
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

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

/* ----------------------- Diagnóstico (GET) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['diag'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $prueba = @mail(
        $DESTINO,
        '=?UTF-8?B?' . base64_encode('Prueba de diagnóstico - DATAR') . '?=',
        "Correo de prueba enviado desde send.php?diag=1 el " . date('d/m/Y H:i') . ".\n",
        "From: DATAR Consulting <$REMITENTE>\r\nContent-Type: text/plain; charset=UTF-8\r\n",
        "-f$REMITENTE"
    );
    echo json_encode(array(
        'php_version'      => phpversion(),
        'mail_disponible'  => function_exists('mail'),
        'disable_functions'=> ini_get('disable_functions'),
        'sendmail_path'    => ini_get('sendmail_path'),
        'SMTP'             => ini_get('SMTP'),
        'smtp_port'        => ini_get('smtp_port'),
        'destino'          => $DESTINO,
        'remitente'        => $REMITENTE,
        'mail_prueba_ok'   => $prueba,
        'nota'             => $prueba
            ? 'mail() devolvió true. Revisa la bandeja de ' . $DESTINO . ' (y spam).'
            : 'mail() devolvió false: el hosting no tiene mail() operativo o requiere SMTP autenticado.',
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/* --------------------------- Envío (POST) ------------------------- */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Suele pasar cuando el hosting redirige (http->https o www) y el
    // navegador reenvía la petición como GET. Prueba también ?diag=1.
    error_log('send.php: petición ' . $_SERVER['REQUEST_METHOD'] . ' (se esperaba POST).');
    responder(false, 'Método no permitido (se recibió ' . $_SERVER['REQUEST_METHOD'] . ', se esperaba POST).', 405, $esAjax,
        array('detail' => 'Probable redirección del servidor que convierte el POST en GET. Verifica que la página y el formulario usen la URL canónica (https, con o sin www).'));
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

$asuntoMime = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

$cabeceras  = "From: DATAR Consulting <$REMITENTE>\r\n";
$cabeceras .= "Reply-To: " . ($nombre !== '' ? "$nombre <$email>" : $email) . "\r\n";
$cabeceras .= "MIME-Version: 1.0\r\n";
$cabeceras .= "Content-Type: text/plain; charset=UTF-8\r\n";
$cabeceras .= "Content-Transfer-Encoding: 8bit\r\n";
$cabeceras .= "X-Mailer: PHP/" . phpversion() . "\r\n";

if (!function_exists('mail')) {
    error_log('send.php: la función mail() está deshabilitada en este servidor.');
    responder(false, 'El servidor no tiene el envío de correo habilitado.', 500, $esAjax,
        array('detail' => 'mail() deshabilitada (disable_functions).'));
}

// Intento 1: con envelope-sender (-f). Intento 2: sin él (algunos hostings lo bloquean).
$enviado = @mail($DESTINO, $asuntoMime, $cuerpo, $cabeceras, "-f$REMITENTE");
if (!$enviado) {
    $enviado = @mail($DESTINO, $asuntoMime, $cuerpo, $cabeceras);
}

if ($enviado) {
    responder(true, 'Tu información fue enviada, pronto nos ponemos en contacto.', 200, $esAjax);
} else {
    error_log('send.php: mail() devolvió false. Destino=' . $DESTINO . ' Remitente=' . $REMITENTE);
    responder(false, 'No se pudo enviar el mensaje. Escríbenos a ' . $DESTINO . '.', 500, $esAjax,
        array('detail' => 'mail() devolvió false. Revisa el log de errores de PHP o usa ?diag=1'));
}
