<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes Files whose collections have a TTL defined and have passed the deadline.
 *
 * Convention: in `config/laracrate.php` each collection may declare
 *
 *   'temp_uploads' => [
 *       'disk'      => 'r2',
 *       'ttl_hours' => 24,
 *       // ...
 *   ],
 *
 * and this command, typically scheduled hourly, removes the Files of that
 * collection with `created_at < now() - ttl_hours`. It uses `forceDelete()`
 * so that `FileObserver::forceDeleted` also purges the binary in the backend.
 *
 * Suggested run:
 *
 *   Schedule::command('laracrate:purge-expired')->hourly();
 */
class PurgeExpiredFilesCommand extends Command
{
    protected $signature = 'laracrate:purge-expired
                            {--collection= : Limit to a specific collection}
                            {--dry-run : Only list, do not delete}
                            {--limit=1000 : Maximum files to process per collection}';

    protected $description = 'Deletes Files from collections with a configured TTL that have already expired';

    /** Deletes (or lists, in dry-run) expired Files per collection based on ttl_hours. */
    public function handle(): int
    {
        $only  = $this->option('collection');
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $collections = config('laracrate.collections', []);
        if (!is_array($collections) || $collections === []) {
            $this->info('No collections configured.');
            return self::SUCCESS;
        }

        $totalDeleted = 0;
        $totalFailed  = 0;

        foreach ($collections as $name => $_rawConfig) {
            if ($only !== null && $name !== $only) {
                continue;
            }

            // Resolution without morph alias: returns the base, ignores `models`.
            $config   = \EduLazaro\Laracrate\Support\CollectionConfig::resolve($name);
            $ttlHours = $config['ttl_hours'] ?? null;
            if ($ttlHours === null || $ttlHours <= 0) {
                continue;
            }

            $cutoff = now()->subHours((int) $ttlHours);

            $expired = File::withTrashed()
                ->where('collection', $name)
                ->where('created_at', '<', $cutoff)
                ->whereNull('parent_id')  // variants are deleted in cascade when the parent is deleted
                ->limit($limit)
                ->get();

            if ($expired->isEmpty()) {
                continue;
            }

            $this->info(sprintf(
                "%s '%s' (ttl=%dh): %d expired file(s)%s.",
                $dry ? 'Found in' : 'Processing',
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
                    $this->error("    failed: {$e->getMessage()}");
                }
            }
        }

        if (!$dry) {
            $this->info("Deleted: {$totalDeleted}. Failed: {$totalFailed}.");
        }

        return self::SUCCESS;
    }
}
