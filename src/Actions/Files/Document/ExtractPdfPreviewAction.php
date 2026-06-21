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
 * Renders a PDF page as an image and uploads it as variant='preview' of the
 * File. Afterwards it dispatches GenerateImageVariantsAction on the preview.
 *
 * The rasterizing engine is selectable via `engine` (or config laracrate.pdf_preview_engine):
 *   - 'pdftoppm' : poppler-utils binary. Does NOT require Ghostscript or policy.xml.
 *   - 'imagick'  : PHP Imagick extension + Ghostscript + PDF coder enabled in policy.xml.
 *   - 'auto'     : tries pdftoppm and falls back to Imagick if not available.
 */
class ExtractPdfPreviewAction extends Action
{
    /**
     * Render a PDF page and persist it as the File's 'preview' variant.
     */
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
     * Returns the page's binary PNG, according to the chosen engine.
     */
    protected function render(string $pdfPath, int $page, int $width, string $engine, int $resolution, File $file): ?string
    {
        if ($engine === 'pdftoppm' || $engine === 'auto') {
            $binary = $this->renderWithPdftoppm($pdfPath, $page, $width, $resolution, $file);

            if ($binary !== null) {
                return $binary;
            }

            // engine forced: do not fall back to Imagick, just record it.
            if ($engine === 'pdftoppm') {
                logger()->warning('Laracrate: pdftoppm failed or is not installed (engine forced)', [
                    'file_id' => $file->id,
                ]);

                return null;
            }
        }

        return $this->renderWithImagick($pdfPath, $page, $width, $resolution, $file);
    }

    /**
     * Rasterizes with pdftoppm (poppler-utils). Reads the PDF directly with
     * Poppler: no Ghostscript, no Imagick, no policy.xml. Returns null if the
     * binary is not available or the render fails (to allow fallback).
     */
    protected function renderWithPdftoppm(string $pdfPath, int $page, int $width, int $resolution, File $file): ?string
    {
        $prefix = sys_get_temp_dir() . '/laracrate_pdftoppm_' . Str::random(16);
        $outPng = $prefix . '.png';
        $pageNum = $page + 1; // pdftoppm is 1-indexed; $page arrives 0-indexed.

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
     * Rasterizes with Imagick (delegates to Ghostscript to read the PDF).
     * Requires the imagick extension, the gs binary and policy.xml with PDF.
     */
    protected function renderWithImagick(string $pdfPath, int $page, int $width, int $resolution, File $file): ?string
    {
        if (!class_exists(Imagick::class)) {
            logger()->warning('Laracrate: Imagick not available for PDF preview', [
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
            logger()->warning('Laracrate: failed to render PDF', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reduces the PNG width to $width (only if it exceeds it) using GD, to
     * respect the "downscale only" semantics without depending on Imagick. If
     * GD is missing or fails, returns the original binary untouched.
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
