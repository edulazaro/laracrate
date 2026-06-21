<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\GenerateEmbeddingAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Events\EmbeddingsReady;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Pipeline step that generates vector embeddings for the file's text chunks.
 */
class GenerateEmbeddingStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        if (! ExtractionResolver::shouldEmbed($file)) {
            return false;
        }

        // Requires the JSONL written by ChunkTextStep.
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return Storage::disk($file->disk)->exists($file->path . '.chunks.jsonl');
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 80;
    }

    /** Runs the embedding generation and dispatches the ready event. */
    public function handle(File $file): void
    {
        $count = (int) GenerateEmbeddingAction::create()->run(['file' => $file]);

        if ($count > 0) {
            EmbeddingsReady::dispatch($file, $count);
        }
    }
}
