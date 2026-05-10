<?php

namespace EduLazaro\Laracrate\Actions\Local;

use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * Equivalente al "presigned PUT" para Local: una URL Laravel firmada que
 * apunta al endpoint de upload del paquete. El cliente hace POST con el
 * binario y el controller lo guarda en disco.
 */
class GenerateLocalUploadUrlAction extends Action
{
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
