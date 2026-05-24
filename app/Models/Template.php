<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $fillable = ['name', 'category', 'body', 'status', 'meta_id', 'team_label', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
