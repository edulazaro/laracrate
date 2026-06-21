<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chunk of a File. 1 row = 1 piece of the extracted document.
 *
 * In MySQL search mode, it stores `text` (with FULLTEXT) and `embedding`
 * (cosine in PHP) for semantic and keyword queries. In Meilisearch mode, this
 * table is dropped entirely and the chunks live in Meili docs + a JSONL backup
 * in storage.
 *
 * The processing state (status, error, provider, model, summary) lives in the
 * `File`, not here, because it is rewritten with each extraction of the file
 * and applies to the whole file, not chunk by chunk.
 *
 * @property int $id
 * @property int $file_id
 * @property int $chunk_index
 * @property ?string $text
 * @property ?array $embedding
 * @property ?int $tokens
 * @property ?array $metadata
 */
class FileChunk extends Model
{
    protected $table = 'laracrate_file_chunks';

    protected $fillable = [
        'file_id',
        'chunk_index',
        'context',
        'text',
        'embedding',
        'tokens',
        'metadata',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'tokens'      => 'integer',
        'embedding'   => 'array',
        'metadata'    => 'array',
    ];

    /** The file this chunk belongs to. */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** True if this chunk has an embedding. */
    public function hasEmbedding(): bool
    {
        return is_array($this->embedding) && count($this->embedding) > 0;
    }
}
