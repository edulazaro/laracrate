<?php

namespace EduLazaro\Laracrate\Http\Controllers\Local;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve un File de un disk local tras verificar la firma de URL.
 */
class LocalServeController extends Controller
{
    public function serve(File $file): StreamedResponse
    {
        $key  = $file->key;
        $disk = app(\EduLazaro\Laracrate\Services\StorageManager::class)->diskFor($file);

        abort_unless($disk->exists($key), 404);

        return $disk->response($key, $file->original_name, [
            'Content-Type' => $file->mime_type,
        ]);
    }
}
