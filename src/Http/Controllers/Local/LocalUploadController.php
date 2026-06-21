<?php

namespace EduLazaro\Laracrate\Http\Controllers\Local;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Receives the client binary for the local disk "presigned" upload.
 * The URL signature is already validated by the `signed` middleware.
 */
class LocalUploadController extends Controller
{
    /** Store the request body at the given key on the local disk. */
    public function store(Request $request)
    {
        $disk = $request->query('disk');
        $key  = base64_decode((string) $request->query('key'));

        if (!$disk || !$key) {
            abort(400, 'Missing disk or key');
        }

        $body = $request->getContent();
        if (!$body) {
            abort(422, 'Empty body');
        }

        Storage::disk($disk)->put($key, $body);

        return response()->json(['ok' => true, 'key' => $key, 'disk' => $disk]);
    }
}
