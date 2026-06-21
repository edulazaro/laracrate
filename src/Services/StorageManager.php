<?php

namespace EduLazaro\Laracrate\Services;

use Aws\S3\S3Client;
use EduLazaro\Laracrate\Actions\Files\GeneratePublicUrlAction;
use EduLazaro\Laracrate\Actions\Files\GenerateSensitiveStreamUrlAction;
use EduLazaro\Laracrate\Actions\Files\GenerateSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\TenantBucket;
use EduLazaro\Laracrate\Support\CollectionConfig;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Package facade. Uses Laravel's Storage::disk() directly: the disks live in
 * config/filesystems.php where the Laravel dev expects them.
 *
 * This service only adds what Storage::disk() does not do well:
 *   - decide a public/signed/sensitive URL based on the File
 *   - access to the S3Client for presigned uploads and batch delete
 *   - helpers for processing Actions (readBinary, withLocalCopy)
 */
class StorageManager
{
    /**
     * Suitable URL to read a File based on its access.
     *
     *   public → direct CDN URL (Storage::url()).
     *   signed → temporary signed URL with server-side cache.
     *   stream → package route signed with Laravel; if sensitive, bind viewer.
     */
    public function urlFor(File $file): ?string
    {
        $access = $file->access?->value ?? $file->access;

        return match ($access) {
            'public' => GeneratePublicUrlAction::create()->run(['file' => $file]),
            'stream' => GenerateSensitiveStreamUrlAction::create()->run(['file' => $file]),
            default  => GenerateSignedUrlAction::create()->run(['file' => $file]),
        };
    }

