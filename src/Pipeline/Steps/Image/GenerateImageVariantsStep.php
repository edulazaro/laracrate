<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;

/**
 * Pipeline step that generates configured image variants (resized derivatives).
 */
class GenerateImageVariantsStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        if ($file->type !== FileType::IMAGE) {
            return false;
        }

        return !empty($this->variantsConfig($file));
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 40;
    }

    /** Runs the image variant generation for the file. */
    public function handle(File $file): void
    {
        GenerateImageVariantsAction::create()->run([
            'file'     => $file,
            'variants' => $this->variantsConfig($file),
        ]);
    }

    /** Resolves the variants configuration for the file's collection. */
    protected function variantsConfig(File $file): array
    {
        $config = app(StorageManager::class)->getTypeConfig($file->collection, 'image', $file->fileable_type);

        return $config['variants'] ?? [];
    }
}
