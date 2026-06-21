<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

/**
 * Optimizes the original: reduces it to the configured max dimensions and
 * converts it to webp. Replaces the binary in the backend (deletes the old
 * one if the key changes) and updates the File row.
 *
 * Only applies to top-level files of type image. No-op for variants
 * (variants are generated already optimized).
 */
class OptimizeImageAction extends Action
{
    /**
     * Optimize the original image to max dimensions and webp.
     */
    public function handle(File $file, ?int $maxWidth = null, ?int $maxHeight = null, ?int $quality = null): File
    {
        if (!$file->isImage() || $file->isVariant()) {
            return $file;
        }

        // Resolution chain: arg -> collection.types.image -> defaults.image -> image (engine)
        $imageCfg  = CollectionConfig::resolve($file->collection, $file->fileable_type)['types']['image'] ?? [];
        $maxWidth  = $maxWidth ?? $this->configFor($imageCfg, 'max_width', 1920);
        $maxHeight = $maxHeight ?? $this->configFor($imageCfg, 'max_height', 1920);
        $quality   = $quality ?? $this->configFor($imageCfg, 'quality', 85);

        $manager = app(StorageManager::class);
        $binary  = $manager->readBinary($file);
        $image   = $this->intervention()->read($binary);

        if ($image->width() > $maxWidth || $image->height() > $maxHeight) {
            $image = $image->scaleDown($maxWidth, $maxHeight);
        }

        $webp = $image->toWebp($quality)->toString();

        $oldKey      = $file->key;
        $newName     = Str::beforeLast($file->name, '.') . '.webp';
        $newKey      = $file->siblingKey($newName);
        $changedName = $oldKey !== $newKey;

        $manager->writeBinary($file->disk, $newKey, $webp, 'image/webp');

        if ($changedName) {
            $manager->deleteFromBackend($file->disk, $oldKey);
        }

        $file->forceFill([
            'path'      => $newKey,
            'name'      => $newName,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size'      => strlen($webp),
            'width'     => $image->width(),
            'height'    => $image->height(),
        ])->save();

        return $file;
    }

    /**
     * Build the Intervention ImageManager for the configured driver.
     */
    protected function intervention(): ImageManager
    {
        $driver = config('laracrate.image.driver', 'imagick');

        return $driver === 'gd'
            ? new ImageManager(new GdDriver())
            : new ImageManager(new ImagickDriver());
    }

    /**
     * Resolves an image parameter reading it in a chain:
     *   1. $imageCfg[$key]            (collection.types.image, already with per-model override applied)
     *   2. config.defaults.image.{key}
     *   3. config.image.{key}         (engine fallback)
     *   4. $default
     */
    protected function configFor(array $imageCfg, string $key, mixed $default): mixed
    {
        return ($imageCfg[$key] ?? null)
            ?? config("laracrate.defaults.image.{$key}")
            ?? config("laracrate.image.{$key}")
            ?? $default;
    }
}
