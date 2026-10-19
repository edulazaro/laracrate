<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Actions\Local\GenerateLocalSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Signed URL that expires when the caller says, or after laracrate.urls.signed_ttl. Only
 * the default one is cached server-side: a page with many files signs each one once.
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
    /**
     * A signed URL for the file. S3/R2 refuse a signature valid for more than 7 days:
     * past that it fails, is logged and returns null.
     */
    public function handle(File $file, ?\DateTimeInterface $expires = null): ?string
    {
        if ($expires !== null) {
            return $this->sign($file, $expires);
        }

        $minutes  = (int) config('laracrate.urls.signed_ttl', 5);
        $cacheTtl = (int) config('laracrate.urls.signed_cache_ttl', 4) * 60;

        return Cache::remember(
            "laracrate:signed:{$file->id}:{$minutes}",
            $cacheTtl,
            fn () => $this->sign($file, now()->addMinutes($minutes))
        );
    }

    protected function sign(File $file, \DateTimeInterface $expires): ?string
    {
        try {
            if (config("filesystems.disks.{$file->disk}.driver") === 's3') {
                return app(\EduLazaro\Laracrate\Services\StorageManager::class)
                    ->diskFor($file)
                    ->temporaryUrl($file->key, $expires);
            }

            return GenerateLocalSignedUrlAction::create()->run(['file' => $file, 'expires' => $expires]);
        } catch (\Throwable $e) {
            Log::warning('laracrate: signed url failed', [
                'file_id' => $file->id,
                'disk'    => $file->disk,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
