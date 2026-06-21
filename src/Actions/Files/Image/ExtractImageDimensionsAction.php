<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Extracts width and height from an image by reading its binary and persists
 * them on the File. No-op if the dimensions are already set.
 */
class ExtractImageDimensionsAction extends Action
{
    /**
     * Reads the image binary and stores its width/height on the File.
     */
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
            logger()->warning('Laracrate: failed to extract image dimensions', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $file;
    }
}
