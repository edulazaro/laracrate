<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\ActiveContent;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generic package endpoints for direct upload to R2/S3.
 *
 * Flow:
 *   1. Client POST /laracrate/uploads/presign  -> receives { url, key, ... }, always under temp/
 *   2. Client PUT directly to `url` with the binary.
 *   3. Client POST to its own app endpoint, which calls
 *      $model->addFile($collection, $key): the action moves the binary
 *      from temp/ to the canonical path.
 *   4. If the user cancels: DELETE /laracrate/uploads/{disk}/{key}. Only the browser
 *      session the key was signed for can cancel it, as with an upload grant.
 *
 * The routes are protected by the middleware configurable in
 * config('laracrate.uploads.middleware'). The app is responsible for
 * authorization (auth, throttle).
 *
 * Only the disks of `uploads.allowed_disks`, or, when that list is empty, the disks
 * the configured collections use: any other disk (`public`, `local`…) would let any
 * signed in user write there. Runnable content (HTML, SVG, scripts) is refused.
 * The Laracrate components no longer use this endpoint but the grant based ones in
 * UploadController; it stays for apps that call it directly.
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
        ]);

        $disk     = $data['disk'];
        $mime     = $data['mime'];
        $fileName = $data['file_name'] ?? 'upload';
        $minutes  = $data['minutes']   ?? 15;
        $maxBytes = isset($data['max_size_kb']) ? $data['max_size_kb'] * 1024 : null;

        if (!in_array($disk, $this->allowedDisks(), true)) {
            abort(403, "Disk '{$disk}' is not allowed for direct uploads.");
        }

        if (ActiveContent::is($mime, $fileName)) {
            abort(422, 'This type of file cannot be uploaded.');
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fileName);

        // Always temp/: addFile() moves it to the model's path once the app registers it.
        // A model path taken from the request let anyone write into another model's folder.
        $key = 'temp/' . Str::ulid() . '_' . $safeName;

        $presigned = app(StorageManager::class)->presignedUpload($disk, $key, $mime, $maxBytes, $minutes);

        // Whose key it is, for cancel(): the browser session, or the user without one.
        Cache::put(self::signedKey($disk, $key), [
            'session' => UploadGrant::currentSession(),
            'user'    => UploadGrant::currentUser(),
        ], now()->addMinutes($minutes + 60));

        return response()->json($presigned);
    }

    /** Delete a temporary upload object when the user cancels. */
    public function cancel(string $disk, string $encodedKey)
    {
        $key = (string) base64_decode(urldecode($encodedKey));

        // Only a key as presign makes them: temp/ and one plain name, no other segment.
        if (!preg_match('#^temp/[A-Za-z0-9_\-.]+$#', $key)) {
            return response()->json(['error' => 'Only temp/ files can be cancelled.'], 422);
        }

        if (!in_array($disk, $this->allowedDisks(), true)) {
            abort(403);
        }

        $signed = Cache::get(self::signedKey($disk, $key));
        if (!$signed || !$this->signedForThisBrowser($signed)) {
            abort(403);
        }

        Cache::forget(self::signedKey($disk, $key));

        if (Storage::disk($disk)->exists($key)) {
            Storage::disk($disk)->delete($key);
            return response()->json(['deleted' => true]);
        }

        return response()->json(['deleted' => false, 'message' => 'Not found'], 404);
    }

    /** The browser session it was signed in, or the same user when there was no session. */
    protected function signedForThisBrowser(array $signed): bool
    {
        if ($signed['session'] !== null) {
            return $signed['session'] === UploadGrant::currentSession();
        }

        return $signed['user'] !== null && $signed['user'] === UploadGrant::currentUser();
    }

    protected static function signedKey(string $disk, string $key): string
    {
        return 'laracrate:presign:' . hash('sha256', $disk . '|' . $key);
    }

    /** `uploads.allowed_disks`, or the disks of the configured collections when it is empty. */
    protected function allowedDisks(): array
    {
        $configured = config('laracrate.uploads.allowed_disks', []);
        if (!empty($configured)) {
            return $configured;
        }

        return collect(config('laracrate.collections', []))
            ->pluck('disk')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
