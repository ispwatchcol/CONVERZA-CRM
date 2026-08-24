<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    use BelongsToTenant;

    /** Días ISO-8601 abreviados, para el resumen legible del horario. */
    private const DAY_LABELS = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue',
        5 => 'Vie', 6 => 'Sáb', 7 => 'Dom',
    ];

    protected $fillable = [
        'tenant_id',
        'bot_enabled',

        'schedule_enabled',
        'schedule_mode',
        'schedule_timezone',
        'schedule_days',
        'schedule_start',
        'schedule_end',

        'step_greeting_enabled',
        'step_branches_enabled',
        'step_qualify_subscribers_enabled',
        'step_qualify_name_enabled',
        'step_fallback_enabled',

        'msg_greeting',
        'msg_info',
        'msg_socio',
        'msg_demo',
        'msg_price',
        'msg_ask_subscribers',
        'msg_ask_name',
        'msg_handoff',
        'msg_fallback_1',
        'msg_fallback_2',
    ];

    protected $casts = [
        'bot_enabled'                      => 'boolean',
        'schedule_enabled'                 => 'boolean',
        'schedule_days'                    => 'array',
        'step_greeting_enabled'            => 'boolean',
        'step_branches_enabled'            => 'boolean',
        'step_qualify_subscribers_enabled' => 'boolean',
        'step_qualify_name_enabled'        => 'boolean',
        'step_fallback_enabled'            => 'boolean',
    ];

    /**
     * Fila del tenant, creándola con los defaults si aún no existe.
     *
     * Las columnas msg_* originales son NOT NULL sin default, así que no se
     * puede crear la fila con solo el flag: hay que mergear defaults().
     */
    public static function forTenant(int $tenantId): self
    {
        return static::firstOrCreate(
            ['tenant_id' => $tenantId],
            static::defaults(),
        );
    }

    /**
     * ¿El horario permite que el bot responda en este instante?
     *
     * Devuelve true cuando el horario está apagado: en ese caso manda el
     * interruptor maestro. Es puro — recibe el "ahora" para poder testearlo.
     */
    public function respondsAt(?CarbonInterface $now = null): bool
    {
        if (! $this->schedule_enabled) {
            return true;
        }

        $timezone = $this->schedule_timezone
            ?: config('services.whatsapp.billing_notify_timezone', 'America/Bogota');

        try {
            $local = ($now ? $now->copy() : now())->setTimezone($timezone);
        } catch (\Throwable) {
            // Zona horaria inválida guardada en BD: no dejamos al bot mudo por
            // una configuración rota; el interruptor maestro sigue mandando.
            return true;
        }

        $days = array_map('intval', (array) ($this->schedule_days ?? []));

        $start   = $this->minutesOf($this->schedule_start, 8 * 60);
        $end     = $this->minutesOf($this->schedule_end, 18 * 60);
        $minutes = $local->hour * 60 + $local->minute;

        if ($start === $end) {
            // Franja de 24 h: el día seleccionado cuenta entero.
            $inWindow  = true;
            $windowDay = $local->dayOfWeekIso;
        } elseif ($end > $start) {
            $inWindow  = $minutes >= $start && $minutes < $end;
            $windowDay = $local->dayOfWeekIso;
        } else {
            // La ventana cruza medianoche (ej. 18:00 → 08:00). El día que cuenta
            // es aquel en que la ventana EMPIEZA: a las 02:00 del martes seguimos
            // dentro de la ventana que abrió el lunes.
            $inWindow  = $minutes >= $start || $minutes < $end;
            $windowDay = $minutes < $end
                ? $local->copy()->subDay()->dayOfWeekIso
                : $local->dayOfWeekIso;
        }

        $inside = $inWindow && in_array($windowDay, $days, true);

        return $this->schedule_mode === 'outside' ? ! $inside : $inside;
    }

    /**
     * Resumen del horario en una línea, para pintarlo en Configuración.
     * Ej.: "Dentro de Lun, Mar, Mié, Jue, Vie · 08:00–18:00 · America/Bogota".
     */
    public function scheduleSummary(): ?string
    {
        if (! $this->schedule_enabled) {
            return null;
        }

        $days = array_values(array_unique(array_map('intval', (array) ($this->schedule_days ?? []))));
        sort($days);

        $labels = $days
            ? implode(', ', array_map(fn ($d) => self::DAY_LABELS[$d] ?? (string) $d, $days))
            : 'Ningún día';

        $prefix = $this->schedule_mode === 'outside' ? 'Fuera de' : 'Dentro de';

        return "{$prefix} {$labels} · {$this->schedule_start}–{$this->schedule_end} · {$this->schedule_timezone}";
    }

    private function minutesOf(?string $value, int $fallback): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) $value, $m)) {
            return $fallback;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /**
     * Valores por defecto del bot.
     *
     * Se usan como fallback en el controlador cuando aún no existe una fila de
     * bot_settings para este tenant, para pre-rellenar el formulario en la UI
     * sin necesidad de guardar primero, y para crear la fila en forTenant().
     *
     * Los defaults del horario y de los pasos reproducen el comportamiento
     * previo al cambio: horario apagado, los cinco pasos encendidos.
     */
    public static function defaults(): array
    {
        return [
            'bot_enabled' => false,

            'schedule_enabled'  => false,
            'schedule_mode'     => 'inside',
            'schedule_timezone' => config('services.whatsapp.billing_notify_timezone', 'America/Bogota'),
            'schedule_days'     => [1, 2, 3, 4, 5],
            'schedule_start'    => '08:00',
            'schedule_end'      => '18:00',

            'step_greeting_enabled'            => true,
            'step_branches_enabled'            => true,
            'step_qualify_subscribers_enabled' => true,
            'step_qualify_name_enabled'        => true,
            'step_fallback_enabled'            => true,

            'msg_greeting'   => "¡Hola! 👋 Gracias por escribirnos.\n\nSoy el asistente de ISPWatch, el sistema de gestión para ISPs.\n¿En qué te puedo ayudar hoy?\n\n1️⃣ Conocer ISPWatch\n2️⃣ Programa Socio Fundador\n3️⃣ Ver una demo de 15 min\n4️⃣ Precios y planes\n5️⃣ Hablar con un asesor",
            'msg_info'       => "ISPWatch es el sistema de gestión todo-en-uno para ISPs 🚀\n\nDesde una sola plataforma puedes gestionar:\n✅ Clientes y contratos\n✅ Equipos de red\n✅ Facturación y pagos\n✅ Soporte técnico y CRM\n\nYa lo usan ISPs en varios países de Latinoamérica.\n\nPara conocerte mejor: ¿cuántos suscriptores tiene tu ISP actualmente?",
            'msg_socio'      => "El programa Socio Fundador es para ISPs que quieren entrar desde el inicio 🤝\n\nBeneficios exclusivos:\n🔹 50% de descuento de por vida, o 6 meses gratis\n🔹 Acceso anticipado a nuevas funciones\n🔹 Soporte prioritario\n🔹 Solo disponible para los primeros 20–30 ISPs\n\nA cambio pedimos: un testimonio, un caso de uso y tu feedback.\n\n¿Tu ISP ya está operando o estás lanzándolo próximamente?",
            'msg_demo'       => "¡Perfecto! Una demo de 15 minutos es la mejor manera de ver ISPWatch en acción 🎯\n\nTe muestro la plataforma funcionando con datos reales y te explico cómo aplica a tu operación.\n\nPara coordinar la sesión, dime: ¿cuántos suscriptores tiene tu ISP aproximadamente?",
            'msg_price'      => "Los precios dependen del tamaño de tu operación. No es un costo fijo para todos 💡\n\nPara darte una propuesta real: ¿cuántos suscriptores tienes actualmente?",

            // Solo se envía cuando el paso de suscriptores se alcanza SIN que una
            // rama comercial lo haya preguntado ya (es decir, ramas desactivadas).
            'msg_ask_subscribers' => 'Para conocerte mejor: ¿cuántos suscriptores tiene tu ISP actualmente?',
            'msg_ask_name'        => '¿Y cuál es tu nombre?',

            'msg_handoff'    => "Perfecto, te conecto ahora con uno de nuestros asesores 🙌\n\nEn breve alguien del equipo te escribe. ¡Gracias por tu interés en ISPWatch!",
            'msg_fallback_1' => "Perdona, no entendí bien tu mensaje 😅\n\n¿Me dices qué estás buscando?\n\n1️⃣ Conocer ISPWatch\n2️⃣ Programa Socio Fundador\n3️⃣ Ver una demo\n4️⃣ Hablar con un asesor",
            'msg_fallback_2' => "Voy a conectarte con un asesor que puede ayudarte mejor. ¡Un momento! 🙏",
        ];
    }
}
