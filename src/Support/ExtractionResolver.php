<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * Resolver del config de extracción/embedding para un File.
 *
 * El config soporta dos formas:
 *
 *   'extract' => true | false               → boolean global
 *   'extract' => ['document', 'image']      → array de FileType (document|image|audio|video)
 *   'extract' => ['video.visual']           → sufijos para extras opt-in
 *
 * Igual para `embed`.
 *
 * Las apps pueden registrar resolvers personalizados (org overrides,
 * case overrides) via `ExtractionResolver::setOverrideResolver(callable)`
 * — recibe el File y devuelve un array que se merge sobre el config base.
 */
class ExtractionResolver
{
    /** @var callable|null */
    protected static $overrideResolver = null;

    /**
     * Registra un resolver de overrides. La callable recibe el File y debe
     * devolver un array como `['extract' => true|false|array, 'embed' => ...]`
     * o null. Si devuelve null, no hay override.
     */
    public static function setOverrideResolver(?callable $resolver): void
    {
        self::$overrideResolver = $resolver;
    }

    /**
     * ¿Se debe extraer texto de este file?
     */
    public static function shouldExtract(File $file): bool
    {
        return self::isEnabledFor($file, 'extract');
    }

    /**
     * ¿Se deben generar embeddings de este file?
     */
    public static function shouldEmbed(File $file): bool
    {
        return self::isEnabledFor($file, 'embed');
    }

    /**
     * ¿Está activado un extra concreto (ej. `video.visual`) para este file?
     */
    public static function hasExtra(File $file, string $extra): bool
    {
        $effective = self::effectiveConfig($file);
        $extract = $effective['extract'] ?? null;
        if (! is_array($extract)) return false;
        return in_array($extra, $extract, true);
    }

    /**
     * Devuelve el config efectivo del file: collection config + overrides.
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
                // Match exacto o prefix (`video` matches `video.visual` y viceversa).
                if ($allowed === $type) return true;
                if (str_starts_with($allowed, $type . '.')) return true;
            }
            return false;
        }

        // Backward compat: si está la clave vieja `extract_text` o `embed` bool.
        if ($key === 'extract' && isset($effective['extract_text'])) {
            return (bool) $effective['extract_text'];
        }

        return false;
    }
}
