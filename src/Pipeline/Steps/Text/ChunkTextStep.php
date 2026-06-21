<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ChunkTextAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;

/**
 * Pipeline step that splits extracted text into chunks for embedding.
 */
class ChunkTextStep implements FileActionInterface
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

        // Only if ExtractTextStep left the `.json` sidecar in storage.
        // The text lives in storage (canonical) and is duplicated into MySQL
        // chunk by chunk during this step (for FULLTEXT keyword search).
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return \Illuminate\Support\Facades\Storage::disk($file->disk)
            ->exists($file->path . '.json');
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 70;
    }

    /** Runs the text chunking for the file. */
    public function handle(File $file): void
    {
        ChunkTextAction::create()->run(['file' => $file]);
    }
}
