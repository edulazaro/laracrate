<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileContent;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Genera embeddings para todos los chunks de un File que aún no los tengan.
 * Llama al EmbeddingProvider en batches según config y escribe los vectores
 * en file_contents.embedding.
 *
 * Si la app prefiere indexar en pgvector/Meilisearch/Qdrant, lo hace en un
 * listener escuchando el modelo `FileContent::saved` (los vectores quedan
 * persistidos en el JSON local también, para portabilidad).
 */
class GenerateEmbeddingAction extends Action
{
    public function handle(File $file): int
    {
        $provider = app(EmbeddingProvider::class);
        $batchSize = (int) config('laracrate.embeddings.batch_size', 16);

        $pending = FileContent::where('file_id', $file->id)
            ->whereNull('embedding')
            ->whereNotNull('text')
            ->orderBy('chunk_index')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $embedded = 0;

        foreach ($pending->chunk($batchSize) as $batch) {
            $texts = $batch->pluck('text')->all();

            try {
                $vectors = $provider->embed($texts);
            } catch (Throwable $e) {
                $batch->each(fn ($c) => $c->fill([
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ])->save());

                logger()->error('Laracrate: GenerateEmbeddingAction failed', [
                    'file_id' => $file->id,
                    'error'   => $e->getMessage(),
                ]);

                throw $e;
            }

            foreach ($batch as $i => $content) {
                $vector = $vectors[$i] ?? null;
                if (!is_array($vector) || empty($vector)) continue;

                $content->fill([
                    'embedding' => $vector,
                    'provider'  => $provider->name(),
                    'model'     => $provider->model(),
                    'status'    => 'completed',
                    'error'     => null,
                ])->save();

                $embedded++;
            }
        }

        return $embedded;
    }
}
