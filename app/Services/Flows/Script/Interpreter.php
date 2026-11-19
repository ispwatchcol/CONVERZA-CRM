<?php

namespace App\Services\Flows\Script;

use App\Services\Flows\Nodes\QuestionNode;
use Illuminate\Support\Str;

/**
 * Ejecuta el código del bloque «Código»: un subconjunto de JavaScript, en una
 * caja cerrada.
 *
 * Por qué un intérprete propio y no Node/V8: el código lo escribe cada ISP y
 * corre en el mismo servidor que todos. Aquí el código NO PUEDE, por
 * construcción, tocar nada fuera de su caja: no hay red, archivos, base de datos,
 * procesos, ni funciones de PHP; solo existe lo que este archivo define. Y no
 * puede colgar al servidor: hay topes de pasos, tiempo, largo de textos y tamaño
 * de listas, y no se pueden declarar funciones (no hay recursión).
 *
 * Lo único que ve del mundo son las variables del flujo (`vars`, de solo
 * lectura salvo las que crea) y lo único que deja son esas variables, el nombre
 * de la salida (`return "vip"`) y lo que imprima con log().
 *
 * Diferencias con JavaScript, a propósito y en la ayuda del editor:
 *   - null y undefined son lo mismo, y como texto se ven vacíos ("" y no "null").
 *   - Las variables del flujo siempre son texto: numero(vars.saldo) las vuelve número.
 */
final class Interpreter
{
    public const MAX_CODE   = 10000;   // caracteres del código
    public const MAX_STEPS  = 20000;   // sentencias + vueltas de ciclo + llamadas
    public const MAX_MS     = 250;     // tiempo de reloj
    public const MAX_STRING = 20000;   // caracteres de un texto
    public const MAX_ITEMS  = 1000;    // elementos de una lista u objeto
    public const MAX_WRITES = 20;      // variables del flujo que puede crear
    public const MAX_VALUE  = 4096;    // caracteres de cada variable que deja (lo que cabe en un WhatsApp)
    public const MAX_LOGS   = 50;

    /** Grupos de variables del flujo con punto: vars.cliente.saldo = vars["cliente.saldo"]. */
    private const GROUPS = ['contacto', 'empresa', 'fecha', 'cliente', 'pago', 'agente', 'codigo'];

    private const STRING_METHODS = [
        'toUpperCase', 'toLowerCase', 'trim', 'trimStart', 'trimEnd', 'includes', 'startsWith', 'endsWith',
        'indexOf', 'lastIndexOf', 'slice', 'substring', 'split', 'replace', 'replaceAll', 'padStart', 'padEnd',
        'charAt', 'at', 'repeat', 'concat', 'toString',
    ];

    private const LIST_METHODS = [
        'includes', 'indexOf', 'join', 'slice', 'push', 'pop', 'shift', 'unshift', 'concat', 'reverse', 'at', 'sort', 'toString',
    ];

    private const NUMBER_METHODS = ['toFixed', 'toString'];

    private int $steps = 0;

    private float $started = 0.0;

    /** @var list<array<string, array{v: mixed, c: bool}>> */
    private array $scopes = [];

    /** @var array<string, string> Variables del flujo que el código creó o cambió. */
    private array $writes = [];

    /** @var list<string> */
    private array $logs = [];

    /** @param \Closure(string): ?string $resolve Lee una variable del flujo por nombre. */
    public function __construct(private readonly \Closure $resolve) {}

    /** Revisa la sintaxis sin ejecutar. null = está bien; si no, el error. */
    public static function check(string $code): ?ScriptError
    {
        try {
            self::parse($code);

            return null;
        } catch (ScriptError $e) {
            return $e;
        }
    }

    /**
     * Los nombres de las variables del flujo que el código escribe (vars.x = …),
     * para que los bloques siguientes puedan usar {{x}}. Es una lectura del
     * texto, no una ejecución: un nombre armado en tiempo de ejecución no sale aquí.
     *
     * @return list<string>
     */
    public static function writtenNames(string $code): array
    {
        preg_match_all(
            '/\bvars\s*(?:\.\s*([A-Za-z_$][A-Za-z0-9_$]*)|\[\s*([\'"])([^\'"\\\\]+)\2\s*\])\s*(?:[+\-*\/%]?=(?!=)|\+\+|--)/',
            $code,
            $matches,
            PREG_SET_ORDER,
        );

        $names = [];
        foreach ($matches as $m) {
            $names[] = ($m[1] ?? '') !== '' ? $m[1] : $m[3];
        }

        return array_values(array_unique($names));
    }

