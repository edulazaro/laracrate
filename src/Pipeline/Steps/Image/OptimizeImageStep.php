<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\OptimizeImageAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;

class OptimizeImageStep implements FileActionInterface
{
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::IMAGE) {
            return false;
        }

        return $this->shouldOptimize($file);
    }

    public function priority(): int
    {
        return 20;
    }

    public function handle(File $file): void
    {
        OptimizeImageAction::create()->run(['file' => $file]);
    }

    protected function shouldOptimize(File $file): bool
    {
        $colRoot = CollectionConfig::resolve($file->collection, $file->fileable_type);
        $config  = app(StorageManager::class)->getTypeConfig($file->collection, 'image', $file->fileable_type);

        return (bool) (
            $colRoot['optimize']
            ?? $config['optimize']
            ?? config('laracrate.image.optimize_originals', false)
        );
    }
}
