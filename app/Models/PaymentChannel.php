<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Un lugar donde los clientes le pagan al ISP: «Nequi 300 123 4567», «Bancolombia
 * ahorros 123-456789-01», «Efecty convenio 1234».
 *
 * Se cuelga de una forma de pago de ispwatch (payment_methods, que solo tiene
 * nombre: Efectivo, Transacción…) porque es con ella que se registra el pago
 * allá; lo que agrega Converza es lo que el cliente necesita saber y lo que el
 * verificador necesita comparar: el número de la cuenta.
 */
class PaymentChannel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'ispwatch_payment_method_id',
        'account_number',
        'account_holder',
        'instructions',
        'show_in_bot',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'ispwatch_payment_method_id' => 'integer',
        'show_in_bot'                => 'boolean',
        'is_active'                  => 'boolean',
        'sort_order'                 => 'integer',
    ];

    /** Solo los dígitos de la cuenta: así se compara con lo que dice el comprobante. */
    public function accountDigits(): string
    {
        return (string) preg_replace('/\D/', '', (string) $this->account_number);
    }
}
