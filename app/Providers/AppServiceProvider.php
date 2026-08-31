<?php

namespace App\Providers;

use App\Models\Conversation;
use App\Observers\ConversationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Conversation::observe(ConversationObserver::class);

        $this->registerRateLimiters();
    }

    /**
     * Topes de peticiones. Los límites son deliberadamente holgados: no están
     * para vigilar a los agentes, sino para que una cuenta comprometida, un
     * script o un cliente en bucle no puedan disparar sin freno.
     *
     * Lo que NO se limita, a propósito: `POST /webhook`. Un tope ahí significa
     * descartar mensajes de clientes cuando Meta manda una ráfaga, y toda la
     * ingesta está construida sobre lo contrario (ver el postmortem del
     * 20/08/2026 en integracion-whatsapp.md §2.4). Un flood del webhook es un
     * problema de disponibilidad, no de pérdida de datos: el cuerpo crudo queda
     * en disco igual. Se resuelve en el borde, no tirando mensajes.
     */
    private function registerRateLimiters(): void
    {
        // Login por IP. NO reemplaza al limitador de LoginRequest —ese cuenta
        // por email|IP con 5 intentos— sino que tapa el hueco que le queda:
        // con la clave por email, probar una contraseña contra 500 correos
        // distintos desde la misma IP no consume ningún cubo. Eso es password
        // spraying y hasta ahora pasaba sin fricción. 30/min deja trabajar a
        // una oficina entera detrás de un NAT y corta el barrido, que necesita
        // cientos de intentos para servir de algo.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Handshake de verificación del webhook (GET /webhook). Meta lo llama
        // UNA vez al suscribir el número; el resto de las llamadas son pruebas
        // manuales. Compara un token contra el de cada tenant activo, así que
        // sin tope es un oráculo para adivinarlos a fuerza bruta.
        RateLimiter::for('webhook-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Envío de texto y plantillas desde el chat, por usuario. Un agente que
        // escribe rápido no llega a 60 mensajes en un minuto; un bucle sí.
        RateLimiter::for('chat-envio', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Medios: cada uno sube un archivo y puede disparar un transcode con
        // ffmpeg, que en un droplet de 1 GB es lo más caro que hace la app.
        // Tope más bajo por eso, no por la frecuencia de uso.
        RateLimiter::for('chat-media', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
    }
}
