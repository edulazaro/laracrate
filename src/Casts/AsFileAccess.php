<?php

namespace EduLazaro\Laracrate\Casts;

use EduLazaro\Laracrate\Enums\FileAccess;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The `access` column as a FileAccess. Rows written by 1.x ('signed', 'stream') read
 * as private until the 2.0 migration rewrites them.
 */
class AsFileAccess implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FileAccess
    {
        return $value === null ? null : FileAccess::fromConfig((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return ($value instanceof FileAccess ? $value : FileAccess::fromConfig((string) $value))->value;
    }
}
