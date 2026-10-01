<?php

namespace App\Http\Controllers;

use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\BotFlowStep;
use App\Models\BotFlowVersion;
use App\Models\BotSetting;
use App\Models\Label;
use App\Models\Team;
use App\Services\Flows\Execution;
use App\Services\Flows\FlowGraph;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\FlowSimulator;
use App\Services\Flows\FlowTemplates;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\Nodes\CustomerLookupNode;
use App\Services\Flows\Nodes\StartNode;
use App\Services\Flows\Validation\ValidationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El Workspace de flujos del bot (CON-48). Solo el admin del tenant entra:
 * un flujo decide qué se le dice a TODOS los clientes del ISP.
 *
 * El editor habla por JSON (guardar, validar, publicar, simular) para no
 * recargar el lienzo en cada acción; la lista usa Inertia como el resto de la
 * app.
 */
class FlowController extends Controller
{
    /** Tope duro del borrador guardado, independiente del validador: protege la base. */
    private const MAX_DRAFT_BYTES = 512 * 1024;

    public function __construct(
        private readonly FlowValidator $validator,
        private readonly FlowPublisher $publisher,
        private readonly FlowSimulator $simulator,
        private readonly FlowTemplates $templates,
    ) {}

    public function index(): Response
    {
        $tenant = app('tenant');

        $flows = BotFlow::where('tenant_id', $tenant->id)
            ->with('publishedVersion')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $since = now()->subDays(7);

        $byStatus = BotFlowRun::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->selectRaw('bot_flow_id, status, count(*) as n')
            ->groupBy('bot_flow_id', 'status')
            ->get()
            ->groupBy('bot_flow_id');

        // "No entendí" por flujo: la medida directa de si el menú sirve.
        $noMatch = BotFlowStep::withoutGlobalScopes()
            ->join('bot_flow_runs', 'bot_flow_runs.id', '=', 'bot_flow_steps.bot_flow_run_id')
            ->where('bot_flow_steps.tenant_id', $tenant->id)
            ->where('bot_flow_steps.created_at', '>=', $since)
            ->where('bot_flow_steps.outcome', 'no_match')
            ->selectRaw('bot_flow_runs.bot_flow_id, count(*) as n')
            ->groupBy('bot_flow_runs.bot_flow_id')
            ->pluck('n', 'bot_flow_id');

        return Inertia::render('Flows/Index', [
            'flows' => $flows->map(function (BotFlow $flow) use ($byStatus, $noMatch) {
                $counts = collect($byStatus->get($flow->id, []))->pluck('n', 'status');

                return [
                    'id'                      => $flow->id,
                    'name'                    => $flow->name,
                    'description'             => $flow->description,
                    'is_active'               => $flow->is_active,
                    'version'                 => $flow->publishedVersion?->version,
                    'published_at'            => $flow->publishedVersion?->published_at?->toIso8601String(),
                    'has_unpublished_changes' => $flow->hasUnpublishedChanges(),
                    'trigger'                 => $this->triggerSummary($flow),
                    'updated_at'              => $flow->updated_at?->toIso8601String(),
                    'stats'                   => [
                        'runs'       => (int) $counts->sum(),
                        'handed_off' => (int) ($counts[BotFlowRun::STATUS_HANDED_OFF] ?? 0),
                        'completed'  => (int) ($counts[BotFlowRun::STATUS_COMPLETED] ?? 0),
                        'failed'     => (int) ($counts[BotFlowRun::STATUS_FAILED] ?? 0),
                        'live'       => (int) collect(BotFlowRun::LIVE_STATUSES)->sum(fn ($s) => $counts[$s] ?? 0),
                        'no_match'   => (int) ($noMatch[$flow->id] ?? 0),
                    ],
                ];
            })->values(),
            'templates' => FlowTemplates::catalog(),
            'legacyBot' => [
                'enabled' => (bool) BotSetting::where('tenant_id', $tenant->id)->value('bot_enabled'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'template' => ['required', 'string', Rule::in(array_column(FlowTemplates::catalog(), 'key'))],
        ]);

        $flow = BotFlow::create([
            'tenant_id'        => $tenant->id,
            'name'             => $data['name'],
            'draft'            => $this->templates->build($data['template'], $tenant),
            'draft_updated_at' => now(),
            'is_active'        => false,
            'priority'         => (int) BotFlow::where('tenant_id', $tenant->id)->max('priority') + 1,
        ]);

        return redirect()->route('flows.edit', $flow)->with('success', 'Flujo creado. Ajústalo, pruébalo en el simulador y publícalo.');
    }

    public function edit(BotFlow $flow): Response
    {
        $this->authorizeFlow($flow);
        $tenant = app('tenant');

        return Inertia::render('Flows/Editor', [
            'flow'     => $this->flowPayload($flow),
            'graph'    => FlowGraph::normalize($flow->draft),
            'versions' => $this->versionsPayload($flow),
            'catalog'  => [
                'labels'         => Label::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'color']),
                'teams'          => Team::where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name']),
                'timezones'      => BotSettingsController::TIMEZONES,
                'variables'      => array_merge(
                    collect(Execution::BUILTINS)->map(fn ($d, $k) => ['name' => $k, 'description' => $d, 'group' => 'Siempre disponibles'])->values()->all(),
                    collect(CustomerLookupNode::VARIABLES)->map(fn ($d, $k) => ['name' => $k, 'description' => $d, 'group' => 'Después de «Datos del cliente»'])->values()->all(),
                ),
                'limits'         => [
                    'max_wait_minutes' => (int) config('flows.max_wait_minutes'),
                    'max_nodes'        => (int) config('flows.max_nodes'),
                    'input_timeout_hours' => (int) config('flows.input_timeout_hours'),
                ],
                'ispwatchLinked' => (bool) $tenant->ispwatch_tenant_id,
                'autoAssign'     => (bool) $tenant->auto_assign_enabled,
            ],
        ]);
    }

