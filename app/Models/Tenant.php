<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'ispwatch_tenant_id',
        'wa_phone_number_id',
        'wa_business_account_id',
        'wa_access_token',
        'wa_app_secret',
        'wa_verify_token',
        'wa_status',
        'wa_invoice_template',
        'wa_reminder_template',
        'billing_notify_enabled',
        'auto_assign_enabled',
        'auto_close_enabled',
        'auto_close_hours',
        'is_active',
        'ai_enabled',
        'ai_monthly_budget_usd',
        'receipts_auto_max_amount',
        'ispwatch_api_token',
    ];

    protected $hidden = [
        'wa_access_token',
        'wa_app_secret',
        'ispwatch_api_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'billing_notify_enabled' => 'boolean',
            'auto_assign_enabled' => 'boolean',
            'auto_close_enabled' => 'boolean',
            'auto_close_hours' => 'integer',
            'wa_access_token' => 'encrypted',
            'wa_app_secret' => 'encrypted',
            'ai_enabled' => 'boolean',
            'ai_monthly_budget_usd' => 'decimal:2',
            'receipts_auto_max_amount' => 'decimal:2',
            'ispwatch_api_token' => 'encrypted',
        ];
    }

    /** Dónde le pagan los clientes al ISP (Configuración → Medios de pago). */
    public function paymentChannels(): HasMany
    {
        return $this->hasMany(PaymentChannel::class);
    }

    /**
     * ¿Puede Converza registrar pagos en ispwatch por esta empresa? Hace falta
     * el vínculo, la URL de la API y la credencial que emite ispwatch. Igual que
     * en hasWhatsAppConfigured(), se mira el valor crudo para no descifrar.
     */
    public function canRegisterPayments(): bool
    {
        return filled($this->ispwatch_tenant_id)
            && filled(config('services.ispwatch_api.url'))
            && filled($this->getRawOriginal('ispwatch_api_token'));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** Enrutamiento de avisos automáticos (evento → plantilla). */
    public function notificationRoutes(): HasMany
    {
        return $this->hasMany(TenantNotificationRoute::class);
    }

    public function hasWhatsAppConfigured(): bool
    {
        // Usamos getRawOriginal para evitar disparar el cast 'encrypted'
        // si el valor en BD no es un payload válido (texto plano legado o APP_KEY vieja).
        return filled($this->wa_phone_number_id) && filled($this->getRawOriginal('wa_access_token'));
    }
}
