<?php

namespace EduLazaro\Laracrate\Actions\Local;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * Signed URL for downloading a local File. Points to the package's
 * ServeLocalController, which verifies the signature and serves the binary.
 */
class GenerateLocalSignedUrlAction extends Action
{
    /** Build a temporary signed URL for the given local file. */
    public function handle(File $file, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'laracrate.local.serve',
            now()->addMinutes($minutes),
            ['file' => $file->slug]
        );
    }
}
