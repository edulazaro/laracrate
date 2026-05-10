<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El step de embeddings terminó y generó al menos un vector nuevo. Las apps
 * lo usan para indexar en pgvector / Meilisearch / Qdrant escuchando este
 * evento en un listener (mantiene los vectores en file_contents.embedding
 * para portabilidad).
 *
 * `count` = nº de chunks que quedaron con embedding tras este pase.
 */
class EmbeddingsReady
{
    use Dispatchable;

    public function __construct(
        public File $file,
        public int $count,
    ) {}
}
