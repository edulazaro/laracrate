<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use InvalidArgumentException;

/**
 * Resolver for the extraction/embedding config of a File.
 *
 * `extract`, `embed` and, for videos, `visual` resolve to one of three modes:
 *
 *   'always'    (or true)  -> runs when the file is uploaded
 *   'on_demand'            -> runs only once someone requests it, see File::requestExtraction()
 *   'never'     (or false) -> never runs
 *
 * They can be declared for the whole collection or per type, and the type wins:
 *
 *   'extract' => 'always',
 *   'embed'   => true,
 *   'types'   => [
 *       'audio' => ['extract' => 'on_demand'],
 *       'video' => ['extract' => 'on_demand', 'visual' => 'never'],
 *   ],
 *
 * The collection-level value also accepts a list of FileTypes, which means
 * 'always' for those types and 'never' for the rest:
 *
 *   'extract' => ['document', 'image']
 *   'extract' => ['document', 'video.visual']   -> videos with frame description
 *
 * `extract` decides whether the text pipeline runs and `embed` whether its text
 * is embedded. When `extract` is not declared at all, the pipeline follows `embed`.
 *
 * Apps can register an override resolver (org overrides, case overrides) via
 * `ExtractionResolver::setOverrideResolver(callable)`: it receives the File and
 * returns an array in the same shape as the collection config. Whatever the
 * override declares wins over the collection config.
 */
class ExtractionResolver
{
    public const ALWAYS = 'always';
    public const ON_DEMAND = 'on_demand';
    public const NEVER = 'never';

    /** Metadata key set by File::requestExtraction(). */
    public const REQUESTED_AT = 'extraction_requested_at';

    /** @var callable|null */
    protected static $overrideResolver = null;

    /**
     * Register an override resolver. The callable receives the File and must
     * return an array like `['extract' => ..., 'embed' => ..., 'types' => [...]]`
     * or null. If it returns null, there is no override.
     */
    public static function setOverrideResolver(?callable $resolver): void
    {
        self::$overrideResolver = $resolver;
    }

    /**
     * Should text be extracted from this file now?
     */
    public static function shouldExtract(File $file): bool
    {
        return self::isActive($file, self::extractMode($file));
    }

    /**
     * Should embeddings be generated for this file now? Never without text.
     */
    public static function shouldEmbed(File $file): bool
    {
        return self::shouldExtract($file) && self::isActive($file, self::embedMode($file));
    }

    /**
     * Should the frames of this video be described now?
     */
    public static function shouldDescribeVisual(File $file): bool
    {
        if (self::typeOf($file) !== FileType::VIDEO->value) {
            return false;
        }

        return self::shouldExtract($file) && self::isActive($file, self::mode($file, 'visual') ?? self::NEVER);
    }

    /**
     * Is a specific extra (e.g. `video.visual`) enabled for this file?
     */
    public static function hasExtra(File $file, string $extra): bool
    {
        if ($extra === 'video.visual') {
            return self::shouldDescribeVisual($file);
        }

        $extract = self::effectiveConfig($file)['extract'] ?? null;

        return is_array($extract) && in_array($extra, $extract, true);
    }

    /**
     * Resolved mode of the text pipeline for the file's type.
     */
    public static function extractMode(File $file): string
    {
        return self::mode($file, 'extract') ?? self::mode($file, 'embed') ?? self::NEVER;
    }

    /**
     * Resolved mode of the embeddings for the file's type.
     */
    public static function embedMode(File $file): string
    {
        return self::mode($file, 'embed') ?? self::NEVER;
    }

    /**
     * Is anything about this file waiting for a request?
     */
    public static function isOnDemand(File $file): bool
    {
        $extract = self::extractMode($file);

        if ($extract === self::ON_DEMAND) {
            return true;
        }

        if ($extract === self::NEVER) {
            return false;
        }

        if (self::embedMode($file) === self::ON_DEMAND) {
            return true;
        }

        return self::typeOf($file) === FileType::VIDEO->value
            && self::mode($file, 'visual') === self::ON_DEMAND;
    }

    /**
     * Has someone requested the on-demand processing of this file?
     */
    public static function isRequested(File $file): bool
    {
        return ! empty(((array) ($file->metadata ?? []))[self::REQUESTED_AT] ?? null);
    }

