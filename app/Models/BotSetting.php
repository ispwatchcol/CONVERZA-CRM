<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BotSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'bot_enabled',
        'msg_greeting',
        'msg_info',
        'msg_socio',
        'msg_demo',
        'msg_price',
        'msg_handoff',
        'msg_fallback_1',
        'msg_fallback_2',
    ];

    protected $casts = [
        'bot_enabled' => 'boolean',
    ];

    /**
     * Valores por defecto de los mensajes del bot.
     * Se usan como fallback en el controlador cuando aún no existe
     * una fila de bot_settings para este tenant, y para pre-rellenar
     * el formulario en la UI sin necesidad de guardar primero.
     */
    public static function defaults(): array
    {
        return [
            'bot_enabled'    => false,
            'msg_greeting'   => "¡Hola! 👋 Gracias por escribirnos.\n\nSoy el asistente de ISPWatch, el sistema de gestión para ISPs.\n¿En qué te puedo ayudar hoy?\n\n1️⃣ Conocer ISPWatch\n2️⃣ Programa Socio Fundador\n3️⃣ Ver una demo de 15 min\n4️⃣ Precios y planes\n5️⃣ Hablar con un asesor",
            'msg_info'       => "ISPWatch es el sistema de gestión todo-en-uno para ISPs 🚀\n\nDesde una sola plataforma puedes gestionar:\n✅ Clientes y contratos\n✅ Equipos de red\n✅ Facturación y pagos\n✅ Soporte técnico y CRM\n\nYa lo usan ISPs en varios países de Latinoamérica.\n\nPara conocerte mejor: ¿cuántos suscriptores tiene tu ISP actualmente?",
            'msg_socio'      => "El programa Socio Fundador es para ISPs que quieren entrar desde el inicio 🤝\n\nBeneficios exclusivos:\n🔹 50% de descuento de por vida, o 6 meses gratis\n🔹 Acceso anticipado a nuevas funciones\n🔹 Soporte prioritario\n🔹 Solo disponible para los primeros 20–30 ISPs\n\nA cambio pedimos: un testimonio, un caso de uso y tu feedback.\n\n¿Tu ISP ya está operando o estás lanzándolo próximamente?",
            'msg_demo'       => "¡Perfecto! Una demo de 15 minutos es la mejor manera de ver ISPWatch en acción 🎯\n\nTe muestro la plataforma funcionando con datos reales y te explico cómo aplica a tu operación.\n\nPara coordinar la sesión, dime: ¿cuántos suscriptores tiene tu ISP aproximadamente?",
            'msg_price'      => "Los precios dependen del tamaño de tu operación. No es un costo fijo para todos 💡\n\nPara darte una propuesta real: ¿cuántos suscriptores tienes actualmente?",
            'msg_handoff'    => "Perfecto, te conecto ahora con uno de nuestros asesores 🙌\n\nEn breve alguien del equipo te escribe. ¡Gracias por tu interés en ISPWatch!",
            'msg_fallback_1' => "Perdona, no entendí bien tu mensaje 😅\n\n¿Me dices qué estás buscando?\n\n1️⃣ Conocer ISPWatch\n2️⃣ Programa Socio Fundador\n3️⃣ Ver una demo\n4️⃣ Hablar con un asesor",
            'msg_fallback_2' => "Voy a conectarte con un asesor que puede ayudarte mejor. ¡Un momento! 🙏",
        ];
    }
}
