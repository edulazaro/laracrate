<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Collection;

/**
 * Backend de persistencia + búsqueda para chunks (trozos de texto extraído
 * con embeddings) de un File.
 *
 * El driver es la ÚNICA fuente de verdad para los chunks. La pipeline
 * (ChunkTextAction → GenerateEmbeddingAction → PersistChunksAction) deja
 * la lista completa en `.chunks.jsonl` (artefacto portable en R2) y al
 * final `store()` la persiste al backend del driver activo:
 *
 *   - `mysql`        → MysqlChunkStore. Inserta filas en
 *                      `laracrate_file_chunks`. Búsqueda LIKE + cosine PHP.
 *
 *   - `meilisearch`  → MeilisearchChunkStore. Pushea docs al índice Meili.
 *                      Búsqueda híbrida server-side (BM25 + vector).
 *                      `laracrate_file_chunks` NO se escribe en este modo.
 *
 * Apps custom (Qdrant, pgvector...) implementan este contract y bindean
 * ChunkStore::class al container.
 */
interface ChunkStore
{
    /**
     * Persiste los chunks de un File en el backend.
     *
     * @param  File  $file
     * @param  array<int,array{chunk_index:int,context:?string,text:string,tokens:int,metadata:array,embedding?:?array}>  $chunks
     * @return int  número de chunks persistidos
     */
    public function store(File $file, array $chunks): int;

    /**
     * Devuelve todos los chunks de un File ordenados por chunk_index.
     * Lo usa `extract_file_content` y cualquier consumer que necesite leer
     * el contenido completo de un archivo.
     *
     * @return Collection<int,array{chunk_index:int,text:string,tokens:int,metadata:array}>
     */
    public function getByFile(File $file): Collection;

    /**
     * Busca chunks por query (keyword + semántico) con filtros opcionales.
     *
     * @param  string  $query   Texto de la consulta.
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
     * Borra todos los chunks asociados a un File del backend. Idempotente.
     * Llamado por FileObserver::forceDeleted.
     */
    public function deleteByFile(File $file): void;

    /**
     * Nombre identificador del driver. Útil para logs y healthchecks.
     */
    public function driverName(): string;
}
