<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ChunkTextAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;

class ChunkTextStep implements ProcessingStep
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

        // Solo si ExtractTextStep dejó texto en file_contents.
        return $file->contents()->whereNotNull('text')->exists();
    }

    public function priority(): int
    {
        return 70;
    }

    public function handle(File $file): void
    {
        ChunkTextAction::create()->run(['file' => $file]);
    }
}
