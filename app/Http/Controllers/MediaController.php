<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function serve(string $path)
    {
        // Prevent directory traversal attacks
        $base = realpath(Storage::disk('public')->path(''));
        $full = realpath(Storage::disk('public')->path($path));

        if (! $full || ! str_starts_with($full, $base) || ! is_file($full)) {
            abort(404);
        }

        // BinaryFileResponse handles Range requests correctly (needed for audio/video seeking)
        return response()->file($full);
    }
}
