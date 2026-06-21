<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\ExtractVideoPreviewAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

/**
 * Pipeline step that generates a still preview image from a video.
 */
class ExtractVideoPreviewStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::VIDEO) {
            return false;
        }

        return !empty($this->previewConfig($file));
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 45;
    }

    /** Runs the video preview extraction for the file. */
    public function handle(File $file): void
    {
        $preview = $this->previewConfig($file);

        ExtractVideoPreviewAction::create()->run([
            'file'            => $file,
            'frameAt'         => $preview['frame_at'] ?? '00:00:01',
            'previewVariants' => $preview['variants'] ?? [],
        ]);
    }

    /** Resolves the preview configuration for the file's collection. */
    protected function previewConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'video', $file->fileable_type);

        return $config['preview'] ?? [];
    }
}
