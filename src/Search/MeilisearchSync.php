<?php

namespace EduLazaro\Laracrate\Search;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use RuntimeException;

/**
 * Synchronizes file_contents chunks with a Meilisearch index for hybrid search
 * (BM25 + vector).
 *
 * Embedder configured as `userProvided`: laracrate generates the embeddings
 * with OpenAI (or any `EmbeddingProvider`) and injects them into
 * `_vectors.{embedder}`. Meili stores them and uses them for `vector` queries.
 *
 * Meili document structure (one row per chunk):
 *   {
 *     "chunk_id":      "f42-c0",
 *     "file_id":       42,
 *     "chunk_index":   0,
 *     "content":       "...",
 *     "fileable_type": "case",
 *     "fileable_id":   123,
 *     "tenant_type":   "organization",
 *     "tenant_id":     456,
 *     "collection":    "documents",
 *     "context":       "uploads",
 *     "category":      "contracts",
 *     "_vectors":      { "default": [0.014, ...] }
 *   }
 *
 * Apps that need extra fields in each doc can extend this class and override
 * `formatDocument()`.
 */
class MeilisearchSync
{
    public const DEFAULT_INDEX = 'laracrate_file_chunks';
    public const DEFAULT_EMBEDDER = 'default';

    /**
     * Create a new Meilisearch sync helper.
     */
    public function __construct(
        protected Client $client,
        protected ?string $index = null,
        protected ?string $embedder = null,
        protected ?int $dimensions = null,
    ) {
        $this->index ??= config('laracrate.meilisearch.index', self::DEFAULT_INDEX);
        $this->embedder ??= config('laracrate.meilisearch.embedder', self::DEFAULT_EMBEDDER);
        $this->dimensions ??= (int) config('laracrate.embeddings.dimensions', 1536);
    }

    /**
     * Create the index (idempotent) and configure filterable/sortable/searchable
     * attributes + the userProvided embedder with the expected dimensions.
     */
    public function ensureIndex(): void
    {
        $existing = collect($this->client->getIndexes()->getResults())
            ->contains(fn ($i) => $i->getUid() === $this->index);

        if (! $existing) {
            $task = $this->client->createIndex($this->index, ['primaryKey' => 'chunk_id']);
            if (isset($task['taskUid'])) {
                $this->client->waitForTask($task['taskUid']);
            }
            Log::info('Laracrate: created Meilisearch index', ['index' => $this->index]);
        }

        $index = $this->client->index($this->index);

        $index->updateFilterableAttributes([
            'file_id', 'fileable_type', 'fileable_id',
            'tenant_type', 'tenant_id',
            'collection', 'context', 'category',
        ]);

        $index->updateSortableAttributes(['chunk_index', 'file_id']);
        $index->updateSearchableAttributes(['content']);

        try {
            $index->updateEmbedders([
                $this->embedder => [
                    'source'     => 'userProvided',
                    'dimensions' => $this->dimensions,
                ],
            ]);
        } catch (\Throwable $e) {
            // updateEmbedders can fail if the Meili version does not support the
            // feature (before 1.10). Log and continue: the app can index chunks
            // without vectors and use BM25 only.
            Log::warning('Laracrate: could not configure Meilisearch embedder', [
                'embedder' => $this->embedder,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Index all chunks of a File. If the file has no chunks or no embeddings,
     * does nothing.
     */
    public function indexFile(File $file): int
    {
        $chunks = $file->contents()->orderBy('chunk_index')->get();
        if ($chunks->isEmpty()) {
            return 0;
        }

        $docs = $chunks->map(fn (FileChunk $chunk) => $this->formatDocument($file, $chunk))
            ->filter()
            ->values()
            ->all();

        if (empty($docs)) {
            return 0;
        }

        $this->client->index($this->index)->addDocuments($docs, 'chunk_id');

        return count($docs);
    }

    /**
     * Remove all chunks of a File from the index. Idempotent.
     */
    public function removeFile(File $file): void
    {
        $this->client->index($this->index)->deleteDocuments([
            'filter' => 'file_id = ' . (int) $file->id,
        ]);
    }

    /**
     * Format a chunk as a Meili document. Subclasses may override.
     */
    protected function formatDocument(File $file, FileChunk $chunk): ?array
    {
        if (empty($chunk->text)) {
            return null;
        }

        $doc = [
            'chunk_id'      => "f{$file->id}-c{$chunk->chunk_index}",
            'file_id'       => $file->id,
            'chunk_index'   => $chunk->chunk_index,
            'content'       => $chunk->text,
            'fileable_type' => $file->fileable_type,
            'fileable_id'   => $file->fileable_id,
            'tenant_type'   => $file->tenant_type,
            'tenant_id'     => $file->tenant_id,
            'collection'    => $file->collection,
            'context'       => $file->context,
            'category'      => $file->category,
        ];

        if (! empty($chunk->embedding) && is_array($chunk->embedding)) {
            $doc['_vectors'] = [$this->embedder => $chunk->embedding];
        }

        return $doc;
    }
}
