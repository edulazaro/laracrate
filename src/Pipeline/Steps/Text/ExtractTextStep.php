<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ExtractTextAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;

class ExtractTextStep implements ProcessingStep
{
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        $colRoot     = CollectionConfig::resolve($file->collection, $file->fileable_type);
        $extractText = (bool) ($colRoot['extract_text'] ?? false);
        $embed       = (bool) ($colRoot['embed'] ?? false);

        if (!$extractText && !$embed) {
            return false;
        }

        // Solo aplica si el registry tiene un extractor para este File.
        return app(TextExtractorRegistry::class)->for($file) !== null;
    }

    public function priority(): int
    {
        return 60;
    }

    public function handle(File $file): void
    {
        ExtractTextAction::create()->run(['file' => $file]);
    }
}
