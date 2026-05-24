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
        'ispwatch_api_base_url',
        'ispwatch_api_token',
        'wa_phone_number_id',
        'wa_business_account_id',
        'wa_access_token',
        'wa_app_secret',
        'wa_verify_token',
        'wa_status',
        'is_active',
    ];

    protected $hidden = [
        'ispwatch_api_token',
        'wa_access_token',
        'wa_app_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ispwatch_api_token' => 'encrypted',
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
        return filled($this->wa_phone_number_id) && filled($this->wa_access_token);
    }
}
