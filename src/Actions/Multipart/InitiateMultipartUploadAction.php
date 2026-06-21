<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Creates a multipart session in S3/R2 and persists its state in the DB.
 *
 *   $upload = InitiateMultipartUploadAction::create()->run([
 *       'disk'          => 'r2',
 *       'key'           => 'cases/42/videos/foo.mp4',
 *       'mime'          => 'video/mp4',
 *       'expectedSize'  => 800 * 1024 * 1024,
 *       'creator'       => $user,             // optional
 *       'tenant'        => $organization,     // optional
 *       'partSize'      => 10 * 1024 * 1024,  // optional, default config
 *       'expireMinutes' => 60,                // optional, default config
 *   ]);
 *
 *   // $upload contains upload_id, total_parts, expires_at...
 *   // The presigned URLs are requested with GeneratePartUrlsAction.
 */
class InitiateMultipartUploadAction extends Action
{
    /**
     * Create the multipart session in S3/R2 and persist its DB row.
     */
    public function handle(
        string $disk,
        string $key,
        ?string $mime = null,
        int $expectedSize = 0,
        ?Model $creator = null,
        ?Model $tenant = null,
        ?Model $fileable = null,
        ?string $fileableType = null,
        ?string $fileableId = null,
        ?string $collection = null,
        ?int $partSize = null,
        ?int $expireMinutes = null,
        ?array $metadata = null,
    ): MultipartUpload {
        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($disk);

        if ($client === null) {
            throw new RuntimeException(
                "Disk '{$disk}' is not S3-compatible. Multipart only works against S3/R2/MinIO."
            );
        }

        $partSize      = $partSize      ?? (int) config('laracrate.multipart.part_size', 10 * 1024 * 1024);
        $expireMinutes = $expireMinutes ?? (int) config('laracrate.multipart.expire_minutes', 60);

        if ($partSize < 5 * 1024 * 1024) {
            throw new RuntimeException("The minimum part_size in S3 is 5 MB.");
        }

        if ($expectedSize <= 0) {
            throw new RuntimeException("expectedSize must be > 0 to compute total_parts.");
        }

        $totalParts = (int) ceil($expectedSize / $partSize);

        if ($totalParts > 10000) {
            throw new RuntimeException(
                "S3 does not allow more than 10,000 parts. Increase part_size (got {$totalParts} parts)."
            );
        }

        $bucket = config("filesystems.disks.{$disk}.bucket");

        $result = $client->createMultipartUpload(array_filter([
            'Bucket'      => $bucket,
            'Key'         => $key,
            'ContentType' => $mime,
        ]));

        return MultipartUpload::create([
            'upload_id'     => $result['UploadId'],
            'disk'          => $disk,
            'key'           => $key,
            'mime_type'     => $mime,
            'expected_size' => $expectedSize,
            'part_size'     => $partSize,
            'total_parts'   => $totalParts,
            'status'        => MultipartUploadStatus::ACTIVE,
            'creator_type'  => $creator?->getMorphClass(),
            'creator_id'    => $creator?->getKey(),
            'tenant_type'   => $tenant?->getMorphClass(),
            'tenant_id'     => $tenant?->getKey(),
            'fileable_type' => $fileable?->getMorphClass() ?? $fileableType,
            'fileable_id'   => $fileable?->getKey() ?? $fileableId,
            'collection'    => $collection,
            'metadata'      => $metadata,
            'expires_at'    => now()->addMinutes($expireMinutes),
        ]);
    }
}
