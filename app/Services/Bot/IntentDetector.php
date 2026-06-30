<?php

namespace App\Services\Bot;

class IntentDetector
{
    /**
     * Keywords por intención. Las claves multi-palabra van primero dentro
     * de cada grupo para que un match largo no sea "tapado" por uno corto.
     * El orden de los grupos también importa: si un texto matchea "socio" Y
     * "precio", gana el grupo definido primero.
     */
    private array $keywords = [
        'demo'  => [
            'quiero ver', 'ver demo', 'ver la demo', 'cómo funciona', 'como funciona',
            'muéstrame', 'muestrame', 'demo', 'ver', 'prueba', 'probar', 'mostrar', 'conocer',
        ],
        'socio' => [
            'socio fundador', 'precio especial', 'precio preferencial',
            'socio', 'fundador', 'descuento', 'programa', 'oferta', 'gratis',
        ],
        'info'  => [
            'qué es', 'que es', 'más información', 'mas informacion',
            'ispwatch', 'sistema', 'software', 'plataforma', 'producto',
            'información', 'informacion', 'info',
        ],
        'price' => [
            'cuánto cuesta', 'cuanto cuesta', 'cuánto vale', 'cuanto vale',
            'precio', 'costo', 'cuánto', 'cuanto', 'valor', 'tarifa',
            'plan', 'planes', 'cobran', 'mensualidad', 'inversión', 'inversion',
        ],
        'agent' => [
            'quiero hablar', 'necesito hablar', 'hablar con', 'comunicarme',
            'asesor', 'humano', 'persona', 'agente', 'hablar', 'ayuda',
        ],
    ];

    /**
     * Detecta la intención del mensaje.
     *
     * Retorna: demo | socio | info | price | agent | unknown
     */
    public function detect(string $body): string
    {
        $normalized = mb_strtolower(trim($body));

        // Selecciones numéricas directas del menú (1–5 o emoji).
        $numericMap = [
            '1'    => 'info',
            '2'    => 'socio',
            '3'    => 'demo',
            '4'    => 'price',
            '5'    => 'agent',
            '1️⃣' => 'info',
            '2️⃣' => 'socio',
            '3️⃣' => 'demo',
            '4️⃣' => 'price',
            '5️⃣' => 'agent',
        ];

        if (isset($numericMap[$normalized])) {
            return $numericMap[$normalized];
        }

        // Busca emoji numérico dentro del texto (el usuario puede escribir
        // "1️⃣ quiero conocer ispwatch" en vez de solo "1").
        foreach (['1️⃣' => 'info', '2️⃣' => 'socio', '3️⃣' => 'demo', '4️⃣' => 'price', '5️⃣' => 'agent'] as $emoji => $intent) {
            if (str_contains($normalized, $emoji)) {
                return $intent;
            }
        }

        // Keyword matching: las frases más largas se definen primero en el array
        // para evitar falsos positivos de substrings.
        foreach ($this->keywords as $intent => $words) {
            foreach ($words as $word) {
                if (str_contains($normalized, $word)) {
                    return $intent;
                }
            }
        }

        return 'unknown';
    }
}
