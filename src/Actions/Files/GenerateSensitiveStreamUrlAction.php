<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * Signed URL pointing to the package stream controller. Re-validates
 * permissions per request, audits and optionally binds to the viewer's
 * user_id.
 *
 * NEVER exposes the backend URL (R2/S3). For collections declared as
 * `sensitive` in config.
 */
class GenerateSensitiveStreamUrlAction extends Action
{
    /** Build a temporary signed URL to the stream controller. */
    public function handle(File $file): string
    {
        $minutes = (int) config('laracrate.urls.route_signed_ttl', 15);
        $bind    = (bool) config('laracrate.urls.bind_to_user', true);

        $params = ['file' => $file->slug];

        if ($bind && auth()->check()) {
            $params['u'] = auth()->id();
        }

        return URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes($minutes),
            $params
        );
    }
}
