<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * Resolver for the extraction/embedding config of a File.
 *
 * The config supports two forms:
 *
 *   'extract' => true | false               -> global boolean
 *   'extract' => ['document', 'image']      -> array of FileType (document|image|audio|video)
 *   'extract' => ['video.visual']           -> suffixes for opt-in extras
 *
 * Same for `embed`.
 *
 * Apps can register custom resolvers (org overrides, case overrides) via
 * `ExtractionResolver::setOverrideResolver(callable)`: it receives the File
 * and returns an array that is merged over the base config.
 */
class ExtractionResolver
{
    /** @var callable|null */
    protected static $overrideResolver = null;

    /**
     * Register an override resolver. The callable receives the File and must
     * return an array like `['extract' => true|false|array, 'embed' => ...]`
     * or null. If it returns null, there is no override.
     */
    public static function setOverrideResolver(?callable $resolver): void
    {
        self::$overrideResolver = $resolver;
    }

    /**
     * Should text be extracted from this file?
     */
    public static function shouldExtract(File $file): bool
    {
        return self::isEnabledFor($file, 'extract');
    }

    /**
     * Should embeddings be generated for this file?
     */
    public static function shouldEmbed(File $file): bool
    {
        return self::isEnabledFor($file, 'embed');
    }

    /**
     * Is a specific extra (e.g. `video.visual`) enabled for this file?
     */
    public static function hasExtra(File $file, string $extra): bool
    {
        $effective = self::effectiveConfig($file);
        $extract = $effective['extract'] ?? null;
        if (! is_array($extract)) return false;
        return in_array($extra, $extract, true);
    }

    /**
     * Return the file's effective config: collection config + overrides.
     */
    public static function effectiveConfig(File $file): array
    {
        $base = CollectionConfig::resolve($file->collection, $file->fileable_type);

        if (self::$overrideResolver !== null) {
            $override = (self::$overrideResolver)($file);
            if (is_array($override) && ! empty($override)) {
                $base = array_replace($base, $override);
            }
        }

        return $base;
    }

    /** Resolve whether the given config key is enabled for the file's type. */
    protected static function isEnabledFor(File $file, string $key): bool
    {
        $effective = self::effectiveConfig($file);
        $value = $effective[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            $type = $file->type?->value ?? (string) $file->type;
            foreach ($value as $allowed) {
                // Exact match or prefix (`video` matches `video.visual` and vice versa).
                if ($allowed === $type) return true;
                if (str_starts_with($allowed, $type . '.')) return true;
            }
            return false;
        }

        // Backward compat: if the old key `extract_text` or `embed` bool is present.
        if ($key === 'extract' && isset($effective['extract_text'])) {
            return (bool) $effective['extract_text'];
        }

        return false;
    }
}
