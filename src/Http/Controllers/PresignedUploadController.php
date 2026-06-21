<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generic package endpoints for direct upload to R2/S3.
 *
 * Flow:
 *   1. Client POST /laracrate/uploads/presign  -> receives { url, key, ... }
 *   2. Client PUT directly to `url` with the binary.
 *   3. Client POST to its own app endpoint, which calls
 *      $model->addFile($collection, $key): the action moves the binary
 *      from temp/ to the canonical path.
 *   4. If the user cancels: DELETE /laracrate/uploads/{disk}/{key}.
 *
 * The routes are protected by the middleware configurable in
 * config('laracrate.uploads.middleware'). The app is responsible for
 * authorization (auth, throttle, validation of the allowed disk).
 */
class PresignedUploadController extends Controller
{
    /** Generate a presigned upload URL for a direct PUT to the bucket. */
    public function presign(Request $request)
    {
        $data = $request->validate([
            'disk'           => 'required|string',
            'mime'           => 'required|string|max:255',
            'file_name'      => 'nullable|string|max:255',
            'max_size_kb'    => 'nullable|integer|min:1',
            'minutes'        => 'nullable|integer|min:1|max:60',

            // Optional for a direct canonical key (no move afterwards).
            // If present, the file is uploaded DIRECTLY to the final path.
            // If absent, it goes to temp/ and is moved on confirm.
            'fileable_type'  => 'nullable|string',
            'fileable_id'    => 'nullable',
            'collection'     => 'nullable|string',
        ]);

        $disk     = $data['disk'];
        $mime     = $data['mime'];
        $fileName = $data['file_name'] ?? 'upload';
        $minutes  = $data['minutes']   ?? 15;
        $maxBytes = isset($data['max_size_kb']) ? $data['max_size_kb'] * 1024 : null;

        $allowedDisks = config('laracrate.uploads.allowed_disks', []);
        if (!empty($allowedDisks) && !in_array($disk, $allowedDisks, true)) {
            abort(403, "Disk '{$disk}' is not allowed for direct uploads.");
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fileName);
        $name     = Str::ulid() . '_' . $safeName;

        // Canonical key if the model + collection are known (preferred).
        // Otherwise, fall back to temp/ (typical case: a creation form without a model yet).
        if (!empty($data['fileable_type']) && !empty($data['fileable_id']) && !empty($data['collection'])) {
            $key = trim("{$data['fileable_type']}/{$data['fileable_id']}/{$data['collection']}/{$name}", '/');
        } else {
            $key = "temp/{$name}";
        }

        $presigned = app(StorageManager::class)->presignedUpload($disk, $key, $mime, $maxBytes, $minutes);

        return response()->json($presigned);
    }

    /** Delete a temporary upload object when the user cancels. */
    public function cancel(string $disk, string $encodedKey)
    {
        $key = base64_decode(urldecode($encodedKey));

        if (!str_starts_with($key, 'temp/')) {
            return response()->json(['error' => 'Only temp/ files can be cancelled.'], 422);
        }

        $allowedDisks = config('laracrate.uploads.allowed_disks', []);
        if (!empty($allowedDisks) && !in_array($disk, $allowedDisks, true)) {
            abort(403);
        }

        if (Storage::disk($disk)->exists($key)) {
            Storage::disk($disk)->delete($key);
            return response()->json(['deleted' => true]);
        }

        return response()->json(['deleted' => false, 'message' => 'Not found'], 404);
    }
}
