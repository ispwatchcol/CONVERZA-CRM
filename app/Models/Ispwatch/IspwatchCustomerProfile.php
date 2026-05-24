<?php

namespace App\Models\Ispwatch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirror read-only de ispwatch.public.customer_profile.
 * La PK es user_id (no hay id propio).
 */
class IspwatchCustomerProfile extends Model
{
    protected $connection = 'ispwatch';
    protected $table = 'customer_profile';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(IspwatchUser::class, 'user_id');
    }
}
