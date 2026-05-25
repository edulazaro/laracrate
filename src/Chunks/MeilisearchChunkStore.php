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
 * Driver Meilisearch: pushea chunks como documentos al índice configurado
 * con embeddings inyectados como `_vectors.{embedder}` (modo userProvided).
 * Búsqueda híbrida server-side con `semanticRatio`.
 *
 * Cuando este driver está activo, `laracrate_file_chunks` NO se escribe —
 * Meili es la única fuente de chunks. El artefacto `.chunks.jsonl` en R2
 * sigue siendo el backup portable (lo escribe ChunkTextAction y lo lee
 * PersistChunksAction para alimentar `store()`).
 *
 * Requiere `meilisearch/meilisearch-php` y un binding de Meilisearch\Client
 * en el container.
 */
class MeilisearchChunkStore implements ChunkStore
{
    /** Máximo de docs a pedir por request al leer (paginación de getByFile). */
    private const FETCH_PAGE_SIZE = 1000;

    public function __construct(
        protected Client $client,
        protected MeilisearchSync $sync,
        protected string $index,
        protected string $embedder = 'default',
    ) {}

    public function driverName(): string
    {
        return 'meilisearch';
    }

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

            // Borra los previos del file (evita duplicados de chunks viejos) y
            // pushea los nuevos.
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

            // userProvided exige `vector` server-side. Embedea el query
            // con el mismo provider que generó los embeddings de los chunks.
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
