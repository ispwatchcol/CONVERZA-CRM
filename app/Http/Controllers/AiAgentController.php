<?php

namespace App\Http\Controllers;

use App\Models\AiAgent;
use App\Models\Tenant;
use App\Services\Ai\Agents\AgentReply;
use App\Services\Ai\Agents\AgentResponder;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviders;
use App\Services\Ai\AiUnavailable;
use App\Services\Flows\Execution;
use App\Services\Flows\FlowGraph;
use App\Services\Flows\IO\SimulatedFlowIO;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agentes IA: la empresa escribe el prompt de cada agente, elige el modelo (de
 * la lista que le da SU proveedor con SU llave) y lo prueba en un chat antes de
 * ponerlo en un flujo con el bloque «Agente IA». Solo admin.
 *
 * El proveedor y la llave se configuran una vez en Configuración → Pagos e IA;
 * aquí solo se eligen el prompt y, si se quiere, otro modelo para cada agente.
 */
class AiAgentController extends Controller
{
    /** Por qué la IA no está disponible, dicho para esta pantalla. */
    public const REASONS = [
        'ia_apagada_global'   => 'La IA está pausada en Converza.',
        'ia_apagada'          => 'La IA está apagada para tu empresa: enciéndela en Configuración → Pagos e IA.',
        'sin_llave'           => 'Falta elegir tu proveedor y cargar tu llave en Configuración → Pagos e IA.',
        'presupuesto_agotado' => 'Se agotó el presupuesto de IA del mes (Configuración → Pagos e IA).',
    ];

    /** Lo que un id de modelo puede traer (openai/gpt-5-mini, models/gemini-2.5-flash, llama3:8b…). */
    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/@+-]{1,120}$/';

    public function index(AiGateway $ai): Response
    {
        $tenant = app('tenant');

        return Inertia::render('Agents/Index', [
            'agents' => AiAgent::where('tenant_id', $tenant->id)->orderBy('name')
                ->get(['id', 'name', 'instructions', 'model', 'is_active', 'updated_at']),
            'ai' => $this->aiStatus($tenant, $ai),
            'variables' => collect(Execution::BUILTINS)
                ->except(['respuesta', 'opcion'])
                ->map(fn ($description, $name) => ['name' => $name, 'description' => $description])
                ->values(),
            'limits' => [
                'max_instructions' => (int) config('ai.agents.max_instructions_chars', 12000),
            ],
        ]);
    }

    /** Los modelos que la llave de la empresa puede usar, para elegir el de un agente. */
    public function models(AiProviders $providers): JsonResponse
    {
        $provider = $providers->forTenant(app('tenant'));

        if ($provider === null) {
            return response()->json(['ok' => false, 'models' => [], 'message' => self::REASONS['sin_llave']]);
        }

        try {
            return response()->json(['ok' => true, 'models' => $provider->models()]);
        } catch (AiProviderException $e) {
            return response()->json(['ok' => false, 'models' => [], 'message' => 'No se pudo traer la lista de modelos: ' . $e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        $agent = AiAgent::create($this->validated($request) + ['tenant_id' => app('tenant')->id]);

        return back()->with('success', "Agente «{$agent->name}» creado. Pruébalo en el chat y úsalo en un flujo con el bloque «Agente IA».");
    }

    public function update(Request $request, AiAgent $agent)
    {
        $this->authorizeAgent($agent);

        $agent->update($this->validated($request));

        return back()->with('success', 'Agente guardado. Los flujos publicados lo usan desde su próxima respuesta.');
    }

    public function destroy(AiAgent $agent)
    {
        $this->authorizeAgent($agent);

        // Un flujo publicado que lo use sale por «IA no disponible» (ver AgentNode).
        $agent->delete();

        return back()->with('success', 'Agente eliminado. Si un flujo lo usaba, ahora sale por «IA no disponible»: cámbialo en el flujo.');
    }

    /**
     * El chat de prueba: responde con la IA de verdad (cuesta como una
     * conversación real) usando lo que está en el formulario, sin guardarlo. No
     * se envía nada por WhatsApp. Las variables del flujo que dependen de un
     * cliente real (cliente.*, respuestas guardadas) quedan vacías.
     */
    public function test(Request $request, AgentResponder $responder): JsonResponse
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'instructions'      => ['required', 'string', 'max:' . (int) config('ai.agents.max_instructions_chars', 12000)],
            'model'             => ['nullable', 'string', 'regex:' . self::MODEL_PATTERN],
            'history'           => ['required', 'array', 'min:1', 'max:40'],
            'history.*.role'    => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $ex = new Execution(
            FlowGraph::fromArray(null),
            new SimulatedFlowIO($tenant, ['name' => 'Cliente de prueba', 'phone' => null], [], true, [], [], app(IspwatchRepository::class)),
            (string) config('flows.default_timezone', 'America/Bogota'),
        );

        $instructions = AgentResponder::instructions($ex->render($data['instructions']), [
            'empresa'  => $ex->get('empresa.nombre'),
            'contacto' => $ex->get('contacto.nombre'),
            'dia'      => $ex->get('fecha.dia'),
            'fecha'    => $ex->get('fecha.hoy'),
            'hora'     => $ex->get('fecha.hora'),
        ]);

        try {
            $reply = $responder->reply($tenant, $instructions, $data['history'], $data['model'] ?? null, purpose: 'agent_test');
        } catch (AiUnavailable $e) {
            return response()->json(['message' => self::REASONS[$e->reason] ?? $e->reason], 422);
        } catch (AiProviderException $e) {
            return response()->json(['message' => 'La IA no respondió: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'reply'  => $reply->text,
            'action' => $reply->action,
            'model'  => $reply->model,
            'exit'   => match ($reply->action) {
                AgentReply::HUMAN => 'En un flujo, aquí saldría por «Pasar a un asesor».',
                AgentReply::END   => 'En un flujo, aquí saldría por «Conversación resuelta».',
                default           => null,
            },
        ]);
    }

    /** @return array<string, mixed> */
    private function aiStatus(Tenant $tenant, AiGateway $ai): array
    {
        $reason = $ai->unavailableReason($tenant);

        return [
            'ready'          => $reason === null,
            'reason'         => $reason !== null ? (self::REASONS[$reason] ?? $reason) : null,
            'provider'       => $tenant->ai_provider,
            'provider_label' => $tenant->ai_provider ? (string) config("ai.{$tenant->ai_provider}.label", $tenant->ai_provider) : null,
            'model'          => $tenant->hasAiKey() ? $tenant->aiModel() : null,
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80'],
            'instructions' => ['required', 'string', 'max:' . (int) config('ai.agents.max_instructions_chars', 12000)],
            'model'        => ['nullable', 'string', 'regex:' . self::MODEL_PATTERN],
            'is_active'    => ['required', 'boolean'],
        ], [
            'model.regex' => 'Ese no parece el nombre de un modelo. Elígelo de la lista.',
        ]);

        $data['name']  = trim($data['name']);
        $data['model'] = filled($data['model'] ?? null) ? trim($data['model']) : null;

        return $data;
    }

    /** El binding corre antes de ResolveTenant: la pertenencia se mira a mano. */
    private function authorizeAgent(AiAgent $agent): void
    {
        abort_if((int) $agent->tenant_id !== (int) app('tenant')->id, 404);
    }
}
