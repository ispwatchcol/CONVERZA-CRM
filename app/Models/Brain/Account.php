<?php

namespace App\Models\Brain;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ficha central del ISP que le paga al founder.
 * NO usa BelongsToTenant — es cross-tenant por diseño.
 * El founder crea una Cuenta por cada ISP cliente.
 */
class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'tenant_id',
        'ispwatch_tenant_id',
        'status',
        'health',
        'owner_user_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'country',
        'onboarding_at',
        'renewal_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_at' => 'date',
            'renewal_at'    => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(AccountProduct::class);
    }

    /** MRR en la moneda base de cada producto activo. */
    public function getMrrAttribute(): array
    {
        $mrr = [];
        foreach ($this->products->where('status', 'active') as $product) {
            $monthly = match ($product->billing_cycle) {
                'quarterly' => $product->amount / 3,
                'yearly'    => $product->amount / 12,
                default     => $product->amount,
            };
            $mrr[$product->currency] = ($mrr[$product->currency] ?? 0) + $monthly;
        }
        return $mrr;
    }
}
