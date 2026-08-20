<?php

/**
 * Da de alta los monitores externos en UptimeRobot.
 *
 * POR QUÉ ESTO ESTÁ VERSIONADO Y NO SE HACE A CLIC
 * La configuración del centinela es infraestructura: si vive sólo dentro de un
 * panel web, nadie sabe qué se está vigilando ni con qué criterio, y cuando
 * alguien la cambie no quedará rastro. Aquí queda explícito y reproducible.
 *
 * POR QUÉ MONITORES DE TIPO «KEYWORD» Y NO «HTTP(S)»
 * Un monitor que sólo mira el código de estado se conforma con un 200. El
 * 2026-08-20 quedó demostrado en producción: `ispwatch-crm.app/health/deep`
 * devolvía 200 con el HTML del panel, porque la ruta no existía todavía y el
 * catch-all del SPA la atendía. Un monitor de código habría reportado ese
 * endpoint como sano para siempre. Validar el CUERPO cuesta lo mismo y detecta
 * el endpoint que desaparece, el proxy que interpone una página de error con
 * 200, y el refactor que rompe el contrato sin que nadie se dé cuenta.
 *
 * CÓMO SE USA
 *   La llave NUNCA va como argumento: los argumentos quedan en el historial del
 *   shell y en la lista de procesos. Va por variable de entorno y se borra sola
 *   al cerrar la terminal.
 *
 *     # PowerShell
 *     $env:UPTIMEROBOT_API_KEY = "u1234567-..."
 *     C:\Users\User\php82\php.exe deploy/monitoring-setup.php
 *
 *     # Git Bash
 *     UPTIMEROBOT_API_KEY="u1234567-..." php deploy/monitoring-setup.php
 *
 *   Añade --dry-run para ver qué haría sin tocar nada.
 *
 * Es idempotente: si el monitor ya existe con ese nombre, no lo duplica.
 *
 * La llave se saca en UptimeRobot → My Settings → API. Usa la **Main API Key**
 * (las de sólo lectura no pueden crear monitores).
 */

const API = 'https://api.uptimerobot.com/v2/';

/**
 * Qué se vigila y con qué criterio.
 *
 * `keyword` es lo que DEBE aparecer en la respuesta. Si falta, el monitor marca
 * caída. Se busca `"status":"ok"` y no sólo `ok` porque la segunda aparecería
 * por accidente en cualquier página que contenga esas dos letras.
 */
const MONITORES = [
    [
        'nombre'   => 'ISPWatch — health/deep',
        'url'      => 'https://ispwatch-crm.app/health/deep',
        'keyword'  => '"status":"ok"',
        'contexto' => 'Hasta desplegar feat/health-deep-and-db-failure-handling responde 200 con el HTML del panel. El monitor lo marcará CAÍDO desde el primer minuto, y es correcto: el endpoint todavía no existe. Se pondrá verde solo al desplegar.',
    ],
    [
        'nombre'   => 'Converza CRM — health',
        'url'      => 'https://converza-crm.duckdns.org/health',
        'keyword'  => '"status":"ok"',
        'contexto' => 'Autoalojado tras DNS dinámico: vigila también que el equipo y el enlace sigan en pie.',
    ],
];

/**
 * Segundos entre comprobaciones.
 *
 * El plan de resiliencia pedía 60 segundos. No se puede: el mínimo del plan
 * gratuito de UptimeRobot es 300, y el minuto exige plan de pago. El requisito
 * estaba mal escrito —«cada 60 segundos, con plan gratuito suficiente» son dos
 * condiciones incompatibles— y se corrige aquí y en el plan.
 *
 * Se acepta a conciencia: la distancia entre 1 y 5 minutos es despreciable al
 * lado de la que se acaba de recorrer. La caída del 2026-08-20 duró quince
 * horas. Cinco minutos ya entrega prácticamente todo el valor; bajar a uno
 * tiene sentido el día que cuatro minutos extra de caída cuesten más que la
 * suscripción.
 */
const INTERVALO = 300;

// ─────────────────────────────────────────────────────────────────────────────

$dryRun = in_array('--dry-run', $argv, true);
$apiKey = getenv('UPTIMEROBOT_API_KEY');

