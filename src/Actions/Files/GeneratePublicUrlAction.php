<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Facades\Storage;

/**
 * URL pública directa para archivos con access=public. Usa el driver de
 * Laravel — para s3 con `url` configurado devuelve la pública del bucket;
 * para local devuelve la URL del Storage::url(). Si el disk es local sin
 * url pública, fallback a una ruta del paquete que sirve el binario.
 */
class GeneratePublicUrlAction extends Action
{
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
