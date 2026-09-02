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
 *
 * ── Por qué una marca de agua y no una ventana fija ──────────────────────────
 *
 * Hasta el 02/09/2026 esto miraba `now() - 60 min` en cada tic. Cubría un corte
 * breve y nada más: si el sistema estuvo caído más de una hora, al recuperarse la
 * ventana ya no alcanzaba el principio del corte y esos mensajes quedaban en el
 * log crudo para siempre. Peor, en silencio — el `Log::error` de abajo solo salta
 * cuando se ENCUENTRAN huecos, y no se encontraban justamente porque no se
 * llegaba a mirarlos. La documentación decía que un corte largo "se cubre
 * corriendo webhooks:replay", lo cual supone que alguien sabe que hubo un corte.
 *
 * Lo encontramos auditando un mensaje de Chaguani del 28/08 que nunca apareció en
 * el panel (CON-66/CON-67), y ya había precedente: 18 mensajes el 20/08/2026.
 *
 * Ahora cada corrida con éxito deja una marca en disco y la siguiente arranca de
 * ahí. Un corte de seis horas se repara solo al volver, sin que nadie intervenga.
 *
 * La marca va en disco, no en base ni en Redis, por lo mismo que el log crudo:
 * es lo único que sobrevive al fallo que este comando existe para reparar. Si la
 * BD está caída el comando revienta antes de escribirla, que es exactamente lo
 * que queremos —la marca se queda donde estaba y al volver cubrimos el hueco.
 */
class ReconcileWebhooks extends Command
{
    protected $signature = 'webhooks:reconcile
        {--minutos= : Ventana fija hacia atrás; si se omite, se arranca desde la marca de agua}
        {--gracia=3 : Ignorar eventos más recientes que esto, por si siguen en cola}
        {--max-horas=24 : Tope de cuánto puede estirarse la marca de agua hacia atrás}
        {--dry-run : Reporta los huecos sin despachar nada}';

    protected $description = 'Reprocesa los mensajes entrantes que quedaron en el log crudo pero no llegaron a la base';

    /** Minutos sin corrida exitosa a partir de los cuales asumimos que el sistema estuvo caído. */
    private const UMBRAL_CAIDA = 15;

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $seco     = (bool) $this->option('dry-run');
        $maxHoras = max(1, (int) $this->option('max-horas'));
        $hasta    = now()->subMinutes((int) $this->option('gracia'));

        [$desde, $origen] = $this->desde($maxHoras);

        $this->line(sprintf(
            'Ventana: %s → %s  (%s)',
            $desde->format('Y-m-d H:i:s'),
            $hasta->format('Y-m-d H:i:s'),
            $origen
        ));

        if ($desde->gte($hasta)) {
            // Puede pasar si la marca quedó adelantada (reloj movido, corrida
            // manual con --minutos pequeño). No es un error: no hay nada nuevo.
            $this->info('La marca de agua ya cubre hasta ahora. Nada que reconciliar.');

            return self::SUCCESS;
        }

        $candidatos = [];

        foreach ($this->archivos($desde, $hasta) as $ruta) {
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
            $this->avanzarMarca($hasta, $seco);

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
            $this->avanzarMarca($hasta, $seco);

            return self::SUCCESS;
        }

        // A nivel error para que aparezca con LOG_LEVEL=warning y quede visible:
        // que existan huecos significa que algo falló y nadie se enteró.
        Log::error('Reconciliación de webhooks: se encontraron mensajes sin guardar', [
            'huecos'       => $huecos,
            'reprocesados' => $reprocesados,
            'ventana_min'  => (int) $desde->diffInMinutes($hasta),
            'origen'       => $origen,
            'dry_run'      => $seco,
        ]);

        $this->error("Huecos encontrados: {$huecos}");

        if (! $seco) {
            $this->info("Jobs despachados para repararlos: {$reprocesados}");
        }

        // La marca avanza igual: los huecos ya se despacharon, y no avanzarla
        // haría que la siguiente corrida los volviera a "encontrar" —los jobs
        // todavía no habrán persistido— y alertara dos veces por lo mismo.
        $this->avanzarMarca($hasta, $seco);

