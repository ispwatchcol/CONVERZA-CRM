<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca de agua por (tenant, evento) del polling incremental de ispwatch que hace
 * whatsapp:events-notify. `last_id` = último id de la tabla origen ya procesado.
 * Ver migración 2026_07_03_000001_create_ispwatch_event_cursors_table.
 */
class IspwatchEventCursor extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event_key',
        'last_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_id'      => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
