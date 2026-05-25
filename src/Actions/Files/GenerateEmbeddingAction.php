<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Genera embeddings para los chunks de un File que aún no los tengan.
 *
 * Lee chunks de `{path}.chunks.jsonl` (escrito por ChunkTextAction), embedea
 * en batches y reescribe el JSONL con el campo `embedding` añadido a cada
 * chunk. NO toca BD ni Meili — eso lo hace después PersistChunksAction.
 *
 * Devuelve el número de chunks embebidos.
 */
class GenerateEmbeddingAction extends Action
{
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

        // Indices pendientes de embedear (sin `embedding` o vacío).
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

        // Reescribe JSONL con embeddings.
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
     * Parsea un JSONL en array de chunks. Líneas vacías o malformadas se
     * descartan silenciosamente.
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

        // Orden estable por chunk_index.
        usort($chunks, fn ($a, $b) => $a['chunk_index'] <=> $b['chunk_index']);

        return $chunks;
    }
}
