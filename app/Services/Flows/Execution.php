<?php

namespace App\Services\Flows;

use App\Services\Flows\IO\FlowIO;
use Carbon\CarbonImmutable;

/**
 * El estado de una ejecución mientras el motor la avanza: variables, reintentos
 * por bloque y las notas que cada bloque deja para la traza.
 *
 * Es igual para el motor de verdad y para el simulador; lo único que cambia es
 * el FlowIO que trae adentro.
 */
final class Execution
{
    /**
     * Variables que existen siempre, sin que ningún bloque las defina. El editor
     * las ofrece en la paleta y el validador no las marca como desconocidas.
     */
    public const BUILTINS = [
        'contacto.nombre'        => 'Nombre del contacto',
        'contacto.primer_nombre' => 'Primer nombre del contacto',
        'contacto.telefono'      => 'Teléfono del contacto',
        'empresa.nombre'         => 'Nombre de tu empresa',
        'saludo'                 => 'Buenos días / Buenas tardes / Buenas noches',
        'fecha.hoy'              => 'Fecha de hoy (dd/mm/aaaa)',
        'fecha.hora'             => 'Hora actual (HH:MM)',
        'fecha.dia'              => 'Día de la semana',
        'mensaje_inicial'        => 'El mensaje con el que el cliente abrió el flujo',
        'respuesta'              => 'La última respuesta del cliente',
        'opcion'                 => 'La última opción que eligió en un menú',
    ];

    /** @var list<array{output: ?string, message_id: ?int, detail: array, input: ?string}> */
    private array $notes = [];

    /**
     * @param array<string, string|null> $variables
     * @param array<string, mixed>       $state
     */
    public function __construct(
        public readonly FlowGraph $graph,
        public readonly FlowIO $io,
        public readonly string $timezone,
        public array $variables = [],
        public array $state = [],
    ) {}

    public function get(string $name): ?string
    {
        if (array_key_exists($name, $this->variables)) {
            return $this->variables[$name] === null ? null : (string) $this->variables[$name];
        }

        return $this->builtin($name);
    }

    public function set(string $name, ?string $value): void
    {
        $this->variables[$name] = $value;
    }

    public function render(string $text): string
    {
        return Interpolator::render($text, fn (string $name) => $this->get($name));
    }

    /** La hora en la zona del flujo (horarios y saludo). */
    public function now(): CarbonImmutable
    {
        try {
            return $this->io->now()->setTimezone($this->timezone);
        } catch (\Throwable) {
            // Zona inválida guardada: el validador la rechaza al publicar, pero un
            // dato roto no debe tumbar la ejecución.
            return $this->io->now()->setTimezone(config('flows.default_timezone', 'America/Bogota'));
        }
    }

    public function retries(string $nodeId): int
    {
        return (int) ($this->state['retries'][$nodeId] ?? 0);
    }

    public function bumpRetries(string $nodeId): int
    {
        $this->state['retries'][$nodeId] = $this->retries($nodeId) + 1;

        return $this->state['retries'][$nodeId];
    }

    public function resetRetries(string $nodeId): void
    {
        unset($this->state['retries'][$nodeId]);
    }

    /** Deja constancia en la traza de lo que hizo el bloque. */
    public function note(?string $output = null, ?int $messageId = null, array $detail = [], ?string $input = null): void
    {
        $this->notes[] = [
            'output'     => $output,
            'message_id' => $messageId,
            'detail'     => $detail,
            'input'      => $input,
        ];
    }

    /**
     * Las notas del bloque que acaba de correr, fundidas en un solo registro
     * (un Menú con reintento envía dos mensajes: quedan los dos textos).
     *
     * @return array{output: ?string, message_id: ?int, detail: array, input: ?string}
     */
    public function takeNotes(): array
    {
        $merged = ['output' => null, 'message_id' => null, 'detail' => [], 'input' => null];

        foreach ($this->notes as $note) {
            if ($note['output'] !== null) {
                $merged['output'] = $merged['output'] === null
                    ? $note['output']
                    : $merged['output'] . "\n—\n" . $note['output'];
            }
            $merged['message_id'] = $note['message_id'] ?? $merged['message_id'];
            $merged['detail']     = array_merge($merged['detail'], $note['detail']);
            $merged['input']      = $note['input'] ?? $merged['input'];
        }

        $this->notes = [];

        return $merged;
    }

    private function builtin(string $name): ?string
    {
        return match ($name) {
            'contacto.nombre'        => (string) ($this->io->contact()['name'] ?? ''),
            'contacto.primer_nombre' => $this->firstWord((string) ($this->io->contact()['name'] ?? '')),
            'contacto.telefono'      => (string) ($this->io->contact()['phone'] ?? ''),
            'empresa.nombre'         => $this->io->tenantName(),
            'saludo'                 => $this->greeting(),
            'fecha.hoy'              => $this->now()->format('d/m/Y'),
            'fecha.hora'             => $this->now()->format('H:i'),
            'fecha.dia'              => $this->now()->locale('es')->isoFormat('dddd'),
            default                  => null,
        };
    }

    private function greeting(): string
    {
        $hour = (int) $this->now()->format('H');

        return $hour < 12 ? 'Buenos días' : ($hour < 19 ? 'Buenas tardes' : 'Buenas noches');
    }

    private function firstWord(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : (preg_split('/\s+/u', $text)[0] ?? '');
    }
}
