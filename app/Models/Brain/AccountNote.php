<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountNote extends Model
{
    protected $fillable = ['account_id', 'author_user_id', 'body', 'pinned'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function author(): BelongsTo  { return $this->belongsTo(User::class, 'author_user_id'); }
}
