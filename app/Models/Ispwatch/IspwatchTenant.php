<?php

namespace App\Models\Ispwatch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mirror read-only de ispwatch.public.tenant.
 * NUNCA escribir desde Converza: la BD ispwatch es productiva.
 */
class IspwatchTenant extends Model
{
    protected $connection = 'ispwatch';
    protected $table = 'tenant';
    public $timestamps = false;
    protected $guarded = [];

    public function users(): HasMany
    {
        return $this->hasMany(IspwatchUser::class, 'tenant_id');
    }
}
