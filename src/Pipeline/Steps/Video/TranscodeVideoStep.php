<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\TranscodeVideoAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

class TranscodeVideoStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::VIDEO) {
            return false;
        }

        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'video', $file->fileable_type);

        return !empty($config['transcode']);
    }

    public function priority(): int
    {
        return 25;
    }

    public function handle(File $file): void
    {
        TranscodeVideoAction::create()->run(['file' => $file]);
    }
}
