<?php

namespace EduLazaro\Laracrate\Actions\Files\Document;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Imagick;
use Throwable;

/**
 * Renderiza una página de un PDF como imagen y la sube como variant='preview'
 * del File. Después dispatcha GenerateImageVariantsAction sobre el preview.
 *
 * Requiere extensión PHP Imagick + Ghostscript.
 */
class ExtractPdfPreviewAction extends Action
{
    public function handle(File $file, int $page = 0, int $width = 2000, array $previewVariants = []): ?File
    {
        if ($file->mime_type !== 'application/pdf') {
            return null;
        }

        if (!class_exists(Imagick::class)) {
            logger()->warning('Laracrate: Imagick no disponible para preview de PDF', [
                'file_id' => $file->id,
            ]);
            return null;
        }

        $manager = app(StorageManager::class);

        $existing = $file->children()->where('variant', 'preview')->first();
        if ($existing) {
            $existing->forceDelete();
        }

        try {
            $previewBinary = $manager->withLocalCopy($file, function (string $pdfPath) use ($page, $width) {
                $im = new Imagick();
                $im->setResolution(150, 150);
                $im->readImage($pdfPath . '[' . $page . ']');
                $im->setImageFormat('png');
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

                if ($im->getImageWidth() > $width) {
                    $ratio = $width / $im->getImageWidth();
                    $im->resizeImage($width, (int) ($im->getImageHeight() * $ratio), Imagick::FILTER_LANCZOS, 1);
                }

                $bin = $im->getImageBlob();
                $im->clear();
                return $bin;
            });

            if (!$previewBinary) {
                return null;
            }
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al renderizar PDF', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        $baseName = Str::beforeLast($file->name, '.');
        $newName  = "{$baseName}_preview.png";
        $key      = $file->variantKey($newName);

        $manager->writeBinary($file->disk, $key, $previewBinary, 'image/png');

        [$w, $h] = @getimagesizefromstring($previewBinary) ?: [null, null];

        $preview = $file->createVariant('preview', [
            'path'          => $key,
            'name'          => $newName,
            'original_name' => $newName,
            'extension'     => 'png',
            'mime_type'     => 'image/png',
            'size'          => strlen($previewBinary),
            'type'          => FileType::IMAGE,
            'width'         => $w,
            'height'        => $h,
        ]);

        if (!empty($previewVariants)) {
            GenerateImageVariantsAction::create()->run([
                'file'     => $preview,
                'variants' => $previewVariants,
            ]);
        }

        return $preview;
    }
}
