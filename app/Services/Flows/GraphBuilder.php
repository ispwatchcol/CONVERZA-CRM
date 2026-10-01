<?php

namespace App\Services\Flows;

/** Arma un grafo en código (plantillas de arranque y pruebas). */
final class GraphBuilder
{
    /** @var list<array> */
    private array $nodes = [];

    /** @var list<array> */
    private array $edges = [];

    public function node(string $id, string $type, array $data = [], float $x = 0, float $y = 0): self
    {
        $this->nodes[] = [
            'id'       => $id,
            'type'     => $type,
            'position' => ['x' => $x, 'y' => $y],
            'data'     => $data,
        ];

        return $this;
    }

    public function edge(string $source, string $handle, string $target): self
    {
        $this->edges[] = [
            'id'           => "e_{$source}_{$handle}_{$target}",
            'source'       => $source,
            'sourceHandle' => $handle,
            'target'       => $target,
        ];

        return $this;
    }

    /** @return array{nodes: list<array>, edges: list<array>} */
    public function toArray(): array
    {
        return FlowGraph::normalize(['nodes' => $this->nodes, 'edges' => $this->edges]);
    }
}
