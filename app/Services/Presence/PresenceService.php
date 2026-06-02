<?php

namespace App\Services\Presence;

use Illuminate\Support\Facades\Cache;

/**
 * Presencia y detección de colisión SIN WebSockets: se apoya en el polling del
 * chat (cada 5s ChatController::index registra un heartbeat) y guarda el estado
 * en cache (Redis en prod, 'database' en dev — driver-agnóstico).
 *
 *  - "En línea": el usuario hizo heartbeat hace < ONLINE_TTL segundos.
 *  - "Viendo":   el usuario tiene abierta esa conversación (heartbeat con su id).
 *
 * Las entradas viejas se podan en cada lectura/escritura y las claves expiran
 * solas, así que si alguien cierra la pestaña desaparece en ~ONLINE_TTL segundos.
 *
 * El estado es best-effort: dos escrituras concurrentes sobre la misma clave
 * podrían perder una actualización, pero el siguiente heartbeat (≤5s) la repone.
 */
class PresenceService
{
    /** Segundos desde el último heartbeat para seguir considerado presente. */
    private const ONLINE_TTL = 20;

    /** TTL de las claves en cache (limpieza si el tenant/conversación queda vacío). */
    private const CACHE_TTL = 120;

    /**
     * Registra que $userId está en línea en el tenant y, si aplica, viendo una
     * conversación concreta.
     */
    public function heartbeat(int $tenantId, int $userId, string $name, ?int $conversationId): void
    {
        $now = time();

        $onlineKey = $this->onlineKey($tenantId);
        $online = Cache::get($onlineKey, []);
        $online[$userId] = ['name' => $name, 'at' => $now];
        Cache::put($onlineKey, $this->prune($online, $now), self::CACHE_TTL);

        if ($conversationId) {
            $viewersKey = $this->viewersKey($conversationId);
            $viewers = Cache::get($viewersKey, []);
            $viewers[$userId] = ['name' => $name, 'at' => $now];
            Cache::put($viewersKey, $this->prune($viewers, $now), self::CACHE_TTL);
        }
    }

    /**
     * Ids de usuarios en línea en el tenant.
     *
     * @return array<int, int>
     */
    public function onlineUserIds(int $tenantId): array
    {
        $online = $this->prune(Cache::get($this->onlineKey($tenantId), []), time());

        return array_map('intval', array_keys($online));
    }

    /**
     * Otros usuarios (≠ $excludeUserId) que están viendo la conversación ahora.
     *
     * @return array<int, array{id:int, name:string, initial:string}>
     */
    public function viewersOf(int $conversationId, int $excludeUserId): array
    {
        $viewers = $this->prune(Cache::get($this->viewersKey($conversationId), []), time());

        $out = [];
        foreach ($viewers as $userId => $data) {
            if ((int) $userId === $excludeUserId) {
                continue;
            }
            $name = $data['name'] ?? 'Agente';
            $out[] = [
                'id'      => (int) $userId,
                'name'    => $name,
                'initial' => mb_substr($name, 0, 1),
            ];
        }

        return $out;
    }

    /**
     * Poda entradas cuyo último heartbeat es más viejo que ONLINE_TTL.
     *
     * @param  array<int, array{name?:string, at?:int}>  $entries
     * @return array<int, array{name?:string, at?:int}>
     */
    private function prune(array $entries, int $now): array
    {
        return array_filter(
            $entries,
            fn ($e) => isset($e['at']) && ($now - $e['at']) < self::ONLINE_TTL,
        );
    }

    private function onlineKey(int $tenantId): string
    {
        return "presence:online:{$tenantId}";
    }

    private function viewersKey(int $conversationId): string
    {
        return "presence:viewers:{$conversationId}";
    }
}
