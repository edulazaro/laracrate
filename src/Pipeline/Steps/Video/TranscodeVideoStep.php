<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\TranscodeVideoAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

/**
 * Pipeline step that transcodes a video into the configured output format.
 */
class TranscodeVideoStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::VIDEO) {
            return false;
        }

        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'video', $file->fileable_type);

        return !empty($config['transcode']);
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 25;
    }

    /** Runs the video transcoding for the file. */
    public function handle(File $file): void
    {
        TranscodeVideoAction::create()->run(['file' => $file]);
    }
}
