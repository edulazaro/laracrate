<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Aborts a multipart session in S3/R2 and marks the row as ABORTED or EXPIRED.
 * Called when the user explicitly cancels, when the cron detects sessions past
 * expires_at, or after an unrecoverable failure.
 *
 * IMPORTANT: if this is not called, the already-uploaded parts keep occupying
 * storage in the bucket indefinitely and the provider bills for them. That is
 * why the `laracrate:abort-stale-multipart` cron is mandatory in production.
 *
 *   AbortMultipartUploadAction::create()->run([
 *       'upload' => $multipartUpload,
 *       'reason' => MultipartUploadStatus::EXPIRED,  // optional, default ABORTED
 *   ]);
 */
class AbortMultipartUploadAction extends Action
{
    /**
     * Abort the multipart session and mark the row as ABORTED or EXPIRED.
     */
    public function handle(
        MultipartUpload $upload,
        ?MultipartUploadStatus $reason = null,
    ): MultipartUpload {
        if ($upload->status === MultipartUploadStatus::COMPLETED) {
            // No-op: it is already closed on the provider side.
            return $upload;
        }

        $reason = $reason ?? MultipartUploadStatus::ABORTED;

        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($upload->disk);

        if ($client !== null) {
            $bucket = config("filesystems.disks.{$upload->disk}.bucket");

            try {
                $client->abortMultipartUpload([
                    'Bucket'   => $bucket,
                    'Key'      => $upload->key,
                    'UploadId' => $upload->upload_id,
                ]);
            } catch (Throwable $e) {
                // If S3 no longer knows it (already aborted externally, expired
                // by the bucket lifecycle, etc.), we continue and mark it locally.
                logger()->warning('Laracrate: AbortMultipartUpload provider failed', [
                    'upload_id' => $upload->upload_id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $upload->forceFill([
            'status'     => $reason,
            'aborted_at' => now(),
        ])->save();

        return $upload->refresh();
    }
}
