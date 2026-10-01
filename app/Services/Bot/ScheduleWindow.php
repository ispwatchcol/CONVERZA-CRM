<?php

namespace App\Services\Bot;

use Carbon\CarbonInterface;

/**
 * ¿Un instante cae dentro de una franja semanal (días + desde/hasta)?
 *
 * La usan el horario del bot clásico (BotSetting::respondsAt) y la condición de
 * horario de los flujos del Workspace. Vive en un solo sitio porque la
 * semántica tiene sutilezas que no deben divergir:
 *
 *   - `desde` entra y `hasta` no (08:00 sí, 18:00 no).
 *   - Si `hasta <= desde` la franja cruza medianoche (18:00 → 08:00) y el día
 *     que cuenta es aquel en que EMPIEZA: a las 02:00 del martes seguimos en la
 *     franja que abrió el lunes.
 *   - `desde == hasta` es una franja de 24 h sobre los días marcados.
 *   - Sin días marcados nunca se está dentro.
 */
final class ScheduleWindow
{
    /**
     * @param array<int, int|string> $days     Días ISO-8601: 1 = lunes … 7 = domingo.
     * @param CarbonInterface        $localNow El instante YA en la zona horaria de la franja.
     */
    public static function contains(
        array $days,
        ?string $start,
        ?string $end,
        CarbonInterface $localNow,
        int $fallbackStart = 8 * 60,
        int $fallbackEnd = 18 * 60,
    ): bool {
        $days = array_map('intval', $days);

        $startMin = self::minutesOf($start, $fallbackStart);
        $endMin   = self::minutesOf($end, $fallbackEnd);
        $minutes  = $localNow->hour * 60 + $localNow->minute;

        if ($startMin === $endMin) {
            $inWindow  = true;
            $windowDay = $localNow->dayOfWeekIso;
        } elseif ($endMin > $startMin) {
            $inWindow  = $minutes >= $startMin && $minutes < $endMin;
            $windowDay = $localNow->dayOfWeekIso;
        } else {
            $inWindow  = $minutes >= $startMin || $minutes < $endMin;
            $windowDay = $minutes < $endMin
                ? $localNow->copy()->subDay()->dayOfWeekIso
                : $localNow->dayOfWeekIso;
        }

        return $inWindow && in_array($windowDay, $days, true);
    }

    /** Minutos desde medianoche de un "HH:MM", o el respaldo si no tiene esa forma. */
    public static function minutesOf(?string $value, int $fallback): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) $value, $m)) {
            return $fallback;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
