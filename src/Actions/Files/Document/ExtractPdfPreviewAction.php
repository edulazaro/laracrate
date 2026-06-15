<?php

namespace EduLazaro\Laracrate\Actions\Files\Document;

use EduLazaro\Laracrate\Actions\Files\Image\GenerateImageVariantsAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Imagick;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Renderiza una página de un PDF como imagen y la sube como variant='preview'
 * del File. Después dispatcha GenerateImageVariantsAction sobre el preview.
 *
 * Motor de rasterizado seleccionable vía `engine` (o config laracrate.pdf_preview_engine):
 *   - 'pdftoppm' : binario poppler-utils. NO requiere Ghostscript ni policy.xml.
 *   - 'imagick'  : extensión PHP Imagick + Ghostscript + coder PDF habilitado en policy.xml.
 *   - 'auto'     : intenta pdftoppm y cae a Imagick si no está disponible.
 */
class ExtractPdfPreviewAction extends Action
{
    public function handle(
        File $file,
        int $page = 0,
        int $width = 2000,
        array $previewVariants = [],
        ?string $engine = null,
        int $resolution = 150,
    ): ?File {
        if ($file->mime_type !== 'application/pdf') {
            return null;
        }

        $engine = in_array($engine, ['auto', 'pdftoppm', 'imagick'], true)
            ? $engine
            : config('laracrate.pdf_preview_engine', 'auto');

        $manager = app(StorageManager::class);

        $existing = $file->children()->where('variant', 'preview')->first();
        if ($existing) {
            $existing->forceDelete();
        }

        $previewBinary = $manager->withLocalCopy($file, function (string $pdfPath) use ($page, $width, $engine, $resolution, $file) {
            return $this->render($pdfPath, $page, $width, $engine, $resolution, $file);
        });

        if (!$previewBinary) {
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

    /**
     * Devuelve el PNG binario de la página, según el engine elegido.
     */
    protected function render(string $pdfPath, int $page, int $width, string $engine, int $resolution, File $file): ?string
    {
        if ($engine === 'pdftoppm' || $engine === 'auto') {
            $binary = $this->renderWithPdftoppm($pdfPath, $page, $width, $resolution, $file);

            if ($binary !== null) {
                return $binary;
            }

            // engine forzado: no caemos a Imagick, dejamos constancia.
            if ($engine === 'pdftoppm') {
                logger()->warning('Laracrate: pdftoppm falló o no está instalado (engine forzado)', [
                    'file_id' => $file->id,
                ]);

                return null;
            }
        }

        return $this->renderWithImagick($pdfPath, $page, $width, $resolution, $file);
    }

    /**
     * Rasteriza con pdftoppm (poppler-utils). Lee el PDF directamente con
     * Poppler: sin Ghostscript, sin Imagick, sin policy.xml. Devuelve null si
     * el binario no está disponible o el render falla (para permitir fallback).
     */
    protected function renderWithPdftoppm(string $pdfPath, int $page, int $width, int $resolution, File $file): ?string
    {
        $prefix = sys_get_temp_dir() . '/laracrate_pdftoppm_' . Str::random(16);
        $outPng = $prefix . '.png';
        $pageNum = $page + 1; // pdftoppm es 1-indexed; $page llega 0-indexed.

        try {
            $process = new Process([
                'pdftoppm',
                '-f', (string) $pageNum,
                '-l', (string) $pageNum,
                '-singlefile',
                '-r', (string) $resolution,
                '-png',
                $pdfPath,
                $prefix,
            ]);
            $process->run();

            if (!$process->isSuccessful() || !is_file($outPng)) {
                return null;
            }

            $binary = file_get_contents($outPng) ?: null;
            if ($binary === null) {
                return null;
            }

            return $this->downscalePng($binary, $width);
        } catch (Throwable $e) {
            return null;
        } finally {
            if (is_file($outPng)) {
                @unlink($outPng);
            }
        }
    }

    /**
     * Rasteriza con Imagick (delega en Ghostscript para leer el PDF).
     * Requiere la extensión imagick, el binario gs y policy.xml con PDF.
     */
    protected function renderWithImagick(string $pdfPath, int $page, int $width, int $resolution, File $file): ?string
    {
        if (!class_exists(Imagick::class)) {
            logger()->warning('Laracrate: Imagick no disponible para preview de PDF', [
                'file_id' => $file->id,
            ]);

            return null;
        }

        try {
            $im = new Imagick();
            $im->setResolution($resolution, $resolution);
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
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al renderizar PDF', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reduce el ancho del PNG a $width (solo si lo supera) usando GD, para
     * respetar la semántica "solo downscale" sin depender de Imagick. Si GD
     * no está o falla, devuelve el binario original sin tocar.
     */
    protected function downscalePng(string $binary, int $width): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagescale')) {
            return $binary;
        }

        $img = @imagecreatefromstring($binary);
        if ($img === false) {
            return $binary;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if ($w <= $width) {
            imagedestroy($img);

            return $binary;
        }

        $newHeight = (int) round($h * ($width / $w));
        $resized = imagescale($img, $width, $newHeight);
        imagedestroy($img);

        if ($resized === false) {
            return $binary;
        }

        ob_start();
        imagepng($resized);
        $out = ob_get_clean();
        imagedestroy($resized);

        return ($out !== false && $out !== '') ? $out : $binary;
    }
}
