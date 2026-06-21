<?php

namespace EduLazaro\Laracrate\Chunks;

use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Search\MeilisearchSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;

/**
 * Meilisearch driver: pushes chunks as documents to the configured index with
 * embeddings injected as `_vectors.{embedder}` (userProvided mode). Server-side
 * hybrid search with `semanticRatio`.
 *
 * When this driver is active, `laracrate_file_chunks` is NOT written: Meili is
 * the sole source of chunks. The `.chunks.jsonl` artifact on R2 remains the
 * portable backup (ChunkTextAction writes it and PersistChunksAction reads it
 * to feed `store()`).
 *
 * Requires `meilisearch/meilisearch-php` and a Meilisearch\Client binding in
 * the container.
 */
class MeilisearchChunkStore implements ChunkStore
{
    /** Max docs to request per call when reading (pagination of getByFile). */
    private const FETCH_PAGE_SIZE = 1000;

    /**
     * Create a new Meilisearch chunk store.
     */
    public function __construct(
        protected Client $client,
        protected MeilisearchSync $sync,
        protected string $index,
        protected string $embedder = 'default',
    ) {}

    /**
     * Return the driver name.
     */
    public function driverName(): string
    {
        return 'meilisearch';
    }

    /**
     * Store the chunks for a file in the index and return the count written.
     */
    public function store(File $file, array $chunks): int
    {
        try {
            $this->sync->ensureIndex();

            $docs = [];
            foreach ($chunks as $c) {
                $text = (string) ($c['text'] ?? '');
                if ($text === '') continue;

                $doc = [
                    'chunk_id'      => "f{$file->id}-c" . ((int) ($c['chunk_index'] ?? 0)),
                    'file_id'       => (int) $file->id,
                    'chunk_index'   => (int) ($c['chunk_index'] ?? 0),
                    'context'       => $c['context'] ?? null,
                    'content'       => $text,
                    'tokens'        => (int) ($c['tokens'] ?? 0),
                    'metadata'      => is_array($c['metadata'] ?? null) ? $c['metadata'] : [],
                    'fileable_type' => $file->fileable_type,
                    'fileable_id'   => $file->fileable_id,
                    'tenant_type'   => $file->tenant_type,
                    'tenant_id'     => $file->tenant_id,
                    'collection'    => $file->collection,
                    'category'      => $file->category,
                ];

                if (! empty($c['embedding']) && is_array($c['embedding'])) {
                    $doc['_vectors'] = [$this->embedder => $c['embedding']];
                }

                $docs[] = $doc;
            }

            if (empty($docs)) {
                return 0;
            }

            // Delete the file's previous docs (avoids duplicate old chunks) and
            // push the new ones.
            $this->sync->removeFile($file);
            $this->client->index($this->index)->addDocuments($docs, 'chunk_id');

            $file->forceFill(['meili_indexed_at' => now()])->save();

            return count($docs);
        } catch (\Throwable $e) {
            Log::warning('MeilisearchChunkStore: store failed', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Return all stored chunks for a file, ordered by chunk index.
     */
    public function getByFile(File $file): Collection
    {
        $all = collect();
        $offset = 0;

        do {
            try {
                $query = (new DocumentsQuery())
                    ->setFilter(['file_id = ' . (int) $file->id])
                    ->setLimit(self::FETCH_PAGE_SIZE)
                    ->setOffset($offset)
                    ->setFields(['chunk_index', 'context', 'content', 'tokens', 'metadata']);

                $response = $this->client->index($this->index)->getDocuments($query);

                $results = method_exists($response, 'getResults') ? $response->getResults() : ($response['results'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('MeilisearchChunkStore: getByFile failed', [
                    'file_id' => $file->id,
                    'error'   => $e->getMessage(),
                ]);
                break;
            }

            if (empty($results)) break;

            foreach ($results as $doc) {
                $all->push([
                    'chunk_index' => (int) ($doc['chunk_index'] ?? 0),
                    'context'     => $doc['context'] ?? null,
                    'text'        => (string) ($doc['content'] ?? ''),
                    'tokens'      => (int) ($doc['tokens'] ?? 0),
                    'metadata'    => is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [],
                ]);
            }

            $offset += count($results);

            if (count($results) < self::FETCH_PAGE_SIZE) break;
        } while (true);

        return $all->sortBy('chunk_index')->values();
    }

    /**
     * Remove all stored chunks for a file from the index.
     */
    public function deleteByFile(File $file): void
    {
        try {
            $this->sync->removeFile($file);
        } catch (\Throwable $e) {
            Log::warning('MeilisearchChunkStore: removeFile failed', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run a hybrid keyword/semantic search and return scored chunk hits.
     */
    public function search(string $query, array $filters = [], array $options = []): Collection
    {
        $limit         = max(1, (int) ($options['limit'] ?? 10));
        $semanticRatio = max(0.0, min(1.0, (float) ($options['semantic_ratio'] ?? 0.7)));

        $meiliFilter = $this->buildFilter($filters);

        try {
            $params = [
                'limit'                => $limit,
                'attributesToRetrieve' => ['chunk_id', 'file_id', 'chunk_index', 'content', 'metadata'],
                'showRankingScore'     => true,
            ];

            if ($meiliFilter !== null) {
                $params['filter'] = $meiliFilter;
            }

            // userProvided requires `vector` server-side. Embed the query with
            // the same provider that generated the chunk embeddings.
            if ($semanticRatio > 0) {
                $queryVector = $this->embedQuery($query);

                if ($queryVector !== null) {
                    $params['vector'] = $queryVector;
                    $params['hybrid'] = [
                        'embedder'      => $this->embedder,
                        'semanticRatio' => $semanticRatio,
                    ];
                } else {
                    Log::info('MeilisearchChunkStore: query embedding unavailable, falling back to keyword');
                }
            }

            $result = $this->client->index($this->index)->search($query, $params);
            $hits = method_exists($result, 'getHits') ? $result->getHits() : ($result['hits'] ?? []);

            return collect($hits)->map(function (array $hit) use ($semanticRatio) {
                return [
                    'file_id'     => (int) ($hit['file_id'] ?? 0),
                    'chunk_index' => (int) ($hit['chunk_index'] ?? 0),
                    'text'        => (string) ($hit['content'] ?? ''),
                    'score'       => isset($hit['_rankingScore']) ? round((float) $hit['_rankingScore'], 4) : null,
                    'matched'     => $semanticRatio >= 1.0 ? 'semantic'
                                    : ($semanticRatio <= 0.0 ? 'keyword' : 'hybrid'),
                    'metadata'    => is_array($hit['metadata'] ?? null) ? $hit['metadata'] : [],
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('MeilisearchChunkStore: search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Embed a search query, returning the vector or null on failure.
     */
    protected function embedQuery(string $query): ?array
    {
        try {
            $provider = app(EmbeddingProvider::class);
            $vectors = $provider->embed([$query]);
            return $vectors[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('MeilisearchChunkStore: query embedding failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the Meilisearch filter expression from the given filters.
     */
    protected function buildFilter(array $filters): ?string
    {
        $parts = [];

        if (! empty($filters['file_ids']) && is_array($filters['file_ids'])) {
            $ids = implode(',', array_map('intval', $filters['file_ids']));
            $parts[] = "file_id IN [{$ids}]";
        }

        foreach (['fileable_type', 'tenant_type', 'collection', 'context', 'category'] as $strKey) {
            if (isset($filters[$strKey])) {
                $val = addslashes((string) $filters[$strKey]);
                $parts[] = "{$strKey} = \"{$val}\"";
            }
        }

        foreach (['fileable_id', 'tenant_id'] as $intKey) {
            if (isset($filters[$intKey])) {
                $parts[] = "{$intKey} = " . (int) $filters[$intKey];
            }
        }

        return empty($parts) ? null : implode(' AND ', $parts);
    }
}
