<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'category', 'language', 'body',
        'status', 'meta_id', 'team_label', 'is_active', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