if (! $apiKey) {
    fwrite(STDERR, "Falta UPTIMEROBOT_API_KEY.\n\n");
    fwrite(STDERR, "  PowerShell:  \$env:UPTIMEROBOT_API_KEY = \"u1234567-...\"\n");
    fwrite(STDERR, "  Git Bash:    UPTIMEROBOT_API_KEY=\"u1234567-...\" php " . basename(__FILE__) . "\n\n");
    fwrite(STDERR, "Se saca en UptimeRobot → My Settings → API → Main API Key.\n");
    exit(1);
}

/**
 * Localiza un almacén de certificados raíz.
 *
 * Por esta conexión viaja la API key, así que la verificación TLS NO se
 * desactiva bajo ninguna circunstancia. En Linux basta con el almacén del
 * sistema; el PHP de Windows suele venir sin `curl.cainfo` configurado y ahí
 * hace falta señalarlo a mano — Git for Windows trae uno utilizable.
 *
 * Devuelve null si no encuentra ninguno, en cuyo caso se deja que curl use su
 * valor por defecto: puede que php.ini sí lo tenga puesto.
 */
function caBundle(): ?string
{
    $candidatos = array_filter([
        getenv('CURL_CA_BUNDLE') ?: null,
        ini_get('curl.cainfo') ?: null,
        ini_get('openssl.cafile') ?: null,
        'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
    ]);

    foreach ($candidatos as $ruta) {
        if (is_readable($ruta)) {
            return $ruta;
        }
    }

    return null;
}

/**
 * @param array<string, string|int> $campos
 * @return array<string, mixed>
 */
function llamar(string $metodo, array $campos): array
{
    $ch = curl_init(API . $metodo);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($campos + ['format' => 'json']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if ($ca = caBundle()) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }

    $cuerpo = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($cuerpo === false) {
        fwrite(STDERR, "Error de red llamando a {$metodo}: {$error}\n");

        if (str_contains(strtolower($error), 'certificate')) {
            fwrite(STDERR, "\nNo se pudo verificar el certificado de UptimeRobot. Por esta conexión\n");
            fwrite(STDERR, "viaja tu API key, así que la verificación no se desactiva: hay que\n");
            fwrite(STDERR, "indicarle a PHP dónde están los certificados raíz.\n\n");
            fwrite(STDERR, "  PowerShell:\n");
            fwrite(STDERR, "    \$env:CURL_CA_BUNDLE = \"C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt\"\n\n");
            fwrite(STDERR, "  O de forma permanente, en php.ini:\n");
            fwrite(STDERR, "    curl.cainfo = \"C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt\"\n\n");
            fwrite(STDERR, "En el servidor Linux no hace falta: el almacén del sistema ya sirve.\n");
        }

        exit(1);
    }

    $datos = json_decode($cuerpo, true);

    if (! is_array($datos)) {
        fwrite(STDERR, "Respuesta ilegible de {$metodo}:\n{$cuerpo}\n");
        exit(1);
    }

    return $datos;
}

