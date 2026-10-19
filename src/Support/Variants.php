<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use InvalidArgumentException;

/**
 * The `variants` of a collection type, and the variant its `preview` names.
 *
 *   'video' => [
 *       'variants' => ['web', 'portada' => ['type' => 'image', 'frame_at' => '00:00:03']],
 *       'preview'  => 'portada',
 *   ],
 */
final class Variants
{
    /** name => spec. A bare name ('web') is a variant with no options. */
    public static function normalize(array $variants): array
    {
        $out = [];

        foreach ($variants as $name => $spec) {
            if (is_int($name)) {
                $out[(string) $spec] = [];
            } else {
                $out[$name] = (array) $spec;
            }
        }

        return $out;
    }

    /**
     * The type's config for the file's collection (the file's own type without $type),
     * refusing the settings 2.0 replaced.
     */
    public static function typeConfig(File $file, ?string $type = null): array
    {
        $type ??= $file->type->value;
        $config = app(StorageManager::class)->getTypeConfig($file->collection, $type, $file->fileable_type);

        // A name, or false/null for none; an array is the 1.x preview block.
        if (is_array($config['preview'] ?? null)) {
            throw new InvalidArgumentException("Laracrate 2.0: `preview` of the {$type} type of `{$file->collection}` names a variant now. Move the frame or page into `variants` (as `'type' => 'image'`) and set `preview` to its name.");
        }

        if (($config['transcode'] ?? null) === 'variant') {
            throw new InvalidArgumentException("Laracrate 2.0: `'transcode' => 'variant'` of `{$file->collection}` is gone. Declare the copy in `variants`, e.g. `'variants' => ['playback']`.");
        }

        return $config;
    }

    /** The variants of the type for the file, by name. */
    public static function of(File $file, string $type): array
    {
        return self::normalize(self::typeConfig($file, $type)['variants'] ?? []);
    }
}
