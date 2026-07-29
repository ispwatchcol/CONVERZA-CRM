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
 *
 * LÍMITES: además del `limit` legible para humanos, cada plan declara el tope que
 * gobierna en forma numérica — `max_clients` (ISPWatch) y/o `max_agents` (Converza),
 * donde **null = ilimitado**. Los combos declaran AMBOS y su tope de agentes es
 * propio, NO el del plan Converza que referencian (el Combo hasta 800 da 5 agentes
 * aunque incluya Converza Pro, que suelto son 3). Los usa `limitsFor()` para pintar
 * el semáforo de uso en la ficha de la cuenta y en el Cockpit.
 *
 * Ojo: esto NO bloquea nada en ISPWatch — Converza lee esa BD en read-only, así que
 * el tope de clientes es informativo (semáforo para decidir el upgrade a mano).
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
     *     max_clients?: int|null,     // tope de clientes ISPWatch (null = ilimitado)
     *     max_agents?: int|null,      // tope de agentes Converza (null = ilimitado)
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
                'max_clients' => 30,
            ],
            'ispwatch_emprendedor' => [
                'product' => 'ispwatch', 'name' => 'Emprendedor', 'price' => 19,
                'limit' => 'hasta 200 clientes', 'blurb' => 'Micro-ISP que ya factura',
                'max_clients' => 200,
            ],
            'ispwatch_crecimiento' => [
                'product' => 'ispwatch', 'name' => 'Crecimiento', 'price' => 47,
                'limit' => 'hasta 800 clientes', 'blurb' => 'ISP en crecimiento', 'popular' => true,
                'max_clients' => 800,
            ],
            'ispwatch_ilimitado' => [
                'product' => 'ispwatch', 'name' => 'Ilimitado', 'price' => 79,
                'limit' => 'clientes ilimitados', 'blurb' => 'Operadores medianos',
                'max_clients' => null,
            ],
            'ispwatch_enterprise' => [
                'product' => 'ispwatch', 'name' => 'Enterprise', 'price' => null,
                'limit' => '2.000+ clientes', 'blurb' => 'Grandes: cobras por abonado', 'custom' => true,
                'max_clients' => null,
            ],

            // ── Converza — por número de agentes ─────────────────────────────────
            'converza_inicial' => [
                'product' => 'converza', 'name' => 'Inicial', 'price' => 0,
                'limit' => '1 agente', 'blurb' => '1 número, ≤100 contactos, bot keywords, bandeja',
                'max_agents' => 1,
            ],
            'converza_pro' => [
                'product' => 'converza', 'name' => 'Pro', 'price' => 29,
                'limit' => 'hasta 3 agentes', 'blurb' => 'Campañas + anti-baneo + plantillas', 'popular' => true,
                'max_agents' => 3,
            ],
            'converza_business' => [
                'product' => 'converza', 'name' => 'Business', 'price' => 39,
                'limit' => 'hasta 8 agentes', 'blurb' => 'Multi-número, API, reportes',
                'max_agents' => 8,
            ],

            // ── Combos — ambas apps a precio de paquete (por clientes del ISP) ────
            // El tope de agentes del combo es PROPIO (el de la tabla de precios), no el
            // del plan Converza que referencia: el Combo hasta 800 da 5 agentes aunque
            // incluya Converza Pro, que suelto son 3.
            'combo_200' => [
                'product' => 'combo', 'name' => 'Combo hasta 200', 'price' => 39,
                'limit' => 'hasta 200 clientes', 'blurb' => '2 agentes, avisos ISP por WhatsApp',
                'saving' => 9, 'ispwatch_plan' => 'ispwatch_emprendedor', 'converza_plan' => 'converza_pro',
                'max_clients' => 200, 'max_agents' => 2,
            ],
            'combo_800' => [
                'product' => 'combo', 'name' => 'Combo hasta 800', 'price' => 69,
                'limit' => 'hasta 800 clientes', 'blurb' => '5 agentes, campañas, anti-baneo',
                'saving' => 13, 'popular' => true, 'ispwatch_plan' => 'ispwatch_crecimiento', 'converza_plan' => 'converza_pro',
                'max_clients' => 800, 'max_agents' => 5,
            ],
            'combo_ilimitado' => [
                'product' => 'combo', 'name' => 'Combo Ilimitado', 'price' => 109,
                'limit' => 'clientes ilimitados', 'blurb' => 'Agentes ilimitados, API, multinúmero',
                'saving' => 15, 'ispwatch_plan' => 'ispwatch_ilimitado', 'converza_plan' => 'converza_business',
                'max_clients' => null, 'max_agents' => null,
            ],
            'combo_enterprise' => [
                'product' => 'combo', 'name' => 'Combo Enterprise', 'price' => null,
                'limit' => '2.000+ clientes', 'blurb' => 'Por abonado', 'custom' => true,
                'ispwatch_plan' => 'ispwatch_enterprise', 'converza_plan' => 'converza_business',
                'max_clients' => null, 'max_agents' => null,
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
     * Topes efectivos de una cuenta a partir de las claves de plan de sus productos.
     *
     * Un combo gobierna las DOS dimensiones; si no hay combo, cada familia gobierna la
     * suya (ispwatch_* → clientes, converza_* → agentes). Devuelve null en la dimensión
     * que la cuenta no tiene contratada — que es distinto de tenerla ilimitada, donde el
     * sub-array existe con `limit => null`. Un plan_key nulo o fuera del catálogo (plan
     * a medida) no aporta tope.
     *
     * @param  iterable<string|null>  $planKeys
     * @return array{clients: ?array{limit: ?int, plan_key: string, plan_name: string}, agents: ?array{limit: ?int, plan_key: string, plan_name: string}}
     */
    public static function limitsFor(iterable $planKeys): array
    {
        $limits = ['clients' => null, 'agents' => null];

        foreach ($planKeys as $key) {
            if (($plan = self::find($key)) === null) {
                continue;
            }

            $entry = fn (string $dim) => [
                'limit'     => $plan[$dim],
                'plan_key'  => $plan['key'],
                'plan_name' => $plan['name'],
            ];

            // El combo pisa lo que hubiera puesto un plan suelto: es la fuente de verdad
            // de ambas dimensiones cuando está presente.
            $isCombo = $plan['product'] === 'combo';

            if (array_key_exists('max_clients', $plan) && ($isCombo || $limits['clients'] === null)) {
                $limits['clients'] = $entry('max_clients');
            }

            if (array_key_exists('max_agents', $plan) && ($isCombo || $limits['agents'] === null)) {
                $limits['agents'] = $entry('max_agents');
            }
        }

        return $limits;
    }

    /**
     * Cruza un tope con el uso real y arma la celda del semáforo.
     *
     * `state`: 'unlimited' (sin tope) | 'ok' (<75%) | 'warn' (75-89%) | 'high' (90-99%)
     * | 'over' (>=100%, ya excedido). Devuelve null si la cuenta no tiene esa dimensión
     * contratada o si todavía no hay lectura de uso.
     *
     * @param  array{limit: ?int, plan_key: string, plan_name: string}|null  $limit
     * @return array{used: int, limit: ?int, pct: ?int, state: string, plan_key: string, plan_name: string}|null
     */
    public static function usage(?array $limit, ?int $used): ?array
    {
        if ($limit === null || $used === null) {
            return null;
        }

        $max = $limit['limit'];
        // floor, no round: con round, 799/800 mostraría "100%" sin estar excedido.
        $pct = $max > 0 ? (int) floor($used / $max * 100) : null;

        $state = match (true) {
            $max === null => 'unlimited',
            $used >= $max => 'over',
            $pct >= 90    => 'high',
            $pct >= 75    => 'warn',
            default       => 'ok',
        };

        return [
            'used'      => $used,
            'limit'     => $max,
            'pct'       => $pct,
            'state'     => $state,
            'plan_key'  => $limit['plan_key'],
            'plan_name' => $limit['plan_name'],
        ];
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