function abortar(string $metodo, array $respuesta): void
{
    // UptimeRobot devuelve el valor rechazado en `passed_value`, y cuando el
    // parámetro inválido es la propia llave eso significa imprimirla en pantalla
    // — donde queda en el scrollback de la terminal. Se tapa.
    if (($respuesta['error']['parameter_name'] ?? null) === 'api_key') {
        $respuesta['error']['passed_value'] = '[oculto]';
    }

    // El resto del mensaje crudo se imprime a propósito: si UptimeRobot cambia
    // el contrato (hay una v3 con tokens Bearer), este script tiene que decir
    // exactamente qué respondió en vez de fallar en silencio.
    fwrite(STDERR, "La API rechazó {$metodo}:\n" . json_encode($respuesta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "Consultando la cuenta…\n";

// ── Contactos de alerta ──────────────────────────────────────────────────────
$contactos = llamar('getAlertContacts', ['api_key' => $apiKey]);

if (($contactos['stat'] ?? '') !== 'ok') {
    abortar('getAlertContacts', $contactos);
}

$listaContactos = $contactos['alert_contacts'] ?? [];

if ($listaContactos === []) {
    fwrite(STDERR, "\nNo hay ningún contacto de alerta configurado.\n");
    fwrite(STDERR, "Un monitor sin contacto vigila y no avisa a nadie — que es el fallo\n");
    fwrite(STDERR, "que estás intentando eliminar. Añade al menos uno en\n");
    fwrite(STDERR, "UptimeRobot → My Settings → Alert Contacts y vuelve a ejecutar.\n\n");
    fwrite(STDERR, "Recomendado: la app móvil (push), no sólo el correo.\n");
    exit(1);
}

// Formato de UptimeRobot: "id_umbral_recurrencia", separados por guion.
// Umbral 0 = avisar de inmediato. Recurrencia 0 = no repetir.
$destinos = implode('-', array_map(
    fn (array $c): string => $c['id'] . '_0_0',
    $listaContactos
));

echo "Contactos de alerta encontrados: " . count($listaContactos) . "\n";
foreach ($listaContactos as $c) {
    echo "  · " . ($c['friendly_name'] ?? '(sin nombre)') . " — " . ($c['value'] ?? '') . "\n";
}

$soloCorreo = count(array_filter(
    $listaContactos,
    fn (array $c): bool => (int) ($c['type'] ?? 0) !== 2   // 2 = e-mail
)) === 0;

// ── Monitores existentes ─────────────────────────────────────────────────────
$existentes = llamar('getMonitors', ['api_key' => $apiKey, 'limit' => 50]);

if (($existentes['stat'] ?? '') !== 'ok') {
    abortar('getMonitors', $existentes);
}

$yaCreados = array_column($existentes['monitors'] ?? [], 'friendly_name');

// ── Alta ─────────────────────────────────────────────────────────────────────
echo "\n";

foreach (MONITORES as $m) {
    if (in_array($m['nombre'], $yaCreados, true)) {
        echo "= «{$m['nombre']}» ya existe, no se toca.\n";
        continue;
    }

    if ($dryRun) {
        echo "~ crearía «{$m['nombre']}»\n";
        echo "    URL:      {$m['url']}\n";
        echo "    Alerta si el cuerpo NO contiene: {$m['keyword']}\n";
        echo "    Cada:     " . INTERVALO . "s\n";
        continue;
    }

    $alta = llamar('newMonitor', [
        'api_key'        => $apiKey,
        'friendly_name'  => $m['nombre'],
        'url'            => $m['url'],
        'type'           => 2,   // 2 = Keyword
        'keyword_type'   => 2,   // 2 = alertar cuando NO exista
        'keyword_value'  => $m['keyword'],
        'interval'       => INTERVALO,
        'alert_contacts' => $destinos,
    ]);

    if (($alta['stat'] ?? '') !== 'ok') {
        abortar("newMonitor «{$m['nombre']}»", $alta);
    }

    echo "+ «{$m['nombre']}» creado (id {$alta['monitor']['id']}).\n";
    echo "    {$m['contexto']}\n";
}

// ── Avisos ───────────────────────────────────────────────────────────────────
echo "\n";

if ($soloCorreo) {
    echo "AVISO: todos tus contactos de alerta son correo.\n";
    echo "Un correo a las 3 a.m. no despierta a nadie, y ése fue el problema real del\n";
    echo "2026-08-20. Añade el push de la app móvil de UptimeRobot.\n\n";
}

echo "DOS HUECOS QUE ESTO NO CUBRE, a propósito y por escrito:\n\n";

echo "1. No hay escalado. El plan gratuito avisa a todos los contactos a la vez y\n";
echo "   ahí termina. Si quien recibe la alerta está durmiendo o sin señal, nadie\n";
echo "   más se entera — la persona es el punto único de fallo que queda. El plan\n";
echo "   de resiliencia pedía escalar a un segundo contacto a los 10 minutos; eso\n";
echo "   es gestión de guardia y exige plan de pago o Better Stack.\n\n";

echo "2. Nadie vigila al vigilante. UptimeRobot no avisa si deja de funcionar. La\n";
echo "   contramedida barata es un segundo proveedor independiente cubriendo el\n";
echo "   otro flanco: Healthchecks.io con un dead man's switch al que el\n";
echo "   planificador de ISPWatch haga ping desde `system:heartbeat`. Dos\n";
echo "   proveedores distintos cubriéndose mutuamente, ambos gratuitos.\n";
