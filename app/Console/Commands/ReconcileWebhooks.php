<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Services\WhatsApp\WebhookDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detecta mensajes entrantes que llegaron al servidor pero no acabaron en la BD,
 * y los reprocesa solos.
 *
 * `webhooks:replay` repara una caída, pero exige que alguien se acuerde de
 * ejecutarlo. Esto cierra el círculo: compara lo que dice el log crudo contra lo
 * que hay en `messages` y despacha lo que falte, sin intervención humana.
 *
 * Solo reconcilia eventos con `messages` (mensajes de clientes). Los `statuses`
 * quedan fuera a propósito: no hay forma barata de saber si un acuse concreto se
 * aplicó, y perder un "entregado" es cosmético mientras que perder un mensaje no.
 *
 * El periodo de gracia evita la carrera obvia: un webhook recibido hace diez
 * segundos puede estar todavía en la cola, y reprocesarlo no rompe nada
 * —wa_message_id es único— pero ensucia el diagnóstico con falsos huecos.
 */
class ReconcileWebhooks extends Command
{
    protected $signature = 'webhooks:reconcile
        {--minutos=60 : Cuánto hacia atrás mirar}
        {--gracia=3 : Ignorar eventos más recientes que esto, por si siguen en cola}
        {--dry-run : Reporta los huecos sin despachar nada}';

    protected $description = 'Reprocesa los mensajes entrantes que quedaron en el log crudo pero no llegaron a la base';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $seco   = (bool) $this->option('dry-run');
        $desde  = now()->subMinutes((int) $this->option('minutos'));
        $hasta  = now()->subMinutes((int) $this->option('gracia'));

        // Se leen hoy y ayer porque una ventana de 60 min a las 00:30 cruza
        // medianoche, y el log rota por día.
        $candidatos = [];

        foreach ($this->archivos($desde) as $ruta) {
            foreach ($this->eventos($ruta) as [$momento, $payload]) {
                if ($momento->lt($desde) || $momento->gt($hasta)) {
                    continue;
                }

                $ids = $this->idsEntrantes($payload);

                if ($ids === []) {
                    continue; // solo estados, o payload sin mensajes
                }

                $candidatos[] = ['momento' => $momento, 'payload' => $payload, 'ids' => $ids];
            }
        }

        if ($candidatos === []) {
            $this->info('Sin mensajes entrantes en la ventana. Nada que reconciliar.');

            return self::SUCCESS;
        }

        // Una sola consulta para todos los ids, en vez de una por evento.
        $todos      = array_merge(...array_column($candidatos, 'ids'));
        $existentes = Message::whereIn('wa_message_id', $todos)
            ->pluck('wa_message_id')
            ->flip();

        $huecos = 0;
        $reprocesados = 0;

        foreach ($candidatos as $c) {
            $faltantes = array_values(array_filter(
                $c['ids'],
                fn ($id) => ! $existentes->has($id)
            ));

            if ($faltantes === []) {
                continue;
            }

            $huecos += count($faltantes);

            $this->warn(sprintf(
                '  Hueco a las %s — %d mensaje(s) sin guardar',
                $c['momento']->format('Y-m-d H:i:s'),
                count($faltantes)
            ));

            if ($seco) {
                continue;
            }

            $r = $dispatcher->dispatch($c['payload']);
            $reprocesados += $r['dispatched'];
        }

        $this->newLine();
        $this->line('Eventos revisados: '.count($candidatos));
        $this->line('Mensajes esperados: '.count($todos));

        if ($huecos === 0) {
            $this->info('Sin huecos: todo lo que entró está guardado.');

            return self::SUCCESS;
        }

        // A nivel error para que aparezca con LOG_LEVEL=warning y quede visible:
        // que existan huecos significa que algo falló y nadie se enteró.
        Log::error('Reconciliación de webhooks: se encontraron mensajes sin guardar', [
            'huecos'       => $huecos,
            'reprocesados' => $reprocesados,
            'ventana_min'  => (int) $this->option('minutos'),
            'dry_run'      => $seco,
        ]);

        $this->error("Huecos encontrados: {$huecos}");

        if (! $seco) {
            $this->info("Jobs despachados para repararlos: {$reprocesados}");
        }

        return self::SUCCESS;
    }

    /** @return list<string> rutas de log que cubren la ventana */
    private function archivos(Carbon $desde): array
    {
        $dias = [now()->format('Y-m-d')];

        if ($desde->format('Y-m-d') !== $dias[0]) {
            $dias[] = $desde->format('Y-m-d');
        }

        return array_values(array_filter(array_map(
            fn ($d) => storage_path("logs/webhook-raw-{$d}.log"),
            $dias
        ), 'is_file'));
    }

    /** @return \Generator<array{0: Carbon, 1: array}> */
    private function eventos(string $ruta): \Generator
    {
        $fh = fopen($ruta, 'r');

        while (($linea = fgets($fh)) !== false) {
            if (! preg_match('/^\[([\d-]+ [\d:]+)\][^{]*(\{.*\})$/', trim($linea), $m)) {
                continue;
            }

            $contexto = json_decode($m[2], true);

            if (! is_array($contexto) || ! isset($contexto['body'])) {
                continue;
            }

            $payload = json_decode((string) $contexto['body'], true);

            if (! is_array($payload)) {
                continue;
            }

            try {
                yield [Carbon::parse($m[1]), $payload];
            } catch (\Throwable) {
                continue;
            }
        }

        fclose($fh);
    }

    /** @return list<string> wa_message_id de los mensajes ENTRANTES del payload */
    private function idsEntrantes(array $payload): array
    {
        $ids = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $mensaje) {
                    if (isset($mensaje['id'])) {
                        $ids[] = (string) $mensaje['id'];
                    }
                }
            }
        }

        return $ids;
    }
}
