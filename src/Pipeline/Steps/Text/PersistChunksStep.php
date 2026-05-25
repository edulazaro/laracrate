<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\PersistChunksAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Storage;

/**
 * Persiste los chunks (con embeddings) al backend del ChunkStore activo.
 * Corre después de ChunkText (70) y GenerateEmbedding (80).
 */
class PersistChunksStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return Storage::disk($file->disk)->exists($file->path . '.chunks.jsonl');
    }

    public function priority(): int
    {
        return 90;
    }

    public function handle(File $file): void
    {
        PersistChunksAction::create()->run(['file' => $file]);
    }
}