    public function run(string $code): ScriptResult
    {
        $program = self::parse($code);

        $this->steps   = 0;
        $this->started = hrtime(true) / 1e6;
        $this->writes  = [];
        $this->logs    = [];
        $this->scopes  = [$this->globals(), []];

        $returned = null;

        try {
            foreach ($program as $statement) {
                $signal = $this->exec($statement);

                if ($signal !== null) {
                    if ($signal[0] === 'ret') {
                        $returned = $signal[1];
                        break;
                    }

                    throw new ScriptError("«{$signal[0]}» fuera de un ciclo.", $statement['l']);
                }
            }
        } catch (ScriptError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Nada de PHP debería escapar, pero si escapa no se le muestra al ISP
            // un error interno: se reporta y el bloque sale por «Error».
            report($e);

            throw new ScriptError('El código hizo algo que no se pudo ejecutar.');
        }

        return new ScriptResult($this->writes, $returned, $this->logs, $this->steps);
    }

    /** @return list<array> */
    private static function parse(string $code): array
    {
        if (mb_strlen($code) > self::MAX_CODE) {
            throw new ScriptError('El código es demasiado largo (máximo ' . number_format(self::MAX_CODE, 0, ',', '.') . ' caracteres).');
        }

        return Parser::parse($code);
    }

    // ── Sentencias ───────────────────────────────────────────────────────────

    /** @return array{0: string, 1?: mixed}|null Una señal (break, continue, return) o null. */
    private function exec(array $s): ?array
    {
        $this->tick($s['l']);

        switch ($s['k']) {
            case 'expr':
                $this->eval($s['e']);

                return null;

            case 'let':
                foreach ($s['decls'] as [$name, $init]) {
                    $this->declare($name, $init !== null ? $this->eval($init) : null, $s['const'], $s['l']);
                }

                return null;

            case 'block':
                $this->scopes[] = [];

                try {
                    foreach ($s['body'] as $statement) {
                        if (($signal = $this->exec($statement)) !== null) {
                            return $signal;
                        }
                    }

                    return null;
                } finally {
                    array_pop($this->scopes);
                }

            case 'if':
                if ($this->truthy($this->eval($s['test']))) {
                    return $this->exec($s['then']);
                }

                return $s['else'] !== null ? $this->exec($s['else']) : null;

            case 'while':
                while (true) {
                    $this->tick($s['l']);

                    if (! $this->truthy($this->eval($s['test']))) {
                        return null;
                    }

                    $signal = $this->exec($s['body']);

                    if ($signal !== null) {
                        if ($signal[0] === 'break') {
                            return null;
                        }
                        if ($signal[0] === 'ret') {
                            return $signal;
                        }
                    }
                }

            case 'for':
                $this->scopes[] = [];

                try {
                    if ($s['init'] !== null) {
                        $this->exec($s['init']);
                    }

                    while (true) {
                        $this->tick($s['l']);

                        if ($s['test'] !== null && ! $this->truthy($this->eval($s['test']))) {
                            return null;
                        }

                        $signal = $this->exec($s['body']);

                        if ($signal !== null) {
                            if ($signal[0] === 'break') {
                                return null;
                            }
                            if ($signal[0] === 'ret') {
                                return $signal;
                            }
                        }

                        if ($s['update'] !== null) {
                            $this->eval($s['update']);
                        }
                    }
                } finally {
                    array_pop($this->scopes);
                }

            case 'forof':
                $iterable = $this->eval($s['iter']);
                $items    = match (true) {
                    $iterable instanceof ScriptList => $iterable->items,
                    is_string($iterable)            => mb_str_split($iterable),
                    $iterable instanceof ScriptMap  => throw new ScriptError('Para recorrer un objeto usa for (const llave of Object.keys(objeto)).', $s['l']),
                    default                         => throw new ScriptError('for…of recorre una lista o un texto.', $s['l']),
                };

                foreach ($items as $item) {
                    $this->tick($s['l']);
                    $this->scopes[] = [];

                    try {
                        $this->declare($s['name'], $item, false, $s['l']);
                        $signal = $this->exec($s['body']);
                    } finally {
                        array_pop($this->scopes);
                    }

                    if ($signal !== null) {
                        if ($signal[0] === 'break') {
                            break;
                        }
                        if ($signal[0] === 'ret') {
                            return $signal;
                        }
                    }
                }

                return null;

            case 'break':
            case 'continue':
                return [$s['k']];

            case 'return':
                return ['ret', $s['arg'] !== null ? $this->eval($s['arg']) : null];

            case 'empty':
                return null;
        }

        throw new ScriptError('Sentencia desconocida.', $s['l']);
    }

    // ── Expresiones ──────────────────────────────────────────────────────────

