<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Actions\Multipart\AbortMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\CompleteMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\GeneratePartUrlsAction;
use EduLazaro\Laracrate\Actions\Multipart\InitiateMultipartUploadAction;
use EduLazaro\Laracrate\Models\MultipartUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * HTTP endpoints for direct multipart upload to S3/R2.
 *
 * Client flow:
 *   1. POST /laracrate/multipart/init       -> { upload_id, total_parts, parts: [{part_number, url}] }
 *   2. PUT each part directly to its `url` (in parallel). Capture the `ETag` from each response.
 *   3. POST /laracrate/multipart/{id}/parts -> re-issues URLs if any expired (optional).
 *   4. POST /laracrate/multipart/{id}/complete with the list [{part_number, etag}].
 *   5. (cancellation) DELETE /laracrate/multipart/{id}.
 *
 * Authorization: middleware in config('laracrate.multipart.middleware').
 */
class MultipartUploadController extends Controller
{
    /** Initiate a multipart upload session and return the part URLs. */
    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'disk'           => 'required|string',
            'mime'           => 'nullable|string|max:255',
            'file_name'      => 'nullable|string|max:255',
            'expected_size'  => 'required|integer|min:1',
            'part_size'      => 'nullable|integer|min:5242880', // 5 MB
            'expire_minutes' => 'nullable|integer|min:1|max:1440',

            // Optional for a direct canonical key.
            'fileable_type' => 'nullable|string',
            'fileable_id'   => 'nullable',
            'collection'    => 'nullable|string',
        ]);

        $disk = $data['disk'];

        $allowedDisks = config('laracrate.uploads.allowed_disks', []);
        if (!empty($allowedDisks) && !in_array($disk, $allowedDisks, true)) {
            abort(403, "Disk '{$disk}' is not allowed for direct uploads.");
        }

        $fileName = $data['file_name'] ?? 'upload';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fileName);
        $name     = Str::ulid() . '_' . $safeName;

        if (!empty($data['fileable_type']) && !empty($data['fileable_id']) && !empty($data['collection'])) {
            $key = trim("{$data['fileable_type']}/{$data['fileable_id']}/{$data['collection']}/{$name}", '/');
        } else {
            $key = "temp/{$name}";
        }

        $upload = InitiateMultipartUploadAction::create()->run([
            'disk'          => $disk,
            'key'           => $key,
            'mime'          => $data['mime'] ?? null,
            'expectedSize'  => $data['expected_size'],
            'partSize'      => $data['part_size'] ?? null,
            'expireMinutes' => $data['expire_minutes'] ?? null,
            'creator'       => $request->user(),
            'fileableType'  => $data['fileable_type'] ?? null,
            'fileableId'    => isset($data['fileable_id']) ? (string) $data['fileable_id'] : null,
            'collection'    => $data['collection'] ?? null,
        ]);

        $parts = GeneratePartUrlsAction::create()->run(['upload' => $upload]);

        return response()->json([
            'upload_id'   => $upload->upload_id,
            'id'          => $upload->id,
            'disk'        => $upload->disk,
            'key'         => $upload->key,
            'part_size'   => $upload->part_size,
            'total_parts' => $upload->total_parts,
            'expires_at'  => $upload->expires_at->toIso8601String(),
            'parts'       => $parts,
        ]);
    }

    /** Re-issue presigned URLs for the requested part numbers. */
    public function reissueParts(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $this->authorizeOwner($request, $multipart);

        $data = $request->validate([
            'part_numbers'   => 'required|array|min:1',
            'part_numbers.*' => 'integer|min:1',
            'ttl_minutes'    => 'nullable|integer|min:1|max:1440',
        ]);

        $parts = GeneratePartUrlsAction::create()->run([
            'upload'      => $multipart,
            'partNumbers' => array_values(array_unique(array_map('intval', $data['part_numbers']))),
            'ttlMinutes'  => $data['ttl_minutes'] ?? null,
        ]);

        return response()->json(['parts' => $parts]);
    }

    /** Complete the multipart upload from the submitted part ETags. */
    public function complete(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $this->authorizeOwner($request, $multipart);

        $data = $request->validate([
            'parts'                 => 'required|array|min:1',
            'parts.*.part_number'   => 'required|integer|min:1',
            'parts.*.etag'          => 'required|string',
        ]);

        $upload = CompleteMultipartUploadAction::create()->run([
            'upload' => $multipart,
            'parts'  => $data['parts'],
        ]);

        return response()->json([
            'upload_id'    => $upload->upload_id,
            'status'       => $upload->status->value,
            'key'          => $upload->key,
            'disk'         => $upload->disk,
            'completed_at' => $upload->completed_at?->toIso8601String(),
        ]);
    }

    /** Abort the multipart upload session. */
    public function abort(Request $request, MultipartUpload $multipart): JsonResponse
    {
        $this->authorizeOwner($request, $multipart);

        $upload = AbortMultipartUploadAction::create()->run(['upload' => $multipart]);

        return response()->json([
            'upload_id'  => $upload->upload_id,
            'status'     => $upload->status->value,
            'aborted_at' => $upload->aborted_at?->toIso8601String(),
        ]);
    }

    /**
     * Only the creator (if there was one) can touch the session. Apps with
     * more complex flows (an admin cleaning up other people's uploads) override
     * the logic via a Gate or additional middleware.
     */
    protected function authorizeOwner(Request $request, MultipartUpload $upload): void
    {
        if ($upload->creator_id === null) {
            return;
        }

        $user = $request->user();

        if (
            $user === null
            || $upload->creator_type !== $user->getMorphClass()
            || (string) $upload->creator_id !== (string) $user->getKey()
        ) {
            abort(403, 'Not authorized for this multipart session.');
        }
    }

}
