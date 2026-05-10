<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Console\Command;
use Throwable;

/**
 * Borra Files cuyas colecciones tengan TTL definido y hayan superado el plazo.
 *
 * Convención: en `config/laracrate.php` cada colección puede declarar
 *
 *   'temp_uploads' => [
 *       'disk'      => 'r2',
 *       'ttl_hours' => 24,
 *       // ...
 *   ],
 *
 * y este comando, programado típicamente cada hora, elimina los Files de
 * esa colección con `created_at < now() - ttl_hours`. Usa `forceDelete()`
 * para que el `FileObserver::forceDeleted` purgue también el binario en
 * el backend.
 *
 * Ejecución sugerida:
 *
 *   Schedule::command('laracrate:purge-expired')->hourly();
 */
class PurgeExpiredFilesCommand extends Command
{
    protected $signature = 'laracrate:purge-expired
                            {--collection= : Limita a una colección concreta}
                            {--dry-run : Solo lista, no borra}
                            {--limit=1000 : Máximo de archivos a procesar por colección}';

    protected $description = 'Borra Files de colecciones con TTL configurado que ya han expirado';

    public function handle(): int
    {
        $only  = $this->option('collection');
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $collections = config('laracrate.collections', []);
        if (!is_array($collections) || $collections === []) {
            $this->info('No hay colecciones configuradas.');
            return self::SUCCESS;
        }

        $totalDeleted = 0;
        $totalFailed  = 0;

        foreach ($collections as $name => $config) {
            if ($only !== null && $name !== $only) {
                continue;
            }

            $ttlHours = $config['ttl_hours'] ?? null;
            if ($ttlHours === null || $ttlHours <= 0) {
                continue;
            }

            $cutoff = now()->subHours((int) $ttlHours);

            $expired = File::withTrashed()
                ->where('collection', $name)
                ->where('created_at', '<', $cutoff)
                ->whereNull('parent_id')  // variants se borran en cascada al borrar el padre
                ->limit($limit)
                ->get();

            if ($expired->isEmpty()) {
                continue;
            }

            $this->info(sprintf(
                "%s '%s' (ttl=%dh): %d archivo(s) expirado(s)%s.",
                $dry ? 'Encontrados en' : 'Procesando',
                $name,
                $ttlHours,
                $expired->count(),
                $dry ? ' (dry-run)' : '',
            ));

            foreach ($expired as $file) {
                $this->line("  - #{$file->id} {$file->name} (created_at={$file->created_at})");

                if ($dry) continue;

                try {
                    $file->forceDelete();
                    $totalDeleted++;
                } catch (Throwable $e) {
                    $totalFailed++;
                    $this->error("    fallo: {$e->getMessage()}");
                }
            }
        }

        if (!$dry) {
            $this->info("Borrados: {$totalDeleted}. Fallidos: {$totalFailed}.");
        }

        return self::SUCCESS;
    }
}
