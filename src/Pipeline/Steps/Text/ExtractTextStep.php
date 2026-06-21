<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Text;

use EduLazaro\Laracrate\Actions\Files\ExtractTextAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;

/**
 * Pipeline step that extracts text content from supported files.
 */
class ExtractTextStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if (!config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        // `extract` and `embed` accept a bool or an array of FileTypes in the
        // collection config. ExtractionResolver also consults app-level overrides
        // (org, case) if the app registered one via setOverrideResolver().
        if (!ExtractionResolver::shouldExtract($file) && !ExtractionResolver::shouldEmbed($file)) {
            return false;
        }

        // Only applies if the registry has an extractor for this File.
        return app(TextExtractorRegistry::class)->for($file) !== null;
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 60;
    }

    /** Runs the text extraction for the file. */
    public function handle(File $file): void
    {
        ExtractTextAction::create()->run(['file' => $file]);
    }
}
