<?php

namespace App\Services\Flows;

use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Decide si un flujo se puede publicar. Un flujo con errores no se publica, y
 * cada error señala el bloque exacto que falla.
 *
 * Además de la configuración de cada bloque, revisa lo que solo se ve mirando
 * el grafo entero:
 *
 *   - Salidas sin conectar: una rama que no lleva a nada deja al cliente
 *     hablándole a la nada.
 *   - Ciclos que nunca esperan al cliente: el bot quedaría mandando mensajes
 *     en bucle. Un ciclo solo es válido si pasa por una Pregunta o un Menú.
 *   - La ventana de 24 h: ningún mensaje puede salir más de 23 h después del
 *     último mensaje del cliente sumando las Esperas del camino. Fuera de la
 *     ventana Meta acepta el envío con 200 y lo rechaza después; el editor lo
 *     tiene que decir al diseñar, no al fallar.
 *   - Variables que no existen en ningún lado (casi siempre, un error de tipeo).
 */
final class FlowValidator
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @return array{errors: list<array>, warnings: list<array>}
     */
    public function validate(?array $graphArray, ValidationContext $ctx): array
    {
        $normalized = FlowGraph::normalize($graphArray);
        $graph      = FlowGraph::fromArray($normalized);
        $issues     = [];

        if (count($normalized['nodes']) > $ctx->maxNodes()) {
            $issues[] = Issue::error("El flujo tiene más de {$ctx->maxNodes()} bloques. Divídelo en varios flujos.");
        }

        $seen = [];
        foreach ($normalized['nodes'] as $node) {
            if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $node['id'])) {
                $issues[] = Issue::error('Hay un bloque con un identificador inválido. Bórralo y créalo de nuevo.');
            } elseif (isset($seen[$node['id']])) {
                $issues[] = Issue::error('Hay dos bloques con el mismo identificador. Borra uno y créalo de nuevo.', $node['id']);
            }
            $seen[$node['id']] = true;
        }

        $starts = array_values(array_filter($graph->nodes(), fn (array $n) => $n['type'] === 'start'));
        if ($starts === []) {
            $issues[] = Issue::error('Falta el bloque Inicio: el flujo no sabría cuándo arrancar.');
        } elseif (count($starts) > 1) {
            $issues[] = Issue::error('Solo puede haber un bloque Inicio.', $starts[1]['id']);
        }

        foreach ($graph->nodes() as $node) {
            $handler = $this->registry->get($node['type']);
            if ($handler === null) {
                $issues[] = Issue::error("Tipo de bloque desconocido: «{$node['type']}».", $node['id']);
                continue;
            }
            array_push($issues, ...$handler->validate($node, $ctx));
        }

        array_push($issues, ...$this->edges($graph));
        array_push($issues, ...$this->unconnectedOutputs($graph));
        array_push($issues, ...$this->unreachable($graph, $starts[0] ?? null));

        $cycles = $this->cyclesWithoutInput($graph);
        array_push($issues, ...$cycles);

        // La regla de la ventana recorre el grafo como un DAG; con un ciclo sin
        // espera el recorrido no está definido (y ese ciclo ya es un error).
        if ($cycles === []) {
            array_push($issues, ...$this->serviceWindow($graph, $ctx));
        }

        array_push($issues, ...$this->unknownVariables($graph));

        $errors = $warnings = [];
        foreach ($this->unique($issues) as $issue) {
            if ($issue->severity === Issue::ERROR) {
                $errors[] = $issue->toArray();
            } else {
                $warnings[] = $issue->toArray();
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @return list<Issue> */
    private function edges(FlowGraph $graph): array
    {
        $issues = [];
        $used   = [];

        foreach ($graph->edges() as $edge) {
            $source = $graph->node($edge['source']);
            $target = $graph->node($edge['target']);

            if ($source === null || $target === null) {
                $issues[] = Issue::error('Hay una conexión que apunta a un bloque que ya no existe.', $source['id'] ?? null);
                continue;
            }

            if ($target['type'] === 'start') {
                $issues[] = Issue::error('Nada puede volver al bloque Inicio. Si quieres repetir, conecta al primer bloque después del Inicio.', $source['id']);
            }

            if ($edge['source'] === $edge['target']) {
                $issues[] = Issue::error('Un bloque no puede conectarse consigo mismo.', $source['id']);
            }

            $handler = $this->registry->get($source['type']);
            if ($handler === null) {
                continue;
            }

            if (! in_array($edge['sourceHandle'], $handler->outputs($source['data']), true)) {
                $issues[] = Issue::error("{$handler->label()}: hay una conexión que sale de una opción que ya no existe. Bórrala.", $source['id']);
                continue;
            }

            $key = $edge['source'] . '|' . $edge['sourceHandle'];
            if (isset($used[$key])) {
                $label = $handler->outputLabel($edge['sourceHandle'], $source['data']);
                $issues[] = Issue::error("{$handler->label()}: la salida «{$label}» tiene dos conexiones; deja solo una.", $source['id']);
            }
            $used[$key] = true;
        }

        return $issues;
    }

    /** @return list<Issue> */
    private function unconnectedOutputs(FlowGraph $graph): array
    {
        $issues = [];

        foreach ($graph->nodes() as $node) {
            $handler = $this->registry->get($node['type']);
            if ($handler === null) {
                continue;
            }

            foreach ($handler->outputs($node['data']) as $handle) {
                if ($graph->target($node['id'], $handle) !== null) {
                    continue;
                }

                $issues[] = Issue::error($handle === 'next'
                    ? "{$handler->label()}: no está conectado al bloque siguiente."
                    : "{$handler->label()}: {$handler->outputLabel($handle, $node['data'])} no lleva a ningún bloque.", $node['id']);
            }
        }

        return $issues;
    }

    /** @return list<Issue> */
    private function unreachable(FlowGraph $graph, ?array $start): array
    {
        if ($start === null) {
            return [];
        }

        $reached = [$start['id'] => true];
        $queue   = [$start['id']];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($graph->edges() as $edge) {
                if ($edge['source'] === $current && ! isset($reached[$edge['target']]) && $graph->node($edge['target'])) {
                    $reached[$edge['target']] = true;
                    $queue[] = $edge['target'];
                }
            }
        }

        $issues = [];
        foreach ($graph->nodes() as $node) {
            if (! isset($reached[$node['id']])) {
                $issues[] = Issue::warning('Este bloque no está conectado al flujo: nunca se va a ejecutar.', $node['id']);
            }
        }

        return $issues;
    }

    /**
     * Ciclos que no pasan por ningún bloque que espere al cliente. Se quitan del
     * grafo la Pregunta y el Menú (lo que corta la repetición) y todo ciclo que
     * quede es un bucle: el bot mandaría mensajes sin parar, aunque haya una
     * Espera en el medio (con Espera, solo más despacio).
     *
     * @return list<Issue>
     */
    private function cyclesWithoutInput(FlowGraph $graph): array
    {
        $adjacency = [];
        foreach ($graph->nodes() as $id => $node) {
            if (! $this->waitsForInput($node)) {
                $adjacency[$id] = [];
            }
        }
        foreach ($graph->edges() as $edge) {
            if (isset($adjacency[$edge['source']], $adjacency[$edge['target']])) {
                $adjacency[$edge['source']][] = $edge['target'];
            }
        }

        $issues = [];
        foreach ($this->stronglyConnected($adjacency) as $component) {
            $selfLoop = count($component) === 1 && in_array($component[0], $adjacency[$component[0]], true);
            if (count($component) < 2 && ! $selfLoop) {
                continue;
            }

            foreach ($component as $id) {
                $issues[] = Issue::error('Estos bloques forman un ciclo que nunca espera al cliente: el bot repetiría mensajes sin parar. Pon una Pregunta o un Menú dentro del ciclo.', $id);
            }
        }

        return $issues;
    }

    /**
     * Componentes fuertemente conexos (Tarjan, iterativo para no depender de la
     * profundidad de la pila con flujos grandes).
     *
     * @param array<string, list<string>> $adjacency
     * @return list<list<string>>
     */
    private function stronglyConnected(array $adjacency): array
    {
        $index = 0;
        $indices = $lowlink = $onStack = [];
        $stack = [];
        $components = [];

        foreach (array_keys($adjacency) as $root) {
            if (isset($indices[$root])) {
                continue;
            }

            $work = [[$root, 0]];
            while ($work !== []) {
                [$node, $childPos] = array_pop($work);

                if ($childPos === 0) {
                    $indices[$node] = $lowlink[$node] = $index++;
                    $stack[] = $node;
                    $onStack[$node] = true;
                }

                $children = $adjacency[$node];
                if ($childPos < count($children)) {
                    $child = $children[$childPos];
                    $work[] = [$node, $childPos + 1];

                    if (! isset($indices[$child])) {
                        $work[] = [$child, 0];
                    } elseif (! empty($onStack[$child])) {
                        $lowlink[$node] = min($lowlink[$node], $indices[$child]);
                    }
                    continue;
                }

                // Todos los hijos procesados: propagar al padre.
                if ($work !== []) {
                    $parent = $work[count($work) - 1][0];
                    $lowlink[$parent] = min($lowlink[$parent], $lowlink[$node]);
                }

                if ($lowlink[$node] === $indices[$node]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        $onStack[$member] = false;
                        $component[] = $member;
                    } while ($member !== $node);
                    $components[] = $component;
                }
            }
        }

        return $components;
    }

    /**
     * La ventana de 24 h. Se cortan las conexiones que SALEN de una Pregunta o
     * un Menú (el cliente acaba de escribir: la ventana se renueva) y sobre el
     * grafo que queda —sin ciclos, ya validado— se acumula la espera máxima que
     * puede haber antes de cada bloque que envía algo.
     *
     * @return list<Issue>
     */
    private function serviceWindow(FlowGraph $graph, ValidationContext $ctx): array
    {
        $nodes    = $graph->nodes();
        $outgoing = array_fill_keys(array_keys($nodes), []);
        $incoming = array_fill_keys(array_keys($nodes), 0);

        foreach ($graph->edges() as $edge) {
            $source = $nodes[$edge['source']] ?? null;
            if ($source === null || ! isset($nodes[$edge['target']]) || $this->waitsForInput($source)) {
                continue;
            }
            $outgoing[$edge['source']][] = $edge['target'];
            $incoming[$edge['target']]++;
        }

        $wait  = array_fill_keys(array_keys($nodes), 0);
        $queue = array_keys(array_filter($incoming, fn (int $n) => $n === 0));

        while ($queue !== []) {
            $id      = array_shift($queue);
            $handler = $this->registry->get($nodes[$id]['type']);
            $own     = $handler?->waitMinutes($nodes[$id]['data']) ?? 0;

            foreach ($outgoing[$id] as $next) {
                $wait[$next] = max($wait[$next], $wait[$id] + $own);
                if (--$incoming[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        $issues = [];
        foreach ($nodes as $id => $node) {
            $handler = $this->registry->get($node['type']);
            if ($handler === null || ! $handler->sendsMessage($node['data']) || $wait[$id] <= $ctx->maxWait()) {
                continue;
            }

            $issues[] = Issue::error(sprintf(
                '%s: este mensaje saldría hasta %s h después del último mensaje del cliente. La ventana de 24 h de WhatsApp ya estaría cerrada y Meta lo rechazaría. Acorta las esperas o pon una Pregunta o un Menú antes.',
                $handler->label(),
                rtrim(rtrim(number_format($wait[$id] / 60, 1, ',', ''), '0'), ','),
            ), $id);
        }

        return $issues;
    }

    /** @return list<Issue> */
    private function unknownVariables(FlowGraph $graph): array
    {
        $defined = array_fill_keys(array_keys(Execution::BUILTINS), true);
        foreach ($graph->nodes() as $node) {
            foreach ($this->registry->get($node['type'])?->definesVariables($node['data']) ?? [] as $name) {
                $defined[$name] = true;
            }
        }

        $issues = [];
        foreach ($graph->nodes() as $node) {
            $handler = $this->registry->get($node['type']);
            if ($handler === null) {
                continue;
            }

            $used = [];
            foreach ($handler->texts($node['data']) as $text) {
                foreach (Interpolator::variablesIn($text) as $name) {
                    $used[$name] = true;
                }
            }

            foreach (array_keys($used) as $name) {
                if (isset($defined[$name])) {
                    continue;
                }

                $issues[] = Issue::error(str_starts_with($name, 'cliente.')
                    ? "{$handler->label()}: {{{$name}}} solo existe después de un bloque «Datos del cliente». Agrégalo antes de este mensaje."
                    : "{$handler->label()}: la variable {{{$name}}} no existe. Revisa cómo está escrita.", $node['id']);
            }
        }

        return $issues;
    }

    private function waitsForInput(array $node): bool
    {
        return (bool) $this->registry->get($node['type'])?->waitsForInput();
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function unique(array $issues): array
    {
        $seen = [];
        $out  = [];
        foreach ($issues as $issue) {
            $key = $issue->severity . '|' . $issue->nodeId . '|' . $issue->message;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $issue;
            }
        }

        return $out;
    }
}
