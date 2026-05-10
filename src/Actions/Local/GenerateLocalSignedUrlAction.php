<?php

namespace EduLazaro\Laracrate\Actions\Local;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * URL firmada para descarga de un File local. Apunta al ServeLocalController
 * del paquete, que verifica la firma y sirve el binario.
 */
class GenerateLocalSignedUrlAction extends Action
{
    public function handle(File $file, int $minutes = 5): string
    {
        return URL::temporarySignedRoute(
            'laracrate.local.serve',
            now()->addMinutes($minutes),
            ['file' => $file->slug]
        );
    }
}
