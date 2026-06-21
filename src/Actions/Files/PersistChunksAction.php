<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Final step of the chunks pipeline: reads `{path}.chunks.jsonl` (written by
 * ChunkTextAction + enriched with embeddings by GenerateEmbeddingAction) and
 * persists it to the active ChunkStore backend.
 *
 *   - MysqlChunkStore: inserts rows into `laracrate_file_chunks`.
 *   - MeilisearchChunkStore: pushes docs to the Meili index.
 *   - Custom drivers: whatever they decide.
 *
 * JSONL = portable artifact. It allows a rebuild if you change driver (just
 * call this action again over all the files of a disk).
 *
 * Returns the number of persisted chunks.
 */
class PersistChunksAction extends Action
{
    /** Read the chunks JSONL and persist it to the active ChunkStore. */
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
     * Parse a JSONL string into an ordered array of chunks.
     *
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
