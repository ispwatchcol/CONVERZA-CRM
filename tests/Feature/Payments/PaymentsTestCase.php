<?php

namespace Tests\Feature\Payments;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PaymentChannel;
use App\Models\PaymentReport;
use App\Models\User;
use App\Services\Flows\GraphBuilder;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flows\FlowsTestCase;

/**
 * Base de las pruebas de comprobantes con IA.
 *
 * Todo lo externo es falso: la IA (xAI), la API de ispwatch, la descarga de la
 * imagen desde Meta y la base de ispwatch (un repositorio en memoria). La
 * conexión `ispwatch` apunta además a un SQLite vacío: si algún camino se
 * saltara el repositorio falso, la prueba revienta en vez de leer producción
 * (el .env local apunta a la base real).
 */
abstract class PaymentsTestCase extends FlowsTestCase
{
    /** Lo que "lee" la IA del próximo comprobante (la salida del schema). */
    public array $reading = [];

    public int $xaiStatus = 200;

    /** [status, cuerpo] que contesta la API de ispwatch al registrar. */
    public array $ispwatchApi = [];

    /** El cliente que devuelve ispwatch por teléfono (null = no está registrado). */
    public ?array $customer = null;

    /** Facturas pendientes de ese cliente. */
    public array $pending = [];

    /** El pago de ispwatch que ya trae la referencia (null = ninguno). */
    public ?array $existingPayment = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.ispwatch', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        config()->set('ai.enabled', true);
        config()->set('ai.xai.api_key', 'xai-test-key');
        config()->set('ai.xai.base_url', 'https://api.x.ai/v1');
        config()->set('ai.xai.model', 'grok-4.3');
        config()->set('ai.xai.reasoning_effort', 'low');
        config()->set('services.ispwatch_api.url', 'https://ispwatch.test');
        config()->set('filesystems.media_disk', 'public');
        config()->set('media.download_types', ['image', 'audio', 'document']);
        Storage::fake('public');

        $this->tenant->update(['ispwatch_tenant_id' => 77, 'ai_enabled' => true, 'ispwatch_api_token' => 'tok-77']);

        PaymentChannel::create([
            'tenant_id'                  => $this->tenant->id,
            'name'                       => 'Nequi',
            'ispwatch_payment_method_id' => 4,
            'account_number'             => '300 123 4567',
            'account_holder'             => 'Fibra Chaguaní SAS',
            'show_in_bot'                => true,
            'is_active'                  => true,
        ]);

        $this->reading  = $this->validReading();
        $this->customer = ['user_id' => 501, 'tenant_id' => 77, 'name' => 'Ana Gómez', 'service_status' => 'suspendido', 'ambiguous' => false];
        $this->pending  = [['id' => 9, 'number' => 'F-0009', 'total' => '50000', 'balance_due' => '50000', 'status' => 'issued', 'due_date' => now()->toIso8601String()]];
        $this->ispwatchApi = [201, [
            'payment'      => ['id' => 9001, 'amount' => '50000.00', 'allocations' => [['invoice_id' => 9, 'invoice_number' => 'F-0009', 'amount' => '50000.00']]],
            'reactivation' => ['was_suspended' => true, 'reactivated' => true, 'router_ok' => true],
        ]];

