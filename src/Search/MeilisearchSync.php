<?php

namespace EduLazaro\Laracrate\Search;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use RuntimeException;

/**
 * Sincroniza chunks de file_contents con un índice de Meilisearch para
 * búsqueda híbrida (BM25 + vector).
 *
 * Embedder configurado como `userProvided`: laracrate genera los embeddings
 * con OpenAI (o cualquier `EmbeddingProvider`) y los inyecta en
 * `_vectors.{embedder}`. Meili los almacena y los usa para `vector` queries.
 *
 * Estructura del documento Meili (una fila por chunk):
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
 * Apps que necesiten campos adicionales en cada doc pueden extender esta
 * clase y override `formatDocument()`.
 */
class MeilisearchSync
{
    public const DEFAULT_INDEX = 'laracrate_file_chunks';
    public const DEFAULT_EMBEDDER = 'default';

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
     * Crea el índice (idempotente) y configura filterable/sortable/searchable
     * attributes + embedder userProvided con las dimensiones esperadas.
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
            // updateEmbedders puede fallar si la versión de Meili no soporta
            // el feature (antes de 1.10). Log y sigue: la app puede indexar
            // chunks sin vectores y solo usar BM25.
            Log::warning('Laracrate: could not configure Meilisearch embedder', [
                'embedder' => $this->embedder,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Indexa todos los chunks de un File. Si el file no tiene chunks o no
     * tiene embeddings, no hace nada.
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
     * Borra todos los chunks de un File del índice. Idempotente.
     */
    public function removeFile(File $file): void
    {
        $this->client->index($this->index)->deleteDocuments([
            'filter' => 'file_id = ' . (int) $file->id,
        ]);
    }

    /**
     * Formatea un chunk como documento Meili. Subclases pueden override.
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
