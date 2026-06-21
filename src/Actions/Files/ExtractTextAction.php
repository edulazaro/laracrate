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
 * Extracts structured content from the File by iterating the extractor chain
 * with fallback (if one returns little text, it tries the next one).
 *
 * Persists:
 *  - Sidecar `{path}.json` in storage with full_text + pages + metadata.
 *  - File-level state: processing_status, processing_started_at,
 *    processing_error, storage_indexed_at.
 *
 * Chunking + embeddings run in later actions.
 */
class ExtractTextAction extends Action
{
    /** Run the extractor chain and persist the extracted content sidecar. */
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

        // Sidecar `{path}.json` with the full structure.
        $jsonPath = $file->path . '.json';
        Storage::disk($file->disk)->put(
            $jsonPath,
            json_encode($best, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // NOTE: we do NOT mark processing_status = completed here. The final
        // status is managed by ProcessFileAction (or the calling job) when ALL
        // pipeline steps finish, including chunking and embeddings. Marking it
        // completed here prematurely causes the UI to show "no embeddings"
        // because GenerateEmbeddingStep has not run yet.
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