        return self::SUCCESS;
    }

    /**
     * Inicio de la ventana, y de dónde salió.
     *
     * Prioridad: `--minutos` explícito > marca de agua > default de 60 min. El
     * tope de `--max-horas` existe para que una marca muy vieja (servidor
     * apagado un fin de semana) no dispare un escaneo de días enteros de log en
     * un tic de cinco minutos.
     *
     * @return array{0: Carbon, 1: string}
     */
    private function desde(int $maxHoras): array
    {
        $tope = now()->subHours($maxHoras);

        if ($this->option('minutos') !== null) {
            $minutos = max(1, (int) $this->option('minutos'));

            return [now()->subMinutes($minutos), "ventana fija de {$minutos} min"];
        }

        $marca = $this->leerMarca();

        if ($marca === null) {
            // Primera corrida, o marca ilegible. El default de siempre.
            return [now()->subMinutes(60), 'sin marca previa, default de 60 min'];
        }

        $inactividad = (int) $marca->diffInMinutes(now());

        if ($inactividad >= self::UMBRAL_CAIDA) {
            // Esta es la alerta que faltaba. Si la reconciliación no corrió
            // durante N minutos es que el scheduler —y por tanto el servidor o
            // la base— estuvo caído. Antes ese silencio era indistinguible de
            // "no escribió nadie", y era justo el caso en que se perdían
            // mensajes sin que nadie se enterara.
            Log::error('Reconciliación de webhooks: no corrió durante un rato largo; el sistema pudo estar caído', [
                'ultima_corrida'    => $marca->toDateTimeString(),
                'inactividad_min'   => $inactividad,
                'umbral_min'        => self::UMBRAL_CAIDA,
            ]);

            $this->warn(sprintf(
                'No se reconciliaba desde hace %d min (última: %s). El sistema pudo estar caído.',
                $inactividad,
                $marca->toDateTimeString()
            ));
        }

        if ($marca->lt($tope)) {
            // Más allá del tope no miramos, pero lo decimos fuerte: puede haber
            // mensajes sin recuperar antes de esta hora, y para eso está replay.
            Log::error('Reconciliación de webhooks: la marca de agua excede el tope; puede quedar un hueco sin reparar', [
                'marca'      => $marca->toDateTimeString(),
                'tope'       => $tope->toDateTimeString(),
                'max_horas'  => $maxHoras,
                'sugerencia' => 'php artisan webhooks:replay --fecha=... --desde=... --hasta=...',
            ]);

            $this->error(sprintf(
                'La marca (%s) es más vieja que el tope de %dh. Revisá con webhooks:replay el tramo anterior a %s.',
                $marca->toDateTimeString(),
                $maxHoras,
                $tope->toDateTimeString()
            ));

            return [$tope, "marca de agua recortada al tope de {$maxHoras}h"];
        }

        return [$marca, 'marca de agua'];
    }

    private function rutaMarca(): string
    {
        return storage_path('app/webhooks-reconcile.marca');
    }

    private function leerMarca(): ?Carbon
    {
        $ruta = $this->rutaMarca();

        if (! is_file($ruta)) {
            return null;
        }

        try {
            $crudo = trim((string) file_get_contents($ruta));

            return $crudo === '' ? null : Carbon::parse($crudo);
        } catch (\Throwable $e) {
            Log::warning('Reconciliación de webhooks: no se pudo leer la marca de agua', [
                'ruta'  => $ruta,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Deja la marca donde terminó esta corrida.
     *
     * En modo seco NO se toca: `--dry-run` es lo que se corre a mano durante un
     * incidente, y avanzar la marca ahí le robaría la ventana a la corrida
     * automática siguiente, que sí despacha.
     *
     * Un fallo al escribir no aborta nada: se pierde el avance y la próxima
     * corrida repite trabajo ya hecho, que es idempotente.
     */
    private function avanzarMarca(Carbon $hasta, bool $seco): void
    {
        if ($seco) {
            $this->comment('Modo seco: la marca de agua no se mueve.');

            return;
        }

        $ruta = $this->rutaMarca();

        try {
            if (! is_dir(dirname($ruta))) {
                mkdir(dirname($ruta), 0775, true);
            }

            file_put_contents($ruta, $hasta->toDateTimeString().PHP_EOL, LOCK_EX);
        } catch (\Throwable $e) {
            Log::warning('Reconciliación de webhooks: no se pudo escribir la marca de agua', [
                'ruta'  => $ruta,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rutas de log que cubren la ventana, un archivo por día.
     *
     * Antes esto devolvía solo hoy + el día de `$desde`, que alcanzaba para una
     * ventana de 60 minutos y se rompía en silencio con cualquiera más larga: con
     * la marca de agua la ventana puede cruzar varios días.
     *
     * @return list<string>
     */
    private function archivos(Carbon $desde, Carbon $hasta): array
    {
        $rutas = [];

        for ($dia = $desde->copy()->startOfDay(); $dia->lte($hasta); $dia->addDay()) {
            $ruta = storage_path("logs/webhook-raw-{$dia->format('Y-m-d')}.log");

            if (is_file($ruta)) {
                $rutas[] = $ruta;
            }
        }

        return $rutas;
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
