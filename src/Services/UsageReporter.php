<?php

namespace EduLazaro\Laracrate\Services;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\UsageStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Storage usage reports. Package singleton:
 *
 *   $stats = app(UsageReporter::class)->forTenant($organization);
 *   $stats->human();                  // "1.42 GB"
 *   $stats->exceeds(5 * 1024**3);     // true if > 5 GB
 *   $stats->byCollection['gallery'];  // ['bytes' => 123, 'files' => 45]
 *
 * The queries count **all Files** (including variants and soft-deleted)
 * because both consume real storage in the bucket. To exclude trashed,
 * pass `excludeTrashed: true`.
 */
class UsageReporter
{
    /** Usage stats scoped to a tenant. */
    public function forTenant(Model $tenant, bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(function (Builder $q) use ($tenant) {
            $q->where('tenant_type', $tenant->getMorphClass())
              ->where('tenant_id', $tenant->getKey());
        }, $excludeTrashed);
    }

    /** Usage stats scoped to a creator. */
    public function forCreator(Model $creator, bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(function (Builder $q) use ($creator) {
            $q->where('creator_type', $creator->getMorphClass())
              ->where('creator_id', $creator->getKey());
        }, $excludeTrashed);
    }

    /** Usage stats scoped to a collection, optionally within a tenant. */
    public function forCollection(string $collection, ?Model $tenant = null, bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(function (Builder $q) use ($collection, $tenant) {
            $q->where('collection', $collection);

            if ($tenant !== null) {
                $q->where('tenant_type', $tenant->getMorphClass())
                  ->where('tenant_id', $tenant->getKey());
            }
        }, $excludeTrashed);
    }

    /**
     * Total system usage. Careful: on large databases this can be a heavy
     * scan, so consider running it offline or with a cache.
     */
    public function global(bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(fn (Builder $q) => $q, $excludeTrashed);
    }

    /**
     * Core: a single query with SUM/COUNT grouped by (collection, type).
     * Builds the DTO from the resulting rows.
     */
    protected function aggregate(callable $scope, bool $excludeTrashed): UsageStats
    {
        $query = File::query();

        if (!$excludeTrashed) {
            $query->withTrashed();
        }

        $scope($query);

        $rows = $query
            ->selectRaw('collection, type, SUM(size) as bytes, COUNT(*) as files')
            ->groupBy('collection', 'type')
            ->get();

        $totalBytes  = 0;
        $totalFiles  = 0;
        $byCollection = [];
        $byType       = [];

        foreach ($rows as $row) {
            $bytes = (int) $row->bytes;
            $files = (int) $row->files;
            $collection = $row->collection ?? '';
            // type is an enum cast on File, but since this is a raw selectRaw query,
            // it comes back as a raw string, which is perfect for indexing the array.
            $type = (string) ($row->type ?? '');

            $totalBytes += $bytes;
            $totalFiles += $files;

            if (!isset($byCollection[$collection])) {
                $byCollection[$collection] = ['bytes' => 0, 'files' => 0];
            }
            $byCollection[$collection]['bytes'] += $bytes;
            $byCollection[$collection]['files'] += $files;

            if ($type !== '') {
                if (!isset($byType[$type])) {
                    $byType[$type] = ['bytes' => 0, 'files' => 0];
                }
                $byType[$type]['bytes'] += $bytes;
                $byType[$type]['files'] += $files;
            }
        }

        return new UsageStats(
            totalBytes:   $totalBytes,
            totalFiles:   $totalFiles,
            byCollection: $byCollection,
            byType:       $byType,
        );
    }
}