    /**
     * Can the on-demand processing of this file be requested? It can until it
     * has been requested, and again if that processing failed.
     */
    public static function canRequest(File $file): bool
    {
        if ($file->isVariant() || ! config('laracrate.embeddings.enabled', false)) {
            return false;
        }

        if (! self::isOnDemand($file)) {
            return false;
        }

        return ! self::isRequested($file) || $file->processing_status === ProcessingStatus::FAILED;
    }

    /**
     * Return the file's effective config: collection config + overrides. The
     * per-type blocks are merged type by type, so an override for one type
     * keeps the rest of that type's config.
     */
    public static function effectiveConfig(File $file): array
    {
        $base = self::baseConfig($file);
        $override = self::overrideConfig($file);

        if (empty($override)) {
            return $base;
        }

        $merged = array_replace($base, $override);

        if (is_array($base['types'] ?? null) && is_array($override['types'] ?? null)) {
            $merged['types'] = $base['types'];

            foreach ($override['types'] as $type => $config) {
                $merged['types'][$type] = is_array($config) && is_array($base['types'][$type] ?? null)
                    ? array_replace($base['types'][$type], $config)
                    : $config;
            }
        }

        return $merged;
    }

    /** Mode declared for the key, override first, or null when nothing declares it. */
    protected static function mode(File $file, string $key): ?string
    {
        $type = self::typeOf($file);

        return self::modeIn(self::overrideConfig($file), $type, $key)
            ?? self::modeIn(self::baseConfig($file), $type, $key);
    }

    /** Mode declared for the key in one config layer: the type block wins over the collection. */
    protected static function modeIn(array $config, string $type, string $key): ?string
    {
        $typeConfig = $config['types'][$type] ?? null;

        if (is_array($typeConfig) && array_key_exists($key, $typeConfig)) {
            return self::normalize($typeConfig[$key], $key);
        }

        // Before `types.video.visual` existed, frames were an extra in the extract list.
        if ($key === 'visual') {
            $extract = $config['extract'] ?? null;

            if (! is_array($extract)) {
                return null;
            }

            return in_array('video.visual', $extract, true) ? self::ALWAYS : self::NEVER;
        }

        if (! array_key_exists($key, $config)) {
            // Legacy alias of `extract`.
            if ($key === 'extract' && array_key_exists('extract_text', $config)) {
                return self::normalize($config['extract_text'], 'extract_text');
            }

            return null;
        }

        $value = $config[$key];

        if (is_array($value)) {
            foreach ($value as $allowed) {
                // Exact match or prefix (`video` matches `video.visual`).
                if ($allowed === $type || str_starts_with((string) $allowed, $type . '.')) {
                    return self::ALWAYS;
                }
            }

            return self::NEVER;
        }

        return self::normalize($value, $key);
    }

    /** Turn a declared value into one of the three modes. */
    protected static function normalize(mixed $value, string $key): string
    {
        return match (true) {
            $value === true, $value === self::ALWAYS => self::ALWAYS,
            $value === false, $value === null, $value === self::NEVER => self::NEVER,
            $value === self::ON_DEMAND => self::ON_DEMAND,
            default => throw new InvalidArgumentException(sprintf(
                "Laracrate: invalid value for '%s'. Use true, false, 'always', 'on_demand' or 'never'.",
                $key,
            )),
        };
    }

    /** Whether a mode lets the step run for this file right now. */
    protected static function isActive(File $file, string $mode): bool
    {
        return match ($mode) {
            self::ALWAYS => true,
            self::ON_DEMAND => self::isRequested($file),
            default => false,
        };
    }

    /** Collection config for the file, with its per-model block applied. */
    protected static function baseConfig(File $file): array
    {
        return CollectionConfig::resolve($file->collection, $file->fileable_type);
    }

    /** What the app's override resolver returns for the file, or an empty array. */
    protected static function overrideConfig(File $file): array
    {
        if (self::$overrideResolver === null) {
            return [];
        }

        $override = (self::$overrideResolver)($file);

        return is_array($override) ? $override : [];
    }

    /** The file's type as a string (`document`, `image`, `audio`, `video`). */
    protected static function typeOf(File $file): string
    {
        return $file->type?->value ?? (string) $file->type;
    }
}
