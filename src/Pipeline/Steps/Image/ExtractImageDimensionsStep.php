<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\ExtractImageDimensionsAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;

class ExtractImageDimensionsStep implements ProcessingStep
{
    public function supports(File $file): bool
    {
        return $file->type === FileType::IMAGE;
    }

    public function priority(): int
    {
        return 10;
    }

    public function handle(File $file): void
    {
        ExtractImageDimensionsAction::create()->run(['file' => $file]);
    }
}
