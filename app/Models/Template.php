<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'category', 'language', 'event_key', 'body',
        'header_text', 'footer_text', 'button_text', 'button_url',
        'status', 'meta_id', 'team_label', 'is_active', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Variables NOMBRADAS presentes en el body, en orden de aparición y únicas.
     * Ej. "Hola {{nombre_cliente}}, factura {{numero_factura}}" → ['nombre_cliente', 'numero_factura'].
     *
     * @return array<int, string>
     */
    public static function namedVariablesIn(?string $body): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', (string) $body, $m);
        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Números de variable POSICIONALES presentes en el body ({{1}}, {{2}}, …).
     * Plantillas legadas usan este formato; las nuevas usan variables nombradas.
     *
     * @return array<int, int>
     */
    public static function positionalVariablesIn(?string $body): array
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $body, $m);
        return array_values(array_unique(array_map('intval', $m[1] ?? [])));
    }

    /** ¿El body usa variables nombradas ({{nombre}}) en vez de posicionales ({{1}})? */
    public function usesNamedVariables(): bool
    {
        return self::namedVariablesIn($this->body) !== [];
    }
}
