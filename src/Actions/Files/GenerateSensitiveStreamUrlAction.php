<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\URL;

/**
 * URL firmada hacia el stream controller del paquete. Re-valida permisos
 * por request, audita y opcionalmente liga al user_id del visor.
 *
 * NUNCA expone la URL del backend (R2/S3). Para colecciones declaradas
 * como `sensitive` en config.
 */
class GenerateSensitiveStreamUrlAction extends Action
{
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
