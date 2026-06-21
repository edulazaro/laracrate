<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates embeddings for a File's chunks that do not have them yet.
 *
 * Reads chunks from `{path}.chunks.jsonl` (written by ChunkTextAction),
 * embeds in batches and rewrites the JSONL with the `embedding` field added
 * to each chunk. Does NOT touch the DB or Meili, that is done later by
 * PersistChunksAction.
 *
 * Returns the number of embedded chunks.
 */
class GenerateEmbeddingAction extends Action
{
    /** Embed the pending chunks and rewrite the JSONL with the vectors. */
    public function handle(File $file): int
    {
        $provider  = app(EmbeddingProvider::class);
        $batchSize = (int) config('laracrate.embeddings.batch_size', 16);
        $disk      = Storage::disk($file->disk);
        $jsonlPath = $file->path . '.chunks.jsonl';

        if (! $disk->exists($jsonlPath)) {
            return 0;
        }

        $chunks = $this->readJsonl((string) $disk->get($jsonlPath));
        if (empty($chunks)) {
            return 0;
        }

        // Indices pending embedding (without `embedding` or empty).
        $pendingIndices = [];
        foreach ($chunks as $idx => $chunk) {
            $text = trim((string) ($chunk['text'] ?? ''));
            if ($text === '') continue;

            $hasEmbedding = ! empty($chunk['embedding']) && is_array($chunk['embedding']);
            if (! $hasEmbedding) {
                $pendingIndices[] = $idx;
            }
        }

        if (empty($pendingIndices)) {
            return 0;
        }

        $embedded = 0;

        foreach (array_chunk($pendingIndices, $batchSize) as $batch) {
            $texts = array_map(fn ($i) => $chunks[$i]['text'], $batch);

            try {
                $vectors = $provider->embed($texts);
            } catch (Throwable $e) {
                $file->forceFill([
                    'processing_status' => 'failed',
                    'processing_error'  => $e->getMessage(),
                ])->save();

                logger()->error('Laracrate: GenerateEmbeddingAction failed', [
                    'file_id' => $file->id,
                    'error'   => $e->getMessage(),
                ]);

                throw $e;
            }

            foreach ($batch as $position => $chunkIdx) {
                $vector = $vectors[$position] ?? null;
                if (! is_array($vector) || empty($vector)) continue;

                $chunks[$chunkIdx]['embedding'] = $vector;
                $embedded++;
            }
        }

        // Rewrite the JSONL with embeddings.
        $newJsonl = collect($chunks)
            ->map(fn ($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n");

        $disk->put($jsonlPath, $newJsonl);

        if ($embedded > 0) {
            $file->forceFill([
                'processing_provider' => $provider->name(),
                'processing_model'    => $provider->model(),
            ])->save();
        }

        return $embedded;
    }

    /**
     * Parses a JSONL into an array of chunks. Empty or malformed lines are
     * silently discarded.
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

        // Stable order by chunk_index.
        usort($chunks, fn ($a, $b) => $a['chunk_index'] <=> $b['chunk_index']);

        return $chunks;
    }
}
