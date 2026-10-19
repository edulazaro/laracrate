<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\GenerateVideoVariantsAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\Variants;

/**
 * Pipeline step that generates the video type's variants: copies, teasers, frames.
 */
class GenerateVideoVariantsStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::VIDEO) {
            return false;
        }

        return Variants::of($file, 'video') !== [];
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 30;
    }

    /** Runs the video variant generation for the file. */
    public function handle(File $file): void
    {
        GenerateVideoVariantsAction::create()->run([
            'file'     => $file,
            'variants' => Variants::of($file, 'video'),
        ]);
    }
}
