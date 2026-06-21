<?php

namespace EduLazaro\Laracrate\Http\Controllers\Local;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a File from a local disk after verifying the URL signature.
 */
class LocalServeController extends Controller
{
    /** Stream a file stored on a local disk. */
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
