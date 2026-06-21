<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use RuntimeException;

/**
 * Generates presigned URLs to upload the parts (PutPart) of an active
 * multipart session. Each URL is a PUT to the S3/R2 endpoint with that part's
 * binary; the client must read the `ETag` header from the response and send it
 * back to us on `complete`.
 *
 *   GeneratePartUrlsAction::create()->run([
 *       'upload'      => $multipartUpload,
 *       'partNumbers' => [1, 2, 3, ..., N],   // optional; default all
 *       'ttlMinutes'  => 60,                  // optional, default config
 *   ]);
 *
 * Returns [['part_number' => 1, 'url' => '...'], ...].
 *
 * If a URL expires before the client uses it, call again to request only the
 * pending parts (idempotent, does not generate new state).
 */
class GeneratePartUrlsAction extends Action
{
    /**
     * Generate presigned PUT URLs for the requested parts of the session.
     */
    public function handle(
        MultipartUpload $upload,
        ?array $partNumbers = null,
        ?int $ttlMinutes = null,
    ): array {
        if (!$upload->isActive()) {
            throw new RuntimeException(
                "Upload {$upload->upload_id} is not active (status={$upload->status->value})."
            );
        }

        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($upload->disk);

        if ($client === null) {
            throw new RuntimeException("Disk '{$upload->disk}' is not S3-compatible.");
        }

        $ttlMinutes = $ttlMinutes ?? (int) config('laracrate.multipart.url_ttl_minutes', 60);
        $bucket     = config("filesystems.disks.{$upload->disk}.bucket");

        $partNumbers = $partNumbers ?? range(1, $upload->total_parts);

        // Sanity check: valid parts according to total_parts.
        foreach ($partNumbers as $n) {
            if (!is_int($n) || $n < 1 || $n > $upload->total_parts) {
                throw new RuntimeException(
                    "Part number {$n} out of range [1, {$upload->total_parts}]."
                );
            }
        }

        $urls = [];

        foreach ($partNumbers as $partNumber) {
            $cmd = $client->getCommand('UploadPart', [
                'Bucket'     => $bucket,
                'Key'        => $upload->key,
                'UploadId'   => $upload->upload_id,
                'PartNumber' => $partNumber,
            ]);

            $request = $client->createPresignedRequest($cmd, "+{$ttlMinutes} minutes");

            $urls[] = [
                'part_number' => $partNumber,
                'url'         => (string) $request->getUri(),
                'method'      => 'PUT',
            ];
        }

        return $urls;
    }
}
