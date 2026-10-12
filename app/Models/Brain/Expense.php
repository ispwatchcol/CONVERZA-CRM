<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un egreso del negocio: plata que salió para mantener las apps.
 *
 * `kind` separa lo fijo (lo que se paga igual todos los meses: el servidor, la
 * base de datos) de lo variable (lo que depende del uso o de una decisión del
 * mes: Meta, publicidad, un freelancer). Los fijos casi siempre nacen de un
 * `RecurringExpense`; uno variable se registra a mano.
 */
class Expense extends Model
{
    protected $table = 'brain_expenses';

    /** Categorías fijas: con texto libre, el reporte por categoría se parte en "Servidor" y "servidores". */
    public const CATEGORIES = [
        'infraestructura' => 'Infraestructura (servidores, BD, dominios)',
        'herramientas'    => 'Herramientas y suscripciones',
        'whatsapp_meta'   => 'WhatsApp / Meta',
        'marketing'       => 'Marketing y ventas',
        'personal'        => 'Personal y freelancers',
        'impuestos'       => 'Impuestos y comisiones',
        'otros'           => 'Otros',
    ];

    /** Sugerencias de app; el campo acepta cualquier otra. */
    public const DEFAULT_APPS = ['converza', 'ispwatch', 'general'];

    public const METHODS = ['card', 'transfer', 'cash', 'other'];

    protected $fillable = [
        'recurring_expense_id', 'concept', 'vendor', 'category', 'app', 'kind',
        'amount', 'currency', 'spent_at', 'method', 'reference', 'notes', 'period',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'   => 'decimal:2',
            'spent_at' => 'date',
        ];
    }

    public function recurring(): BelongsTo { return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
