<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enrutamiento por-tenant de un evento de aviso a una plantilla concreta.
 * Reemplaza las columnas fijas tenants.wa_invoice_template / wa_reminder_template.
 * Los eventos válidos los define App\Services\Notifications\EventCatalog.
 */
class TenantNotificationRoute extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_key',
        'template_id',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
