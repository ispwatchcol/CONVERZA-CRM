<?php

namespace App\Services\Ai;

/** xAI (Grok). Informa el costo real de cada llamada en `usage.cost_in_usd_ticks`. */
final class XaiProvider extends ResponsesApiProvider
{
    public function name(): string
    {
        return 'xai';
    }

    protected function label(): string
    {
        return 'xAI';
    }
}