    private function eval(array $e): mixed
    {
        switch ($e['k']) {
            case 'num':
            case 'str':
            case 'lit':
                return $e['v'];

            case 'tmpl':
                $out = '';
                foreach ($e['parts'] as $part) {
                    $out .= is_string($part) ? $part : $this->str($this->eval($part));
                }

                return $this->checkString($out, $e['l']);

            case 'id':
                return $this->lookup($e['n'], $e['l']);

            case 'arr':
                $items = [];
                foreach ($e['items'] as $item) {
                    $items[] = $this->eval($item);
                }

                return new ScriptList($this->checkItems($items, $e['l']));

            case 'obj':
                $props = [];
                foreach ($e['props'] as [$key, $value]) {
                    $props[$key] = $this->eval($value);
                }

                return new ScriptMap($this->checkItems($props, $e['l']));

            case 'mem':
                $object = $this->eval($e['o']);

                if ($e['opt'] && $object === null) {
                    return null;
                }

                return $this->get($object, $this->eval($e['p']), $e['l']);

            case 'call':
                return $this->call($e);

            case 'un':
                if ($e['op'] === 'typeof') {
                    return $this->typeOf($e['a']['k'] === 'id' && ! $this->defined($e['a']['n']) ? null : $this->eval($e['a']));
                }

                $value = $this->eval($e['a']);

                return match ($e['op']) {
                    '!'     => ! $this->truthy($value),
                    '-'     => -$this->num($value),
                    default => $this->num($value),
                };

            case 'bin':
                return $this->binary($e['op'], $this->eval($e['a']), $this->eval($e['b']), $e['l']);

            case 'log':
                $left = $this->eval($e['a']);

                return match ($e['op']) {
                    '&&'    => $this->truthy($left) ? $this->eval($e['b']) : $left,
                    '||'    => $this->truthy($left) ? $left : $this->eval($e['b']),
                    default => $left !== null ? $left : $this->eval($e['b']),
                };

            case 'cond':
                return $this->truthy($this->eval($e['t'])) ? $this->eval($e['a']) : $this->eval($e['b']);

            case 'asg':
                $value = $this->eval($e['v']);

                if ($e['op'] !== '=') {
                    $value = $this->binary(substr($e['op'], 0, 1), $this->eval($e['target']), $value, $e['l']);
                }

                $this->assign($e['target'], $value, $e['l']);

                return $value;

            case 'upd':
                $old = $this->num($this->eval($e['target']));
                $new = $e['op'] === '++' ? $old + 1 : $old - 1;
                $this->assign($e['target'], $new, $e['l']);

                return $e['prefix'] ? $new : $old;
        }

        throw new ScriptError('Expresión desconocida.', $e['l']);
    }

    private function binary(string $op, mixed $a, mixed $b, int $line): mixed
    {
        switch ($op) {
            case '+':
                if (is_string($a) || is_string($b) || $this->isObject($a) || $this->isObject($b)) {
                    return $this->checkString($this->str($a) . $this->str($b), $line);
                }

                return $this->num($a) + $this->num($b);

            case '-':
                return $this->num($a) - $this->num($b);

            case '*':
                return $this->num($a) * $this->num($b);

            case '/':
                return fdiv($this->num($a), $this->num($b));

            case '%':
                return fmod($this->num($a), $this->num($b));

            case '===':
                return $this->strictEquals($a, $b);

            case '!==':
                return ! $this->strictEquals($a, $b);

            case '==':
                return $this->looseEquals($a, $b);

            case '!=':
                return ! $this->looseEquals($a, $b);

            case '<':
            case '>':
            case '<=':
            case '>=':
                if (is_string($a) && is_string($b)) {
                    $cmp = strcmp($a, $b);
                } else {
                    $x = $this->num($a);
                    $y = $this->num($b);

                    if (is_nan((float) $x) || is_nan((float) $y)) {
                        return false;
                    }

                    $cmp = $x <=> $y;
                }

                return match ($op) {
                    '<'     => $cmp < 0,
                    '>'     => $cmp > 0,
                    '<='    => $cmp <= 0,
                    default => $cmp >= 0,
                };
        }

        throw new ScriptError("Operador «{$op}» desconocido.", $line);
    }

    // ── Variables ────────────────────────────────────────────────────────────

    private function declare(string $name, mixed $value, bool $const, int $line): void
    {
        if ($name === 'vars') {
            throw new ScriptError('«vars» ya existe: son las variables del flujo.', $line);
        }

        $top = array_key_last($this->scopes);

        if (array_key_exists($name, $this->scopes[$top])) {
            throw new ScriptError("La variable «{$name}» ya estaba declarada aquí.", $line);
        }

        $this->scopes[$top][$name] = ['v' => $value, 'c' => $const];
    }

    private function defined(string $name): bool
    {
        foreach ($this->scopes as $scope) {
            if (array_key_exists($name, $scope)) {
                return true;
            }
        }

        return false;
    }

