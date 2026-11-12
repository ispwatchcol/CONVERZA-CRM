<?php

/**
 * Propaga al `.env` del servidor las credenciales que vienen de GitHub Secrets.
 *
 * POR QUÉ EXISTE
 * El 20/08/2026 se rotó la contraseña de Postgres en Supabase y producción quedó
 * caída 9 h 54 m. La rotación fue correcta; lo que falló fue propagarla: la misma
 * contraseña vive en DOS variables del `.env` (`DB_PASSWORD` para el schema
 * `converza` e `ISPWATCH_DB_PASSWORD` para `public`), y solo se actualizó una.
 *
 * Con esto, rotar pasa a ser «cambiar un secreto en GitHub y desplegar».
 *
 * DOS BASES, DOS SECRETOS (desde el 12/11/2026)
 * Hasta el 02/11/2026 Converza e ispwatch vivían en el mismo Postgres y un solo
 * secreto se escribía en las dos variables. Ese día Converza se mudó a su propio
 * Supabase, con otra contraseña, y el secreto quedó con la vieja: el primer
 * despliegue siguiente (12/11) pisó `DB_PASSWORD` con una contraseña que la base
 * nueva rechaza y tumbó producción. Ahora cada variable tiene su secreto, y la
 * que no venga no se toca.
 *
 * POR QUÉ SOLO LAS CREDENCIALES Y NO EL `.env` ENTERO
 * Generar el `.env` completo desde secretos obligaría a tener las ~40 variables
 * en GitHub, y una sola omisión rompe producción en el siguiente despliegue. Acá
 * se tocan únicamente las claves que rotan; el resto del `.env` lo sigue
 * administrando el servidor.
 *
 * POR QUÉ EL VALOR VIENE POR ENTORNO Y NO POR ARGUMENTO
 * Los argumentos quedan en la lista de procesos (`ps`) y en el historial del
 * shell. Una variable de entorno solo es visible para este proceso.
 *
 * USO
 *   CONVERZA_DB_PASSWORD="..." ISPWATCH_DB_PASSWORD="..." php deploy/sync-secrets.php
 *
 * Cada una es opcional. Sin ninguna, no hace nada y sale con 0: así los
 * despliegues siguen funcionando aunque los secretos no existan en GitHub.
 *
 * ORDEN EN EL DESPLIEGUE
 * Tiene que correr ANTES de `migrate` y de `config:cache`. Si la contraseña rotó,
 * `migrate` fallaría con la vieja, y `config:cache` congelaría el valor viejo.
 */

const ENV_PATH = __DIR__ . '/../.env';

/** Clave del .env => variable de entorno que trae su valor. Son dos bases
 *  distintas (Converza e ispwatch), cada una con su contraseña. */
const CLAVES_DB = [
    'DB_PASSWORD'          => 'CONVERZA_DB_PASSWORD',
    'ISPWATCH_DB_PASSWORD' => 'ISPWATCH_DB_PASSWORD',
];

/** @var array<string, string> $nuevas Clave del .env => valor, solo las que vinieron. */
$nuevas = [];

foreach (CLAVES_DB as $clave => $variable) {
    $valor = getenv($variable);

    if ($valor !== false && $valor !== '') {
        $nuevas[$clave] = $valor;
    }
}

if ($nuevas === []) {
    echo "sync-secrets: no vino ninguna contraseña — no se toca el .env.\n";
    echo "  (esperado si los secretos no existen en GitHub; ver docs/operaciones.md §6 bis)\n";
    exit(0);
}

if (! is_file(ENV_PATH)) {
    fwrite(STDERR, "sync-secrets: no existe " . ENV_PATH . "\n");
    exit(1);
}

$contenido = file_get_contents(ENV_PATH);

if ($contenido === false) {
    fwrite(STDERR, "sync-secrets: no se pudo leer el .env\n");
    exit(1);
}

$original = $contenido;
$cambiadas = [];

// Se reemplaza recorriendo líneas, NO con preg_replace. En el reemplazo de
// preg_replace, `$` y `\` son sintaxis (referencias de retroceso), así que una
// contraseña que los contenga hay que escapar — y hacerlo en el orden equivocado
// mete backslashes de más. Recorriendo líneas no hay sintaxis que respetar: el
// valor se escribe tal cual.
//
// PREG_SPLIT_DELIM_CAPTURE conserva los saltos de línea como elementos propios,
// así que el archivo se reconstruye byte a byte —incluidos CRLF y la ausencia o
// presencia de salto final—.
$lineas = preg_split('/(\r\n|\n|\r)/', $contenido, -1, PREG_SPLIT_DELIM_CAPTURE);

foreach ($nuevas as $clave => $nueva) {
    $encontrada = false;

    foreach ($lineas as $i => $linea) {
        if (str_starts_with($linea, $clave . '=')) {
            $lineas[$i] = $clave . '=' . formatear($nueva);
            $encontrada = true;
            break;
        }
    }

    // Se exige que la clave YA exista: si falta, el .env no es el que esperamos
    // y es mejor fallar que añadir una variable a ciegas.
    if (! $encontrada) {
        fwrite(STDERR, "sync-secrets: el .env no tiene la clave {$clave}. Abortando sin tocar nada.\n");
        exit(1);
    }

    $cambiadas[] = $clave;
}

$contenido = implode('', $lineas);

if ($contenido === $original) {
    echo "sync-secrets: el .env ya tenía el valor correcto — sin cambios.\n";
    exit(0);
}

// Respaldo antes de escribir. Si algo sale mal, el anterior queda a mano.
$respaldo = ENV_PATH . '.bak.' . date('Ymd-His');

if (! copy(ENV_PATH, $respaldo)) {
    fwrite(STDERR, "sync-secrets: no se pudo respaldar el .env. Abortando.\n");
    exit(1);
}

if (file_put_contents(ENV_PATH, $contenido) === false) {
    fwrite(STDERR, "sync-secrets: no se pudo escribir el .env. El respaldo está en {$respaldo}\n");
    exit(1);
}

// Verificación: releer y confirmar que quedó lo que queríamos, sin imprimir el
// valor. Que file_put_contents devuelva bytes no garantiza el contenido final.
$verificar = file_get_contents(ENV_PATH);

foreach ($nuevas as $clave => $nueva) {
    if (! str_contains($verificar, $clave . '=' . formatear($nueva))) {
        fwrite(STDERR, "sync-secrets: la verificación de {$clave} falló. Respaldo en {$respaldo}\n");
        exit(1);
    }
}

echo "sync-secrets: actualizadas " . implode(', ', $cambiadas) . " (respaldo: " . basename($respaldo) . ")\n";
echo "  Recordá que hace falta `php artisan config:cache` para que surta efecto.\n";
exit(0);

/**
 * Devuelve el valor listo para una línea de .env.
 *
 * Dotenv trata el espacio como fin de valor y `#` como comienzo de comentario,
 * así que un valor con esos caracteres necesita comillas. Se entrecomilla solo
 * cuando hace falta, para no cambiar innecesariamente un .env que ya funcionaba.
 *
 * Solo se escapan `\` y `"`, que son la sintaxis de las comillas de Dotenv. `$`
 * NO se escapa: phpdotenv interpola `${VAR}`, no `$VAR`, y escaparlo dejaba un
 * backslash literal dentro de la contraseña.
 */
function formatear(string $valor): string
{
    if (preg_match('/^[A-Za-z0-9._\/+=:@-]+$/', $valor)) {
        return $valor;
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $valor) . '"';
}
