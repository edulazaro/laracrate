<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * Direct public URL for files with access=public. Uses the Laravel driver:
 * for s3 with `url` configured it returns the bucket public URL; for local
 * it returns the Storage::url() URL. If the disk is local without a public
 * url, it falls back to a package route that serves the binary.
 */
class GeneratePublicUrlAction extends Action
{
    /** Return the public URL for the file, with a local-serve fallback. */
    public function handle(File $file): ?string
    {
        $key  = $file->key;
        $disk = app(\EduLazaro\Laracrate\Services\StorageManager::class)->diskFor($file);

        try {
            return $disk->url($key);
        } catch (\Throwable) {
            return route('laracrate.local.serve', ['file' => $file->slug]);
        }
    }
}
