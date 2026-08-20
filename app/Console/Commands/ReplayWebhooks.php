<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WebhookDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reprocesa webhooks de WhatsApp desde el log crudo (storage/logs/webhook-raw-*.log).
 *
 * Es el botón que no existía el 20/08/2026: la base estuvo caída 9 h 54 m, se
 * rechazaron 18 eventos y no hubo forma de recuperarlos. Ahora el payload queda
 * en disco antes de tocar nada que pueda fallar, así que basta con volver a
 * despacharlo cuando el sistema se recupere.
 *
 * Se lee del ARCHIVO y no de una tabla a propósito: durante una caída de base,
 * un INSERT en cualquier tabla fallaría igual que todo lo demás. El disco es lo
 * único que sobrevive al fallo que este comando existe para reparar.
 *
 * Reprocesar es seguro: messages.wa_message_id tiene índice único y el job
 * atrapa la violación sin ruido, así que un evento repetido no duplica nada.
 */
class ReplayWebhooks extends Command
{
    protected $signature = 'webhooks:replay
        {--desde= : Inicio del rango, ej "2026-08-20 09:51" (por defecto, todo el archivo)}
        {--hasta= : Fin del rango, ej "2026-08-20 13:19"}
        {--fecha= : Día a reprocesar en formato Y-m-d (por defecto, hoy)}
        {--dry-run : Muestra qué se reprocesaría sin despachar nada}';

    protected $description = 'Reprocesa webhooks de WhatsApp desde el log crudo, por si una caída impidió guardarlos';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $fecha = $this->option('fecha') ?: now()->format('Y-m-d');
        $ruta  = storage_path("logs/webhook-raw-{$fecha}.log");

        if (! File::exists($ruta)) {
            $this->error("No existe el log crudo de {$fecha}: {$ruta}");
            $this->line('Archivos disponibles:');
            foreach (glob(storage_path('logs/webhook-raw-*.log')) as $f) {
                $this->line('  '.basename($f));
            }

            return self::FAILURE;
        }

        [$desde, $hasta] = $this->rango();
        $seco = (bool) $this->option('dry-run');

        if ($seco) {
            $this->warn('MODO SECO — no se despacha nada.');
        }

        $leidos = 0;
        $enRango = 0;
        $despachados = 0;
        $sinTenant = 0;
        $ilegibles = 0;

        // Se lee línea por línea en vez de file(): con 30 días de retención el
        // archivo puede crecer y no hace falta tenerlo entero en memoria.
        $fh = fopen($ruta, 'r');

        while (($linea = fgets($fh)) !== false) {
            $linea = trim($linea);
            if ($linea === '') {
                continue;
            }

            $evento = $this->parsear($linea);

            if ($evento === null) {
                // Las líneas de continuación de un stack trace u otro ruido no
                // son eventos; solo se cuentan si parecían serlo.
                if (str_contains($linea, 'inbound')) {
                    $ilegibles++;
                }
                continue;
            }

            $leidos++;

            [$momento, $payload] = $evento;

            if ($desde && $momento->lt($desde)) {
                continue;
            }
            if ($hasta && $momento->gt($hasta)) {
                continue;
            }

            $enRango++;

            if ($seco) {
                $this->line(sprintf(
                    '  %s  %s',
                    $momento->format('H:i:s'),
                    $this->resumir($payload)
                ));
                continue;
            }

            $r = $dispatcher->dispatch($payload);
            $despachados += $r['dispatched'];
            $sinTenant   += $r['skipped_no_tenant'];
        }

        fclose($fh);

        $this->newLine();
        $this->info("Archivo:            {$ruta}");
        $this->line("Eventos leídos:     {$leidos}");
        $this->line('Rango:              '.($desde ? $desde->format('Y-m-d H:i:s') : 'inicio').' → '.($hasta ? $hasta->format('Y-m-d H:i:s') : 'fin'));
        $this->line("Eventos en rango:   {$enRango}");

        if ($ilegibles > 0) {
            $this->warn("Líneas ilegibles:   {$ilegibles}");
        }

        if (! $seco) {
            $this->line("Jobs despachados:   {$despachados}");
            if ($sinTenant > 0) {
                $this->warn("Sin tenant (descartados): {$sinTenant}");
            }
            $this->newLine();
            $this->info('Los duplicados se ignoran solos: wa_message_id es único.');
        }

        return self::SUCCESS;
    }

    /**
     * Convierte una línea del log en [momento, payload].
     *
     * Formato:  [2026-08-20 14:38:43] production.DEBUG: inbound {"received_at":...,"body":"{...}"}
     * El `body` es el cuerpo crudo tal como llegó de Meta, guardado como string
     * para que Monolog no lo trunque a 9 niveles de anidamiento.
     */
    private function parsear(string $linea): ?array
    {
        if (! preg_match('/^\[([\d-]+ [\d:]+)\][^{]*(\{.*\})$/', $linea, $m)) {
            return null;
        }

        $contexto = json_decode($m[2], true);

        if (! is_array($contexto) || ! isset($contexto['body'])) {
            return null;
        }

        $payload = json_decode((string) $contexto['body'], true);

        if (! is_array($payload)) {
            return null;
        }

        try {
            $momento = Carbon::parse($m[1]);
        } catch (\Throwable) {
            return null;
        }

        return [$momento, $payload];
    }

    /** Resumen de una línea para el modo seco, sin volcar datos de clientes. */
    private function resumir(array $payload): string
    {
        $mensajes = 0;
        $estados  = 0;
        $numero   = '?';

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value    = $change['value'] ?? [];
                $mensajes += count($value['messages'] ?? []);
                $estados  += count($value['statuses'] ?? []);
                $numero   = $value['metadata']['phone_number_id'] ?? $numero;
            }
        }

        return sprintf('mensajes=%d estados=%d numero=%s', $mensajes, $estados, $numero);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function rango(): array
    {
        $parse = function (?string $v): ?Carbon {
            if (! $v) {
                return null;
            }

            return Carbon::parse($v);
        };

        return [$parse($this->option('desde')), $parse($this->option('hasta'))];
    }
}
