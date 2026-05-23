<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Video;

use EduLazaro\Laracrate\Actions\Files\Video\ExtractVideoPreviewAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

class ExtractVideoPreviewStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::VIDEO) {
            return false;
        }

        return !empty($this->previewConfig($file));
    }

    public function priority(): int
    {
        return 45;
    }

    public function handle(File $file): void
    {
        $preview = $this->previewConfig($file);

        ExtractVideoPreviewAction::create()->run([
            'file'            => $file,
            'frameAt'         => $preview['frame_at'] ?? '00:00:01',
            'previewVariants' => $preview['variants'] ?? [],
        ]);
    }

    protected function previewConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'video', $file->fileable_type);

        return $config['preview'] ?? [];
    }
}
