<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\PersistChunksAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Storage;

/**
 * Persists the chunks (with embeddings) to the active ChunkStore backend.
 * Runs after ChunkText (70) and GenerateEmbedding (80).
 */
class PersistChunksStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return Storage::disk($file->disk)->exists($file->path . '.chunks.jsonl');
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 90;
    }

    /** Runs the chunk persistence for the file. */
    public function handle(File $file): void
    {
        PersistChunksAction::create()->run(['file' => $file]);
    }
}
