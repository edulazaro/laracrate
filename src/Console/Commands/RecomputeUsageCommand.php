<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\Folderable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recomputes the `laracrate_folderables` rows from laracrate_files.
 * Defense against drift (observer failure, manual imports, restore from
 * backup). Idempotent: it always leaves the row in the correct state.
 *
 *   php artisan laracrate:recompute-usage              # all tracked collections
 *   php artisan laracrate:recompute-usage drive        # only drive
 *   php artisan laracrate:recompute-usage --dry-run    # shows deltas without writing
 */
class RecomputeUsageCommand extends Command
{
    protected $signature = 'laracrate:recompute-usage
                            {collection? : Restrict to a specific collection}
                            {--dry-run : Show differences without persisting}';

    protected $description = 'Recomputes the laracrate_folderables counters from laracrate_files.';

    /** Recomputes usage counters for the tracked collections (or a single one). */
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
            $this->warn('No collections with track_usage enabled.');
            return self::SUCCESS;
        }

        foreach ($collections as $collection) {
            $this->recomputeCollection($collection, $dryRun);
        }

        return self::SUCCESS;
    }

    /** Recomputes and persists folderable counters for a single collection. */
    protected function recomputeCollection(string $collection, bool $dryRun): void
    {
        $this->info("Recomputing collection: {$collection}");

        // We aggregate by (fileable_type, fileable_id) in a single query.
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
                continue; // no changes
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

        // Existing Folderable rows that NO LONGER have any file (orphan).
        // We set them to 0/0 to reflect reality without deleting the row
        // (it may have useful history / last_recomputed_at).
        $existingKeys = $aggregates->map(fn ($a) => $a->fileable_type . '#' . $a->fileable_id)->all();
        $orphans = Folderable::where('collection', $collection)->get()
            ->filter(fn ($r) => ! in_array($r->folderable_type . '#' . $r->folderable_id, $existingKeys, true))
            ->filter(fn ($r) => $r->total_size_bytes > 0 || $r->files_count > 0);

        foreach ($orphans as $orphan) {
            $this->line(sprintf(
                '  %s#%s: ORPHAN, reset to 0/0 (was %d files / %s bytes)',
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

        $verb = $dryRun ? 'would change' : 'updated';
        $this->info("  → {$touched} rows {$verb}.");
    }
}
