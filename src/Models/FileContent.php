<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileContent extends Model
{
    protected $table = 'laracrate_file_contents';

    protected $fillable = [
        'file_id',
        'chunk_index',
        'text',
        'summary',
        'description',
        'embedding',
        'tokens',
        'provider',
        'model',
        'metadata',
        'status',
        'error',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'tokens'      => 'integer',
        'embedding'   => 'array',
        'metadata'    => 'array',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function hasEmbedding(): bool
    {
        return is_array($this->embedding) && count($this->embedding) > 0;
    }
}
