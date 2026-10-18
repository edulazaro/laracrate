<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Exceptions\UploadRejected;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\Binary;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laractions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrator. Accepts the upload (UploadedFile, Binary, FileUpload, or
 * key string), decides the strategy based on the collection disk driver,
 * runs the upload to the backend if needed, and persists the File model.
 */
class CreateFileAction extends Action
{
    /**
     * Keys accepted inside `$data`. Any other one throws an explicit error
     * to avoid silently dropping values due to a typo.
     */
    private const ALLOWED_DATA_KEYS = [
        'title', 'description', 'category', 'visibility',
        'label', 'default', 'position', 'metadata',
    ];

    /** Validate, upload if needed, and persist a File model for the upload. */
    public function handle(
        ?Model $fileable,
        string $collection,
        array $config,
        UploadedFile|Binary|FileUpload|string $upload,
        array $data = [],
        ?Model $creator = null,
        ?Model $owner = null,
        ?Model $tenant = null,
        ?File $parent = null,
        ?string $variant = null,
        array $slots = [],
        ?\EduLazaro\Laracrate\Models\Folder $folder = null,
    ): ?File {
        // Validation: unexpected keys in $data are typos or lost concepts.
        $unknown = array_diff(array_keys($data), self::ALLOWED_DATA_KEYS);
        if (!empty($unknown)) {
            throw new \InvalidArgumentException(
                "Unknown key(s) in \$data: " . implode(', ', $unknown) .
                ". Allowed: " . implode(', ', self::ALLOWED_DATA_KEYS) .
                ". For arbitrary data use \$data['metadata']."
            );
        }
        // If slots come as IDs, resolve them to models.
        $slotModels = collect($slots)
            ->map(fn ($s) => $s instanceof \EduLazaro\Laracrate\Models\FileSlot
                ? $s
                : \EduLazaro\Laracrate\Models\FileSlot::find($s))
            ->filter()
            ->values();
        $disk   = $config['disk']   ?? 'documents';
        $access = $config['access'] ?? 'private';
        $sensitive = (bool) ($config['sensitive'] ?? false);
        $encrypt   = (bool) ($config['encrypt'] ?? false);

        // Critical validation: encrypt requires PHP to hold the binary
        // (server-side mode: UploadedFile or Binary). Presigned mode
        // (FileUpload/key) arrives raw at the backend, with no way to encrypt
        // after the fact.
        $hasServerSideBinary = $upload instanceof UploadedFile || $upload instanceof Binary;
        if ($encrypt && !$hasServerSideBinary && !$parent) {
            throw new \InvalidArgumentException(
                "Collection '{$collection}' has encrypt=true. Upload the file " .
                "directly via the server (UploadedFile or Binary), not via presigned."
            );
        }

        $manager = app(StorageManager::class);

        // 1. Validate FIRST with the declared metadata (without touching the
        //    binary yet). This avoids leaving orphan binaries in R2 if the
        //    collection rejects by type/mime/size/slot.
        $declared = $this->declaredMetadata($upload);
        $type = FileType::fromMime($declared['mime_type']);
        $this->validateAgainstCollection(
            $collection, $type, $declared,
            $fileable, $parent, $manager, $slotModels, $creator
        );

        // 2. Validation passed: now move/upload the binary.
        $resolved = $this->resolveUpload($upload, $disk, $collection, $fileable, $manager, $encrypt, $tenant);

        // Auto-position at the end if not declared explicitly.
        if (!isset($data['position']) && !$parent) {
            $data['position'] = (int) (File::query()
                ->where('fileable_type', $fileable?->getMorphClass())
                ->where('fileable_id', $fileable?->getKey())
                ->where('collection', $collection)
                ->whereNull('parent_id')
                ->max('position') ?? -1) + 1;
        }

        // 3. Persist the File model. If something fails here (DB lock, encrypt,
        //    etc.), defensively clean up the binary we already wrote to the backend.
        try {
            $file = File::create([
                'slug'            => (string) Str::ulid(),
                'parent_id'       => $parent?->getKey(),
                'variant'         => $variant,
                'fileable_type'   => $fileable?->getMorphClass(),
                'fileable_id'     => $fileable?->getKey(),
                'folder_id'       => $folder?->getKey(),
                'creator_type'    => $creator?->getMorphClass(),
                'creator_id'      => $creator?->getKey(),
                'owner_type'      => $owner?->getMorphClass(),
                'owner_id'        => $owner?->getKey(),
                'tenant_type'     => $tenant?->getMorphClass(),
                'tenant_id'       => $tenant?->getKey(),
                'disk'            => $disk,
                'path'            => $resolved['path'],
                'name'            => $resolved['name'],
                'original_name'   => $resolved['original_name'],
                'extension'       => $resolved['extension'],
                'mime_type'       => $resolved['mime_type'],
                'size'            => $resolved['size'],
                'digest'          => $resolved['digest'] ?? null,
                'context'         => $config['context'] ?? $disk,
                'collection'      => $collection,
                'type'            => $type,
                'category'        => $data['category'] ?? null,
                'access'          => $access,
                'visibility'      => $data['visibility'] ?? null,
                'sensitive'       => $sensitive,
                'is_encrypted'    => $encrypt,
                'title'           => $data['title'] ?? $resolved['original_name'],
                'description'     => $data['description'] ?? null,
                'label'           => $data['label'] ?? null,
                'default'         => $data['default'] ?? false,
                'position'        => $data['position'] ?? 0,
                'duration'        => $resolved['duration'] ?? null,
                'width'           => $resolved['width'] ?? null,
                'height'          => $resolved['height'] ?? null,
                'metadata'        => $data['metadata'] ?? [],
                'processing_status' => $resolved['needs_processing'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk($disk)->delete($resolved['path']);
            throw $e;
        }

        if ($upload instanceof FileUpload) {
            $upload->bindTo($file);
        }

        // Attach slots if they came in the call (already validated above).
        if ($slotModels->isNotEmpty()) {
            $file->slots()->syncWithoutDetaching($slotModels->pluck('id')->all());
        }

        // TODO (next phase): if the collection defines variants and the type is
        // image, enqueue GenerateVariantsAction. If encrypt=true, enqueue
        // EncryptFileAction.

        return $file;
    }

    /**
     * Checks a file against the collection and slot rules before any byte is sent,
     * with what the browser declares (name, mime, size). The same rules run again
     * when the file is created, so this only saves a useless upload; it is not the
     * check that protects anything.
     *
     * @throws UploadRejected
     */
    public function preflight(
        ?Model $fileable,
        string $collection,
        string $mime,
        int $size,
        string $originalName,
        array $slots = [],
        ?Model $creator = null,
    ): void {
        $slotModels = collect($slots)
            ->map(fn ($s) => $s instanceof \EduLazaro\Laracrate\Models\FileSlot ? $s : \EduLazaro\Laracrate\Models\FileSlot::find($s))
            ->filter()
            ->values();

        $this->validateAgainstCollection(
            $collection,
            FileType::fromMime($mime),
            [
                'mime_type' => $mime,
                'size'      => $size,
                'extension' => strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin'),
            ],
            $fileable,
            null,
            app(StorageManager::class),
            $slotModels,
            $creator,
        );
    }

    /**
     * Extracts the declared metadata from the upload WITHOUT touching the
     * binary in the backend. Returns mime_type, size and extension. For
     * pre-move validation.
     */
    protected function declaredMetadata(UploadedFile|Binary|FileUpload|string $upload): array
    {
        if ($upload instanceof UploadedFile) {
            // `getMimeType()` detects the mime with finfo over the real file
            // (always works). `getClientMimeType()` reads from the browser
            // header, but Livewire's TemporaryUploadedFile does NOT pass it to
            // its parent constructor, so it always returns octet-stream and
            // breaks type detection on uploads via WithFileUploads.
            $mime = $upload->getMimeType() ?: ($upload->getClientMimeType() ?: 'application/octet-stream');
            return [
                'mime_type' => $mime,
                'size'      => (int) $upload->getSize(),
                'extension' => strtolower($upload->getClientOriginalExtension() ?: 'bin'),
            ];
        }

        if ($upload instanceof Binary) {
            return [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size(),
                'extension' => $upload->extension(),
            ];
        }

        if ($upload instanceof FileUpload) {
            return [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size,
                'extension' => strtolower(pathinfo($upload->originalName, PATHINFO_EXTENSION) ?: 'bin'),
            ];
        }

        // string key: the backend already has the file, we cannot know mime/size
        // without a HEAD. We do not validate (we assume trust). Caller responsible.
        return [
            'mime_type' => 'application/octet-stream',
            'size'      => 0,
            'extension' => strtolower(pathinfo($upload, PATHINFO_EXTENSION) ?: 'bin'),
        ];
    }

    /**
     * Validates that the collection accepts the declared type, its mime and
     * size, and that the selected slots accept the extension and have quota.
     * Without touching the binary in the backend.
     */
    protected function validateAgainstCollection(
        string $collection,
        FileType $type,
        array $declared,
        ?Model $fileable,
        ?File $parent,
        StorageManager $manager,
        \Illuminate\Support\Collection $slotModels,
        ?Model $creator,
    ): void {
        if (!$parent) {
            $morphAlias = $fileable?->getMorphClass();

            if (!$manager->acceptsType($collection, $type->value, $morphAlias)) {
                throw new UploadRejected(
                    "Collection '{$collection}' does not accept files of type '{$type->value}'.",
                    'type',
                );
            }

            $typeConfig = $manager->getTypeConfig($collection, $type->value, $morphAlias);

            $acceptedMimes = $typeConfig['accepted_mime_types'] ?? [];
            if (!empty($acceptedMimes) && !in_array($declared['mime_type'], $acceptedMimes, true)) {
                throw new UploadRejected(
                    "MIME '{$declared['mime_type']}' not accepted by collection '{$collection}'. Allowed: " . implode(', ', $acceptedMimes),
                    'type',
                );
            }

            $maxSizeKb = $typeConfig['max_file_size'] ?? null;
            if ($maxSizeKb && $declared['size'] > $maxSizeKb * 1024) {
                throw new UploadRejected(
                    "The file exceeds the maximum size of {$maxSizeKb} KB for collection '{$collection}'.",
                    'size',
                    ['size' => round($maxSizeKb / 1024, 1)],
                );
            }
        }

        if ($slotModels->isNotEmpty()) {
            $extension = $declared['extension'];
            $creatorType = $creator?->getMorphClass();
            $creatorId   = $creator?->getKey();

            foreach ($slotModels as $slot) {
                if (!$slot->acceptsExtension($extension)) {
                    $allowed = implode(', ', array_map('strtoupper', $slot->allowed_extensions ?? []));
                    throw new UploadRejected(
                        "Slot '{$slot->name}' does not accept .{$extension} files. Allowed: {$allowed}",
                        'slot_extension',
                        ['slot' => $slot->name, 'allowed' => $allowed],
                    );
                }

                $check = $slot->canAcceptMore($creatorType, $creatorId);
                if (!$check['can']) {
                    $reason = $check['reason'] === 'global'
                        ? "the global limit of {$check['limit']} files"
                        : "your limit of {$check['limit']} files";
                    throw new UploadRejected(
                        "Slot '{$slot->name}' has reached {$reason}.",
                        'slot_full',
                        ['slot' => $slot->name, 'limit' => $check['limit']],
                    );
                }
            }
        }
    }

    /**
     * Determines where the file ends up in the backend and returns the final
     * data to create the File model.
     *
     * Convention of the returned array: `path` = the whole object key in disk;
     * `name` = denormalization (basename) for convenience in queries/display.
     */
    protected function resolveUpload(
        UploadedFile|Binary|FileUpload|string $upload,
        string $disk,
        string $collection,
        ?Model $fileable,
        StorageManager $manager,
        bool $encrypt = false,
        ?Model $tenant = null,
    ): array {
        // Case A: presigned upload completed by the client.
        if ($upload instanceof FileUpload) {
            $key = ltrim($upload->key, '/');

            // The key usually comes from the browser. Only an object it just uploaded
            // (temp/) or one already under this fileable's own path may become a File
            // of it: any other key would let someone claim an object of another
            // model or tenant just by knowing its key.
            if (!$this->keyBelongsTo($key, $collection, $fileable, $tenant)) {
                throw new \InvalidArgumentException(
                    "The key '{$key}' is not an upload of this fileable."
                );
            }

            // If the key lives in temp/ and we know the fileable, move it
            // server-side to the canonical path (zero download to PHP).
            if (str_starts_with($key, 'temp/') && $fileable) {
                $name     = basename($key);
                $finalKey = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');
                $manager->moveServerSide($disk, $key, $finalKey);
                $key      = $finalKey;
            }

            return $this->makeRow($key, $upload->originalName, [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size,
                'digest'    => $upload->digest,
                'width'     => $upload->width,
                'height'    => $upload->height,
                'duration'  => $upload->duration,
            ]);
        }

        // Case B: already in the backend, loose key.
        if (is_string($upload)) {
            $key = ltrim($upload, '/');

            return $this->makeRow($key, basename($key), [
                'mime_type' => 'application/octet-stream',
                'size'      => 0,
            ]);
        }

        // Case D: Binary, in-memory content generated server-side. We write to
        // the disk directly; the package chooses the canonical path, the caller
        // never touches Storage::*.
        if ($upload instanceof Binary) {
            $name = time() . '_' . Str::random(24) . '.' . $upload->extension();
            $key  = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');

            $binary = $encrypt
                ? EncryptFileAction::create()->run(['binary' => $upload->content])
                : $upload->content;

            $manager->writeBinary($disk, $key, $binary, $upload->mimeType);

            return $this->makeRow($key, $upload->originalName, [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size(),
                'width'     => $upload->width,
                'height'    => $upload->height,
                'duration'  => $upload->duration,
            ]);
        }

        // Case C: UploadedFile, must be uploaded to the backend now.
        $extension = $upload->getClientOriginalExtension() ?: 'bin';
        $name      = time() . '_' . Str::random(24) . '.' . $extension;
        $key       = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');

        $binary = $upload->get();
        if ($encrypt) {
            $binary = EncryptFileAction::create()->run(['binary' => $binary]);
        }

        $manager->writeBinary($disk, $key, $binary, $upload->getMimeType());

        return $this->makeRow($key, $upload->getClientOriginalName(), [
            'mime_type' => $upload->getMimeType(),
            'size'      => $upload->getSize(),
        ]);
    }

    /**
     * Builds the array that `CreateFileAction::handle` passes to `File::create`.
     * Encapsulates the contract `path = whole key`, `name = basename(key)` and
     * derives `extension` from `original_name` by default. The caller only
     * passes the upload-specific fields.
     */
    protected function makeRow(string $key, string $originalName, array $extras): array
    {
        return array_merge([
            'path'          => $key,
            'name'          => basename($key),
            'original_name' => $originalName,
            'extension'     => pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin',
        ], $extras);
    }

    /**
     * Whether a presigned key may be registered for this fileable: a fresh upload
     * under temp/, or a key under the fileable's own collection path, with or
     * without the tenant prefix (presigned canonical keys never carry it).
     */
    protected function keyBelongsTo(string $key, string $collection, ?Model $fileable, ?Model $tenant): bool
    {
        if (str_contains($key, '..')) {
            return false;
        }

        if (str_starts_with($key, 'temp/')) {
            return true;
        }

        if (!$fileable) {
            return false;
        }

        $base = $this->buildPath($collection, $fileable) . '/';
        $tenantBase = $tenant ? $this->buildPath($collection, $fileable, $tenant) . '/' : null;

        return str_starts_with($key, $base) || ($tenantBase && str_starts_with($key, $tenantBase));
    }

    /**
     * Builds the canonical path of a file inside the bucket. If a tenant is
     * resolved, its id is used as the root prefix: it isolates per tenant
     * within the same shared bucket, eases auditing and GDPR deletion ("rm -rf
     * /{tenant_id}/*"), and prepares migration to a dedicated bucket while
     * preserving structure.
     *
     * Typical results:
     *   without tenant:  case/123/documents
     *   with tenant=42:  42/case/123/documents
     */
    protected function buildPath(string $collection, ?Model $fileable, ?Model $tenant = null): string
    {
        $base = $fileable
            ? $fileable->getMorphClass() . '/' . $fileable->getKey() . '/' . $collection
            : $collection;

        if ($tenant) {
            return $tenant->getKey() . '/' . $base;
        }

        return $base;
    }

}
