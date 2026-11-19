<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\User;
use App\Services\Flows\GraphBuilder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Flows\FlowsTestCase;

/**
 * Base de las pruebas del agente IA. La IA es falsa: xAI (Responses API)
 * contesta, en orden, los textos de `$replies`. WhatsApp también es falso (lo
 * de FlowsTestCase). La empresa tiene la IA encendida con SU llave.
 */
abstract class AgentTestCase extends FlowsTestCase
{
    /** @var list<string> Lo que va contestando la IA, en orden. */
    public array $replies = [];

    public int $aiStatus = 200;

    protected AiAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.enabled', true);
        config()->set('ai.xai.base_url', 'https://api.x.ai/v1');
        config()->set('ai.xai.model', 'grok-4.3');
        config()->set('ai.xai.reasoning_effort', 'low');

        $this->tenant->update(['ai_enabled' => true, 'ai_provider' => 'xai', 'ai_api_key' => 'xai-de-la-empresa', 'ai_model' => 'grok-4.3-mini']);

        $this->agent = AiAgent::create([
            'tenant_id'    => $this->tenant->id,
            'name'         => 'Ventas',
            'instructions' => "Vendes internet de {{empresa.nombre}}. Planes: 20 megas a $60.000 y 50 megas a $90.000.\nMedios de pago:\n{{empresa.medios_pago}}",
            'model'        => null,
            'is_active'    => true,
        ]);
    }

    /**
     * Respuestas de otros proveedores, por prefijo de URL: [status, cuerpo] en
     * orden. (Un Http::fake() en la prueba no sirve: el de FlowsTestCase se
     * registró antes y contesta todo.)
     *
     * @var array<string, list<array{0: int, 1: array}>>
     */
    public array $otherApis = [];

    protected function fakeExternal(HttpRequest $request): mixed
    {
        foreach ($this->otherApis as $prefix => $queue) {
            if (str_starts_with($request->url(), $prefix) && $queue !== []) {
                [$status, $body] = array_shift($this->otherApis[$prefix]);

                return Http::response($body, $status);
            }
        }

        if (! str_starts_with($request->url(), 'https://api.x.ai/')) {
            return null;
        }

        if ($this->aiStatus !== 200) {
            return Http::response(['error' => ['message' => 'falla de prueba']], $this->aiStatus);
        }

        $text = array_shift($this->replies) ?? 'Respuesta de prueba';

        return Http::response([
            'status' => 'completed',
            'model'  => (string) ($request['model'] ?? 'grok'),
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]],
            'usage'  => ['input_tokens' => 900, 'output_tokens' => 60, 'cost_in_usd_ticks' => 12_000_000],
        ]);
    }

    /** Un flujo: el agente conversa; si pide un asesor o no hay IA, traspaso; si resolvió, fin. */
    protected function agentFlow(array $data = []): GraphBuilder
    {
        return (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start'], 'timezone' => 'America/Bogota'])
            ->node('agente', 'ai_agent', array_merge(['agent_id' => $this->agent->id, 'greeting' => '', 'max_turns' => 10], $data))
            ->node('asesor', 'handoff', ['text' => '', 'assign' => 'none', 'note' => true])
            ->node('sin_ia', 'handoff', ['text' => 'Te comunico con un asesor 🙌', 'assign' => 'none', 'note' => true])
            ->node('fin', 'end')
            ->edge('start', 'next', 'agente')
            ->edge('agente', 'human', 'asesor')
            ->edge('agente', 'unavailable', 'sin_ia')
            ->edge('agente', 'done', 'fin');
    }

    /** @return list<HttpRequest> Las llamadas a la IA, en orden. */
    protected function aiRequests(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.x.ai/'))
            ->values()->all();
    }

    /** @return list<string> Lo que salió por WhatsApp (sin las llamadas a la IA). */
    protected function whatsappTexts(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'))
            ->map(fn (HttpRequest $r) => $r->data()['text']['body'] ?? $r->data()['interactive']['body']['text'] ?? '[' . ($r->data()['type'] ?? '?') . ']')
            ->values()->all();
    }

    protected function userWithRole(string $email, string $role): User
    {
        $user = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'x', 'tenant_id' => $this->tenant->id]);

        \DB::table('staff_members')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
        ]);

        return $user;
    }
}
