<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\GenerateEmbeddingAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Events\EmbeddingsReady;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use Illuminate\Support\Facades\Storage;

class GenerateEmbeddingStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        if (! ExtractionResolver::shouldEmbed($file)) {
            return false;
        }

        // Necesita el JSONL escrito por ChunkTextStep.
        if (! $file->disk || ! $file->path) {
            return false;
        }

        return Storage::disk($file->disk)->exists($file->path . '.chunks.jsonl');
    }

    public function priority(): int
    {
        return 80;
    }

    public function handle(File $file): void
    {
        $count = (int) GenerateEmbeddingAction::create()->run(['file' => $file]);

        if ($count > 0) {
            EmbeddingsReady::dispatch($file, $count);
        }
    }
}
