<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.whatsapp.url', '');
        $this->token = (string) config('services.whatsapp.token', '');
    }

    /**
     * Check if the WhatsApp Cloud API is reachable with current credentials.
     * Cached for 60s to avoid hitting Graph API on every page load.
     */
    public function checkConnection(): array
    {
        if (empty($this->baseUrl) || empty($this->token)) {
            return ['connected' => false, 'reason' => 'not_configured'];
        }

        return Cache::remember('whatsapp.connection_status', 60, function () {
            try {
                $response = Http::withToken($this->token)
                    ->timeout(5)
                    ->get($this->baseUrl);

                if ($response->successful()) {
                    return ['connected' => true, 'reason' => 'ok'];
                }

                Log::warning('WhatsApp connection check failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return ['connected' => false, 'reason' => 'http_' . $response->status()];
            } catch (\Throwable $e) {
                Log::warning('WhatsApp connection check exception: ' . $e->getMessage());
                return ['connected' => false, 'reason' => 'exception'];
            }
        });
    }

    /**
     * Download a media file from WhatsApp Cloud API and store it on the public disk.
     *
     * Returns ['path' => 'whatsapp-media/xxx.ext', 'mime' => '...', 'filename' => '...'] or null on failure.
     */
    public function downloadMedia(string $mediaId): ?array
    {
        if (empty($this->token) || empty($this->baseUrl)) {
            return null;
        }

        // baseUrl looks like https://graph.facebook.com/v18.0/{phone-number-id}
        // for media we need https://graph.facebook.com/v18.0/{media-id}
        $graphRoot = preg_replace('#/[^/]+$#', '', rtrim($this->baseUrl, '/'));

        try {
            $meta = Http::withToken($this->token)
                ->timeout(10)
                ->get($graphRoot . '/' . $mediaId);

            if (! $meta->successful()) {
                Log::warning('WhatsApp media metadata fetch failed', [
                    'media_id' => $mediaId,
                    'status' => $meta->status(),
                    'body' => $meta->body(),
                ]);
                return null;
            }

            $url = $meta->json('url');
            $mime = $meta->json('mime_type') ?? 'application/octet-stream';
            if (! $url) {
                return null;
            }

            $binary = Http::withToken($this->token)
                ->timeout(20)
                ->get($url);

            if (! $binary->successful()) {
                Log::warning('WhatsApp media binary fetch failed', [
                    'media_id' => $mediaId,
                    'status' => $binary->status(),
                ]);
                return null;
            }

            $ext = $this->extensionFromMime($mime);
            $filename = $mediaId . ($ext ? '.' . $ext : '');
            $path = 'whatsapp-media/' . $filename;

            $mediaDisk = config('filesystems.media_disk', 'public');
            $content = $binary->body();

            // Comprimir imágenes para ahorrar espacio en disco
            if (str_starts_with($mime, 'image/')) {
                $content = $this->compressImage($content, $mime);
                // After compression images are always JPEG
                if ($content !== $binary->body()) {
                    $mime = 'image/jpeg';
                    $ext = 'jpg';
                    $filename = $mediaId . '.jpg';
                    $path = 'whatsapp-media/' . $filename;
                }
            }

            $savedRemote = false;

            // Intentar guardar en el disco configurado (puede ser Supabase/S3)
            if ($mediaDisk !== 'public') {
                try {
                    Storage::disk($mediaDisk)->put($path, $content);
                    $savedRemote = true;
                } catch (\Throwable $e) {
                    Log::warning('WhatsApp downloadMedia: remote disk save failed, using local fallback', [
                        'media_id' => $mediaId,
                        'disk'     => $mediaDisk,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            // Siempre guardar copia local como fallback para que el
            // MediaController pueda servir el archivo aunque el disco
            // remoto falle temporalmente.
            Storage::disk('public')->put($path, $content);

            return [
                'path' => $path,
                'mime' => $mime,
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp downloadMedia exception', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function extensionFromMime(string $mime): ?string
    {
        $mime = Str::before($mime, ';');
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/amr' => 'amr',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/webm' => 'weba',
            'application/pdf' => 'pdf',
            default => null,
        };
    }

    /**
     * Comprime una imagen usando GD para reducir su tamaño en disco.
     * Si GD no está disponible o falla, retorna el contenido original.
     */
    private function compressImage(string $content, string $mime): string
    {
        if (! config('media.compress_images', true)) {
            return $content;
        }

        if (! extension_loaded('gd')) {
            Log::info('GD extension not available, skipping image compression');
            return $content;
        }

        try {
            $image = @imagecreatefromstring($content);
            if (! $image) {
                return $content;
            }

            $origWidth  = imagesx($image);
            $origHeight = imagesy($image);
            $maxWidth   = config('media.max_width', 1200);
            $quality    = config('media.jpeg_quality', 75);

            // Redimensionar si excede el ancho máximo
            if ($origWidth > $maxWidth) {
                $ratio    = $maxWidth / $origWidth;
                $newWidth  = $maxWidth;
                $newHeight = (int) round($origHeight * $ratio);

                $resized = imagecreatetruecolor($newWidth, $newHeight);

                // Fondo blanco para PNGs con transparencia
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefill($resized, 0, 0, $white);

                imagecopyresampled(
                    $resized, $image,
                    0, 0, 0, 0,
                    $newWidth, $newHeight,
                    $origWidth, $origHeight,
                );

                imagedestroy($image);
                $image = $resized;
            } else {
                // Even without resizing, we still need white background for PNGs
                $canvas = imagecreatetruecolor($origWidth, $origHeight);
                $white  = imagecolorallocate($canvas, 255, 255, 255);
                imagefill($canvas, 0, 0, $white);
                imagecopy($canvas, $image, 0, 0, 0, 0, $origWidth, $origHeight);
                imagedestroy($image);
                $image = $canvas;
            }

            // Guardar como JPEG comprimido
            ob_start();
            imagejpeg($image, null, $quality);
            $compressed = ob_get_clean();
            imagedestroy($image);

            if (! $compressed || strlen($compressed) === 0) {
                return $content;
            }

            $saved = strlen($content) - strlen($compressed);
            if ($saved > 0) {
                Log::info('Image compressed', [
                    'original_kb'   => round(strlen($content) / 1024, 1),
                    'compressed_kb' => round(strlen($compressed) / 1024, 1),
                    'saved_kb'      => round($saved / 1024, 1),
                ]);
            }

            // Solo usar la comprimida si realmente es más pequeña
            return strlen($compressed) < strlen($content) ? $compressed : $content;
        } catch (\Throwable $e) {
            Log::warning('Image compression failed, using original', [
                'error' => $e->getMessage(),
            ]);
            return $content;
        }
    }

    /**
     * Upload a file to WhatsApp Cloud API and return the media_id.
     * Needed before calling sendMedia().
     */
    public function uploadMedia(string $fileContent, string $filename, string $mimeType): ?string
    {
        if (empty($this->token) || empty($this->baseUrl)) {
            return null;
        }

        $graphRoot = preg_replace('#/[^/]+$#', '', rtrim($this->baseUrl, '/'));

        try {
            $response = Http::withToken($this->token)
                ->attach('file', $fileContent, $filename, ['Content-Type' => $mimeType])
                ->post($graphRoot . '/media', [
                    'messaging_product' => 'whatsapp',
                ]);

            if ($response->successful()) {
                return $response->json('id');
            }

            Log::error('WhatsApp media upload failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('WhatsApp uploadMedia exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Send an image or audio to a WhatsApp contact using a previously uploaded media_id.
     *
     * @param string $type 'image' | 'audio' | 'document'
     */
    public function sendMedia(string $to, string $type, string $mediaId, ?string $caption = null): array
    {
        if (empty($this->baseUrl)) {
            Log::info("WhatsApp Mock sendMedia: To $to, Type: $type, MediaId: $mediaId");
            return ['success' => true, 'mock' => true];
        }

        $mediaPayload = ['id' => $mediaId];
        if ($caption && in_array($type, ['image', 'video', 'document'])) {
            $mediaPayload['caption'] = $caption;
        }

        try {
            $response = Http::withToken($this->token)
                ->post($this->baseUrl . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => $type,
                    $type               => $mediaPayload,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp sendMedia Error: ' . $response->body());
            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('WhatsApp sendMedia Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a WhatsApp message.
     *
     * @param string $to The phone number to send to.
     * @param string $message The message content.
     * @return array
     */
    public function sendMessage(string $to, string $message): array
    {
        // For now, if no config is present, we log and return success mock
        if (empty($this->baseUrl)) {
            Log::info("WhatsApp Mock Send: To $to, Message: $message");
            return ['success' => true, 'mock' => true];
        }

        try {
            $response = Http::withToken($this->token)
                ->post($this->baseUrl . '/messages', [ // Assuming standard endpoint, adjustable
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            Log::error('WhatsApp API Error: ' . $response->body());
            return ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
