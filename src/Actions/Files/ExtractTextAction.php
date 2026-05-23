<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractedContent;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Extrae contenido estructurado del File iterando la chain de extractors
 * con fallback (si uno devuelve poco texto, prueba el siguiente).
 *
 * Persiste:
 *  - Sidecar `{path}.json` en storage con full_text + pages + metadata.
 *  - State a nivel file: processing_status, processing_started_at,
 *    processing_error, storage_indexed_at.
 *
 * El chunking + embeddings se ejecutan en actions posteriores.
 */
class ExtractTextAction extends Action
{
    public function handle(File $file): bool
    {
        $registry = app(TextExtractorRegistry::class);
        $chain = $registry->chainFor($file);

        if (empty($chain)) {
            return false;
        }

        $file->forceFill([
            'processing_status'     => 'processing',
            'processing_started_at' => now(),
            'processing_error'      => null,
        ])->save();

        $minText = (int) config('laracrate.embeddings.min_text_per_file', 100);
        $best = null;
        $lastError = null;
        $usedExtractor = null;

        foreach ($chain as $extractor) {
            try {
                $extracted = $extractor->extract($file);
                $length = mb_strlen(trim($extracted->fullText));

                if ($length >= $minText) {
                    $best = $extracted;
                    $usedExtractor = $extractor;
                    break;
                }

                if ($best === null || $length > mb_strlen(trim($best->fullText))) {
                    $best = $extracted;
                    $usedExtractor = $extractor;
                }

                logger()->info('Laracrate: extractor returned text below threshold, trying next', [
                    'file_id'   => $file->id,
                    'extractor' => $extractor::class,
                    'chars'     => $length,
                    'min_chars' => $minText,
                ]);
            } catch (Throwable $e) {
                $lastError = $e;
                logger()->warning('Laracrate: extractor failed, trying next', [
                    'file_id'   => $file->id,
                    'extractor' => $extractor::class,
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }
        }

        if ($best === null || $best->isEmpty()) {
            $file->forceFill([
                'processing_status' => 'failed',
                'processing_error'  => $lastError?->getMessage() ?? 'No extractor returned usable text',
            ])->save();

            if ($lastError) {
                throw $lastError;
            }

            return false;
        }

        // Sidecar `{path}.json` con la estructura completa.
        $jsonPath = $file->path . '.json';
        Storage::disk($file->disk)->put(
            $jsonPath,
            json_encode($best, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // OJO: NO marcamos processing_status = completed aquí. El status final
        // lo gestiona ProcessFileAction (o el job caller) cuando TODOS los
        // steps de la pipeline acaban — incluyendo chunking y embeddings.
        // Marcar completed aquí prematuramente causa que la UI muestre
        // "sin embeddings" porque GenerateEmbeddingStep aún no ha corrido.
        $file->forceFill([
            'storage_indexed_at' => now(),
            'metadata'           => array_merge(
                (array) ($file->metadata ?? []),
                [
                    'text_chars'  => mb_strlen($best->fullText),
                    'total_pages' => $best->totalPages(),
                ],
            ),
        ])->save();

        return true;
    }
}
