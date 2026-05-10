<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Extrae width y height de una imagen leyendo su binario y los persiste
 * en el File. No-op si las dimensiones ya están set.
 */
class ExtractImageDimensionsAction extends Action
{
    public function handle(File $file): File
    {
        if ($file->width && $file->height) {
            return $file;
        }

        if (!str_starts_with($file->mime_type, 'image/')) {
            return $file;
        }

        try {
            $binary = app(StorageManager::class)->readBinary($file);
            [$width, $height] = @getimagesizefromstring($binary) ?: [null, null];

            if ($width && $height) {
                $file->forceFill([
                    'width'  => $width,
                    'height' => $height,
                ])->save();
            }
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al extraer dimensiones de imagen', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $file;
    }
}