    /** Guarda el borrador. No toca lo publicado: el motor ni se entera. */
    public function update(Request $request, BotFlow $flow): JsonResponse
    {
        $this->authorizeFlow($flow);

        $data = $request->validate([
            'name'        => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'graph'       => ['sometimes', 'array'],
            'graph.nodes' => ['sometimes', 'array', 'max:300'],
            'graph.edges' => ['sometimes', 'array', 'max:600'],
        ]);

        if (array_key_exists('graph', $data)) {
            $graph = FlowGraph::normalize($data['graph']);

            if (strlen((string) json_encode($graph)) > self::MAX_DRAFT_BYTES) {
                return response()->json(['message' => 'El flujo es demasiado grande para guardarlo. Divídelo en varios.'], 422);
            }

            $flow->draft            = $graph;
            $flow->draft_updated_at = now();
        }

        $flow->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();

        return response()->json([
            'flow' => $this->flowPayload($flow->fresh(['publishedVersion'])),
        ]);
    }

    /** Valida el grafo del editor (aunque no esté guardado) sin publicar nada. */
    public function validateDraft(Request $request, BotFlow $flow): JsonResponse
    {
        $this->authorizeFlow($flow);

        $data = $request->validate(['graph' => ['nullable', 'array']]);

        return response()->json($this->validator->validate(
            $data['graph'] ?? $flow->draft,
            ValidationContext::forTenant($flow->tenant_id),
        ));
    }

    public function publish(Request $request, BotFlow $flow): JsonResponse
    {
        $this->authorizeFlow($flow);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $result = $this->publisher->publish($flow, $request->user()?->id, $data['note'] ?? null);

        if (! $result['ok']) {
            return response()->json([
                'message'  => 'El flujo tiene errores: corrígelos antes de publicar.',
                'errors'   => $result['errors'],
                'warnings' => $result['warnings'],
            ], 422);
        }

        return response()->json([
            'message'  => "Publicada la versión {$result['version']->version}."
                . ($flow->is_active ? ' Las conversaciones nuevas ya la usan.' : ' Enciende el flujo para que empiece a atender.'),
            'flow'     => $this->flowPayload($flow->fresh(['publishedVersion'])),
            'versions' => $this->versionsPayload($flow),
            'warnings' => $result['warnings'],
        ]);
    }

