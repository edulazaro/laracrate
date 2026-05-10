<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use RuntimeException;
use Throwable;

/**
 * Completa una sesión multipart en S3/R2. El cliente nos pasa la lista de
 * partes con su PartNumber y ETag (que recibió en cada PUT exitoso) y aquí
 * llamamos a CompleteMultipartUpload para que S3 ensamble el objeto final.
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
 * Marca el row como COMPLETED. La creación del File row se delega a la app
 * (escuchando el evento o llamando a `addFile` con la key); este paquete
 * solo concierne al transporte.
 */
class CompleteMultipartUploadAction extends Action
{
    public function handle(MultipartUpload $upload, array $parts): MultipartUpload
    {
        if (!$upload->isActive()) {
            throw new RuntimeException(
                "Upload {$upload->upload_id} no está activo (status={$upload->status->value})."
            );
        }

        if ($parts === []) {
            throw new RuntimeException("Lista de partes vacía.");
        }

        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($upload->disk);

        if ($client === null) {
            throw new RuntimeException("Disk '{$upload->disk}' no es S3-compatible.");
        }

        $bucket = config("filesystems.disks.{$upload->disk}.bucket");

        // Normaliza y ordena por PartNumber ascendente (S3 lo exige).
        $normalized = [];
        foreach ($parts as $p) {
            if (!isset($p['part_number'], $p['etag'])) {
                throw new RuntimeException("Cada part requiere 'part_number' y 'etag'.");
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
