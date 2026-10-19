<?php

namespace EduLazaro\Laracrate\Pipeline\Steps\Image;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileMode;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\Variants;

/**
 * Marks a 360° photo with mode `sphere` in a collection whose image type takes that mode
 * (`'image' => ['modes' => ['default', 'sphere']]`); one that does not keeps it a normal
 * image. Only what its XMP declares counts (GPano:ProjectionType="equirectangular", as
 * 360 cameras and Google Photo Sphere write it): a wide photo is not a 360 one.
 */
class DetectSphereStep implements FileActionInterface
{
    /** Determines whether this step applies to the given file. */
    public function supports(File $file): bool
    {
        return $file->type === FileType::IMAGE
            && ($file->mode ?? FileMode::DEFAULT) === FileMode::DEFAULT
            && in_array(FileMode::SPHERE->value, Variants::typeConfig($file)['modes'] ?? [], true);
    }

    /** Returns the priority that orders this step within the pipeline. */
    public function priority(): int
    {
        return 5;
    }

    /** Marks the file when its XMP declares an equirectangular projection. */
    public function handle(File $file): void
    {
        $binary = app(StorageManager::class)->readBinary($file);

        if (preg_match('/GPano:ProjectionType\s*(?:=\s*["\']|>)\s*equirectangular/i', $binary)) {
            $file->forceFill(['mode' => FileMode::SPHERE])->saveQuietly();
        }
    }
}
