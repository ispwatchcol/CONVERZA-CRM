<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'contact_id',
        'body',
        'status',
        'wa_message_id',
        'type',
        'media_id',
        'media_path',
        'media_mime',
        'media_filename',
        'caption',
        'sent_by_user_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Asesor (usuario) que envió un mensaje saliente. Null en mensajes
     * entrantes del cliente o automáticos (plantillas / recordatorios).
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
