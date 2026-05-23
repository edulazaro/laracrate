<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Genera embeddings para los chunks de un File que aún no los tengan.
 *
 * Lee text directamente de `laracrate_file_chunks.text` (MySQL), embedea
 * en batches y persiste:
 *  - `embedding` en `laracrate_file_chunks`.
 *  - re-escribe `{path}.chunks.jsonl` añadiendo `embedding` por chunk (backup).
 *  - actualiza el `File` con processing_provider, processing_model,
 *    mysql_indexed_at = now().
 */
class GenerateEmbeddingAction extends Action
{
    public function handle(File $file): int
    {
        $provider = app(EmbeddingProvider::class);
        $batchSize = (int) config('laracrate.embeddings.batch_size', 16);
        $disk = Storage::disk($file->disk);
        $jsonlPath = $file->path . '.chunks.jsonl';

        $pending = FileChunk::where('file_id', $file->id)
            ->whereNotNull('text')
            ->whereRaw('LENGTH(TRIM(text)) > 0')
            ->whereNull('embedding')
            ->orderBy('chunk_index')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $chunksByIndex = [];
        if ($disk->exists($jsonlPath)) {
            foreach (explode("\n", trim((string) $disk->get($jsonlPath))) as $line) {
                if (empty($line)) continue;
                $parsed = json_decode($line, true);
                if (is_array($parsed) && isset($parsed['chunk_index'])) {
                    $chunksByIndex[$parsed['chunk_index']] = $parsed;
                }
            }
        }

        $embedded = 0;

        foreach ($pending->chunk($batchSize) as $batch) {
            $texts = $batch->pluck('text')->all();

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

            foreach ($batch->values() as $i => $chunk) {
                $vector = $vectors[$i] ?? null;
                if (! is_array($vector) || empty($vector)) continue;

                $chunk->update(['embedding' => $vector]);

                if (isset($chunksByIndex[$chunk->chunk_index])) {
                    $chunksByIndex[$chunk->chunk_index]['embedding'] = $vector;
                }

                $embedded++;
            }
        }

        if ($embedded > 0 && ! empty($chunksByIndex)) {
            ksort($chunksByIndex);
            $newJsonl = collect($chunksByIndex)
                ->map(fn ($c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->implode("\n");
            $disk->put($jsonlPath, $newJsonl);
        }

        if ($embedded > 0) {
            $file->forceFill([
                'processing_provider' => $provider->name(),
                'processing_model'    => $provider->model(),
                'mysql_indexed_at'    => now(),
            ])->save();
        }

        return $embedded;
    }
}
