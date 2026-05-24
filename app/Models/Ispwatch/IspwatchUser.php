<?php

namespace App\Models\Ispwatch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Mirror read-only de ispwatch.public.users.
 * Incluye superadmins, staff y clientes finales; filtrar por la relación
 * con customer_profile cuando solo se quieran clientes.
 */
class IspwatchUser extends Model
{
    protected $connection = 'ispwatch';
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(IspwatchTenant::class, 'tenant_id');
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(IspwatchCustomerProfile::class, 'user_id');
    }
}
