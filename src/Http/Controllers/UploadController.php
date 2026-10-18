<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Actions\Files\CreateFileAction;
use EduLazaro\Laracrate\Exceptions\InvalidUploadGrant;
use EduLazaro\Laracrate\Exceptions\UploadRejected;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\ActiveContent;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
 */
class UploadController extends Controller
{
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

        // The size the bucket holds, not the one the browser declared: a presigned PUT
        // does not bind the length, so a declared 1 KB could be a 10 GB object.
        try {
            $size = (int) Storage::disk($receipt['d'])->size($receipt['k']);
        } catch (\Throwable) {
            return $this->fail(__('laracrate::uploader.not_received'));
        }

        $upload = FileUpload::fromArray([
            'disk'          => $receipt['d'],
            'key'           => $receipt['k'],
            'mime_type'     => $data['mime'] ?: 'application/octet-stream',
            'original_name' => $data['original_name'],
            'size'          => $size,
        ]);

        return $this->store($grant, $upload, $data['original_name']);
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
            'id'         => $file->id,
            'slug'       => $file->slug,
            'name'       => $name,
            'collection' => $grant->collection,
            'contextKey' => $grant->contextKey,
            'scope'      => $grant->scope(),
        ]);
    }

    /** The collection and slot rules, plus no runnable content unless the collection asks for it. */
    protected function refusal(UploadGrant $grant, string $mime, int $size, string $name, Request $request): ?JsonResponse
    {
        $fileable = $grant->fileable();

        try {
            CreateFileAction::create()->preflight(
                $fileable,
                $grant->collection,
                $mime,
                $size,
                $name,
                $grant->slots,
                $grant->creator() ?? $request->user(),
            );
        } catch (UploadRejected $e) {
            return $this->fail($e->userMessage());
        }

        if (ActiveContent::is($mime, $name) && ! $this->acceptsExplicitly($grant, $fileable, $mime)) {
            return $this->fail(__('laracrate::uploader.rejected.active'));
        }

        return null;
    }

    protected function acceptsExplicitly(UploadGrant $grant, $fileable, string $mime): bool
    {
        $type = \EduLazaro\Laracrate\Enums\FileType::fromMime($mime)->value;
        $config = app(StorageManager::class)->getTypeConfig($grant->collection, $type, $fileable->getMorphClass());

        return in_array($mime, $config['accepted_mime_types'] ?? [], true);
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
        $safe = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);

        return Str::limit($safe, 120, '') ?: 'upload';
    }

    protected function fail(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
