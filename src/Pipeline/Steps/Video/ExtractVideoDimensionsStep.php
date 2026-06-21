<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\ExtractVideoDimensionsAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;

/**
 * Pipeline step that extracts width and height metadata from videos.
 */
class ExtractVideoDimensionsStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        return $file->type === FileType::VIDEO;
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 10;
    }

    /** Runs the video dimension extraction for the file. */
    public function handle(File $file): void
    {
        ExtractVideoDimensionsAction::create()->run(['file' => $file]);
    }
}
