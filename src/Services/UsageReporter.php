<?php

namespace EduLazaro\Laracrate\Services;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\UsageStats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reportes de uso de storage. Singleton del paquete:
 *
 *   $stats = app(UsageReporter::class)->forTenant($organization);
 *   $stats->human();                  // "1.42 GB"
 *   $stats->exceeds(5 * 1024**3);     // true si > 5 GB
 *   $stats->byCollection['gallery'];  // ['bytes' => 123, 'files' => 45]
 *
 * Las queries cuentan **todos los Files** (incluido variants y soft-deleted)
 * porque ambos consumen storage real en el bucket. Para excluir trashed
 * pasa `excludeTrashed: true`.
 */
class UsageReporter
{
    public function forTenant(Model $tenant, bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(function (Builder $q) use ($tenant) {
            $q->where('tenant_type', $tenant->getMorphClass())
              ->where('tenant_id', $tenant->getKey());
        }, $excludeTrashed);
    }

    public function forCreator(Model $creator, bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(function (Builder $q) use ($creator) {
            $q->where('creator_type', $creator->getMorphClass())
              ->where('creator_id', $creator->getKey());
        }, $excludeTrashed);
    }

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
     * Uso total del sistema. Cuidado: en bases grandes puede ser un escaneo
     * pesado — considera ejecutar offline o con cache.
     */
    public function global(bool $excludeTrashed = false): UsageStats
    {
        return $this->aggregate(fn (Builder $q) => $q, $excludeTrashed);
    }

    /**
     * Núcleo: una sola query con SUM/COUNT agrupada por (collection, type).
     * Construye el DTO a partir de las filas resultantes.
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
            // type es enum cast en File, pero como aquí es una query raw selectRaw,
            // viene como string crudo — perfecto para indexar el array.
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
