<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use RuntimeException;
use Throwable;

/**
 * Completes a multipart session in S3/R2. The client passes us the list of
 * parts with their PartNumber and ETag (received on each successful PUT) and
 * here we call CompleteMultipartUpload so S3 assembles the final object.
 *
 *   CompleteMultipartUploadAction::create()->run([
 *       'upload' => $multipartUpload,
 *       'parts'  => [
 *           ['part_number' => 1, 'etag' => '"abc..."'],
 *           ['part_number' => 2, 'etag' => '"def..."'],
 *           ...
 *       ],
 *   ]);
 *
 * Marks the row as COMPLETED. Creating the File row is delegated to the app
 * (listening to the event or calling `addFile` with the key); this package
 * only concerns the transport.
 */
class CompleteMultipartUploadAction extends Action
{
    /**
     * Complete the multipart session and mark the row as COMPLETED.
     */
    public function handle(MultipartUpload $upload, array $parts): MultipartUpload
    {
        if (!$upload->isActive()) {
            throw new RuntimeException(
                "Upload {$upload->upload_id} is not active (status={$upload->status->value})."
            );
        }

        if ($parts === []) {
            throw new RuntimeException("Empty parts list.");
        }

        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($upload->disk);

        if ($client === null) {
            throw new RuntimeException("Disk '{$upload->disk}' is not S3-compatible.");
        }

        $bucket = config("filesystems.disks.{$upload->disk}.bucket");

        // Normalize and sort by ascending PartNumber (S3 requires it).
        $normalized = [];
        foreach ($parts as $p) {
            if (!isset($p['part_number'], $p['etag'])) {
                throw new RuntimeException("Each part requires 'part_number' and 'etag'.");
            }
            $normalized[] = [
                'PartNumber' => (int) $p['part_number'],
                'ETag'       => (string) $p['etag'],
            ];
        }
        usort($normalized, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        try {
            $client->completeMultipartUpload([
                'Bucket'          => $bucket,
                'Key'             => $upload->key,
                'UploadId'        => $upload->upload_id,
                'MultipartUpload' => ['Parts' => $normalized],
            ]);
        } catch (Throwable $e) {
            $upload->forceFill([
                'status' => MultipartUploadStatus::ABORTED,
                'error'  => $e->getMessage(),
                'aborted_at' => now(),
            ])->save();

            logger()->error('Laracrate: CompleteMultipartUploadAction failed', [
                'upload_id' => $upload->upload_id,
                'key'       => $upload->key,
                'error'     => $e->getMessage(),
            ]);

            throw $e;
        }

        $upload->forceFill([
            'status'       => MultipartUploadStatus::COMPLETED,
            'completed_at' => now(),
            'error'        => null,
        ])->save();

        return $upload->refresh();
    }
}
