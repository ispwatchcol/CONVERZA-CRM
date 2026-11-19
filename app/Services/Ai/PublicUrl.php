<?php

namespace App\Services\Ai;

/**
 * ¿Se le puede pegar a esta URL desde el servidor? Es la defensa contra SSRF
 * del proveedor «Otro compatible con OpenAI»: la URL la escribe la empresa, y
 * sin este control podría apuntar a la red interna del droplet (Redis,
 * Postgres, el metadata de DigitalOcean en 169.254.169.254) y usar a Converza
 * para leerla.
 *
 * Exige https y que el nombre resuelva SOLO a IPs públicas. Se mira al guardar
 * y al probar la llave. No ataja un DNS que cambie de IP después de guardado
 * (DNS rebinding): para eso habría que fijar la IP en cada llamada; el riesgo
 * queda acotado porque la petición va con la llave de la propia empresa y la
 * respuesta solo se usa como texto de la IA.
 */
final class PublicUrl
{
    /** @var (callable(string): list<string>)|null Para las pruebas: resuelve un host a sus IPs. */
    public static $resolver = null;

    /** null si la URL sirve; si no, por qué, en palabras de la pantalla. */
    public static function problem(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return 'Escribe la URL de la API (por ejemplo https://api.tu-proveedor.com/v1).';
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return 'La URL tiene que empezar por https:// (por seguridad no se aceptan conexiones sin cifrar).';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'La URL no puede traer usuario ni contraseña: la llave va en su campo.';
        }

        $host = trim($parts['host'], '[]');
        $ips  = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if ($ips === []) {
            return "No se encontró el servidor «{$host}». Revisa la URL.";
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'Esa URL apunta a una red privada o reservada. Tiene que ser una dirección pública de internet.';
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($host);
        }

        $ips = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
