<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanOldMedia extends Command
{
    protected $signature = 'media:clean
        {--days= : Días de antigüedad mínima (default: config media.cleanup_days)}
        {--dry-run : Simular sin borrar nada}';

    protected $description = 'Elimina archivos de medios más antiguos que N días del disco para liberar espacio.';

    public function handle(): int
    {
        $days   = (int) ($this->option('days') ?: config('media.cleanup_days', 90));
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = Message::whereNotNull('media_path')
            ->where('created_at', '<', $cutoff);

        $total = $query->count();

        if ($total === 0) {
            $this->info("✅ No hay archivos de medios con más de {$days} días.");
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '🔍 [DRY RUN] ' : '🗑️  ') . "Procesando {$total} archivos con más de {$days} días...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $deleted    = 0;
        $errors     = 0;
        $freedBytes = 0;
        $mediaDisk  = config('filesystems.media_disk', 'public');

        $query->chunkById(100, function ($messages) use (
            $dryRun, $mediaDisk, &$deleted, &$errors, &$freedBytes, $bar,
        ) {
            foreach ($messages as $message) {
                try {
                    $path = $message->media_path;
                    $size = 0;

                    // Calcular tamaño antes de borrar
                    foreach (array_unique(['public', $mediaDisk]) as $disk) {
                        try {
                            if (Storage::disk($disk)->exists($path)) {
                                $size += Storage::disk($disk)->size($path);
                            }
                        } catch (\Throwable) {
                            // Disco no configurado, ignorar
                        }
                    }

                    if (! $dryRun) {
                        // Borrar de ambos discos (remoto + local fallback)
                        foreach (array_unique(['public', $mediaDisk]) as $disk) {
                            try {
                                Storage::disk($disk)->delete($path);
                            } catch (\Throwable) {
                                // Ignorar errores por disco
                            }
                        }

                        // Conservar metadata pero limpiar la referencia al archivo
                        $message->update(['media_path' => null]);
                    }

                    $freedBytes += $size;
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('media:clean error', [
                        'message_id' => $message->id,
                        'path'       => $message->media_path ?? 'unknown',
                        'error'      => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $freedMB = round($freedBytes / 1024 / 1024, 2);
        $prefix  = $dryRun ? '🔍 [DRY RUN] Se eliminarían' : '✅ Eliminados';

        $this->info("{$prefix} {$deleted} archivos (~{$freedMB} MB liberados)");

        if ($errors > 0) {
            $this->warn("⚠️  Errores: {$errors} (ver logs para detalles)");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
