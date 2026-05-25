<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Actions\Local\GenerateLocalSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * URL firmada con TTL corto, cacheada server-side.
 * S3 usa Storage::disk()->temporaryUrl() (presigned GET nativo, no network).
 * Local cae a una ruta firmada de Laravel que sirve el binario.
 *
 * Errores (credenciales mal, SDK roto, etc.) se tragan y devuelven null:
 * el caller (StorageManager + File::url) lo traduce a placeholder. Así una
 * página con N archivos no peta entera porque un disk concreto esté roto.
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
                try {
                    if ($driver === 's3') {
                        return app(\EduLazaro\Laracrate\Services\StorageManager::class)
                            ->diskFor($file)
                            ->temporaryUrl($key, now()->addMinutes($minutes));
                    }
                    return GenerateLocalSignedUrlAction::create()->run(['file' => $file, 'minutes' => $minutes]);
                } catch (\Throwable $e) {
                    Log::warning('laracrate: signed url failed', [
                        'file_id' => $file->id,
                        'disk'    => $file->disk,
                        'error'   => $e->getMessage(),
                    ]);
                    return null;
                }
            }
        );
    }
}
