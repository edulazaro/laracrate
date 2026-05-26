<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Counter agregado de almacenamiento por (folderable, collection). El
 * FileObserver mantiene `total_size_bytes` y `files_count` en sync con los
 * laracrate_files de top-level (parent_id IS NULL) cuya collection tiene el
 * flag `track_usage = true`.
 *
 * Lectura típica: $organization->usage('drive')->total_size_bytes.
 */
class Folderable extends Model
{
    protected $table = 'laracrate_folderables';

    protected $fillable = [
        'folderable_type',
        'folderable_id',
        'collection',
        'total_size_bytes',
        'files_count',
        'folders_count',
        'last_recomputed_at',
    ];

    protected $casts = [
        'total_size_bytes'   => 'integer',
        'files_count'        => 'integer',
        'folders_count'      => 'integer',
        'last_recomputed_at' => 'datetime',
    ];

    public function folderable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Lee el flag track_usage del config laracrate para una collection.
     * Si no existe en config, false (no se trackea).
     */
    public static function isTracked(string $collection): bool
    {
        return (bool) (config("laracrate.collections.{$collection}.track_usage", false));
    }
}