    /**
     * Returns the filesystem of the File's disk. Shortcut to avoid repeating
     * `Storage::disk($file->disk)` throughout the package.
     *
     * Supports two conventions in `$file->disk`:
     *   - 'documents'  → disk from the global config (shared, default).
     *   - 'tb:{id}'    → dedicated bucket, resolved via TenantBucket in the DB.
     */
    public function diskFor(File $file): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->resolveDisk($file->disk);
    }

    /**
     * Resolves a disk name to its Filesystem instance. Centralizes the
     * `tb:{id}` convention so all internal callers (writeBinary, moveServerSide,
     * deleteFromBackend, etc.) honor it without duplicating logic.
     */
    public function resolveDisk(string $disk): \Illuminate\Contracts\Filesystem\Filesystem
    {
        if (str_starts_with($disk, 'tb:')) {
            return Storage::build($this->configFor($disk));
        }

        return Storage::disk($disk);
    }

    /**
     * Returns the disk config array, whether from the global config or from a
     * TenantBucket. Useful for reading `driver`, `bucket`, `root`, etc. without
     * duplicating the `tb:{id}` vs flat name discrimination logic.
     */
    public function configFor(string $disk): array
    {
        if (str_starts_with($disk, 'tb:')) {
            $bucketId = (int) substr($disk, 3);
            $bucket = TenantBucket::find($bucketId);

            if (!$bucket || !$bucket->is_active) {
                throw new \RuntimeException("TenantBucket {$bucketId} not available (does not exist or is_active=false).");
            }

            return $bucket->toDiskConfig();
        }

        return config("filesystems.disks.{$disk}", []);
    }

    /**
     * Reads the full binary from the disk. Use with care: for large files
     * prefer withLocalCopy() or stream.
     *
     * Convention: `$file->key` is a model accessor that returns the full key
     * (`path` already stores directories + filename + extension).
     */
    public function readBinary(File $file): string
    {
        return (string) $this->diskFor($file)->get($file->key);
    }

    /**
     * Uploads a binary to the disk.
     */
    public function writeBinary(string $disk, string $key, string $content, ?string $mime = null): bool
    {
        $options = $mime ? ['ContentType' => $mime] : [];
        return (bool) $this->resolveDisk($disk)->put($key, $content, $options);
    }

    /**
     * Deletes from the disk.
     */
    public function deleteFromBackend(string $disk, string $key): bool
    {
        return (bool) $this->resolveDisk($disk)->delete($key);
    }

    /**
     * Moves an object from one key to another WITHIN the same disk.
     * On S3-compatible disks it uses copyObject server-side: the binary does
     * NOT pass through PHP. Critical for large videos (zero download to the server).
     */
    public function moveServerSide(string $disk, string $fromKey, string $toKey): bool
    {
        if ($fromKey === $toKey) {
            return true;
        }

        $client = $this->s3ClientOf($disk);

        if ($client) {
            $bucket = $this->configFor($disk)['bucket'] ?? null;

            $client->copyObject([
                'Bucket'     => $bucket,
                'CopySource' => "{$bucket}/" . rawurlencode($fromKey),
                'Key'        => $toKey,
            ]);

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key'    => $fromKey,
            ]);

            return true;
        }

        // Fallback for non-S3 disks (local): copy + delete via Storage.
        $disk_ = $this->resolveDisk($disk);
        if (!$disk_->exists($fromKey)) {
            return false;
        }
        $disk_->copy($fromKey, $toKey);
        $disk_->delete($fromKey);
        return true;
    }

    /**
     * Batch delete. If the disk is S3-compatible it uses native deleteObjects
     * (1 call per up to 1000 keys); otherwise, it loops.
     */
    public function batchDelete(string $disk, array $keys): int
    {
        $client = $this->s3ClientOf($disk);

        if (!$client) {
            $resolved = $this->resolveDisk($disk);
            $deleted = 0;
            foreach ($keys as $key) {
                if ($resolved->delete($key)) {
                    $deleted++;
                }
            }
            return $deleted;
        }

        $bucket  = $this->configFor($disk)['bucket'] ?? null;
        $deleted = 0;

        foreach (array_chunk($keys, 1000) as $chunk) {
            $result = $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => [
                    'Objects' => array_map(fn ($k) => ['Key' => $k], $chunk),
                    'Quiet'   => true,
                ],
            ]);
            $deleted += count($chunk) - count($result['Errors'] ?? []);
        }

        return $deleted;
    }

    /**
     * Presigned URL for direct client → backend upload.
     * S3 uses createPresignedRequest. Local uses a Laravel signed route.
     */
    public function presignedUpload(string $disk, string $key, string $mime, ?int $maxSize = null, int $minutes = 15): array
    {
        $client = $this->s3ClientOf($disk);

        if ($client) {
            // The disk may have `root` configured (e.g. 'dev/'). Laravel applies
            // it automatically on read/write via PathPrefixer, but when signing a
            // direct client PUT we have to apply it by hand: otherwise the file
            // uploads WITHOUT the prefix and then no disk read finds it.
            $cfg     = $this->configFor($disk);
            $root    = trim((string) ($cfg['root'] ?? ''), '/');
            $fullKey = $root !== '' ? "{$root}/{$key}" : $key;

            $cmd = $client->getCommand('PutObject', array_filter([
                'Bucket'        => $cfg['bucket'] ?? null,
                'Key'           => $fullKey,
                'ContentType'   => $mime,
                'ContentLength' => $maxSize,
            ]));

            $request = $client->createPresignedRequest($cmd, "+{$minutes} minutes");

            return [
                'url'        => (string) $request->getUri(),
                'method'     => 'PUT',
                'headers'    => ['Content-Type' => $mime],
                'key'        => $key,   // we return the RELATIVE key (without prefix) so the DB stores it that way
                'disk'       => $disk,
                'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
            ];
        }

        // Local: Laravel signed route to the LocalUploadController
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'laracrate.local.upload',
            now()->addMinutes($minutes),
            ['disk' => $disk, 'key' => base64_encode($key), 'mime' => $mime]
        );

        return [
            'url'        => $url,
            'method'     => 'POST',
            'headers'    => [],
            'key'        => $key,
            'disk'       => $disk,
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ];
    }

    /**
     * Downloads the binary to a temporary path, runs the callable with that
     * path, and deletes the temp file when done. For ffmpeg/ffprobe/Imagick.
     */
    public function withLocalCopy(File $file, callable $fn): mixed
    {
        $extension = pathinfo($file->name, PATHINFO_EXTENSION) ?: 'bin';
        $tempPath  = sys_get_temp_dir() . '/laracrate_' . Str::random(16) . '.' . $extension;

        try {
            file_put_contents($tempPath, $this->readBinary($file));
            return $fn($tempPath);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Resolution of a collection's configuration.
     *
     * $morphAlias allows applying the per-model block (`models.{alias}`) if the
     * collection declares it. If the collection is restricted and the alias does
     * not match, it throws CollectionNotAllowedForModel.
     */
    public function getCollectionConfig(string $collection, array $modelOverride = [], ?string $morphAlias = null): array
    {
        $base = CollectionConfig::resolve($collection, $morphAlias);
        return array_replace_recursive($base, $modelOverride);
    }

    /**
     * Effective config of a type within a collection.
     *
     * Merge strategy between `laracrate.defaults.{type}` and the override
     * declared in the collection:
     *
     *   - Scalars and config maps (`max_file_size`, `quality`, `format`,
     *     `max_width`, ...): merge by key. The collection overrides the default
     *     value only for the keys it declares.
     *
     *   - Lists/sets of named items (`variants`, `accepted_mime_types`,
     *     `accepted_extensions`): if the collection declares them, they
     *     completely REPLACE the ones from defaults. It makes no sense to merge
     *     "variants" or "accepted_extensions" key by key: the user wants their
     *     list, not a silent union with the defaults.
     *
     *     This is the standard convention in the ecosystem (Spatie Media Library,
     *     Filament, Glide, LiipImagineBundle): the declared presets/conversions
     *     are the only ones used, without implicit merge.
     *
     * If the collection is restricted by model and $morphAlias is passed, it
     * applies the per-model block first.
     *
     * Returns [] if the collection does not accept that type.
     */
    public function getTypeConfig(string $collection, string $type, ?string $morphAlias = null): array
    {
        $col   = CollectionConfig::resolve($collection, $morphAlias);
        $types = $this->normalizeTypes($col['types'] ?? []);

        if (!array_key_exists($type, $types)) {
            return [];
        }

        $defaults = config("laracrate.defaults.{$type}", []);
        $merged   = array_replace_recursive($defaults, $types[$type]);

        // Lists/sets: if the collection declares them, they replace entirely.
        foreach (['variants', 'accepted_mime_types', 'accepted_extensions'] as $listKey) {
            if (array_key_exists($listKey, $types[$type])) {
                $merged[$listKey] = $types[$type][$listKey];
            }
        }

        return $merged;
    }

    /**
     * Does the collection accept this type? Honors the per-model block if the
     * collection is restricted and $morphAlias is passed.
     */
    public function acceptsType(string $collection, string $type, ?string $morphAlias = null): bool
    {
        $col   = CollectionConfig::resolve($collection, $morphAlias);
        $types = $this->normalizeTypes($col['types'] ?? []);
        return array_key_exists($type, $types);
    }

    /**
     * Normalizes a collection's `types` array. Accepts:
     *   - 'image'                          (bare string, no override)
     *   - 'image' => [...]                 (with override)
     *   - 'image' => 'image'               (unusual but valid)
     */
    protected function normalizeTypes(array $types): array
    {
        $out = [];
        foreach ($types as $key => $value) {
            if (is_int($key)) {
                $out[$value] = [];
            } else {
                $out[$key] = is_array($value) ? $value : [];
            }
        }
        return $out;
    }

    /**
     * Returns the underlying S3Client if the disk is s3-compatible. null otherwise.
     */
    public function s3ClientOf(string $disk): ?S3Client
    {
        if (($this->configFor($disk)['driver'] ?? null) !== 's3') {
            return null;
        }

        $instance = $this->resolveDisk($disk);
        if (!method_exists($instance, 'getClient')) {
            return null;
        }

        $client = $instance->getClient();
        return $client instanceof S3Client ? $client : null;
    }

    /**
     * Returns the disk driver. Supports flat names (lookup in filesystems.php)
     * and `tb:{id}` (lookup in TenantBucket).
     */
    public function driverOf(string $disk): string
    {
        $driver = $this->configFor($disk)['driver'] ?? null;

        if (!$driver) {
            throw new \RuntimeException("Disk '{$disk}' not resolvable (neither in config/filesystems.php nor TenantBucket).");
        }

        return $driver;
    }
}
