<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Actions\Local\GenerateLocalSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * URL firmada con TTL corto, cacheada server-side.
 * S3 usa Storage::disk()->temporaryUrl() (presigned GET nativo).
 * Local cae a una ruta firmada de Laravel que sirve el binario.
 */
class GenerateSignedUrlAction extends Action
{
    public function handle(File $file): ?string
    {
        $minutes  = (int) config('laracrate.urls.signed_ttl', 5);
        $cacheTtl = (int) config('laracrate.urls.signed_cache_ttl', 4) * 60;
        $key      = $file->key;
        $driver   = config("filesystems.disks.{$file->disk}.driver");

        return Cache::remember(
            "laracrate:signed:{$file->id}:{$minutes}",
            $cacheTtl,
            function () use ($file, $key, $driver, $minutes) {
                if ($driver === 's3') {
                    return app(\EduLazaro\Laracrate\Services\StorageManager::class)
                        ->diskFor($file)
                        ->temporaryUrl($key, now()->addMinutes($minutes));
                }
                return GenerateLocalSignedUrlAction::create()->run(['file' => $file, 'minutes' => $minutes]);
            }
        );
    }
}
