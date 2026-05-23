<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ExtractTextAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;

class ExtractTextStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        // `extract` y `embed` admiten bool o array de FileTypes en la collection
        // config. ExtractionResolver consulta tambien overrides app-level (org,
        // case) si la app registr&oacute; uno via setOverrideResolver().
        if (!ExtractionResolver::shouldExtract($file) && !ExtractionResolver::shouldEmbed($file)) {
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
