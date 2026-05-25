<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Paso final de la pipeline de chunks: lee `{path}.chunks.jsonl` (escrito
 * por ChunkTextAction + enriquecido con embeddings por
 * GenerateEmbeddingAction) y persiste al backend del ChunkStore activo.
 *
 *   - MysqlChunkStore: inserta filas en `laracrate_file_chunks`.
 *   - MeilisearchChunkStore: pushea docs al índice Meili.
 *   - Drivers custom: lo que decidan.
 *
 * JSONL = artefacto portable. Permite rebuild si cambias de driver (basta
 * volver a llamar a este action sobre todos los files de un disco).
 *
 * Devuelve el número de chunks persistidos.
 */
class PersistChunksAction extends Action
{
    public function handle(File $file): int
    {
        $disk = Storage::disk($file->disk);
        $jsonlPath = $file->path . '.chunks.jsonl';

        if (! $disk->exists($jsonlPath)) {
            return 0;
        }

        $chunks = $this->readJsonl((string) $disk->get($jsonlPath));
        if (empty($chunks)) {
            return 0;
        }

        return app(ChunkStore::class)->store($file, $chunks);
    }

    /**
     * @return array<int,array>
     */
    protected function readJsonl(string $jsonl): array
    {
        $chunks = [];
        foreach (explode("\n", trim($jsonl)) as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parsed = json_decode($line, true);
            if (is_array($parsed) && isset($parsed['chunk_index'])) {
                $chunks[] = $parsed;
            }
        }

        usort($chunks, fn ($a, $b) => $a['chunk_index'] <=> $b['chunk_index']);

        return $chunks;
    }
}
