<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function serve(string $path)
    {
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

        // BinaryFileResponse handles Range requests automatically, which
        // is required for audio seeking and playback in browsers.
        return response()->file($full, $headers);
    }
}
