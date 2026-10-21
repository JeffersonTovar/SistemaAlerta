<?php
/**
 * Endpoint de recepción de reportes de riñas.
 *
 * GET  -> emite un token CSRF ligado a la sesión.
 * POST -> valida, registra y notifica por correo a los destinatarios
 *         configurados en config/config.php.
 *
 * Este archivo no contiene credenciales ni destinatarios fijos:
 * toda la configuración de correo proviene del archivo de configuración.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ---------------------------------------------------------------------------
// Configuración
// ---------------------------------------------------------------------------

$CONFIG_DEFECTO = [
    'timezone'        => 'America/Bogota',
    'max_por_hora'    => 5,
    'destinatarios'   => [],                       // ej.: ['responsable@dominio.gov.co']
    'remite'          => ['nombre' => '', 'email' => ''],
    'smtp'            => [
        'host'     => 'localhost',
        'puerto'   => 25,
        'auth'     => false,
        'secure'   => '',
        'usuario'  => '',
        'password' => '',
    ],
];

$rutaConfig = dirname(__DIR__) . '/config/config.php';
$config = is_readable($rutaConfig) ? include $rutaConfig : [];
if (!is_array($config)) {
    $config = [];
}
$config = array_merge($CONFIG_DEFECTO, $config);
$config['remite'] = array_merge(
    $CONFIG_DEFECTO['remite'],
    is_array($config['remite'] ?? null) ? $config['remite'] : []
);
$config['smtp'] = array_merge(
    $CONFIG_DEFECTO['smtp'],
    is_array($config['smtp'] ?? null) ? $config['smtp'] : []
);

// Credenciales SMTP privadas. Prioridad: variables de entorno > archivo local.
// El archivo config/smtp.secret.php está excluido de Git. Nunca se registra.
$rutaSecreto = dirname(__DIR__) . '/config/smtp.secret.php';
$secreto = is_readable($rutaSecreto) ? include $rutaSecreto : [];
if (!is_array($secreto)) {
    $secreto = [];
}
$usuarioEntorno  = getenv('ALERTA_SMTP_USER');
$passwordEntorno = getenv('ALERTA_SMTP_PASSWORD');
$usuarioSMTP  = $usuarioEntorno !== false && $usuarioEntorno !== '' ? $usuarioEntorno
    : (is_string($secreto['usuario'] ?? null) ? $secreto['usuario'] : '');
$passwordSMTP = $passwordEntorno !== false && $passwordEntorno !== '' ? $passwordEntorno
    : (is_string($secreto['password'] ?? null) ? $secreto['password'] : '');

$configuracionCorreosOK = false;
$destinatariosValidos = [];
foreach ((array) $config['destinatarios'] as $destino) {
    if (is_string($destino) && filter_var($destino, FILTER_VALIDATE_EMAIL) !== false) {
        $destinatariosValidos[] = $destino;
    }
}
$remitenteEmail = is_string($config['remite']['email']) ? $config['remite']['email'] : '';
$remitenteNombre = is_string($config['remite']['nombre']) ? $config['remite']['nombre'] : '';
if ($destinatariosValidos !== [] && filter_var($remitenteEmail, FILTER_VALIDATE_EMAIL) !== false) {
    $configuracionCorreosOK = true;
}

// Modo de envío: 'smtp' (autenticado con PHPMailer) o 'mail' (comportamiento previo).
$smtpAuth = !empty($config['smtp']['auth']) && is_string($config['smtp']['host']) && $config['smtp']['host'] !== '';
$modoEnvio = $smtpAuth ? 'smtp' : 'mail';

if ($modoEnvio === 'mail') {
    if (isset($config['smtp']['host']) && is_string($config['smtp']['host'])) {
        ini_set('SMTP', $config['smtp']['host']);
    }
    if (isset($config['smtp']['puerto'])) {
        ini_set('smtp_port', (string) (int) $config['smtp']['puerto']);
    }
}

date_default_timezone_set(is_string($config['timezone']) ? $config['timezone'] : 'America/Bogota');

$RAIZ = dirname(__DIR__);
$DIR_DATOS = $RAIZ . '/data';

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

function responder(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function logTecnico(string $codigo, string $detalle): void
{
    // Registro técnico sin datos personales del reporte.
    $linea = sprintf("[%s] %s %s\n", date('c'), $codigo, preg_replace('/\s+/', ' ', $detalle));
    @file_put_contents(dirname(__DIR__) . '/data/errores.log', $linea, FILE_APPEND | LOCK_EX);
}

function asegurarDir(string $ruta): void
{
    if (!is_dir($ruta)) {
        @mkdir($ruta, 0770, true);
    }
    $htaccess = $ruta . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }
    $indice = $ruta . '/index.html';
    if (!is_file($indice)) {
        @file_put_contents($indice, '');
    }
}

function limpiarLinea(string $valor, int $max): string
{
    $valor = str_replace("\0", '', $valor);
    $valor = str_replace(["\r", "\n"], ' ', $valor);
    $valor = preg_replace('/[\x00-\x1F\x7F]/u', '', $valor) ?? '';
    $valor = trim($valor);
    return recortar($valor, $max);
}

function limpiarParrafo(string $valor, int $max): string
{
    $valor = str_replace("\0", '', $valor);
    $valor = str_replace("\r\n", "\n", $valor);
    $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? '';
    $valor = trim($valor);
    return recortar($valor, $max);
}

function recortar(string $valor, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_strlen($valor, 'UTF-8') > $max ? mb_substr($valor, 0, $max, 'UTF-8') : $valor;
    }
    return strlen($valor) > $max ? substr($valor, 0, $max) : $valor;
}

function longitud(string $valor): int
{
    return function_exists('mb_strlen') ? mb_strlen($valor, 'UTF-8') : strlen($valor);
}

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

asegurarDir($DIR_DATOS);

// ---------------------------------------------------------------------------
// Sesión y protección CSRF
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function emitirToken(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf'] = [
        'hash' => hash('sha256', $token),
        'exp'  => time() + 900,
    ];
    return $token;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    responder(200, ['ok' => true, 'token' => emitirToken()]);
}

if ($metodo !== 'POST') {
    header('Allow: GET, POST');
    responder(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

// ---------------------------------------------------------------------------
// Trampa antispam (honeypot)
// ---------------------------------------------------------------------------

$trampa = isset($_POST['url_sitio']) && is_string($_POST['url_sitio']) ? trim($_POST['url_sitio']) : '';
if ($trampa !== '') {
    logTecnico('HONEYPOT', 'Envío con campo trampa completo; rechazado.');
    responder(400, ['ok' => false, 'mensaje' => 'No fue posible procesar el reporte. Verifica los datos e inténtalo de nuevo.']);
}

// ---------------------------------------------------------------------------
// Token CSRF
// ---------------------------------------------------------------------------

$token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$sello = $_SESSION['csrf'] ?? null;
$tokenValido = $token !== ''
    && is_array($sello)
    && isset($sello['hash'], $sello['exp'])
    && (int) $sello['exp'] >= time()
    && hash_equals($sello['hash'], hash('sha256', $token));

if (!$tokenValido) {
    responder(403, ['ok' => false, 'csrf' => true, 'mensaje' => 'La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.']);
}

// ---------------------------------------------------------------------------
// Límite de frecuencia por dirección IP
// ---------------------------------------------------------------------------

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$claveRate = $DIR_DATOS . '/ratelimit/' . hash('sha1', $ip) . '.json';
asegurarDir($DIR_DATOS . '/ratelimit');

$ventanaSeg = 3600;
$maxIntentos = max(1, (int) $config['max_por_hora']);
$ahora = time();

$historial = [];
if (is_readable($claveRate)) {
    $bruto = @file_get_contents($claveRate);
    $decod = json_decode((string) $bruto, true);
    if (is_array($decod)) {
        $historial = $decod;
    }
}
$historial = array_values(array_filter($historial, static function ($t) use ($ahora, $ventanaSeg) {
    return is_int($t) && ($ahora - $t) < $ventanaSeg;
}));

if (count($historial) >= $maxIntentos) {
    $restante = $ventanaSeg - ($ahora - (int) $historial[0]);
    $minutos = max(1, (int) ceil($restante / 60));
    responder(429, [
        'ok'      => false,
        'mensaje' => 'Has enviado varios reportes en poco tiempo. Vuelve a intentarlo en aproximadamente ' . $minutos . ' minuto(s).',
    ]);
}

$historial[] = $ahora;
@file_put_contents($claveRate, json_encode($historial), LOCK_EX);

// ---------------------------------------------------------------------------
// Normalización y validación en el servidor
// ---------------------------------------------------------------------------

$lee = static function (string $clave) : string {
    $v = $_POST[$clave] ?? '';
    return is_string($v) ? $v : '';
};

$tipo      = $lee('tipo');
$fecha     = $lee('fecha');
$hora      = $lee('hora');
$ubicacion = $lee('ubicacion');
$ciudad    = $lee('ciudad');
$descripcion = $lee('descripcion');
$estado    = $lee('estado');
$nombre    = $lee('nombre');
$correo    = $lee('correo');
$telefono  = $lee('telefono');

$tipo = limpiarLinea($tipo, 30);
$fecha = limpiarLinea($fecha, 10);
$hora = limpiarLinea($hora, 5);
$ubicacion = limpiarParrafo($ubicacion, 300);
$ciudad = limpiarLinea($ciudad, 120);
$descripcion = limpiarParrafo($descripcion, 2000);
$estado = limpiarLinea($estado, 20);
$nombre = limpiarLinea($nombre, 120);
$correo = limpiarLinea($correo, 190);
$telefono = limpiarLinea($telefono, 20);

$errores = [];

$TIPOS = ['rina' => 'Riña', 'confrontacion' => 'Confrontación física'];
$ESTADOS = ['en_curso' => 'En curso', 'finalizado' => 'Finalizado', 'no_se_sabe' => 'No se sabe'];

if (!isset($TIPOS[$tipo])) {
    $errores['tipo'] = 'Selecciona el tipo de incidente.';
}
if (!isset($ESTADOS[$estado])) {
    $errores['estado'] = 'Indica el estado del incidente.';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $errores['fecha'] = 'Indica la fecha del incidente.';
} else {
    $d = DateTime::createFromFormat('!Y-m-d', $fecha);
    $valida = $d instanceof DateTime && $d->format('Y-m-d') === $fecha;
    $hoy = new DateTime('today');
    $limiteSuperior = (clone $hoy)->modify('+1 day');
    $limiteInferior = new DateTime('2000-01-01');
    if (!$valida || $d > $limiteSuperior) {
        $errores['fecha'] = 'La fecha del incidente no puede ser futura.';
    } elseif ($d < $limiteInferior) {
        $errores['fecha'] = 'Revisa la fecha del incidente.';
    }
}

if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) {
    $errores['hora'] = 'Indica la hora aproximada del incidente.';
}

if (longitud($ubicacion) < 5) {
    $errores['ubicacion'] = 'Describe la ubicación del incidente (mínimo 5 caracteres).';
}
if (longitud($ciudad) < 2) {
    $errores['ciudad'] = 'Indica la ciudad o municipio.';
}
if (longitud($descripcion) < 10) {
    $errores['descripcion'] = 'Describe brevemente los hechos (mínimo 10 caracteres).';
}
if ($nombre !== '' && longitud($nombre) < 2) {
    $errores['nombre'] = 'Revisa el nombre ingresado.';
}
if ($correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
    $errores['correo'] = 'Revisa el correo electrónico ingresado.';
}
if ($telefono !== '' && !preg_match('/^\+?[0-9\s\-()]{7,20}$/', $telefono)) {
    $errores['telefono'] = 'Revisa el teléfono ingresado.';
}

if ($errores !== []) {
    responder(400, ['ok' => false, 'errores' => $errores]);
}

// ---------------------------------------------------------------------------
// Detección de envíos duplicados (doble clic / reintentos)
// ---------------------------------------------------------------------------

$firma = hash('sha256', implode('|', [
    $tipo, $fecha, $hora, $ubicacion, $ciudad, $descripcion, $estado, $nombre, $correo, $telefono,
]));
$dirDedup = $DIR_DATOS . '/dedup';
asegurarDir($dirDedup);
$rutaDedup = $dirDedup . '/' . $firma . '.json';

if (is_readable($rutaDedup)) {
    $previo = json_decode((string) @file_get_contents($rutaDedup), true);
    if (is_array($previo) && isset($previo['folio'], $previo['exp']) && (int) $previo['exp'] >= time()) {
        responder(200, ['ok' => true, 'status' => 'duplicado', 'folio' => (string) $previo['folio']]);
    }
}

// ---------------------------------------------------------------------------
// Registro del reporte (trazabilidad)
// ---------------------------------------------------------------------------

$folio = 'R-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
$registro = [
    'folio'                 => $folio,
    'fecha_recepcion'       => date('c'),
    'tipo'                  => $tipo,
    'tipo_etiqueta'         => $TIPOS[$tipo],
    'fecha_incidente'       => $fecha,
    'hora_incidente'        => $hora,
    'ubicacion'             => $ubicacion,
    'ciudad'                => $ciudad,
    'estado'                => $estado,
    'estado_etiqueta'       => $ESTADOS[$estado],
    'descripcion'           => $descripcion,
    'reportante_nombre'     => $nombre,
    'reportante_correo'     => $correo,
    'reportante_telefono'   => $telefono,
];

$linea = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($linea === false || @file_put_contents($DIR_DATOS . '/reportes.jsonl', $linea . "\n", FILE_APPEND | LOCK_EX) === false) {
    logTecnico('PERSISTENCIA_FALLIDA', 'No se pudo escribir el registro del reporte.');
    responder(500, ['ok' => false, 'mensaje' => 'No fue posible registrar el reporte en este momento. Inténtalo de nuevo en unos minutos.']);
}

@file_put_contents(
    $rutaDedup,
    json_encode(['folio' => $folio, 'exp' => time() + 900]),
    LOCK_EX
);

// ---------------------------------------------------------------------------
// Notificación por correo
// ---------------------------------------------------------------------------

$estatusCorreo = 'no_configurado';

if ($configuracionCorreosOK) {
    $asunto = 'Nuevo reporte de riña [' . $folio . ']';

    $filas = [
        ['Folio', $folio],
        ['Fecha y hora de recepción', date('d/m/Y H:i:s T')],
        ['Fecha del incidente', $fecha],
        ['Hora aproximada del incidente', $hora],
        ['Tipo de incidente', $TIPOS[$tipo]],
        ['Ubicación', $ubicacion],
        ['Ciudad o municipio', $ciudad],
        ['Estado del incidente', $ESTADOS[$estado]],
        ['Descripción de los hechos', $descripcion],
        ['Nombre del reportante', $nombre !== '' ? $nombre : 'No proporcionado'],
        ['Correo del reportante', $correo !== '' ? $correo : 'No proporcionado'],
        ['Teléfono del reportante', $telefono !== '' ? $telefono : 'No proporcionado'],
    ];

    $cuerpoTexto = "Nuevo reporte de riña\n\n";
    foreach ($filas as [$etiqueta, $valor]) {
        $cuerpoTexto .= $etiqueta . ': ' . $valor . "\n";
    }
    $cuerpoTexto .= "\nMensaje generado automáticamente por el Sistema de Alerta.";

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<title>' . escapar($asunto) . '</title></head><body '
        . 'style="font-family:Arial,Helvetica,sans-serif;color:#222222;">'
        . '<h2 style="color:#0B5E20;">Nuevo reporte de ri&ntilde;a</h2>'
        . '<p>Se recibi&oacute; un nuevo reporte a trav&eacute;s del portal ciudadano.</p>'
        . '<table style="border-collapse:collapse;width:100%;max-width:640px;" cellpadding="6">'
        . '<tr><th align="left" style="background:#E6F4EA;border:1px solid #D0D5D8;width:40%;">Campo</th>'
        . '<th align="left" style="background:#E6F4EA;border:1px solid #D0D5D8;">Detalle</th></tr>';

    foreach ($filas as [$etiqueta, $valor]) {
        $html .= '<tr><td style="border:1px solid #D0D5D8;"><strong>' . escapar($etiqueta) . '</strong></td>'
            . '<td style="border:1px solid #D0D5D8;white-space:pre-wrap;">' . escapar($valor) . '</td></tr>';
    }

    $html .= '</table>'
        . '<p style="color:#555555;font-size:12px;">Este mensaje fue generado autom&aacute;ticamente por el '
        . 'Sistema de Alerta. Responde a este correo para contactar al reportante cuando se haya '
        . 'proporcionado un medio de contacto.</p>'
        . '</body></html>';

    $nombreRemitente = limpiarLinea($remitenteNombre !== '' ? $remitenteNombre : 'Sistema de Alerta', 80);
    $correoReportanteValido = $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;

    if ($modoEnvio === 'smtp') {
        // Envío autenticado (Gmail 587/STARTTLS) mediante PHPMailer.
        $rutaAutoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_readable($rutaAutoload)) {
            require_once $rutaAutoload;
        }
        $phpmailerDisponible = class_exists(\PHPMailer\PHPMailer\PHPMailer::class);

        if (!$phpmailerDisponible || $usuarioSMTP === '' || $passwordSMTP === '') {
            // Falta la dependencia o las credenciales: no se simula el envío.
            $estatusCorreo = 'no_configurado';
            logTecnico('SMTP_NO_CONFIGURADO', 'Faltan PHPMailer o credenciales SMTP para el folio ' . $folio . '.');
        } else {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mailer->isSMTP();
                $mailer->SMTPDebug = 0; // nunca volcar el diálogo SMTP (podría exponer datos).
                $mailer->Host = (string) $config['smtp']['host'];
                $mailer->Port = (int) $config['smtp']['puerto'];
                $mailer->SMTPAuth = true;
                $mailer->Username = $usuarioSMTP;
                $mailer->Password = $passwordSMTP;
                $secure = strtolower((string) $config['smtp']['secure']);
                if ($secure === 'tls' || $secure === 'ssl') {
                    $mailer->SMTPSecure = $secure === 'ssl'
                        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }
                // STARTTLS automático solo si la configuración no lo define: en un
                // relay local sin TLS (secure vacío) no debe forzar el cifrado.
                $mailer->SMTPAutoTLS = ($secure === '');
                $mailer->Timeout = 20;
                $mailer->CharSet = 'UTF-8';
                $mailer->Encoding = 'base64';

                // Remitente autenticado: siempre la cuenta configurada, nunca la del ciudadano.
                $mailer->setFrom($remitenteEmail, $nombreRemitente);
                foreach ($destinatariosValidos as $destino) {
                    $mailer->addAddress($destino);
                }
                // El correo del ciudadano solo como Reply-To validado.
                if ($correoReportanteValido) {
                    $mailer->addReplyTo($correo, $nombre !== '' ? $nombre : '');
                }

                $mailer->Subject = $asunto;
                $mailer->isHTML(true);
                $mailer->Body = $html;
                $mailer->AltBody = $cuerpoTexto;

                $mailer->send();
                $estatusCorreo = 'aceptado';
                // send() true = el servidor SMTP aceptó el mensaje; no implica
                // entrega final en el buzón del destinatario.
            } catch (\Throwable $e) {
                // Registro saneado: se guarda solo la clase/mensaje del error,
                // nunca el objeto de excepción ni las credenciales.
                $estatusCorreo = 'fallido';
                $mensajeError = preg_replace('/\s+/', ' ', (string) $e->getMessage());
                logTecnico('SMTP_FALLIDO', 'Folio ' . $folio . '. ' . recortar($mensajeError, 200));
            } finally {
                if (method_exists($mailer, 'smtpClose')) {
                    $mailer->smtpClose();
                }
            }
        }
    } else {
        // Comportamiento previo con mail() de PHP (sin credenciales SMTP).
        $asuntoCabecera = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($asunto, 'UTF-8', 'B')
            : $asunto;

        $cabeceras = [];
        $nombreRemitenteCab = $nombreRemitente;
        if (function_exists('mb_encode_mimeheader')) {
            $nombreRemitenteCab = mb_encode_mimeheader($nombreRemitenteCab, 'UTF-8', 'B');
        }
        $cabeceras[] = 'From: ' . $nombreRemitenteCab
            . ' <' . limpiarLinea($remitenteEmail, 190) . '>';
        if ($correoReportanteValido) {
            $cabeceras[] = 'Reply-To: ' . limpiarLinea($correo, 190);
        }
        $cabeceras[] = 'Content-Type: text/html; charset=UTF-8';
        $cabeceras[] = 'MIME-Version: 1.0';

        $enviado = @mail(
            implode(', ', $destinatariosValidos),
            $asuntoCabecera,
            $html,
            implode("\r\n", $cabeceras)
        );

        // mail() true = aceptado por el agente local de correo; no implica entrega
        // final en el buzón del destinatario.
        $estatusCorreo = $enviado ? 'aceptado' : 'fallido';
        if (!$enviado) {
            logTecnico('MAIL_FALLIDO', 'mail() devolvió false para el folio ' . $folio . '. Verificar configuración SMTP/sendmail de PHP.');
        }
    }
}

// ---------------------------------------------------------------------------
// Respuesta al cliente
// ---------------------------------------------------------------------------

if ($estatusCorreo === 'aceptado') {
    responder(200, ['ok' => true, 'status' => 'enviado', 'folio' => $folio]);
}
if ($estatusCorreo === 'fallido') {
    responder(200, ['ok' => true, 'status' => 'correo_fallido', 'folio' => $folio]);
}
responder(200, ['ok' => true, 'status' => 'registrado_sin_correo', 'folio' => $folio]);
