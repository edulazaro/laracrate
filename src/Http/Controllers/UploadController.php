<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Http\Controllers\Concerns\HandlesUploadGrants;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laracrate\Uploads\PresignedUploads;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The upload endpoints behind the components and the upload tray. Every call
 * carries an UploadGrant token, which is the only source of the target.
 *
 *   sign     the file is checked against the collection and slot rules before any
 *            byte moves, then: a presigned PUT and a receipt ('direct'), or the
 *            address to post the file to ('server').
 *   confirm  'direct' only: the object is in temp/, the receipt proves this grant
 *            asked for that key, and the File is created (moved server side).
 *   receive  'server' only: the file comes through PHP and is created at once, so
 *            an `encrypt` collection can encrypt it.
 *   register the 'presign' mode of the components: the component already signed the
 *            object, and the server kept where it goes (PresignedUploads); the queue
 *            only sends its key.
 */
class UploadController extends Controller
{
    use HandlesUploadGrants;

    /** Hours a receipt stays valid: a slow upload of a big file still makes it. */
    private const RECEIPT_TTL = 6 * 3600;

    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant'     => 'required|string',
            'file_name' => 'required|string|max:255',
            'mime'      => 'nullable|string|max:255',
            'size'      => 'required|integer|min:0',
        ]);

        $grant = $this->grant($data['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        $mime = $data['mime'] ?: 'application/octet-stream';

        if ($refusal = $this->refusal($grant, $mime, (int) $data['size'], $data['file_name'], $request)) {
            return $refusal;
        }

        if ($grant->transport === 'server') {
            return response()->json([
                'transport' => 'server',
                'url'       => route('laracrate.uploads.receive'),
                'method'    => 'POST',
            ]);
        }

        $disk = $grant->disk();
        $key  = 'temp/' . Str::ulid() . '_' . $this->safeName($data['file_name']);

        $presigned = app(StorageManager::class)->presignedUpload($disk, $key, $mime, null, 60);

        return response()->json($presigned + [
            'transport' => 'direct',
            'receipt'   => Crypt::encryptString(json_encode([
                'g' => $grant->id,
                'k' => $key,
                'd' => $disk,
                'e' => now()->getTimestamp() + self::RECEIPT_TTL,
            ])),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant'         => 'required|string',
            'receipt'       => 'required|string',
            'original_name' => 'required|string|max:255',
            'mime'          => 'nullable|string|max:255',
        ]);

        $grant = $this->grant($data['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        try {
            $receipt = json_decode(Crypt::decryptString($data['receipt']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        if (($receipt['g'] ?? null) !== $grant->id || ($receipt['e'] ?? 0) < now()->getTimestamp()) {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        // No size: addFile() measures the object in the bucket.
        $upload = FileUpload::fromArray([
            'disk'          => $receipt['d'],
            'key'           => $receipt['k'],
            'mime_type'     => $data['mime'] ?: 'application/octet-stream',
            'original_name' => $data['original_name'],
        ]);

        return $this->store($grant, $upload, $data['original_name']);
    }

    /**
     * Creates the File of an object a component signed in 'presign' mode. The target
     * is the one kept when it was signed, under the component's grant, so it only works
     * for the user who picked the file, in that browser.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => 'required|string|max:1024']);

        $pending = PresignedUploads::find($data['key']);
        if ($pending === null) {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        $grant = $this->grant($pending['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        // No size: addFile() measures the object in the bucket.
        $response = $this->store($grant, FileUpload::fromArray([
            'disk'          => $pending['disk'],
            'key'           => $data['key'],
            'mime_type'     => $pending['mime'],
            'original_name' => $pending['name'],
        ]), $pending['name']);

        // Kept while it fails, so a register sent before the bytes arrived can be retried.
        if ($response->isSuccessful()) {
            PresignedUploads::forget($data['key']);
        }

        return $response;
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant' => 'required|string',
            'file'  => 'required|file',
        ]);

        $grant = $this->grant($data['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        /** @var UploadedFile $file */
        $file = $data['file'];
        $name = $file->getClientOriginalName();

        if ($grant->transport !== 'server') {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        // Checked again with what PHP sees of the file, not what the browser said.
        if ($refusal = $this->refusal($grant, $file->getMimeType() ?: 'application/octet-stream', (int) $file->getSize(), $name, $request)) {
            return $refusal;
        }

        return $this->store($grant, $file, $name);
    }

    /**
     * Removes the current file of a target that replaces it, as the components' delete
     * button does. Only a replacing grant can: it already deletes that file with its
     * next upload.
     */
    public function remove(Request $request): JsonResponse
    {
        $data = $request->validate(['grant' => 'required|string']);

        $grant = $this->grant($data['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        if (! $grant->replace) {
            return $this->fail(__('laracrate::uploader.target_refused'), 403);
        }

        $grant->fileable()->setFile($grant->collection, null);

        return response()->json(['deleted' => true]);
    }
}
