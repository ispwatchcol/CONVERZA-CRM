<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un comprobante de pago que mandó un cliente por WhatsApp, desde que se lee
 * hasta que el pago queda registrado en ispwatch (o alguien lo descarta).
 *
 * Converza NUNCA escribe el pago: lo registra ispwatch por su propia API, con su
 * propio proceso (abona a las facturas más viejas, arrastra el saldo, reconecta
 * al cliente). Este registro es la evidencia: qué decía la imagen, qué se
 * verificó, quién decidió y qué contestó ispwatch.
 */
class PaymentReport extends Model
{
    use BelongsToTenant;

    public const STATUS_READING        = 'reading';         // la IA lo está leyendo
    public const STATUS_NOT_RECEIPT    = 'not_receipt';     // la imagen no es un comprobante
    public const STATUS_PENDING_REVIEW = 'pending_review';  // espera a una persona
    public const STATUS_REGISTERING    = 'registering';     // pidiéndoselo a ispwatch
    public const STATUS_REGISTERED     = 'registered';      // el pago existe en ispwatch
    public const STATUS_REJECTED       = 'rejected';        // una persona lo descartó
    public const STATUS_DUPLICATE      = 'duplicate';       // ya se había reportado

    /** Los que todavía piden algo de alguien. */
    public const OPEN_STATUSES = [
        self::STATUS_READING,
        self::STATUS_PENDING_REVIEW,
        self::STATUS_REGISTERING,
    ];

    public const SOURCE_FLOW = 'flow';
    public const SOURCE_CHAT = 'chat';

    /** Por qué un reporte quedó donde quedó, en palabras del equipo del ISP. */
    public const REASONS = [
        'sin_auto'             => 'Registro automático apagado: lo revisa el equipo',
        'ia_apagada'           => 'La IA está apagada para tu empresa: revisión manual',
        'ia_apagada_global'    => 'La IA está pausada en Converza: revisión manual',
        'sin_llave'            => 'Falta configurar el proveedor de IA: revisión manual',
        'presupuesto_agotado'  => 'Se agotó el presupuesto de IA del mes: revisión manual',
        'ia_error'             => 'La IA no respondió: revisión manual',
        'ia_sin_respuesta'     => 'La lectura tardó demasiado: revisión manual',
        'formato_no_soportado' => 'Formato que la IA no lee (PDF u otro): revisión manual',
        'imagen_no_disponible' => 'No se pudo abrir la imagen: revisión manual',
        'no_es_comprobante'    => 'La imagen no parece un comprobante de pago',
        'duplicado'            => 'Ese comprobante ya se había reportado',
        'ya_registrado'        => 'El pago ya estaba registrado en ispwatch',
        'verificacion'         => 'No pasó todas las verificaciones',
        'sin_conexion'         => 'Falta la conexión de pagos con ispwatch',
        'ispwatch_rechazo'     => 'ispwatch no aceptó el pago',
        'ispwatch_error'       => 'ispwatch no respondió',
    ];

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'contact_id',
        'message_id',
        'bot_flow_run_id',
        'source',
        'requested_by',
        'status',
        'auto_requested',
        'reason',
        'image_hash',
        'ai_confidence',
        'amount',
        'currency',
        'paid_on',
        'reference',
        'reference_key',
        'channel',
        'destination_account',
        'destination_holder',
        'payer_name',
        'extraction',
        'checks',
        'ispwatch_customer_id',
        'customer_name',
        'customer_match',
        'payment_channel_id',
        'ispwatch_payment_id',
        'registration',
        'registered_at',
        'registered_by',
        'registered_manually',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'auto_requested'       => 'boolean',
        'ai_confidence'        => 'float',
        'amount'               => 'decimal:2',
        'paid_on'              => 'date',
        'extraction'           => 'array',
        'checks'               => 'array',
        'ispwatch_customer_id' => 'integer',
        'ispwatch_payment_id'  => 'integer',
        'registration'         => 'array',
        'registered_at'        => 'datetime',
        'registered_manually'  => 'boolean',
        'reviewed_at'          => 'datetime',
        'attempts'             => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(BotFlowRun::class, 'bot_flow_run_id');
    }

    public function channelRecord(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class, 'payment_channel_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * La llave de idempotencia con la que se le pide el registro a ispwatch.
     * Es la misma en cada reintento: si la respuesta se perdió por un timeout,
     * ispwatch devuelve el pago que ya creó en vez de crear otro.
     */
    public function idempotencyKey(): string
    {
        return 'converza-payment-report-' . $this->id;
    }

    /** Referencia normalizada para detectar repetidos: «M-1234 567» = «M1234567». */
    public static function referenceKey(?string $reference): ?string
    {
        $key = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $reference));

        return $key === '' ? null : mb_substr($key, 0, 80);
    }
}
