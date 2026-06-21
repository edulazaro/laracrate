<?php

namespace EduLazaro\Laracrate\Actions\Local;

use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * Local equivalent of a "presigned PUT": a signed Laravel URL pointing to
 * the package's upload endpoint. The client POSTs the binary and the
 * controller stores it on disk.
 */
class GenerateLocalUploadUrlAction extends Action
{
    /** Build a temporary signed upload URL plus its descriptor. */
    public function handle(string $disk, string $key, string $mime, ?int $maxSize = null, int $minutes = 15): array
    {
        $url = URL::temporarySignedRoute(
            'laracrate.local.upload',
            now()->addMinutes($minutes),
            [
                'disk' => $disk,
                'key'  => base64_encode($key),
                'mime' => $mime,
            ]
        );

        return [
            'url'        => $url,
            'method'     => 'POST',
            'headers'    => [],
            'key'        => $key,
            'disk'       => $disk,
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ];
    }
}
