<?php

namespace EduLazaro\Laracrate\Services;

use Aws\S3\S3Client;
use EduLazaro\Laracrate\Actions\Files\GeneratePublicUrlAction;
use EduLazaro\Laracrate\Actions\Files\GenerateSensitiveStreamUrlAction;
use EduLazaro\Laracrate\Actions\Files\GenerateSignedUrlAction;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fachada del paquete. Usa Storage::disk() de Laravel directamente — los
 * disks viven en config/filesystems.php donde el dev Laravel los espera.
 *
 * Este servicio solo añade lo que Storage::disk() no da bien:
 *   - decisión de URL pública/firmada/sensitive según el File
 *   - acceso al S3Client para presigned uploads y batch delete
 *   - helpers para Actions de procesamiento (readBinary, withLocalCopy)
 */
class StorageManager
{
    /**
     * URL adecuada para leer un File según su access.
     *
     *   public → URL directa CDN (Storage::url()).
     *   signed → URL firmada temporal con cache server-side.
     *   stream → ruta del paquete firmada con Laravel; si sensitive, bind viewer.
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
     * Devuelve el filesystem del disk del File. Atajo para no andar
     * repitiendo `Storage::disk($file->disk)` por todo el paquete.
     */
    public function diskFor(File $file): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($file->disk);
    }

    /**
     * Lee binario completo desde el disk. Usar con cuidado — para archivos
     * grandes preferir withLocalCopy() o stream.
     *
     * Convención: `$file->key` es accessor del modelo que devuelve la key
     * completa (`path` ya almacena directorios + filename + extensión).
     */
    public function readBinary(File $file): string
    {
        return (string) $this->diskFor($file)->get($file->key);
    }

    /**
     * Sube un binario al disk.
     */
    public function writeBinary(string $disk, string $key, string $content, ?string $mime = null): bool
    {
        $options = $mime ? ['ContentType' => $mime] : [];
        return (bool) Storage::disk($disk)->put($key, $content, $options);
    }

    /**
     * Borra del disk.
     */
    public function deleteFromBackend(string $disk, string $key): bool
    {
        return (bool) Storage::disk($disk)->delete($key);
    }

    /**
     * Mueve un objeto de una key a otra DENTRO del mismo disk.
     * En S3-compatible usa copyObject server-side: el binario NO pasa por PHP.
     * Crítico para vídeos grandes (cero descarga al server).
     */
    public function moveServerSide(string $disk, string $fromKey, string $toKey): bool
    {
        if ($fromKey === $toKey) {
            return true;
        }

        $client = $this->s3ClientOf($disk);

        if ($client) {
            $bucket = config("filesystems.disks.{$disk}.bucket");

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

        // Fallback para disks no-S3 (local): copy + delete vía Storage.
        $disk_ = Storage::disk($disk);
        if (!$disk_->exists($fromKey)) {
            return false;
        }
        $disk_->copy($fromKey, $toKey);
        $disk_->delete($fromKey);
        return true;
    }

    /**
     * Borrado en lote. Si el disk es S3-compatible usa deleteObjects nativo
     * (1 llamada por hasta 1000 keys); si no, loop.
     */
    public function batchDelete(string $disk, array $keys): int
    {
        $client = $this->s3ClientOf($disk);

        if (!$client) {
            $deleted = 0;
            foreach ($keys as $key) {
                if (Storage::disk($disk)->delete($key)) {
                    $deleted++;
                }
            }
            return $deleted;
        }

        $bucket  = config("filesystems.disks.{$disk}.bucket");
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
     * URL presignada para upload directo cliente → backend.
     * S3 usa createPresignedRequest. Local usa una ruta firmada de Laravel.
     */
    public function presignedUpload(string $disk, string $key, string $mime, ?int $maxSize = null, int $minutes = 15): array
    {
        $client = $this->s3ClientOf($disk);

        if ($client) {
            $cmd = $client->getCommand('PutObject', array_filter([
                'Bucket'        => config("filesystems.disks.{$disk}.bucket"),
                'Key'           => $key,
                'ContentType'   => $mime,
                'ContentLength' => $maxSize,
            ]));

            $request = $client->createPresignedRequest($cmd, "+{$minutes} minutes");

            return [
                'url'        => (string) $request->getUri(),
                'method'     => 'PUT',
                'headers'    => ['Content-Type' => $mime],
                'key'        => $key,
                'disk'       => $disk,
                'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
            ];
        }

        // Local: ruta firmada de Laravel hacia el LocalUploadController
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
     * Descarga el binario a una ruta temporal, ejecuta el callable con esa
     * ruta, borra el temporal al terminar. Para ffmpeg/ffprobe/Imagick.
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
     * Resolución de configuración de una colección.
     */
    public function getCollectionConfig(string $collection, array $modelOverride = []): array
    {
        $base = config("laracrate.collections.{$collection}", []);
        return array_replace_recursive($base, $modelOverride);
    }

    /**
     * Config efectiva de un type dentro de una colección. Mergea defaults
     * globales del type con el override declarado en la colección.
     *
     * Devuelve [] si la colección no acepta ese type.
     */
    public function getTypeConfig(string $collection, string $type): array
    {
        $col   = config("laracrate.collections.{$collection}", []);
        $types = $this->normalizeTypes($col['types'] ?? []);

        if (!array_key_exists($type, $types)) {
            return [];
        }

        $defaults = config("laracrate.defaults.{$type}", []);
        return array_replace_recursive($defaults, $types[$type]);
    }

    /**
     * ¿La colección acepta este type?
     */
    public function acceptsType(string $collection, string $type): bool
    {
        $types = $this->normalizeTypes(
            config("laracrate.collections.{$collection}.types", [])
        );
        return array_key_exists($type, $types);
    }

    /**
     * Normaliza el array `types` de una colección. Acepta:
     *   - 'image'                          (string suelto, sin override)
     *   - 'image' => [...]                 (con override)
     *   - 'image' => 'image'               (no usual pero válido)
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
     * Devuelve el S3Client subyacente si el disk es s3-compatible. null si no.
     */
    public function s3ClientOf(string $disk): ?S3Client
    {
        if (config("filesystems.disks.{$disk}.driver") !== 's3') {
            return null;
        }

        $instance = Storage::disk($disk);
        if (!method_exists($instance, 'getClient')) {
            return null;
        }

        $client = $instance->getClient();
        return $client instanceof S3Client ? $client : null;
    }

    /**
     * Devuelve el driver que tiene un disk según filesystems.php.
     */
    public function driverOf(string $disk): string
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        if (!$driver) {
            throw new \RuntimeException("Disk '{$disk}' no definido en config/filesystems.php.");
        }

        return $driver;
    }
}
