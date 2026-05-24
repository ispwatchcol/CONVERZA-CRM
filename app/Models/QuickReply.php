<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class QuickReply extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'title', 'body', 'shortcut', 'category', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
