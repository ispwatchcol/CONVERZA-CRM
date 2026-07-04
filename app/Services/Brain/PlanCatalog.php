<?php

namespace App\Services\Brain;

/**
 * Catálogo de planes comerciales del founder — la ÚNICA fuente de verdad de los
 * precios de ISPWatch, Converza y los Combos (ambas apps). Lo leen:
 *
 *   - El modal "Nueva cuenta" y el editor de productos de la ficha (auto-completa
 *     precio y etiqueta al elegir un plan).
 *   - La validación del backend (una clave de plan tiene que existir aquí).
 *
 * Precios en la MONEDA BASE (USD). El negocio cobra "mixto por cuenta": cada
 * `account_product` guarda su propio `amount` + `currency`, por lo que al asignar
 * un plan el precio base solo PRE-RELLENA y el founder puede sobrescribirlo (p. ej.
 * cobrar el equivalente en COP a un ISP local). El `plan_key` guardado no cambia.
 *
 * Un COMBO (product = 'combo') representa "las dos apps a precio de paquete": al
 * asignarlo se crean DOS filas `account_product` (ispwatch + converza) que
 * comparten el mismo `plan_key` (combo_*), con el precio del combo en la fila de
 * converza y 0 en la de ispwatch, para que el MRR no se duplique.
 */
class PlanCatalog
{
    /** Moneda en la que están expresados los precios base del catálogo. */
    public const BASE_CURRENCY = 'USD';

    /** Familias de producto que maneja el catálogo. */
    public const PRODUCTS = ['ispwatch', 'converza', 'combo'];

    /**
     * @return array<string, array{
     *     product: 'ispwatch'|'converza'|'combo',
     *     name: string,
     *     price: int|null,   // null = "a medida" (Enterprise)
     *     limit: string,
     *     blurb: string,
     *     popular?: bool,
     *     custom?: bool,
     *     saving?: int,               // solo combos: ahorro vs comprar separado
     *     ispwatch_plan?: string,     // solo combos: plan ISPWatch incluido
     *     converza_plan?: string      // solo combos: plan Converza incluido
     * }>
     */
    public static function plans(): array
    {
        return [
            // ── ISPWatch — por número de clientes del ISP ────────────────────────
            'ispwatch_gratis' => [
                'product' => 'ispwatch', 'name' => 'Gratis', 'price' => 0,
                'limit' => 'hasta 30 clientes', 'blurb' => 'Captación / land-grab',
            ],
            'ispwatch_emprendedor' => [
                'product' => 'ispwatch', 'name' => 'Emprendedor', 'price' => 19,
                'limit' => 'hasta 200 clientes', 'blurb' => 'Micro-ISP que ya factura',
            ],
            'ispwatch_crecimiento' => [
                'product' => 'ispwatch', 'name' => 'Crecimiento', 'price' => 47,
                'limit' => 'hasta 800 clientes', 'blurb' => 'ISP en crecimiento', 'popular' => true,
            ],
            'ispwatch_ilimitado' => [
                'product' => 'ispwatch', 'name' => 'Ilimitado', 'price' => 79,
                'limit' => 'clientes ilimitados', 'blurb' => 'Operadores medianos',
            ],
            'ispwatch_enterprise' => [
                'product' => 'ispwatch', 'name' => 'Enterprise', 'price' => null,
                'limit' => '2.000+ clientes', 'blurb' => 'Grandes: cobras por abonado', 'custom' => true,
            ],

            // ── Converza — por número de agentes ─────────────────────────────────
            'converza_inicial' => [
                'product' => 'converza', 'name' => 'Inicial', 'price' => 0,
                'limit' => '1 agente', 'blurb' => '1 número, ≤100 contactos, bot keywords, bandeja',
            ],
            'converza_pro' => [
                'product' => 'converza', 'name' => 'Pro', 'price' => 29,
                'limit' => 'hasta 3 agentes', 'blurb' => 'Campañas + anti-baneo + plantillas', 'popular' => true,
            ],
            'converza_business' => [
                'product' => 'converza', 'name' => 'Business', 'price' => 39,
                'limit' => 'hasta 8 agentes', 'blurb' => 'Multi-número, API, reportes',
            ],

            // ── Combos — ambas apps a precio de paquete (por clientes del ISP) ────
            'combo_200' => [
                'product' => 'combo', 'name' => 'Combo hasta 200', 'price' => 39,
                'limit' => 'hasta 200 clientes', 'blurb' => '2 agentes, avisos ISP por WhatsApp',
                'saving' => 9, 'ispwatch_plan' => 'ispwatch_emprendedor', 'converza_plan' => 'converza_pro',
            ],
            'combo_800' => [
                'product' => 'combo', 'name' => 'Combo hasta 800', 'price' => 69,
                'limit' => 'hasta 800 clientes', 'blurb' => '5 agentes, campañas, anti-baneo',
                'saving' => 13, 'popular' => true, 'ispwatch_plan' => 'ispwatch_crecimiento', 'converza_plan' => 'converza_pro',
            ],
            'combo_ilimitado' => [
                'product' => 'combo', 'name' => 'Combo Ilimitado', 'price' => 109,
                'limit' => 'clientes ilimitados', 'blurb' => 'Agentes ilimitados, API, multinúmero',
                'saving' => 15, 'ispwatch_plan' => 'ispwatch_ilimitado', 'converza_plan' => 'converza_business',
            ],
            'combo_enterprise' => [
                'product' => 'combo', 'name' => 'Combo Enterprise', 'price' => null,
                'limit' => '2.000+ clientes', 'blurb' => 'Por abonado', 'custom' => true,
                'ispwatch_plan' => 'ispwatch_enterprise', 'converza_plan' => 'converza_business',
            ],
        ];
    }

    /** @return list<string> Todas las claves válidas de plan (para validación). */
    public static function keys(): array
    {
        return array_keys(self::plans());
    }

    /** Busca un plan por su clave; devuelve la definición con la clave incluida. */
    public static function find(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $plan = self::plans()[$key] ?? null;

        return $plan ? ['key' => $key, ...$plan] : null;
    }

    /** ¿La clave corresponde a un combo (ambas apps)? */
    public static function isCombo(?string $key): bool
    {
        return ($plan = self::find($key)) !== null && $plan['product'] === 'combo';
    }

    /**
     * Catálogo listo para el frontend, agrupado por familia de producto.
     *
     * @return array{base_currency: string, ispwatch: list<array>, converza: list<array>, combo: list<array>}
     */
    public static function forFrontend(): array
    {
        $grouped = ['ispwatch' => [], 'converza' => [], 'combo' => []];

        foreach (self::plans() as $key => $plan) {
            $grouped[$plan['product']][] = ['key' => $key, ...$plan];
        }

        return ['base_currency' => self::BASE_CURRENCY, ...$grouped];
    }
}
