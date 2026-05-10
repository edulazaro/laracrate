<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\OptimizeImageAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

class OptimizeImageStep implements ProcessingStep
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
        $colRoot = config("laracrate.collections.{$file->collection}", []);
        $config  = app(StorageManager::class)->getTypeConfig($file->collection, 'image');

        return (bool) (
            $colRoot['optimize']
            ?? $config['optimize']
            ?? config('laracrate.image.optimize_originals', false)
        );
    }
}
