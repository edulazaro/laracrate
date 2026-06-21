<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Actions\Local\GenerateLocalSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Signed URL with a short TTL, cached server-side.
 * S3 uses Storage::disk()->temporaryUrl() (native presigned GET, no network).
 * Local falls back to a Laravel signed route that serves the binary.
 *
 * Errors (bad credentials, broken SDK, etc.) are swallowed and return null:
 * the caller (StorageManager + File::url) translates it to a placeholder.
 * This way a page with N files does not break entirely because one specific
 * disk is broken.
 */
class GenerateSignedUrlAction extends Action
{
    /** Return a cached, short-lived signed URL for the file. */
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
