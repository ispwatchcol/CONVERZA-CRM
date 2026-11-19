<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un agente IA de la empresa: un prompt con nombre («Ventas», «Soporte») y,
 * opcionalmente, otro modelo que el de la empresa. Lo usa el bloque «Agente IA»
 * de un flujo para conversar con el cliente. Ver docs/agente-ia.md §11.
 */
class AiAgent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'instructions',
        'model',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
