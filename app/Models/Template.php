<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'category', 'body', 'status', 'meta_id', 'team_label', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
