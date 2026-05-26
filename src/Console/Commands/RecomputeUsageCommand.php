<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\Folderable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recalcula las filas de `laracrate_folderables` desde laracrate_files.
 * Defensa contra drift (observer falla, importaciones a mano, restore desde
 * backup). Idempotente — siempre deja la fila en el estado correcto.
 *
 *   php artisan laracrate:recompute-usage              # todas las collections trackeadas
 *   php artisan laracrate:recompute-usage drive        # solo drive
 *   php artisan laracrate:recompute-usage --dry-run    # muestra deltas sin escribir
 */
class RecomputeUsageCommand extends Command
{
    protected $signature = 'laracrate:recompute-usage
                            {collection? : Restringir a una collection concreta}
                            {--dry-run : Mostrar diferencias sin persistir}';

    protected $description = 'Recalcula los counters de laracrate_folderables desde laracrate_files.';

    public function handle(): int
    {
        $only = $this->argument('collection');
        $dryRun = (bool) $this->option('dry-run');

        $collections = $only
            ? [$only]
            : collect(config('laracrate.collections', []))
                ->filter(fn ($cfg) => ! empty($cfg['track_usage']))
                ->keys()
                ->all();

        if (empty($collections)) {
            $this->warn('No hay collections con track_usage habilitado.');
            return self::SUCCESS;
        }

        foreach ($collections as $collection) {
            $this->recomputeCollection($collection, $dryRun);
        }

        return self::SUCCESS;
    }

    protected function recomputeCollection(string $collection, bool $dryRun): void
    {
        $this->info("Recomputando collection: {$collection}");

        // Agregamos por (fileable_type, fileable_id) en una sola query.
        $aggregates = File::query()
            ->selectRaw('fileable_type, fileable_id, COUNT(*) as files_count, COALESCE(SUM(size), 0) as total_size_bytes')
            ->where('collection', $collection)
            ->whereNull('parent_id')
            ->whereNotNull('fileable_type')
            ->whereNotNull('fileable_id')
            ->groupBy('fileable_type', 'fileable_id')
            ->get();

        $now = Carbon::now();
        $touched = 0;

        foreach ($aggregates as $agg) {
            $row = Folderable::firstOrNew([
                'folderable_type' => $agg->fileable_type,
                'folderable_id'   => $agg->fileable_id,
                'collection'      => $collection,
            ]);

            $newBytes = (int) $agg->total_size_bytes;
            $newCount = (int) $agg->files_count;

            if ($row->exists
                && (int) $row->total_size_bytes === $newBytes
                && (int) $row->files_count === $newCount) {
                continue; // sin cambios
            }

            $delta = $newBytes - (int) $row->total_size_bytes;
            $this->line(sprintf(
                '  %s#%s: %d files / %s bytes (delta %s%d)',
                $agg->fileable_type,
                $agg->fileable_id,
                $newCount,
                number_format($newBytes),
                $delta >= 0 ? '+' : '',
                $delta,
            ));

            if (! $dryRun) {
                $row->total_size_bytes   = $newBytes;
                $row->files_count        = $newCount;
                $row->last_recomputed_at = $now;
                $row->save();
            }
            $touched++;
        }

        // Filas Folderable existentes que YA NO tienen ningún file (orphan).
        // Las llevamos a 0/0 para reflejar la realidad sin borrar la fila
        // (puede tener histórico/last_recomputed_at útil).
        $existingKeys = $aggregates->map(fn ($a) => $a->fileable_type . '#' . $a->fileable_id)->all();
        $orphans = Folderable::where('collection', $collection)->get()
            ->filter(fn ($r) => ! in_array($r->folderable_type . '#' . $r->folderable_id, $existingKeys, true))
            ->filter(fn ($r) => $r->total_size_bytes > 0 || $r->files_count > 0);

        foreach ($orphans as $orphan) {
            $this->line(sprintf(
                '  %s#%s: ORPHAN, reset a 0/0 (era %d files / %s bytes)',
                $orphan->folderable_type,
                $orphan->folderable_id,
                $orphan->files_count,
                number_format($orphan->total_size_bytes),
            ));

            if (! $dryRun) {
                $orphan->total_size_bytes   = 0;
                $orphan->files_count        = 0;
                $orphan->last_recomputed_at = $now;
                $orphan->save();
            }
            $touched++;
        }

        $verb = $dryRun ? 'cambiarían' : 'actualizadas';
        $this->info("  → {$touched} filas {$verb}.");
    }
}
