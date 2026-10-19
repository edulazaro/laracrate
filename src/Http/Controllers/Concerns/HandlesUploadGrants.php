<?php

namespace EduLazaro\Laracrate\Http\Controllers\Concerns;

use EduLazaro\Laracrate\Exceptions\InvalidUploadGrant;
use EduLazaro\Laracrate\Exceptions\UploadRejected;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use EduLazaro\Laracrate\Uploads\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What every grant based upload endpoint shares: reading the grant, the collection
 * rules before any byte moves, and creating the File in the grant's target. Used by
 * the single PUT endpoints (UploadController) and the multipart ones.
 */
trait HandlesUploadGrants
{
    /** Creates the File (or replaces the collection's, for single-file targets). */
    protected function store(UploadGrant $grant, FileUpload|UploadedFile $upload, string $name): JsonResponse
    {
        $fileable = $grant->fileable();

        try {
            $file = $grant->replace
                ? $fileable->setFile($grant->collection, $upload, creator: $grant->creator(), owner: $grant->owner())
                : $fileable->addFile(
                    $upload,
                    $grant->collection,
                    slots: $grant->slots,
                    creator: $grant->creator(),
                    owner: $grant->owner(),
                    folder: $grant->folder(),
                );
        } catch (UploadRejected $e) {
            $this->discard($upload);

            return $this->fail($e->userMessage());
        } catch (\InvalidArgumentException $e) {
            $this->discard($upload);
            Log::warning('Laracrate upload refused', ['collection' => $grant->collection, 'error' => $e->getMessage()]);

            return $this->fail(__('laracrate::uploader.rejected.generic'));
        }

        if (! $file instanceof File) {
            $this->discard($upload);

            return $this->fail(__('laracrate::uploader.rejected.generic'));
        }

        return response()->json([
            ...$file->toComponentArray(),
            'name'       => $name,
            'collection' => $grant->collection,
            'contextKey' => $grant->contextKey,
            'scope'      => $grant->scope(),
        ]);
    }

    /** The collection and slot rules, plus no runnable content unless the collection asks for it. */
    protected function refusal(UploadGrant $grant, string $mime, int $size, string $name, Request $request): ?JsonResponse
    {
        if ($message = UploadRules::refusal($grant, $mime, $size, $name, $request->user())) {
            return $this->fail($message);
        }

        if (! $this->claim($grant, $request->input('upload'))) {
            return $this->fail(__('laracrate::uploader.rejected.too_many'));
        }

        return null;
    }

    /**
     * Counts this file against the grant's maxFiles. A file is told apart by the id the
     * upload queue sends with it (`upload`), so retrying one, or its sign and receive,
     * does not use up another; a request without that id always counts as a new file.
     */
    protected function claim(UploadGrant $grant, mixed $uploadId): bool
    {
        if ($grant->maxFiles === null) {
            return true;
        }

        $key = 'laracrate:grant:' . $grant->id;
        $id = is_scalar($uploadId) && (string) $uploadId !== '' && strlen((string) $uploadId) <= 64
            ? (string) $uploadId
            : (string) Str::ulid();

        return Cache::lock($key . ':lock', 10)->block(5, function () use ($grant, $key, $id) {
            $claimed = Cache::get($key, []);

            if (in_array($id, $claimed, true)) {
                return true;
            }

            if (count($claimed) >= $grant->maxFiles) {
                return false;
            }

            $claimed[] = $id;
            Cache::put($key, $claimed, max(60, $grant->expiresAt - now()->getTimestamp()));

            return true;
        });
    }

    protected function grant(string $token): UploadGrant|JsonResponse
    {
        try {
            return UploadGrant::fromToken($token);
        } catch (InvalidUploadGrant $e) {
            return $this->fail(__('laracrate::uploader.grant_invalid'), 403);
        }
    }

    /** A refused direct upload must not stay in temp/. */
    protected function discard(FileUpload|UploadedFile $upload): void
    {
        if ($upload instanceof FileUpload && str_starts_with($upload->key, 'temp/')) {
            rescue(fn () => Storage::disk($upload->disk)->delete($upload->key), report: false);
        }
    }

    protected function safeName(string $name): string
    {
        return UploadRules::safeName($name);
    }

    protected function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
