<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

class GenerateImageVariantsStep implements ProcessingStep
{
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::IMAGE) {
            return false;
        }

        return !empty($this->variantsConfig($file));
    }

    public function priority(): int
    {
        return 40;
    }

    public function handle(File $file): void
    {
        GenerateImageVariantsAction::create()->run([
            'file'     => $file,
            'variants' => $this->variantsConfig($file),
        ]);
    }

    protected function variantsConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'image', $file->fileable_type);

        return $config['variants'] ?? [];
    }
}
