<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Aggregated storage counter per (folderable, collection). The FileObserver
 * keeps `total_size_bytes` and `files_count` in sync with the top-level
 * laracrate_files (parent_id IS NULL) whose collection has the
 * `track_usage = true` flag.
 *
 * Typical read: $organization->usage('drive')->total_size_bytes.
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

    /**
     * The owning model this usage counter belongs to.
     */
    public function folderable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Reads the track_usage flag from the laracrate config for a collection.
     * If absent in config, returns false (not tracked).
     */
    public static function isTracked(string $collection): bool
    {
        return (bool) (config("laracrate.collections.{$collection}.track_usage", false));
    }
}
