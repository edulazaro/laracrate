<?php

namespace EduLazaro\Laracrate\Models;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Sesión de upload multipart contra un disk S3-compatible.
 *
 * Las acciones (`InitiateMultipartUploadAction`, `CompleteMultipartUploadAction`,
 * etc.) leen y escriben aquí. El controller la usa de fachada para los
 * endpoints HTTP. El cron de cleanup la usa para encontrar abandonadas.
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

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MultipartUploadStatus::ACTIVE);
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('status', MultipartUploadStatus::ACTIVE)
            ->where('expires_at', '<', now());
    }

    public function scopeForCreator(Builder $query, Model $creator): Builder
    {
        return $query
            ->where('creator_type', $creator->getMorphClass())
            ->where('creator_id', $creator->getKey());
    }

    /* ------------------------------------------------------------------
     | Helpers de estado
     * ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status === MultipartUploadStatus::ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
