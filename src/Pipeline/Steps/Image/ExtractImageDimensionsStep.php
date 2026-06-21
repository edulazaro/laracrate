<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\ExtractImageDimensionsAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;

/**
 * Pipeline step that extracts width and height metadata from images.
 */
class ExtractImageDimensionsStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        return $file->type === FileType::IMAGE;
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 10;
    }

    /** Runs the image dimension extraction for the file. */
    public function handle(File $file): void
    {
        ExtractImageDimensionsAction::create()->run(['file' => $file]);
    }
}
