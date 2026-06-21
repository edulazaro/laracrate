<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Actions\Files\ApplyWatermarkAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

/**
 * Generates ONE image variant of the parent File. Persists a child File with
 * parent_id = $file->id, variant = $name, type = image.
 *
 * Idempotent: if the variant already exists, it is deleted and regenerated.
 * The old physical asset is removed via cascade FK + FileObserver.
 *
 * Options:
 *   - width:   int|null
 *   - height:  int|null
 *   - fit:     bool        (cover if true, scale if false)
 *   - quality: int         (0-100, default 80)
 *   - format:  'webp'|'jpg' (default 'webp')
 */
class GenerateImageVariantAction extends Action
{
    /**
     * Generate a single image variant of the given parent File.
     */
    public function handle(File $file, string $name, array $options = []): ?File
    {
        if (!$file->isImage()) {
            return null;
        }

        $manager = app(StorageManager::class);

        // Clean up the existing variant with that name (regeneration).
        $existing = $file->children()->where('variant', $name)->first();
        if ($existing) {
            $existing->forceDelete();
        }

        $binary = $manager->readBinary($file);
        $image  = $this->intervention()->read($binary);

        // Resolution chain for any variant option:
        //   1. $options[$key]                                            (per-variant)
        //   2. config.collections.{X}.types.image.{key}                  (type-level override)
        //   3. config.defaults.image.{key}                               (global default)
        //   4. hardcoded fallback
        // For 'quality', it also tries 'variant_quality' before 'quality' at levels 2 and 3.
        $collection   = $file->collection;
        $imageCfg     = CollectionConfig::resolve($collection, $file->fileable_type)['types']['image'] ?? [];
        $resolve = fn (string $key, mixed $default) => $options[$key]
            ?? ($imageCfg[$key] ?? null)
            ?? config("laracrate.defaults.image.{$key}")
            ?? $default;

        $width  = $resolve('width', null);
        $height = $resolve('height', null);
        $fit    = $resolve('fit', false);

        if ($width !== null || $height !== null) {
            $image = $fit
                ? $image->cover($width ?? $height, $height ?? $width)
                : $image->scaleDown($width, $height);
        }

        $format = $resolve('format', 'webp');

        // quality: variant.quality -> type.variant_quality -> defaults.variant_quality -> normal 'quality' cascade
        $quality = $options['quality']
            ?? ($imageCfg['variant_quality'] ?? null)
            ?? config("laracrate.defaults.image.variant_quality")
            ?? $resolve('quality', 80);

        // Per-variant watermark: if the config declares it, we embed it into
        // the live image BEFORE encoding. The original (master) never carries
        // it, only the variants that explicitly request it.
        if (!empty($options['watermark'])) {
            ApplyWatermarkAction::create()->run([
                'image' => $image,
                'file'  => $file,
            ]);
        }

        $extension = $format === 'jpg' ? 'jpg' : 'webp';
        $mime      = $format === 'jpg' ? 'image/jpeg' : 'image/webp';

        $encoded = $format === 'jpg'
            ? $image->toJpeg($quality)->toString()
            : $image->toWebp($quality)->toString();

        $baseName = Str::beforeLast($file->name, '.');
        $newName  = "{$baseName}_{$name}.{$extension}";
        $key      = $file->variantKey($newName);

        $manager->writeBinary($file->disk, $key, $encoded, $mime);

        return $file->createVariant($name, [
            'path'          => $key,
            'name'          => $newName,
            'original_name' => $newName,
            'extension'     => $extension,
            'mime_type'     => $mime,
            'size'          => strlen($encoded),
            'type'          => FileType::IMAGE,
            'width'         => $image->width(),
            'height'        => $image->height(),
        ]);
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
}