    private function lookup(string $name, int $line): mixed
    {
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return $this->scopes[$i][$name]['v'];
            }
        }

        throw new ScriptError("«{$name}» no existe. Declárala con let, o si es del flujo usa vars.{$name}.", $line);
    }

    private function assign(array $target, mixed $value, int $line): void
    {
        if ($target['k'] === 'mem') {
            $this->set($this->eval($target['o']), $this->eval($target['p']), $value, $line);

            return;
        }

        $name = $target['n'];

        // El ámbito global (0) es de solo lectura: Math, vars, numero…
        for ($i = count($this->scopes) - 1; $i >= 1; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                if ($this->scopes[$i][$name]['c']) {
                    throw new ScriptError("«{$name}» es constante (const): no se le puede cambiar el valor.", $line);
                }
                $this->scopes[$i][$name]['v'] = $value;

                return;
            }
        }

        throw new ScriptError(
            array_key_exists($name, $this->scopes[0])
                ? "«{$name}» es parte del lenguaje: no se puede cambiar."
                : "«{$name}» no está declarada: usa let {$name} = …  (para dejarla en el flujo: vars.{$name} = …).",
            $line,
        );
    }

    // ── Propiedades y métodos ────────────────────────────────────────────────

    private function get(mixed $object, mixed $key, int $line): mixed
    {
        if ($object instanceof VarsProxy) {
            return $this->readVar($object, $this->str($key));
        }

        $name = $this->str($key);

        if (is_string($object)) {
            if ($name === 'length') {
                return mb_strlen($object);
            }
            if (in_array($name, self::STRING_METHODS, true)) {
                return new BoundMethod($object, $name);
            }

            if (! $this->isIndex($key)) {
                return null;
            }

            $char = mb_substr($object, (int) $key, 1);

            return $char === '' ? null : $char;
        }

        if ($object instanceof ScriptList) {
            if ($name === 'length') {
                return count($object->items);
            }
            if (in_array($name, self::LIST_METHODS, true)) {
                return new BoundMethod($object, $name);
            }

            return $this->isIndex($key) ? ($object->items[(int) $key] ?? null) : null;
        }

        if ($object instanceof ScriptMap) {
            return $object->props[$name] ?? null;
        }

        if ($object instanceof Ns) {
            return $object->members[$name] ?? null;
        }

        if (is_int($object) || is_float($object)) {
            return in_array($name, self::NUMBER_METHODS, true) ? new BoundMethod($object, $name) : null;
        }

        if ($object === null) {
            throw new ScriptError("No se puede leer «{$name}» de un valor vacío (null). Usa ?. si puede no estar: x?.{$name}", $line);
        }

        return null;
    }

    private function set(mixed $object, mixed $key, mixed $value, int $line): void
    {
        if ($object instanceof VarsProxy) {
            if ($object->prefix !== '') {
                throw new ScriptError("Las variables «{$object->prefix}…» son del flujo y no se pueden cambiar. Crea una tuya: vars.mi_variable = …", $line);
            }

            $this->writeVar($this->str($key), $value, $line);

            return;
        }

        if ($object instanceof ScriptList) {
            if (! $this->isIndex($key) || (int) $key > count($object->items)) {
                throw new ScriptError('En una lista solo se puede cambiar una posición que existe (o la siguiente). Para agregar usa push.', $line);
            }
            $object->items[(int) $key] = $value;
            $this->checkItems($object->items, $line);

            return;
        }

        if ($object instanceof ScriptMap) {
            $object->props[$this->str($key)] = $value;
            $this->checkItems($object->props, $line);

            return;
        }

        throw new ScriptError('Eso no se puede modificar.', $line);
    }

    private function readVar(VarsProxy $proxy, string $key): mixed
    {
        $name = $proxy->prefix . $key;

        if ($proxy->prefix === '' && array_key_exists($name, $this->writes)) {
            return $this->writes[$name];
        }

        $value = ($this->resolve)($name);

        if ($value === null && $proxy->prefix === '' && in_array($key, self::GROUPS, true)) {
            return new VarsProxy($key . '.');
        }

        return $value;
    }

    private function writeVar(string $name, mixed $value, int $line): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
            throw new ScriptError("«{$name}» no sirve como nombre de variable del flujo: minúsculas, números y _ (sin puntos, espacios ni tildes; hasta 40).", $line);
        }

        if (in_array($name, QuestionNode::RESERVED, true) || in_array($name, self::GROUPS, true)) {
            throw new ScriptError("«{$name}» es una variable del sistema: usa otro nombre.", $line);
        }

        if (! array_key_exists($name, $this->writes) && count($this->writes) >= self::MAX_WRITES) {
            throw new ScriptError('El código crea demasiadas variables del flujo (máximo ' . self::MAX_WRITES . ').', $line);
        }

        $this->writes[$name] = mb_substr($this->str($value), 0, self::MAX_VALUE);
    }

    private function call(array $e): mixed
    {
        $callee = $this->eval($e['c']);
        $args   = [];

        foreach ($e['args'] as $arg) {
            $args[] = $this->eval($arg);
        }

        $this->tick($e['l']);

        if ($callee instanceof NativeFn) {
            return ($callee->fn)($args, $e['l']);
        }

        if ($callee instanceof BoundMethod) {
            return $this->method($callee->target, $callee->name, $args, $e['l']);
        }

        $what = $e['c']['k'] === 'mem' && $e['c']['p']['k'] === 'str' ? "«{$e['c']['p']['v']}»" : 'Eso';

        throw new ScriptError("{$what} no es una función que se pueda usar aquí.", $e['l']);
    }

    private function method(mixed $target, string $name, array $args, int $line): mixed
    {
        $a = $args[0] ?? null;
        $b = $args[1] ?? null;

        if (is_string($target)) {
            $s   = $target;
            $len = mb_strlen($s);

            return match ($name) {
                'toUpperCase' => mb_strtoupper($s),
                'toLowerCase' => mb_strtolower($s),
                'trim'        => $this->trim($s),
                'trimStart'   => $this->trim($s, 'start'),
                'trimEnd'     => $this->trim($s, 'end'),
                'includes'    => str_contains($s, $this->str($a)),
                'startsWith'  => str_starts_with($s, $this->str($a)),
                'endsWith'    => str_ends_with($s, $this->str($a)),
                'indexOf'     => ($pos = mb_strpos($s, $this->str($a))) === false ? -1 : $pos,
                'lastIndexOf' => ($pos = mb_strrpos($s, $this->str($a))) === false ? -1 : $pos,
                'slice'       => $this->sliceString($s, $a, $b, $len),
                'substring'   => $this->substring($s, $a, $b, $len),
                'split'       => $this->split($s, $a, $b, $line),
                'replace'     => $this->replace($s, $a, $b, false, $line),
                'replaceAll'  => $this->replace($s, $a, $b, true, $line),
                'padStart'    => $this->pad($s, $a, $b, STR_PAD_LEFT, $line),
                'padEnd'      => $this->pad($s, $a, $b, STR_PAD_RIGHT, $line),
                'charAt'      => mb_substr($s, (int) $this->num($a), 1),
                'at'          => $this->at(mb_str_split($s), $a),
                'repeat'      => $this->repeat($s, $a, $line),
                'concat'      => $this->checkString($s . implode('', array_map(fn ($x) => $this->str($x), $args)), $line),
                default       => $s,
            };
        }

        if ($target instanceof ScriptList) {
            $items = &$target->items;

            return match ($name) {
                'includes' => $this->listIndex($items, $a) !== -1,
                'indexOf'  => $this->listIndex($items, $a),
                'join'     => $this->checkString(implode($a === null ? ',' : $this->str($a), array_map(fn ($x) => $this->str($x), $items)), $line),
                'slice'    => new ScriptList($this->sliceList($items, $a, $b)),
                'push'     => $this->pushList($target, $args, false, $line),
                'unshift'  => $this->pushList($target, $args, true, $line),
                'pop'      => array_pop($items),
                'shift'    => array_shift($items),
                'concat'   => new ScriptList($this->checkItems(array_merge($items, ...array_map(fn ($x) => $x instanceof ScriptList ? $x->items : [$x], $args)), $line)),
                'reverse'  => $this->reverseList($target),
                'at'       => $this->at($items, $a),
                'sort'     => $this->sortList($target),
                default    => $this->str($target),
            };
        }

        // Número.
        return match ($name) {
            'toFixed' => number_format((float) $target, max(0, min(10, (int) $this->num($a))), '.', ''),
            default   => $this->str($target),
        };
    }

    // ── Lo que trae el lenguaje (ámbito global, de solo lectura) ─────────────

    /** @return array<string, array{v: mixed, c: bool}> */
    private function globals(): array
    {
        $fn = fn (string $name, \Closure $f) => new NativeFn($name, $f);

        $log = $fn('log', function (array $args) {
            if (count($this->logs) < self::MAX_LOGS) {
                $this->logs[] = mb_substr(implode(' ', array_map(fn ($x) => is_string($x) ? $x : $this->json($x), $args)), 0, 500);
            }

            return null;
        });

        $math = new Ns('Math', [
            'round'  => $fn('round', fn ($a) => $this->finite(floor($this->num($a[0] ?? null) + 0.5))),
            'floor'  => $fn('floor', fn ($a) => $this->finite(floor($this->num($a[0] ?? null)))),
            'ceil'   => $fn('ceil', fn ($a) => $this->finite(ceil($this->num($a[0] ?? null)))),
            'trunc'  => $fn('trunc', fn ($a) => $this->finite((float) (int) $this->num($a[0] ?? null))),
            'abs'    => $fn('abs', fn ($a) => abs($this->num($a[0] ?? null))),
            'sqrt'   => $fn('sqrt', fn ($a) => sqrt($this->num($a[0] ?? null))),
            'pow'    => $fn('pow', fn ($a) => (float) pow((float) $this->num($a[0] ?? null), (float) $this->num($a[1] ?? null))),
            'min'    => $fn('min', fn ($a) => $a === [] ? INF : min(array_map(fn ($x) => $this->num($x), $a))),
            'max'    => $fn('max', fn ($a) => $a === [] ? -INF : max(array_map(fn ($x) => $this->num($x), $a))),
            'random' => $fn('random', fn () => mt_rand() / mt_getrandmax()),
            'PI'     => M_PI,
        ]);

        $object = new Ns('Object', [
            'keys'   => $fn('keys', fn ($a) => new ScriptList(match (true) {
                ($a[0] ?? null) instanceof ScriptMap  => array_map('strval', array_keys($a[0]->props)),
                ($a[0] ?? null) instanceof ScriptList => array_map('strval', array_keys($a[0]->items)),
                default                               => [],
            })),
            'values' => $fn('values', fn ($a) => new ScriptList(match (true) {
                ($a[0] ?? null) instanceof ScriptMap  => array_values($a[0]->props),
                ($a[0] ?? null) instanceof ScriptList => $a[0]->items,
                default                               => [],
            })),
        ]);

        $json = new Ns('JSON', [
            'stringify' => $fn('stringify', fn ($a, $line) => $this->checkString($this->json($a[0] ?? null), $line)),
            'parse'     => $fn('parse', function ($a, $line) {
                $text = $this->checkString($this->str($a[0] ?? null), $line);
                try {
                    $data = json_decode($text, true, 10, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ScriptError('JSON.parse: ese texto no es JSON válido.', $line);
                }

                return $this->fromJson($data, $line);
            }),
        ]);

        $globals = [
            'vars'       => new VarsProxy(),
            'Math'       => $math,
            'Object'     => $object,
            'JSON'       => $json,
            'console'    => new Ns('console', ['log' => $log]),
            'log'        => $log,
            'Number'     => $fn('Number', fn ($a) => $this->num($a[0] ?? null)),
            'String'     => $fn('String', fn ($a) => $this->str($a[0] ?? null)),
            'Boolean'    => $fn('Boolean', fn ($a) => $this->truthy($a[0] ?? null)),
            'parseInt'   => $fn('parseInt', fn ($a) => preg_match('/^\s*([+-]?\d+)/', $this->str($a[0] ?? null), $m) ? (int) $m[1] : NAN),
            'parseFloat' => $fn('parseFloat', fn ($a) => preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/', $this->str($a[0] ?? null), $m) ? (float) $m[1] : NAN),
            'isNaN'      => $fn('isNaN', fn ($a) => is_nan((float) $this->num($a[0] ?? null))),
            // Propias de Converza, pensadas para un ISP en Colombia.
            'numero'     => $fn('numero', fn ($a) => $this->colombianNumber($a[0] ?? null)),
            'moneda'     => $fn('moneda', fn ($a) => $this->money($a[0] ?? null)),
            'sinTildes'  => $fn('sinTildes', fn ($a) => Str::ascii($this->str($a[0] ?? null))),
            'NaN'        => NAN,
            'Infinity'   => INF,
        ];

        return array_map(fn ($v) => ['v' => $v, 'c' => true], $globals);
    }

    // ── Conversión de valores (reglas de JavaScript, salvo lo dicho arriba) ──

    /** Como texto. */
    public function str(mixed $v, int $depth = 0): string
    {
        return match (true) {
            $v === null                 => '',
            $v === true                 => 'true',
            $v === false                => 'false',
            is_string($v)               => $v,
            is_int($v)                  => (string) $v,
            is_float($v)                => self::formatNumber($v),
            $v instanceof ScriptList    => $depth > 5 ? '…' : implode(',', array_map(fn ($x) => $this->str($x, $depth + 1), $v->items)),
            $v instanceof ScriptMap     => $this->json($v),
            $v instanceof NativeFn,
            $v instanceof BoundMethod   => '[función]',
            default                     => '',
        };
    }

    public static function formatNumber(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n == 0.0) {
            return '0';
        }
        if (floor($n) === $n && abs($n) < 1e15) {
            return sprintf('%.0f', $n);
        }

        $out = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');

        return $out === '-0' ? '0' : $out;
    }

    private function num(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }

        if ($v === null || $v === false) {
            return 0;
        }

        if ($v === true) {
            return 1;
        }

        if (is_string($v)) {
            $t = trim($v);

            if ($t === '') {
                return 0;
            }

            return is_numeric($t) && ! preg_match('/^0x/i', $t) ? $t + 0 : NAN;
        }

        if ($v instanceof ScriptList) {
            return $v->items === [] ? 0 : (count($v->items) === 1 ? $this->num($v->items[0]) : NAN);
        }

        return NAN;
    }

    private function truthy(mixed $v): bool
    {
        return match (true) {
            $v === null, $v === false, $v === '' => false,
            is_int($v)                           => $v !== 0,
            is_float($v)                         => $v != 0.0 && ! is_nan($v),
            default                              => true,
        };
    }

    private function strictEquals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return $this->num($a) == $this->num($b) && ! is_nan((float) $this->num($a));
        }

        $aNum = is_int($a) || is_float($a);
        $bNum = is_int($b) || is_float($b);

        if (($aNum && is_string($b)) || ($bNum && is_string($a))) {
            return $this->num($a) == $this->num($b);
        }

        return $this->strictEquals($a, $b);
    }

    private function typeOf(mixed $v): string
    {
        return match (true) {
            $v === null                                      => 'undefined',
            is_bool($v)                                      => 'boolean',
            is_int($v), is_float($v)                         => 'number',
            is_string($v)                                    => 'string',
            $v instanceof NativeFn, $v instanceof BoundMethod => 'function',
            default                                          => 'object',
        };
    }

    private function isObject(mixed $v): bool
    {
        return $v instanceof ScriptList || $v instanceof ScriptMap;
    }

    private function json(mixed $v, int $depth = 0): string
    {
        return (string) json_encode($this->toJson($v, $depth), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function toJson(mixed $v, int $depth): mixed
    {
        if ($depth > 10) {
            return null;
        }

        return match (true) {
            $v instanceof ScriptList => array_map(fn ($x) => $this->toJson($x, $depth + 1), $v->items),
            $v instanceof ScriptMap  => (object) array_map(fn ($x) => $this->toJson($x, $depth + 1), $v->props),
            is_float($v)             => is_finite($v) ? (floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v) : null,
            is_scalar($v), $v === null => $v,
            default                  => null,
        };
    }

    private function fromJson(mixed $data, int $line): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $converted = array_map(fn ($x) => $this->fromJson($x, $line), $data);
        $this->checkItems($converted, $line);

        return array_is_list($data) ? new ScriptList($converted) : new ScriptMap(array_combine(array_map('strval', array_keys($converted)), $converted));
    }

    /** "$50.000" → 50000 · "1.234,5" → 1234.5 · "" → 0. Como se escriben los valores en Colombia. */
    private function colombianNumber(mixed $v): int|float
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }

        $t = preg_replace('/[^\d,.\-]/', '', $this->str($v));

        if ($t === '' || $t === '-') {
            return $this->str($v) === '' ? 0 : NAN;
        }

        $negative = str_starts_with($t, '-');
        $t        = str_replace('-', '', $t);

        if (str_contains($t, '.') && str_contains($t, ',')) {
            // El que va de último es el decimal.
            $t = strrpos($t, ',') > strrpos($t, '.')
                ? str_replace(',', '.', str_replace('.', '', $t))
                : str_replace(',', '', $t);
        } elseif (str_contains($t, '.')) {
            $t = preg_match('/^\d{1,3}(\.\d{3})+$/', $t) ? str_replace('.', '', $t) : $t;
        } elseif (str_contains($t, ',')) {
            $t = preg_match('/^\d{1,3}(,\d{3})+$/', $t) ? str_replace(',', '', $t) : str_replace(',', '.', $t);
        }

        if (! is_numeric($t)) {
            return NAN;
        }

        $n = $t + 0;

        return $negative ? -$n : $n;
    }

    /** 50000 → "$50.000". */
    private function money(mixed $v): string
    {
        $n = $this->colombianNumber($v);

        if (is_nan((float) $n) || is_infinite((float) $n)) {
            return '';
        }

        return ($n < 0 ? '-' : '') . '$' . number_format(abs(round((float) $n)), 0, ',', '.');
    }

    // ── Ayudas de textos y listas ────────────────────────────────────────────

    private function trim(string $s, string $side = 'both'): string
    {
        return match ($side) {
            'start' => preg_replace('/^\s+/u', '', $s) ?? $s,
            'end'   => preg_replace('/\s+$/u', '', $s) ?? $s,
            default => preg_replace('/^\s+|\s+$/u', '', $s) ?? $s,
        };
    }

    private function relativeIndex(mixed $v, int $len, int $default): int
    {
        if ($v === null) {
            return $default;
        }

        $n = $this->num($v);
        if (is_nan((float) $n)) {
            return 0;
        }

        $i = (int) $n;

        return $i < 0 ? max(0, $len + $i) : min($i, $len);
    }

    private function sliceString(string $s, mixed $a, mixed $b, int $len): string
    {
        $start = $this->relativeIndex($a, $len, 0);
        $end   = $this->relativeIndex($b, $len, $len);

        return $end > $start ? mb_substr($s, $start, $end - $start) : '';
    }

    private function substring(string $s, mixed $a, mixed $b, int $len): string
    {
        $start = max(0, min($len, (int) $this->num($a)));
        $end   = $b === null ? $len : max(0, min($len, (int) $this->num($b)));

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return mb_substr($s, $start, $end - $start);
    }

    private function split(string $s, mixed $sep, mixed $limit, int $line): ScriptList
    {
        $parts = match (true) {
            $sep === null => [$s],
            $this->str($sep) === '' => mb_str_split($s),
            default => explode($this->str($sep), $s),
        };

        if ($limit !== null) {
            $parts = array_slice($parts, 0, max(0, (int) $this->num($limit)));
        }

        return new ScriptList($this->checkItems($parts, $line));
    }

    private function replace(string $s, mixed $search, mixed $with, bool $all, int $line): string
    {
        $needle = $this->str($search);
        $value  = $this->str($with);

        if ($needle === '') {
            return $s;
        }

        if ($all) {
            return $this->checkString(str_replace($needle, $value, $s), $line);
        }

        $pos = strpos($s, $needle);

        return $pos === false ? $s : $this->checkString(substr_replace($s, $value, $pos, strlen($needle)), $line);
    }

    private function pad(string $s, mixed $length, mixed $fill, int $side, int $line): string
    {
        $target = (int) $this->num($length);
        $fill   = $fill === null ? ' ' : $this->str($fill);

        if ($target > self::MAX_STRING) {
            throw new ScriptError('Ese texto quedaría demasiado largo.', $line);
        }

        $missing = $target - mb_strlen($s);

        if ($missing <= 0 || $fill === '') {
            return $s;
        }

        $padding = mb_substr(str_repeat($fill, (int) ceil($missing / max(1, mb_strlen($fill)))), 0, $missing);

        return $side === STR_PAD_LEFT ? $padding . $s : $s . $padding;
    }

    private function repeat(string $s, mixed $times, int $line): string
    {
        $n = (int) $this->num($times);

        if ($n < 0 || mb_strlen($s) * $n > self::MAX_STRING) {
            throw new ScriptError('Ese texto quedaría demasiado largo.', $line);
        }

        return str_repeat($s, $n);
    }

    private function at(array $items, mixed $index): mixed
    {
        $i = (int) $this->num($index);

        return $items[$i < 0 ? count($items) + $i : $i] ?? null;
    }

    private function listIndex(array $items, mixed $value): int
    {
        foreach ($items as $i => $item) {
            if ($this->strictEquals($item, $value)) {
                return $i;
            }
        }

        return -1;
    }

    private function sliceList(array $items, mixed $a, mixed $b): array
    {
        $len   = count($items);
        $start = $this->relativeIndex($a, $len, 0);
        $end   = $this->relativeIndex($b, $len, $len);

        return $end > $start ? array_slice($items, $start, $end - $start) : [];
    }

    private function pushList(ScriptList $list, array $values, bool $front, int $line): int
    {
        $list->items = $front ? array_merge($values, $list->items) : array_merge($list->items, $values);
        $this->checkItems($list->items, $line);

        return count($list->items);
    }

    private function reverseList(ScriptList $list): ScriptList
    {
        $list->items = array_reverse($list->items);

        return $list;
    }

    /** Números de menor a mayor si todos lo son; si no, como texto (en español, sin importar tildes). */
    private function sortList(ScriptList $list): ScriptList
    {
        $numeric = array_filter($list->items, fn ($x) => is_int($x) || is_float($x)) === $list->items;

        usort($list->items, $numeric
            ? fn ($x, $y) => $x <=> $y
            : fn ($x, $y) => strcmp(mb_strtolower(Str::ascii($this->str($x))), mb_strtolower(Str::ascii($this->str($y)))));

        return $list;
    }

    private function isIndex(mixed $key): bool
    {
        return (is_int($key) || (is_float($key) && floor($key) === $key) || (is_string($key) && ctype_digit($key))) && (int) $key >= 0;
    }

    private function finite(float $n): int|float
    {
        return is_finite($n) && abs($n) < PHP_INT_MAX ? (int) $n : $n;
    }

    // ── Topes ────────────────────────────────────────────────────────────────

    private function tick(int $line): void
    {
        if (++$this->steps > self::MAX_STEPS) {
            throw new ScriptError('El código hizo demasiados pasos (más de ' . number_format(self::MAX_STEPS, 0, ',', '.') . '): ¿hay un ciclo que no termina?', $line);
        }

        if ($this->steps % 256 === 0 && hrtime(true) / 1e6 - $this->started > self::MAX_MS) {
            throw new ScriptError('El código tardó demasiado (más de ' . self::MAX_MS . ' ms).', $line);
        }
    }

    private function checkString(string $s, int $line): string
    {
        if (strlen($s) > self::MAX_STRING && mb_strlen($s) > self::MAX_STRING) {
            throw new ScriptError('Un texto quedó demasiado largo (máximo ' . number_format(self::MAX_STRING, 0, ',', '.') . ' caracteres).', $line);
        }

        return $s;
    }

    private function checkItems(array $items, int $line): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new ScriptError('Una lista u objeto quedó demasiado grande (máximo ' . number_format(self::MAX_ITEMS, 0, ',', '.') . ' elementos).', $line);
        }

        return $items;
    }
}
