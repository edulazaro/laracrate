<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\GenerateEmbeddingAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Events\EmbeddingsReady;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;

class GenerateEmbeddingStep implements ProcessingStep
{
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        $colRoot = CollectionConfig::resolve($file->collection, $file->fileable_type);
        $embed   = (bool) ($colRoot['embed'] ?? false);

        if (!$embed) {
            return false;
        }

        // Solo si hay chunks con texto pendientes de embedding.
        return $file->contents()
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
