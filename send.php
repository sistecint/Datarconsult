<?php
/**
 * Envío del formulario de contacto de DATAR Consulting.
 * Recibe los campos del formulario (#contact-form) y envía un correo
 * a la dirección de destino. Responde JSON cuando la petición es AJAX
 * (fetch desde index.html) y, si no, muestra un aviso y regresa al inicio.
 */

$DESTINO  = 'info@datarconsult.com';
$ASUNTO   = 'Nuevo contacto desde el sitio web - DATAR Consulting';

/* ------------------------------------------------------------------ */

// ¿La petición espera JSON? (fetch envía Accept: application/json)
$esAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function responder($ok, $mensaje, $codigo, $esAjax) {
    http_response_code($codigo);
    if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => $ok, 'message' => $mensaje));
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        $texto = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><meta charset="utf-8">';
        echo '<script>alert(' . json_encode($mensaje) . ');'
           . 'window.location.href="index.html";</script>';
        echo '<noscript>' . $texto . ' <a href="index.html">Volver al inicio</a>.</noscript>';
    }
    exit;
}

// Solo POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(false, 'Método no permitido.', 405, $esAjax);
}

// Trampa anti-spam: si el campo oculto trae contenido, lo ignoramos en silencio.
if (!empty($_POST['_gotcha'])) {
    responder(true, 'Gracias, tu información fue enviada.', 200, $esAjax);
}

/* --------------------------- Datos -------------------------------- */

function limpiar($valor) {
    return trim(str_replace(array("\r", "\n", "%0a", "%0d"), ' ', (string) $valor));
}

$nombre   = limpiar(isset($_POST['name'])    ? $_POST['name']    : '');
$email    = limpiar(isset($_POST['email'])   ? $_POST['email']   : '');
$telefono = limpiar(isset($_POST['phone'])   ? $_POST['phone']   : '');
$servicio = limpiar(isset($_POST['service']) ? $_POST['service'] : 'Consulta general');
$mensaje  = trim(isset($_POST['message'])    ? $_POST['message'] : '');
$asunto   = limpiar(isset($_POST['_subject']) && $_POST['_subject'] !== '' ? $_POST['_subject'] : $ASUNTO);

/* ------------------------- Validación ----------------------------- */

$errores = array();
if ($nombre === '')  { $errores[] = 'el nombre'; }
if ($mensaje === '') { $errores[] = 'el mensaje'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'un correo válido';
}

if ($errores) {
    responder(false, 'Por favor completa ' . implode(', ', $errores) . '.', 422, $esAjax);
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
if (!empty($_SERVER['REMOTE_ADDR'])) {
    $cuerpo .= "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";
}

$asuntoMime = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

$cabeceras  = "From: DATAR Consulting (web) <no-reply@datarconsult.com>\r\n";
$cabeceras .= "Reply-To: $nombre <$email>\r\n";
$cabeceras .= "MIME-Version: 1.0\r\n";
$cabeceras .= "Content-Type: text/plain; charset=UTF-8\r\n";
$cabeceras .= "Content-Transfer-Encoding: 8bit\r\n";
$cabeceras .= "X-Mailer: PHP/" . phpversion() . "\r\n";

$enviado = @mail($DESTINO, $asuntoMime, $cuerpo, $cabeceras, "-f no-reply@datarconsult.com");

if ($enviado) {
    responder(true, 'Tu información fue enviada, pronto nos ponemos en contacto.', 200, $esAjax);
} else {
    responder(false, 'No se pudo enviar el mensaje. Escríbenos a ' . $DESTINO . '.', 500, $esAjax);
}
