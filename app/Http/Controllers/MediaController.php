<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function serve(string $path)
    {
        $mediaDisk = config('filesystems.media_disk', 'public');

        // Para discos remotos (Supabase, S3): redirigir a la URL pública del archivo.
        // Si el archivo no está en el disco configurado, caemos al disco local como fallback
        // para mantener compatibilidad con archivos anteriores al cambio de disco.
        if ($mediaDisk !== 'public') {
            if (Storage::disk($mediaDisk)->exists($path)) {
                return redirect(Storage::disk($mediaDisk)->url($path));
            }
            // Fallback: intentar servir desde disco local si el archivo fue subido antes del cambio
        }

        // Disco local — prevenir directory traversal y servir el archivo
        $base = realpath(Storage::disk('public')->path(''));
        $full = realpath(Storage::disk('public')->path($path));

        if (! $full || ! str_starts_with($full, $base) || ! is_file($full)) {
            abort(404);
        }

        // BinaryFileResponse handles Range requests correctly (needed for audio/video seeking)
        return response()->file($full);
    }
}
