<?php

namespace App\Services\Flows;

/**
 * Un flujo como datos: bloques (nodes) y conexiones (edges).
 *
 * El formato es el mismo que usa el lienzo del editor (Vue Flow), para que el
 * borrador viaje sin traducciones:
 *
 *   nodes: [{ id, type, position: {x, y}, data: {...config del bloque} }]
 *   edges: [{ id, source, sourceHandle, target }]
 *
 * `sourceHandle` es la SALIDA del bloque de origen: 'next' en los lineales,
 * 'opt_<id>' / 'no_match' en un Menú, 'true' / 'false' en una Condición…
 */
final class FlowGraph
{
    /** @var array<string, array{id: string, type: string, position: array, data: array}> */
    private array $nodes = [];

    /** @var array<string, string> "origen|salida" => destino */
    private array $targets = [];

    /** @var list<array{id: string, source: string, sourceHandle: string, target: string}> */
    private array $edges = [];

    private function __construct() {}

    public static function fromArray(?array $graph): self
    {
        $normalized = self::normalize($graph);
        $instance   = new self();

        foreach ($normalized['nodes'] as $node) {
            // El primero gana: un id duplicado es un error que reporta el validador.
            $instance->nodes[$node['id']] ??= $node;
        }

        foreach ($normalized['edges'] as $edge) {
            $instance->edges[] = $edge;
            $instance->targets[$edge['source'] . '|' . $edge['sourceHandle']] ??= $edge['target'];
        }

        return $instance;
    }

    /**
     * Forma canónica para guardar: solo las claves que nos pertenecen y en un
     * orden fijo. Descarta lo que el lienzo agrega por su cuenta (selected,
     * dimensions, computedPosition…) y hace estable la comparación borrador vs
     * publicado.
     *
     * @return array{nodes: list<array>, edges: list<array>}
     */
    public static function normalize(?array $graph): array
    {
        $nodes = [];
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! isset($node['id'], $node['type'])) {
                continue;
            }

            $nodes[] = [
                'id'       => (string) $node['id'],
                'type'     => (string) $node['type'],
                'position' => [
                    'x' => round((float) ($node['position']['x'] ?? 0), 1),
                    'y' => round((float) ($node['position']['y'] ?? 0), 1),
                ],
                'data'     => is_array($node['data'] ?? null) ? $node['data'] : [],
            ];
        }

        $edges = [];
        foreach ((array) ($graph['edges'] ?? []) as $edge) {
            if (! is_array($edge) || ! isset($edge['source'], $edge['target'])) {
                continue;
            }

            $source = (string) $edge['source'];
            $handle = (string) ($edge['sourceHandle'] ?? '') ?: 'next';
            $target = (string) $edge['target'];

            $edges[] = [
                'id'           => (string) ($edge['id'] ?? '') ?: "e_{$source}_{$handle}_{$target}",
                'source'       => $source,
                'sourceHandle' => $handle,
                'target'       => $target,
            ];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /** @return array{id: string, type: string, position: array, data: array}|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return array<string, array{id: string, type: string, position: array, data: array}> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<array{id: string, source: string, sourceHandle: string, target: string}> */
    public function edges(): array
    {
        return $this->edges;
    }

    public function start(): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === 'start') {
                return $node;
            }
        }

        return null;
    }

    /** Bloque al que lleva la salida `$handle` de `$source`, o null si no está conectada. */
    public function target(string $source, string $handle): ?string
    {
        return $this->targets[$source . '|' . $handle] ?? null;
    }
}
