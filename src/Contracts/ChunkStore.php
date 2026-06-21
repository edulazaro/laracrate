<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Collection;

/**
 * Persistence + search backend for chunks (pieces of extracted text
 * with embeddings) of a File.
 *
 * The driver is the ONLY source of truth for the chunks. The pipeline
 * (ChunkTextAction → GenerateEmbeddingAction → PersistChunksAction) leaves
 * the full list in `.chunks.jsonl` (a portable artifact in R2) and finally
 * `store()` persists it to the active driver's backend:
 *
 *   - `mysql`        → MysqlChunkStore. Inserts rows into
 *                      `laracrate_file_chunks`. LIKE search + PHP cosine.
 *
 *   - `meilisearch`  → MeilisearchChunkStore. Pushes docs to the Meili index.
 *                      Server-side hybrid search (BM25 + vector).
 *                      `laracrate_file_chunks` is NOT written in this mode.
 *
 * Custom apps (Qdrant, pgvector...) implement this contract and bind
 * ChunkStore::class to the container.
 */
interface ChunkStore
{
    /**
     * Persists a File's chunks to the backend.
     *
     * @param  File  $file
     * @param  array<int,array{chunk_index:int,context:?string,text:string,tokens:int,metadata:array,embedding?:?array}>  $chunks
     * @return int  number of chunks persisted
     */
    public function store(File $file, array $chunks): int;

    /**
     * Returns all chunks of a File ordered by chunk_index.
     * Used by `extract_file_content` and any consumer that needs to read
     * the full content of a file.
     *
     * @return Collection<int,array{chunk_index:int,text:string,tokens:int,metadata:array}>
     */
    public function getByFile(File $file): Collection;

    /**
     * Searches chunks by query (keyword + semantic) with optional filters.
     *
     * @param  string  $query   Query text.
     * @param  array   $filters
     *                          - 'file_ids'      => int[]
     *                          - 'fileable_type' => string
     *                          - 'fileable_id'   => int
     *                          - 'tenant_type'   => string
     *                          - 'tenant_id'     => int
     *                          - 'collection'    => string
     *                          - 'context'       => string
     *                          - 'category'      => string
     * @param  array   $options
     *                          - 'limit'          => int (default 10)
     *                          - 'semantic_ratio' => float 0..1 (default 0.7)
     *
     * @return Collection<int,array{
     *     file_id:int, chunk_index:int, text:string,
     *     score:?float, matched:string, metadata:array
     * }>
     */
    public function search(string $query, array $filters = [], array $options = []): Collection;

    /**
     * Deletes all chunks associated with a File from the backend. Idempotent.
     * Called by FileObserver::forceDeleted.
     */
    public function deleteByFile(File $file): void;

    /**
     * Driver identifier name. Useful for logs and healthchecks.
     */
    public function driverName(): string;
}
