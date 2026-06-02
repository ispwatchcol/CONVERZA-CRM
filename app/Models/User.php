<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'is_superadmin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * StaffMember del usuario en SU tenant. El global scope de BelongsToTenant
     * en StaffMember ya filtra por el tenant resuelto, y el usuario pertenece a
     * un único tenant, así que devuelve su único registro de staff.
     */
    public function staffMember(): HasOne
    {
        return $this->hasOne(StaffMember::class);
    }

    /**
     * Rol efectivo dentro del tenant: viewer < agent < admin.
     * - El superadmin del SaaS siempre actúa como 'admin' dentro del tenant.
     * - Sin StaffMember (caso legacy) se asume 'agent', igual que el auto-alta
     *   que hace ChatController al entrar al chat.
     */
    public function staffRole(): string
    {
        if ($this->is_superadmin) {
            return 'admin';
        }

        return $this->staffMember?->role ?? 'agent';
    }

    /**
     * ¿El usuario tiene al menos el rol indicado? (viewer < agent < admin)
     */
    public function hasStaffRole(string $minRole): bool
    {
        $rank = ['viewer' => 0, 'agent' => 1, 'admin' => 2];

        return ($rank[$this->staffRole()] ?? 0) >= ($rank[$minRole] ?? 99);
    }
}
