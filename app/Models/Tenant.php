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
        'is_active',
    ];

    protected $hidden = [
        'wa_access_token',
        'wa_app_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'wa_access_token' => 'encrypted',
            'wa_app_secret' => 'encrypted',
        ];
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

    public function hasWhatsAppConfigured(): bool
    {
        // Usamos getRawOriginal para evitar disparar el cast 'encrypted'
        // si el valor en BD no es un payload válido (texto plano legado o APP_KEY vieja).
        return filled($this->wa_phone_number_id) && filled($this->getRawOriginal('wa_access_token'));
    }
}
