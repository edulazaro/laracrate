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
 * Genera UN variant de imagen del File padre. Persiste un File hijo con
 * parent_id = $file->id, variant = $name, type = image.
 *
 * Idempotente: si el variant ya existe, lo borra y lo regenera.
 * El asset físico viejo se va vía cascade FK + FileObserver.
 *
 * Options:
 *   - width:   int|null
 *   - height:  int|null
 *   - fit:     bool        (cover si true, scale si false)
 *   - quality: int         (0-100, default 80)
 *   - format:  'webp'|'jpg' (default 'webp')
 */
class GenerateImageVariantAction extends Action
{
    public function handle(File $file, string $name, array $options = []): ?File
    {
        if (!$file->isImage()) {
            return null;
        }

        $manager = app(StorageManager::class);

        // Limpia el variant existente con ese nombre (regeneración).
        $existing = $file->children()->where('variant', $name)->first();
        if ($existing) {
            $existing->forceDelete();
        }

        $binary = $manager->readBinary($file);
        $image  = $this->intervention()->read($binary);

        // Cadena de resolución para cualquier opción del variant:
        //   1. $options[$key]                                            (per-variant)
        //   2. config.collections.{X}.types.image.{key}                  (type-level override)
        //   3. config.defaults.image.{key}                               (global default)
        //   4. fallback hardcoded
        // Para 'quality', además prueba 'variant_quality' antes de 'quality' en niveles 2 y 3.
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

        // quality: variant.quality → type.variant_quality → defaults.variant_quality → cascade normal de 'quality'
        $quality = $options['quality']
            ?? ($imageCfg['variant_quality'] ?? null)
            ?? config("laracrate.defaults.image.variant_quality")
            ?? $resolve('quality', 80);

        // Watermark per-variant: si la config lo declara, lo incrustamos en
        // la imagen viva ANTES de encodear. El original (master) nunca lo
        // lleva — solo las variants que lo piden explícitamente.
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

    protected function intervention(): ImageManager
    {
        $driver = config('laracrate.image.driver', 'imagick');

        return $driver === 'gd'
            ? new ImageManager(new GdDriver())
            : new ImageManager(new ImagickDriver());
    }
}
