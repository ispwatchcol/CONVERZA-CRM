<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'wa_user_id', 'wa_username', 'name', 'email', 'avatar', 'notes', 'external_id'];

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function getDisplayNameAttribute(): string
    {
        // El BSUID (`CO.1124418266822967`) no se muestra nunca: no le dice nada a
        // nadie. Si el cliente ocultó su teléfono, lo más humano que tenemos es
        // el username de WhatsApp.
        return $this->name
            ?: ($this->phone ?: ($this->wa_username ? '@'.$this->wa_username : 'Cliente sin teléfono'));
    }

    /**
     * A dónde se le escribe a este contacto por WhatsApp.
     *
     * Puede ser un teléfono o un BSUID: `WhatsAppService` distingue uno de otro
     * y arma el payload correspondiente (`to` vs `recipient`). Null si el
     * contacto no tiene ninguna identidad utilizable, que no debería pasar.
     */
    public function waDestino(): ?string
    {
        return $this->phone ?: $this->wa_user_id;
    }

    /** ¿El cliente ocultó su número con un username de WhatsApp? */
    public function sinTelefono(): bool
    {
        return empty($this->phone) && ! empty($this->wa_user_id);
    }

    /**
     * Normaliza un teléfono al formato interno (12 dígitos con prefijo país).
     * Mismas reglas que ChatController::normalizePhone para evitar duplicados
     * entre contactos creados desde la UI vs los autocreados por el webhook.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) return null;

        $digits = preg_replace('/\D/', '', $phone);
        if (! $digits) return null;

        // Si son 10 dígitos empezando en 3 (móvil CO) → agregar prefijo 57.
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            $digits = '57' . $digits;
        }

        return $digits;
    }
}
