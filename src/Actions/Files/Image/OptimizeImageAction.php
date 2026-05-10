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
 * Optimiza el original: reduce a max dimensions configuradas y convierte
 * a webp. Reemplaza el binario en el backend (borra el viejo si la key
 * cambia) y actualiza el File row.
 *
 * Solo aplica a top-level files de tipo image. No-op para variants
 * (los variants se generan ya optimizados).
 */
class OptimizeImageAction extends Action
{
    public function handle(File $file, ?int $maxWidth = null, ?int $maxHeight = null, ?int $quality = null): File
    {
        if (!$file->isImage() || $file->isVariant()) {
            return $file;
        }

        // Cadena de resolución: arg → collection.types.image → defaults.image → image (engine)
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

    protected function intervention(): ImageManager
    {
        $driver = config('laracrate.image.driver', 'imagick');

        return $driver === 'gd'
            ? new ImageManager(new GdDriver())
            : new ImageManager(new ImagickDriver());
    }

    /**
     * Resuelve un parámetro image leyendo en cadena:
     *   1. $imageCfg[$key]            (collection.types.image, ya con override per-model aplicado)
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
