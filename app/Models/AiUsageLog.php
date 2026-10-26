<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Una llamada al proveedor de IA: tokens, costo, latencia y error. Sin el
 * contenido, que son datos personales del cliente del ISP.
 */
class AiUsageLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'purpose',
        'provider',
        'model',
        'status',
        'input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'cached_tokens',
        'cost_usd',
        'cost_estimated',
        'latency_ms',
        'error',
        'subject_type',
        'subject_id',
        'created_at',
    ];

    protected $casts = [
        'input_tokens'     => 'integer',
        'output_tokens'    => 'integer',
        'reasoning_tokens' => 'integer',
        'cached_tokens'    => 'integer',
        'cost_usd'         => 'float',
        'cost_estimated'   => 'boolean',
        'latency_ms'       => 'integer',
        'created_at'       => 'datetime',
    ];
}
