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
 * Embeds a watermark (overlaid PNG + optional text) into a live image
 * (Intervention's `ImageInterface`). Used by `GenerateImageVariantAction`
 * when the variant config declares `'watermark' => true`.
 *
 * Conventions:
 *  - The watermark is applied ONLY to variants. The original (master) never
 *    carries a watermark, guaranteed by the caller.
 *  - The PNG scales proportionally to the variant width (`size` in config
 *    = fraction of the width).
 *  - The auxiliary text (optional) is config-driven: a fixed string or a
 *    closure that receives the `File` and returns the text.
 *  - If no PNG is configured and there is no text, it is a no-op (returns
 *    the image as-is).
 */
class ApplyWatermarkAction extends Action
{
    /** Apply the configured watermark image and text to the image. */
    public function handle(ImageInterface $image, ?File $file = null): ImageInterface
    {
        $config = config('laracrate.watermark', []);

        try {
            $this->stampImage($image, $config);
            $this->stampText($image, $config['text'] ?? [], $file);
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to apply watermark', [
                'file_id' => $file?->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $image;
    }

    /** Overlay the configured watermark PNG onto the image. */
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

        // Positioning: for 'center' we compute the offset; the rest delegate
        // to Intervention with offset 0.
        if ($position === 'center') {
            $x = (int) (($image->width() - $watermark->width()) / 2);
            $y = (int) (($image->height() - $watermark->height()) / 2);
            $image->place($watermark, 'top-left', $x, $y, $opacity);
            return;
        }

        $image->place($watermark, $position, 0, 0, $opacity);
    }

    /** Draw the configured watermark text onto the image. */
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
     * Resolves the text content: a fixed string, a closure(File): ?string,
     * or null. If a closure is given, it is invoked with the File (it may
     * return null to skip the text).
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
     * Returns [x, y, align, valign] for the given position + padding combination.
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
     * Resolves a path: if absolute and it exists, uses it; if relative,
     * tries `public_path()`.
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

    /** Build the Intervention image manager for the configured driver. */
    protected function imageManager(): ImageManager
    {
        return config('laracrate.image.driver') === 'gd'
            ? new ImageManager(new GdDriver())
            : new ImageManager(new ImagickDriver());
    }
}
