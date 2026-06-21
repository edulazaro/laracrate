<?php

namespace EduLazaro\Laracrate\Models;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Multipart upload session against an S3-compatible disk.
 *
 * The actions (`InitiateMultipartUploadAction`, `CompleteMultipartUploadAction`,
 * etc.) read and write here. The controller uses it as a facade for the HTTP
 * endpoints. The cleanup cron uses it to find abandoned ones.
 */
class MultipartUpload extends Model
{
    protected $table = 'laracrate_multipart_uploads';

    protected $fillable = [
        'upload_id',
        'disk', 'key', 'mime_type',
        'expected_size', 'part_size', 'total_parts',
        'status',
        'creator_type', 'creator_id',
        'tenant_type', 'tenant_id',
        'fileable_type', 'fileable_id', 'collection',
        'file_id',
        'metadata',
        'expires_at', 'completed_at', 'aborted_at',
        'error',
    ];

    protected $casts = [
        'status'        => MultipartUploadStatus::class,
        'expected_size' => 'integer',
        'part_size'     => 'integer',
        'total_parts'   => 'integer',
        'metadata'      => 'array',
        'expires_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'aborted_at'    => 'datetime',
    ];

    /** The file produced by this upload, once completed. */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    /** The model that created this upload. */
    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /** The tenant that scopes this upload. */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /** The model this upload belongs to. */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /** Limit the query to active uploads. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MultipartUploadStatus::ACTIVE);
    }

    /** Limit the query to stale uploads (active and expired). */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('status', MultipartUploadStatus::ACTIVE)
            ->where('expires_at', '<', now());
    }

    /** Limit the query to uploads created by the given creator. */
    public function scopeForCreator(Builder $query, Model $creator): Builder
    {
        return $query
            ->where('creator_type', $creator->getMorphClass())
            ->where('creator_id', $creator->getKey());
    }

    /* ------------------------------------------------------------------
     | State helpers
     * ------------------------------------------------------------------ */

    /** True if the upload is active. */
    public function isActive(): bool
    {
        return $this->status === MultipartUploadStatus::ACTIVE;
    }

    /** True if the upload has expired. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
