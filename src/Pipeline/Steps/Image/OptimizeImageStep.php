<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\OptimizeImageAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;

/**
 * Pipeline step that optimizes (compresses) the original image.
 */
class OptimizeImageStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::IMAGE) {
            return false;
        }

        return $this->shouldOptimize($file);
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 20;
    }

    /** Runs the image optimization for the file. */
    public function handle(File $file): void
    {
        OptimizeImageAction::create()->run(['file' => $file]);
    }

    /** Determines whether optimization is enabled for the file's collection. */
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
