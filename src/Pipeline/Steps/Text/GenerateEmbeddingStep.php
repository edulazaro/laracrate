<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\GenerateEmbeddingAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Events\EmbeddingsReady;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;

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

        // Solo si hay chunks con texto pendientes de embedding.
        return $file->chunks()
            ->whereNotNull('text')
            ->whereNull('embedding')
            ->exists();
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
