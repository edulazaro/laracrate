<?php

namespace EduLazaro\Laracrate\Http\Controllers\Local;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

/**
 * Recibe el binario del cliente para el "presigned" del disk local.
 * La firma de la URL ya la valida el middleware `signed`.
 */
class LocalUploadController extends Controller
{
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
