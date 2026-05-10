<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Aborta una sesión multipart en S3/R2 y marca el row como ABORTED o EXPIRED.
 * Llamarse cuando el usuario cancela explícitamente, cuando el cron detecta
 * sesiones más allá de expires_at, o tras un fallo no recuperable.
 *
 * IMPORTANTE: si no se llama a esto, las partes ya subidas quedan ocupando
 * storage en el bucket indefinidamente y el provider las factura. Por eso
 * el cron `laracrate:abort-stale-multipart` es obligatorio en producción.
 *
 *   AbortMultipartUploadAction::create()->run([
 *       'upload' => $multipartUpload,
 *       'reason' => MultipartUploadStatus::EXPIRED,  // opcional, default ABORTED
 *   ]);
 */
class AbortMultipartUploadAction extends Action
{
    public function handle(
        MultipartUpload $upload,
        ?MultipartUploadStatus $reason = null,
    ): MultipartUpload {
        if ($upload->status === MultipartUploadStatus::COMPLETED) {
            // No-op: ya está cerrada del lado provider.
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
                // Si S3 ya no la conoce (ya abortada externamente, expirada por
                // lifecycle del bucket, etc.), seguimos y marcamos local.
                logger()->warning('Laracrate: AbortMultipartUpload provider falló', [
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
