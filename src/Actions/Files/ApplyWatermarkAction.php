<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Incrusta una marca de agua (PNG superpuesta + texto opcional) en una
 * imagen viva (`ImageInterface` de Intervention). Usado por
 * `GenerateImageVariantAction` cuando la config de la variant declara
 * `'watermark' => true`.
 *
 * Convenciones:
 *  - El watermark se aplica SOLO a variants. El original (master) nunca
 *    lleva watermark — lo garantiza el caller.
 *  - La PNG escala proporcionalmente al ancho de la variant (`size` en
 *    config = fracción del ancho).
 *  - El texto auxiliar (opcional) es config-driven: string fijo o closure
 *    que recibe el `File` y devuelve el texto.
 *  - Si no hay PNG configurada y no hay texto, es no-op (devuelve la
 *    imagen tal cual).
 */
class ApplyWatermarkAction extends Action
{
    public function handle(ImageInterface $image, ?File $file = null): ImageInterface
    {
        $config = config('laracrate.watermark', []);

        try {
            $this->stampImage($image, $config);
            $this->stampText($image, $config['text'] ?? [], $file);
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al aplicar watermark', [
                'file_id' => $file?->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $image;
    }

    protected function stampImage(ImageInterface $image, array $config): void
    {
        $imagePath = $config['image_path'] ?? null;
        if (!$imagePath) {
            return;
        }

        $resolved = $this->resolvePath($imagePath);
        if (!$resolved || !file_exists($resolved)) {
            return;
        }

        $size     = (float) ($config['size'] ?? 0.40);
        $opacity  = (int) ($config['opacity'] ?? 30);
        $position = $config['position'] ?? 'center';

        $manager   = $this->imageManager();
        $watermark = $manager->read($resolved);

        $targetWidth = (int) ($image->width() * $size);
        $watermark   = $watermark->scaleDown($targetWidth);

        // Posicionamiento: 'center' calculamos desplazamiento; los demás
        // delegan en Intervention con offset 0.
        if ($position === 'center') {
            $x = (int) (($image->width() - $watermark->width()) / 2);
            $y = (int) (($image->height() - $watermark->height()) / 2);
            $image->place($watermark, 'top-left', $x, $y, $opacity);
            return;
        }

        $image->place($watermark, $position, 0, 0, $opacity);
    }

    protected function stampText(ImageInterface $image, array $textConfig, ?File $file): void
    {
        $content = $this->resolveTextContent($textConfig['content'] ?? null, $file);
        if ($content === null || $content === '') {
            return;
        }

        $fontSizeRatio = (float) ($textConfig['font_size_ratio'] ?? 0.0195);
        $color         = $textConfig['color'] ?? 'rgba(255, 255, 255, 0.60)';
        $position      = $textConfig['position'] ?? 'bottom-left';
        $padding       = (int) ($textConfig['padding'] ?? 20);
        $fontPath      = $textConfig['font_path'] ?? null;

        $fontSize = (int) ($image->width() * $fontSizeRatio);

        [$x, $y, $align, $valign] = $this->textCoordinates($image, $position, $padding);

        $image->text($content, $x, $y, function ($font) use ($fontSize, $color, $align, $valign, $fontPath) {
            $font->size($fontSize);
            $font->color($color);
            $font->align($align);
            $font->valign($valign);
            if ($fontPath && file_exists($fontPath)) {
                $font->filename($fontPath);
            }
        });
    }

    /**
     * Resuelve el contenido del texto: string fijo, closure(File): ?string,
     * o null. Si llega closure, se invoca con el File (puede devolver null
     * para saltar el texto).
     */
    protected function resolveTextContent(mixed $content, ?File $file): ?string
    {
        if ($content === null) {
            return null;
        }

        if (is_string($content)) {
            return $content !== '' ? $content : null;
        }

        if (is_callable($content)) {
            $result = $content($file);
            return is_string($result) && $result !== '' ? $result : null;
        }

        return null;
    }

    /**
     * Devuelve [x, y, align, valign] para la combinación posición + padding.
     */
    protected function textCoordinates(ImageInterface $image, string $position, int $padding): array
    {
        return match ($position) {
            'top-left'     => [$padding,                      $padding,                       'left',  'top'],
            'top-right'    => [$image->width() - $padding,    $padding,                       'right', 'top'],
            'bottom-right' => [$image->width() - $padding,    $image->height() - $padding,    'right', 'bottom'],
            default        => [$padding,                      $image->height() - $padding,    'left',  'bottom'],
        };
    }

    /**
     * Resuelve un path: si es absoluto y existe, lo usa; si es relativo,
     * intenta `public_path()`.
     */
    protected function resolvePath(string $path): ?string
    {
        if (str_starts_with($path, '/') && file_exists($path)) {
            return $path;
        }

        if (function_exists('public_path')) {
            $candidate = public_path($path);
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return file_exists($path) ? $path : null;
    }

    protected function imageManager(): ImageManager
    {
        return config('laracrate.image.driver') === 'gd'
            ? new ImageManager(new GdDriver())
            : new ImageManager(new ImagickDriver());
    }
}
