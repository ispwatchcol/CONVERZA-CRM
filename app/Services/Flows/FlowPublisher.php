<?php

namespace App\Services\Flows;

use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\BotFlowVersion;
use App\Services\Flows\Nodes\StartNode;
use App\Services\Flows\Validation\ValidationContext;
use Illuminate\Support\Facades\DB;

/**
 * Publicar, encender, apagar y restaurar un flujo. Vive aparte del controlador
 * porque cada operación tiene reglas que no pueden saltarse según de dónde se
 * llame (la UI, un comando, una prueba).
 */
final class FlowPublisher
{
    public function __construct(
        private readonly FlowValidator $validator,
        private readonly FlowRunner $runner,
    ) {}

    /**
     * Valida el borrador y, si no tiene errores, lo congela como versión nueva.
     * Las ejecuciones en curso siguen con la versión con la que arrancaron.
     *
     * @return array{ok: bool, version: ?BotFlowVersion, errors: list<array>, warnings: list<array>}
     */
    public function publish(BotFlow $flow, ?int $userId, ?string $note = null): array
    {
        $result = $this->validator->validate($flow->draft, ValidationContext::forTenant($flow->tenant_id));

        if ($result['errors'] !== []) {
            return ['ok' => false, 'version' => null] + $result;
        }

        $graph   = FlowGraph::normalize($flow->draft);
        $trigger = StartNode::triggerOf(FlowGraph::fromArray($graph)->start()['data'] ?? []);
        $note    = trim((string) $note) !== ''
            ? mb_substr(trim((string) $note), 0, 255)
            : ($flow->draft_source_version ? "Restaurada desde la v{$flow->draft_source_version}" : null);

        $version = DB::transaction(function () use ($flow, $graph, $trigger, $userId, $note) {
            // Serializa dos publicaciones simultáneas del mismo flujo: el número
            // de versión es único por flujo.
            BotFlow::withoutGlobalScopes()->whereKey($flow->id)->lockForUpdate()->first();

            $number = (int) BotFlowVersion::withoutGlobalScopes()->where('bot_flow_id', $flow->id)->max('version') + 1;

            $version = BotFlowVersion::create([
                'tenant_id'    => $flow->tenant_id,
                'bot_flow_id'  => $flow->id,
                'version'      => $number,
                'graph'        => $graph,
                'note'         => $note,
                'published_by' => $userId,
                'published_at' => now(),
            ]);

            $flow->forceFill([
                'published_version_id' => $version->id,
                'trigger_type'         => $trigger['type'],
                'trigger_config'       => $trigger['config'],
                'draft'                => $graph,
                'draft_source_version' => null,
            ])->save();

            return $version;
        });

        $flow->setRelation('publishedVersion', $version);

        return ['ok' => true, 'version' => $version] + $result;
    }

    /**
     * Enciende o apaga el flujo.
     *
     * Apagar corta las ejecuciones en curso y le deja al equipo una nota en
     * cada conversación afectada: el cliente puede estar a mitad de una
     * pregunta, y a partir de ahora nadie más le va a contestar si no lo hace
     * un asesor.
     *
     * @return int Ejecuciones cortadas.
     */
    public function setActive(BotFlow $flow, bool $active): int
    {
        if ($active) {
            if (! $flow->published_version_id) {
                throw new \DomainException('Publica el flujo antes de activarlo.');
            }

            $flow->forceFill(['is_active' => true])->save();

            return 0;
        }

        $flow->forceFill(['is_active' => false])->save();

        $cancelled = 0;

        BotFlowRun::withoutGlobalScopes()
            ->where('tenant_id', $flow->tenant_id)
            ->where('bot_flow_id', $flow->id)
            ->live()
            ->chunkById(100, function ($runs) use (&$cancelled) {
                foreach ($runs as $run) {
                    $this->runner->close(
                        $run,
                        BotFlowRun::STATUS_CANCELLED,
                        'flow_deactivated',
                        '🤖 Se apagó el flujo que atendía esta conversación. El cliente puede estar esperando respuesta.',
                    );
                    $cancelled++;
                }
            });

        return $cancelled;
    }

    /** Lleva el grafo de una versión vieja al borrador. No publica: eso lo decide el admin. */
    public function restore(BotFlow $flow, BotFlowVersion $version): void
    {
        $flow->forceFill([
            'draft'                => FlowGraph::normalize($version->graph),
            'draft_updated_at'     => now(),
            'draft_source_version' => $version->version,
        ])->save();
    }
}
