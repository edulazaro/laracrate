<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\ExtractVideoDimensionsAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;

class ExtractVideoDimensionsStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        return $file->type === FileType::VIDEO;
    }

    public function priority(): int
    {
        return 10;
    }

    public function handle(File $file): void
    {
        ExtractVideoDimensionsAction::create()->run(['file' => $file]);
    }
}