        $this->fakeIspwatch();
    }

    /** Un comprobante de Nequi que pasa todas las verificaciones. */
    protected function validReading(array $overrides = []): array
    {
        return array_merge([
            'es_comprobante'     => true,
            'motivo_si_no_es'    => null,
            'valor'              => 50000,
            'moneda'             => 'COP',
            'fecha'              => now('America/Bogota')->format('Y-m-d'),
            'referencia'         => 'M1234567',
            'medio'              => 'Nequi',
            'cuenta_destino'     => '300 123 4567',
            'titular_destino'    => 'Fibra Chaguaní SAS',
            'pagador'            => 'Ana Gómez',
            'estado_transaccion' => 'exitosa',
            'alertas'            => [],
            'confianza'          => 0.95,
        ], $overrides);
    }

    protected function fakeExternal(HttpRequest $request): mixed
    {
        $url = $request->url();

        if (str_starts_with($url, 'https://api.x.ai/')) {
            return $this->xaiStatus === 200
                ? Http::response([
                    'id'     => 'resp_test',
                    'object' => 'response',
                    'model'  => 'grok-4.3',
                    'status' => 'completed',
                    'output' => [
                        ['type' => 'reasoning', 'summary' => []],
                        ['type' => 'message', 'role' => 'assistant', 'content' => [
                            ['type' => 'output_text', 'text' => json_encode($this->reading)],
                        ]],
                    ],
                    'usage' => [
                        'input_tokens'          => 1800,
                        'output_tokens'         => 220,
                        'input_tokens_details'  => ['cached_tokens' => 0],
                        'output_tokens_details' => ['reasoning_tokens' => 150],
                        'cost_in_usd_ticks'     => 33_000_000,
                    ],
                ], 200)
                : Http::response(['error' => ['message' => 'falla de prueba']], $this->xaiStatus);
        }

        if (str_starts_with($url, 'https://ispwatch.test/')) {
            return Http::response($this->ispwatchApi[1], $this->ispwatchApi[0]);
        }

        // La descarga del medio desde Meta: primero la metadata, después el binario.
        if (preg_match('#graph\.facebook\.com/v[\d.]+/(MEDIA\w*)$#', $url, $m)) {
            return Http::response([
                'url'       => 'https://lookaside.fbsbx.com/whatsapp/' . $m[1],
                'mime_type' => str_contains($m[1], 'PDF') ? 'application/pdf' : 'image/jpeg',
            ], 200);
        }

        if (str_starts_with($url, 'https://lookaside.fbsbx.com/')) {
            // Bytes distintos por imagen: el hash de la imagen es una llave anti-duplicados.
            return Http::response("\xFF\xD8\xFF\xE0JPEG-DE-PRUEBA-" . basename($url), 200, ['Content-Type' => 'image/jpeg']);
        }

        return null;
    }

    /** El cliente manda una foto por WhatsApp (pasa por el webhook de verdad). */
    protected function sendImage(string $mediaId = 'MEDIA1', string $from = '573001112233', string $mime = 'image/jpeg'): void
    {
        $this->incoming('', $from, [
            'type'  => $mime === 'application/pdf' ? 'document' : 'image',
            ($mime === 'application/pdf' ? 'document' : 'image') => ['id' => $mediaId, 'mime_type' => $mime, 'filename' => $mime === 'application/pdf' ? 'comprobante.pdf' : null],
        ]);
    }

    /** Un flujo: pide el comprobante y sigue según lo que pasó. */
    protected function receiptFlow(array $data = []): GraphBuilder
    {
        $data = array_merge([
            'text'             => 'Envíame la foto del comprobante 📸',
            'max_retries'      => 1,
            'auto_register'    => true,
            'use_recent_image' => true,
        ], $data);

        $graph = (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start'], 'timezone' => 'America/Bogota'])
            ->node('comprobante', 'receipt', $data)
            ->node('revision', 'handoff', ['text' => 'Recibido 🙌 Lo revisa el equipo y te confirmamos.', 'assign' => 'none', 'note' => false])
            ->node('nada', 'handoff', ['text' => 'Te paso con un asesor.', 'assign' => 'none', 'note' => false])
            ->edge('start', 'next', 'comprobante')
            ->edge('comprobante', 'review', 'revision')
            ->edge('comprobante', 'invalid', 'nada');

        if ($data['auto_register']) {
            $graph->node('ok', 'message', ['text' => '✅ Registramos tu pago de {{pago.valor}} (ref {{pago.referencia}}). {{pago.servicio}}'])
                ->node('fin', 'end')
                ->edge('comprobante', 'registered', 'ok')
                ->edge('ok', 'next', 'fin');
        }

        return $graph;
    }

    /** Solo los textos que se le mandaron al cliente por WhatsApp. */
    protected function whatsappTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/messages'))
            ->map(fn ($pair) => $pair[0]->data()['text']['body'] ?? $pair[0]->data()['interactive']['body']['text'] ?? '')
            ->values()
            ->all();
    }

    /** @return list<HttpRequest> */
    protected function requestsTo(string $prefix): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_starts_with($r->url(), $prefix))
            ->values()
            ->all();
    }

    protected function report(?int $id = null): PaymentReport
    {
        return $id
            ? PaymentReport::withoutGlobalScopes()->findOrFail($id)
            : PaymentReport::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** @return list<string> Notas internas del chat, en orden. */
    protected function notes(?Conversation $conversation = null): array
    {
        $conversation ??= $this->conversation();

        return Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('type', 'note')
            ->orderBy('id')
            ->pluck('body')
            ->all();
    }

    protected function userWithRole(string $email, string $role): User
    {
        $user = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'x', 'tenant_id' => $this->tenant->id]);

        \DB::table('staff_members')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
        ]);

        return $user;
    }

    /** ispwatch en memoria, de solo lectura, con lo que diga cada prueba. */
    private function fakeIspwatch(): void
    {
        $this->app->instance(IspwatchRepository::class, new class($this) extends IspwatchRepository {
            public function __construct(private readonly PaymentsTestCase $test) {}

            public function customerByPhone(int $ispwatchTenantId, ?string $phone, ?string $contactName = null): ?array
            {
                return $ispwatchTenantId === 77 && $phone === '573001112233' ? $this->test->customer : null;
            }

            public function customerById(int $ispwatchTenantId, int $userId): ?array
            {
                return $ispwatchTenantId === 77 && in_array($userId, [501, 777], true)
                    ? ['user_id' => $userId, 'tenant_id' => 77, 'name' => $userId === 777 ? 'Pedro Ruiz' : 'Ana Gómez', 'ambiguous' => false]
                    : null;
            }

            public function pendingInvoicesForCustomer(int $ispwatchTenantId, int $customerUserId): array
            {
                return $this->test->pending;
            }

            public function paymentWithReference(int $ispwatchTenantId, string $referenceKey): ?array
            {
                return $this->test->existingPayment;
            }

            public function searchCustomers(int $ispwatchTenantId, string $term, int $limit = 8): array
            {
                return [['user_id' => 777, 'name' => 'Pedro Ruiz', 'cedula' => '1020304050', 'tel' => '3110000000', 'service_status' => 'active']];
            }

            public function paymentMethodsForTenant(int $ispwatchTenantId): array
            {
                return [
                    ['id' => 1, 'name' => 'Efectivo', 'description' => 'Pago en efectivo', 'is_active' => true],
                    ['id' => 4, 'name' => 'Transacción', 'description' => 'Transferencia o transacción bancaria', 'is_active' => true],
                    ['id' => 5, 'name' => 'Baloto', 'description' => null, 'is_active' => false],
                ];
            }

            public function customerNamesForContacts(int $ispwatchTenantId, array $contacts): array
            {
                return [];
            }
        });
    }
}
