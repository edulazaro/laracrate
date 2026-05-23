<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un chunk de un File. 1 fila = 1 trozo del documento extraído.
 *
 * En modo MySQL search, guarda `text` (con FULLTEXT) y `embedding` (cosine
 * en PHP) para queries semánticas y keyword. En modo Meilisearch, esta
 * tabla se dropea entera y los chunks viven en Meili docs + JSONL backup
 * en storage.
 *
 * State del processing (status, error, provider, model, summary) vive
 * en el `File`, no aquí — porque se re-escribe con cada extracción del
 * file y aplica al file completo, no chunk por chunk.
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

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function hasEmbedding(): bool
    {
        return is_array($this->embedding) && count($this->embedding) > 0;
    }
}
