<?php

namespace EduLazaro\Laracrate\Actions\Multipart;

use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use RuntimeException;

/**
 * Genera URLs presignadas para subir las partes (PutPart) de una sesión
 * multipart activa. Cada URL es un PUT al endpoint S3/R2 con el binario
 * de esa parte; el cliente debe leer el header `ETag` de la respuesta y
 * mandárnoslo al `complete`.
 *
 *   GeneratePartUrlsAction::create()->run([
 *       'upload'      => $multipartUpload,
 *       'partNumbers' => [1, 2, 3, ..., N],   // opcional; default todas
 *       'ttlMinutes'  => 60,                  // opcional, default config
 *   ]);
 *
 * Devuelve [['part_number' => 1, 'url' => '...'], ...].
 *
 * Si una URL caduca antes de que el cliente la use, vuelve a llamarte para
 * pedir solo las partes pendientes (idempotente, no genera estado nuevo).
 */
class GeneratePartUrlsAction extends Action
{
    public function handle(
        MultipartUpload $upload,
        ?array $partNumbers = null,
        ?int $ttlMinutes = null,
    ): array {
        if (!$upload->isActive()) {
            throw new RuntimeException(
                "Upload {$upload->upload_id} no está activo (status={$upload->status->value})."
            );
        }

        $manager = app(StorageManager::class);
        $client  = $manager->s3ClientOf($upload->disk);

        if ($client === null) {
            throw new RuntimeException("Disk '{$upload->disk}' no es S3-compatible.");
        }

        $ttlMinutes = $ttlMinutes ?? (int) config('laracrate.multipart.url_ttl_minutes', 60);
        $bucket     = config("filesystems.disks.{$upload->disk}.bucket");

        $partNumbers = $partNumbers ?? range(1, $upload->total_parts);

        // Sanity check: partes válidas según total_parts.
        foreach ($partNumbers as $n) {
            if (!is_int($n) || $n < 1 || $n > $upload->total_parts) {
                throw new RuntimeException(
                    "Part number {$n} fuera de rango [1, {$upload->total_parts}]."
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
