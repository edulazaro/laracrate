<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Actions\Multipart\AbortMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\CompleteMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\GeneratePartUrlsAction;
use EduLazaro\Laracrate\Actions\Multipart\InitiateMultipartUploadAction;
use EduLazaro\Laracrate\Http\Controllers\Concerns\HandlesUploadGrants;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Multipart upload to S3/R2 for large files, under an UploadGrant like the single PUT
 * of UploadController: the grant is the only source of the target, so the disk, the
 * key and the collection rules come from it and never from the request.
 *
 * Client flow:
 *   1. POST /laracrate/multipart/init           { grant, file_name, mime, size }
 *      -> { id, part_size, total_parts, parts: [{part_number, url}] }
 *   2. PUT each part to its `url` (in parallel). Keep the `ETag` of each response.
 *   3. POST /laracrate/multipart/{id}/parts     { grant, part_numbers } -> fresh URLs (optional).
 *   4. POST /laracrate/multipart/{id}/complete  { grant, parts: [{part_number, etag}] }
 *      -> the File, as confirm returns it.
 *   5. (cancellation) DELETE /laracrate/multipart/{id}  { grant }.
 *
 * Steps 3 to 5 only work with the grant that started the session, which only the user
 * it was issued to can use. Sessions an application starts on its own with the
 * multipart actions carry no grant and cannot be reached from here.
 *
 * The object is written straight to its final key, not to temp/: moving it afterwards
 * is a single CopyObject, which S3 refuses past 5 GB.
 */
class MultipartUploadController extends Controller
{
    use HandlesUploadGrants;

    /** Checks the file against the collection rules and opens the session. */
    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grant'     => 'required|string',
            'file_name' => 'required|string|max:255',
            'mime'      => 'nullable|string|max:255',
            'size'      => 'required|integer|min:1',
            'part_size' => 'nullable|integer|min:5242880', // 5 MB, the S3 minimum
        ]);

        $grant = $this->grant($data['grant']);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        // An encrypted collection goes through PHP, the only place it can be encrypted.
        if ($grant->transport !== 'direct') {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        $mime = $data['mime'] ?: 'application/octet-stream';
        if ($refusal = $this->refusal($grant, $mime, (int) $data['size'], $data['file_name'], $request)) {
            return $refusal;
        }

        $fileable = $grant->fileable();
        $key = $fileable->getMorphClass() . '/' . $fileable->getKey() . '/' . $grant->collection
            . '/' . Str::ulid() . '_' . $this->safeName($data['file_name']);

        try {
            $upload = InitiateMultipartUploadAction::create()->run([
                'disk'         => $grant->disk(),
                'key'          => $key,
                'mime'         => $mime,
                'expectedSize' => (int) $data['size'],
                'partSize'     => $data['part_size'] ?? null,
                'creator'      => $request->user(),
                'fileable'     => $fileable,
                'collection'   => $grant->collection,
                'metadata'     => ['grant' => $grant->id, 'name' => $data['file_name']],
            ]);
        } catch (RuntimeException $e) {
            Log::warning('Laracrate multipart refused', ['collection' => $grant->collection, 'error' => $e->getMessage()]);

            return $this->fail(__('laracrate::uploader.rejected.generic'));
        }

        return response()->json([
            'id'          => $upload->id,
            'part_size'   => $upload->part_size,
            'total_parts' => $upload->total_parts,
            'expires_at'  => $upload->expires_at->toIso8601String(),
            'parts'       => GeneratePartUrlsAction::create()->run(['upload' => $upload]),
        ]);
    }

    /** Re-issues the presigned URLs of the given parts. */
    public function reissueParts(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $data = $request->validate([
            'grant'          => 'required|string',
            'part_numbers'   => 'required|array|min:1',
            'part_numbers.*' => 'integer|min:1',
        ]);

        $grant = $this->sessionGrant($data['grant'], $multipart);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        try {
            $parts = GeneratePartUrlsAction::create()->run([
                'upload'      => $multipart,
                'partNumbers' => array_values(array_unique(array_map('intval', $data['part_numbers']))),
            ]);
        } catch (RuntimeException) {
            return $this->fail(__('laracrate::uploader.rejected.generic'));
        }

        return response()->json(['parts' => $parts]);
    }

    /**
     * Assembles the object and creates the File. addFile() measures it in the bucket,
     * since no part URL binds a length. A refused object is deleted.
     */
    public function complete(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $data = $request->validate([
            'grant'               => 'required|string',
            'parts'               => 'required|array|min:1',
            'parts.*.part_number' => 'required|integer|min:1',
            'parts.*.etag'        => 'required|string',
        ]);

        $grant = $this->sessionGrant($data['grant'], $multipart);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        try {
            CompleteMultipartUploadAction::create()->run(['upload' => $multipart, 'parts' => $data['parts']]);
        } catch (\Throwable) {
            return $this->fail(__('laracrate::uploader.not_received'));
        }

        $name = $multipart->metadata['name'] ?? basename($multipart->key);

        $response = $this->store($grant, FileUpload::fromArray([
            'disk'          => $multipart->disk,
            'key'           => $multipart->key,
            'mime_type'     => $multipart->mime_type ?: 'application/octet-stream',
            'original_name' => $name,
        ]), $name);

        // The key is this session's own, never an existing file's, so it can go.
        if (! $response->isSuccessful()) {
            rescue(fn () => Storage::disk($multipart->disk)->delete($multipart->key), report: false);

            return $response;
        }

        $multipart->forceFill(['file_id' => $response->getData(true)['id']])->save();

        return $response;
    }

    /** Aborts the session, so the parts already uploaded stop taking space. */
    public function abort(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $data = $request->validate(['grant' => 'required|string']);

        $grant = $this->sessionGrant($data['grant'], $multipart);
        if ($grant instanceof JsonResponse) {
            return $grant;
        }

        $upload = AbortMultipartUploadAction::create()->run(['upload' => $multipart]);

        return response()->json(['status' => $upload->status->value]);
    }

    /** The grant, when it is the one that started this session. */
    protected function sessionGrant(string $token, MultipartUpload $multipart): UploadGrant|JsonResponse
    {
        $grant = $this->grant($token);

        if ($grant instanceof UploadGrant && ($multipart->metadata['grant'] ?? null) !== $grant->id) {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }

        return $grant;
    }
}
