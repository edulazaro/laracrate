<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extrae un frame del vídeo con ffmpeg, lo sube como JPG al backend, y crea
 * un File hijo con parent_id = $file->id, variant = 'preview', type = image.
 *
 * Después dispatcha GenerateImageVariantsAction sobre el preview para crear
 * sus thumbnail/medium/large según config.preview.variants.
 *
 * Requiere ffmpeg en el path.
 */
class ExtractVideoPreviewAction extends Action
{
    public function handle(File $file, ?string $frameAt = null, array $previewVariants = []): ?File
    {
        if (!$file->isVideo()) {
            return null;
        }

        $frameAt = $frameAt ?? '00:00:01';

        $manager = app(StorageManager::class);

        // Limpia preview previo (regeneración).
        $existing = $file->children()->where('variant', 'preview')->first();
        if ($existing) {
            $existing->forceDelete();
        }

        try {
            $previewBinary = $manager->withLocalCopy($file, function (string $videoPath) use ($frameAt) {
                $tempOut = sys_get_temp_dir() . '/laracrate_preview_' . Str::random(16) . '.jpg';

                $cmd = sprintf(
                    'ffmpeg -y -i %s -ss %s -vframes 1 -q:v 2 %s 2>&1',
                    escapeshellarg($videoPath),
                    escapeshellarg($frameAt),
                    escapeshellarg($tempOut)
                );

                exec($cmd, $output, $code);

                if ($code !== 0 || !is_file($tempOut)) {
                    logger()->warning('Laracrate: ffmpeg falló al extraer preview', [
                        'cmd'    => $cmd,
                        'output' => $output,
                        'code'   => $code,
                    ]);
                    return null;
                }

                $binary = file_get_contents($tempOut);
                @unlink($tempOut);
                return $binary;
            });

            if (!$previewBinary) {
                return null;
            }
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al extraer preview de vídeo', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        // Sube el JPG y crea el File hijo.
        $baseName = Str::beforeLast($file->name, '.');
        $newName  = "{$baseName}_preview.jpg";
        $key      = $file->variantKey($newName);

        $manager->writeBinary($file->disk, $key, $previewBinary, 'image/jpeg');

        [$width, $height] = @getimagesizefromstring($previewBinary) ?: [null, null];

        $preview = $file->createVariant('preview', [
            'path'          => $key,
            'name'          => $newName,
            'original_name' => $newName,
            'extension'     => 'jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => strlen($previewBinary),
            'type'          => FileType::IMAGE,
            'width'         => $width,
            'height'        => $height,
        ]);

        // Variants del preview (thumbnail/medium/large) si vienen definidos.
        if (!empty($previewVariants)) {
            GenerateImageVariantsAction::create()->run([
                'file'     => $preview,
                'variants' => $previewVariants,
            ]);
        }

        return $preview;
    }
}
