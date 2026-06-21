<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The embeddings step finished and generated at least one new vector. Apps
 * use it to index into pgvector / Meilisearch / Qdrant by listening to this
 * event in a listener (it keeps the vectors in file_contents.embedding
 * for portability).
 *
 * `count` = number of chunks that ended up with an embedding after this pass.
 */
class EmbeddingsReady
{
    use Dispatchable;

    /** Create the event for the file and the number of embedded chunks. */
    public function __construct(
        public File $file,
        public int $count,
    ) {}
}