    public function toggle(Request $request, BotFlow $flow)
    {
        $this->authorizeFlow($flow);

        $data = $request->validate(['active' => ['required', 'boolean']]);

        try {
            $cancelled = $this->publisher->setActive($flow, (bool) $data['active']);
        } catch (\DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        $message = $data['active']
            ? "«{$flow->name}» está atendiendo."
            : "«{$flow->name}» se apagó." . ($cancelled > 0 ? " {$cancelled} conversación(es) en curso pasaron al equipo." : '');

        return $request->wantsJson()
            ? response()->json(['message' => $message, 'flow' => $this->flowPayload($flow->fresh(['publishedVersion']))])
            : back()->with('success', $message);
    }

    public function duplicate(BotFlow $flow)
    {
        $this->authorizeFlow($flow);

        $copy = BotFlow::create([
            'tenant_id'        => $flow->tenant_id,
            'name'             => mb_substr('Copia de ' . $flow->name, 0, 120),
            'description'      => $flow->description,
            'draft'            => $flow->draft,
            'draft_updated_at' => now(),
            'is_active'        => false,
            'priority'         => (int) BotFlow::where('tenant_id', $flow->tenant_id)->max('priority') + 1,
        ]);

        return redirect()->route('flows.edit', $copy)->with('success', 'Copia creada. Está apagada hasta que la publiques y la enciendas.');
    }

    public function destroy(BotFlow $flow)
    {
        $this->authorizeFlow($flow);

        if ($flow->is_active) {
            return back()->with('error', 'Apaga el flujo antes de borrarlo.');
        }

        // Una ejecución viva de un flujo apagado no debería existir (apagar las
        // corta), pero si quedara una, borrarla en cascada dejaría la
        // conversación con bot_active = true sin nadie que la atienda.
        $this->publisher->setActive($flow, false);
        $flow->delete();

        return redirect()->route('flows.index')->with('success', 'Flujo eliminado.');
    }

    public function restore(BotFlow $flow, BotFlowVersion $version): JsonResponse
    {
        $this->authorizeFlow($flow);
        abort_if($version->bot_flow_id !== $flow->id, 404);

        $this->publisher->restore($flow, $version);

        return response()->json([
            'message' => "El borrador ahora es la versión {$version->version}. Publícalo para que vuelva a atender.",
            'graph'   => FlowGraph::normalize($flow->draft),
            'flow'    => $this->flowPayload($flow->fresh(['publishedVersion'])),
        ]);
    }

    /** El simulador: ejecuta el grafo del editor sin enviar nada por WhatsApp. */
    public function simulate(Request $request, BotFlow $flow): JsonResponse
    {
        $this->authorizeFlow($flow);

        $data = $request->validate([
            'graph'       => ['required', 'array'],
            'state'       => ['nullable', 'array'],
            'text'        => ['nullable', 'string', 'max:1000'],
            'reply_id'    => ['nullable', 'string', 'max:64'],
            'skip_wait'   => ['nullable', 'boolean'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'name'        => ['nullable', 'string', 'max:120'],
            'window_open' => ['nullable', 'boolean'],
            'labels'      => ['nullable', 'array', 'max:50'],
            'labels.*'    => ['integer'],
        ]);

        if (strlen((string) json_encode($data['state'] ?? [])) > 64 * 1024) {
            return response()->json(['message' => 'Estado de simulación inválido. Reinicia la simulación.'], 422);
        }

        $result = $this->simulator->run(
            app('tenant'),
            $data['graph'],
            $data['state'] ?? null,
            $data,
            ValidationContext::forTenant($flow->tenant_id),
        );

        return isset($result['error'])
            ? response()->json(['message' => $result['error']], 422)
            : response()->json($result);
    }

    /** Actividad: las últimas ejecuciones con su traza, para diagnosticar sin adivinar. */
    public function runs(Request $request, BotFlow $flow): Response
    {
        $this->authorizeFlow($flow);

        $status = $request->query('status');

        $runs = BotFlowRun::where('tenant_id', $flow->tenant_id)
            ->where('bot_flow_id', $flow->id)
            ->when(in_array($status, ['live', 'completed', 'handed_off', 'cancelled', 'failed'], true), fn ($q) => $status === 'live'
                ? $q->live()
                : $q->where('status', $status))
            ->with([
                'version:id,version',
                'contact:id,name,phone,wa_username',
                'steps' => fn ($q) => $q->with('message:id,status,raw_metadata'),
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Flows/Runs', [
            'flow'   => ['id' => $flow->id, 'name' => $flow->name, 'is_active' => $flow->is_active],
            'status' => $status,
            'runs'   => $runs->map(fn (BotFlowRun $run) => [
                'id'              => $run->id,
                'conversation_id' => $run->conversation_id,
                'contact'         => $run->contact?->display_name,
                'version'         => $run->version?->version,
                'status'          => $run->status,
                'ended_reason'    => $run->ended_reason,
                'steps_count'     => $run->steps_count,
                'variables'       => $run->variables,
                'started_at'      => $run->created_at?->toIso8601String(),
                'ended_at'        => $run->ended_at?->toIso8601String(),
                'steps'           => $run->steps->map(fn (BotFlowStep $step) => [
                    'id'         => $step->id,
                    'node_id'    => $step->node_id,
                    'node_type'  => $step->node_type,
                    'outcome'    => $step->outcome,
                    'input'      => $step->input,
                    'output'     => $step->output,
                    'detail'     => $step->detail,
                    // Si Meta entregó, el cliente lo leyó o lo rechazó.
                    'delivery'   => $step->message?->status,
                    'failure'    => $step->message?->raw_metadata['failure']['title'] ?? null,
                    'created_at' => $step->created_at?->toIso8601String(),
                ])->values(),
            ])->values(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El binding de ruta corre ANTES de que ResolveTenant fije el tenant, así
     * que el global scope no filtra aquí: la pertenencia se comprueba a mano,
     * igual que en ChatController.
     */
    private function authorizeFlow(BotFlow $flow): void
    {
        abort_if($flow->tenant_id !== app('tenant')->id, 404);
    }

    private function flowPayload(BotFlow $flow): array
    {
        return [
            'id'                      => $flow->id,
            'name'                    => $flow->name,
            'description'             => $flow->description,
            'is_active'               => $flow->is_active,
            'version'                 => $flow->publishedVersion?->version,
            'has_unpublished_changes' => $flow->hasUnpublishedChanges(),
            'draft_source_version'    => $flow->draft_source_version,
            'draft_updated_at'        => $flow->draft_updated_at?->toIso8601String(),
        ];
    }

    private function versionsPayload(BotFlow $flow): array
    {
        return BotFlowVersion::where('tenant_id', $flow->tenant_id)
            ->where('bot_flow_id', $flow->id)
            ->with('publisher:id,name')
            ->orderByDesc('version')
            ->limit(30)
            ->get(['id', 'version', 'note', 'published_by', 'published_at'])
            ->map(fn (BotFlowVersion $v) => [
                'id'           => $v->id,
                'version'      => $v->version,
                'note'         => $v->note,
                'published_by' => $v->publisher?->name,
                'published_at' => $v->published_at?->toIso8601String(),
                'is_current'   => $v->id === $flow->published_version_id,
            ])
            ->all();
    }

    /** "Inicio de conversación · 12 h de silencio" / "Palabras: menú, hola". */
    private function triggerSummary(BotFlow $flow): string
    {
        $trigger = $flow->trigger_type
            ? ['type' => $flow->trigger_type, 'config' => (array) $flow->trigger_config]
            : StartNode::triggerOf(FlowGraph::fromArray($flow->draft)->start()['data'] ?? []);

        if ($trigger['type'] === BotFlow::TRIGGER_KEYWORD) {
            return 'Palabras clave: ' . implode(', ', array_slice($trigger['config']['keywords'] ?? [], 0, 6));
        }

        $idle = $trigger['config']['idle_hours'] ?? null;

        return 'Conversación nueva o reabierta' . ($idle ? " · o tras {$idle} h de silencio" : '');
    }
}
