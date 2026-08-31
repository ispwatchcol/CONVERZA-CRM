<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function serve(Request $request, string $path)
    {
        $this->assertBelongsToCurrentTenant($path);

        $mediaDisk = config('filesystems.media_disk', 'public');

        // ── 1. Intentar disco remoto (Supabase / S3) ────────────────────────
        // Envuelto en try-catch para que un error de conexión con Supabase
        // no tire 500 y permita caer al fallback local.
        if ($mediaDisk !== 'public') {
            try {
                if (Storage::disk($mediaDisk)->exists($path)) {
                    return redirect(Storage::disk($mediaDisk)->url($path));
                }
            } catch (\Throwable $e) {
                Log::warning('MediaController: remote disk check failed, falling back to local', [
                    'disk'  => $mediaDisk,
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
            }
            // Fallback: intentar servir desde disco local si el archivo fue
            // subido antes del cambio de disco o si el disco remoto falló.
        }

        // ── 2. Disco local (public) ─────────────────────────────────────────
        $publicDisk = Storage::disk('public');

        // Verificar existencia antes de intentar realpath (evita excepciones)
        if (! $publicDisk->exists($path)) {
            abort(404, 'Media file not found');
        }

        $base = realpath($publicDisk->path(''));
        $full = realpath($publicDisk->path($path));

        if (! $full || ! str_starts_with($full, $base) || ! is_file($full)) {
            abort(404, 'Media file not found');
        }

        // Explicit Content-Type mapping prevents servers with outdated libmagic from
        // serving audio/video with wrong MIME (e.g. application/octet-stream) which
        // causes browsers to refuse playback.
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mimeByExt = [
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'opus' => 'audio/ogg',
            'mp3'  => 'audio/mpeg',
            'aac'  => 'audio/aac',
            'amr'  => 'audio/amr',
            'm4a'  => 'audio/mp4',
            'weba' => 'audio/webm',
            'webm' => 'audio/webm',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'mp4'  => 'video/mp4',
            '3gp'  => 'video/3gpp',
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'doc'  => 'application/msword',
            'xls'  => 'application/vnd.ms-excel',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        $headers = isset($mimeByExt[$ext]) ? ['Content-Type' => $mimeByExt[$ext]] : [];

        // Cache media files for 1 hour to avoid re-fetching on every play
        $headers['Cache-Control'] = 'private, max-age=3600';

        // ?download=1&name=factura.pdf fuerza la descarga con el nombre original.
        // En disco los archivos se guardan con un UUID, así que sin esto el usuario
        // bajaría "9f3c…-a12.pdf". El atributo HTML `download` solo renombra en
        // same-origin; forzarlo acá también cubre "abrir en pestaña nueva".
        if ($request->boolean('download')) {
            // basename() corta cualquier ruta; el resto limpia caracteres de control
            // y separadores que podrían romper la cabecera Content-Disposition.
            $name = basename((string) $request->query('name', ''));
            $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '', $name);
            $name = trim($name) !== '' ? $name : basename($full);

            return response()->download($full, $name, $headers);
        }

        // BinaryFileResponse handles Range requests automatically, which
        // is required for audio seeking and playback in browsers.
        return response()->file($full, $headers);
    }

    /**
     * Un medio se sirve SOLO si un mensaje del tenant en sesión lo referencia.
     *
     * La ruta pedida es exactamente el `media_path` guardado en la fila: la URL
     * la construye el propio backend con route('media.serve', ['path' => …]),
     * así que la comparación es de igualdad y no hace falta normalizar nada.
     *
     * Sin esta comprobación, `auth` alcanzaba para bajar el medio de CUALQUIER
     * workspace conociendo la ruta, y las rutas viajan en las props de Inertia:
     * un IDOR real aunque los nombres sean UUIDs no adivinables.
     *
     * No se delega en el global scope de BelongsToTenant. Ese scope se
     * desactiva solo —`if (!app()->bound('tenant')) return;`— cuando no hay
     * tenant enlazado (usuario sin tenant, o tenant desactivado), que es justo
     * el caso en el que dejaría pasar todo. Acá se falla cerrado primero y el
     * `where` del tenant va explícito.
     *
     * Siempre 404 y nunca 403: un 403 confirmaría que el archivo existe en otro
     * tenant, que es la mitad de lo que un atacante quiere averiguar.
     */
    private function assertBelongsToCurrentTenant(string $path): void
    {
        abort_unless(app()->bound('tenant'), 404, 'Media file not found');

        abort_unless(
            Message::query()
                ->where('tenant_id', app('tenant')->id)
                ->where('media_path', $path)
                ->exists(),
            404,
            'Media file not found',
        );
    }
}
