<?php

namespace EduLazaro\Laracrate\Http\Controllers\Local;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\RangeResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a File from a local disk after verifying the URL signature. Honours
 * `Range`, so audio and video can seek like they do from R2.
 */
class LocalServeController extends Controller
{
    /** Stream a file stored on a local disk. */
    public function serve(Request $request, File $file): StreamedResponse
    {
        $key  = $file->key;
        $disk = app(StorageManager::class)->diskFor($file);

        abort_unless($disk->exists($key), 404);

        return RangeResponse::make($request, $disk, $key, [
            'Content-Type'        => $file->mime_type,
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($file->original_name ?: $file->name)),
        ]);
    }
}
