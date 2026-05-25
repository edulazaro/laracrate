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
     *
     * Soporta dos convenciones en `$file->disk`:
     *   - 'documents'  → disk del config global (shared, default).
     *   - 'tb:{id}'    → bucket dedicado, resuelto vía TenantBucket en BD.
     */
    public function diskFor(File $file): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->resolveDisk($file->disk);
    }

    /**
     * Resuelve un nombre de disk a su instancia de Filesystem. Centraliza
     * la convención `tb:{id}` para que todos los callers internos (writeBinary,
     * moveServerSide, deleteFromBackend, etc.) la respeten sin duplicar lógica.
     */
    public function resolveDisk(string $disk): \Illuminate\Contracts\Filesystem\Filesystem
    {
        if (str_starts_with($disk, 'tb:')) {
            return Storage::build($this->configFor($disk));
        }

        return Storage::disk($disk);
    }

    /**
     * Devuelve el array de config del disk, sea del config global o de un
     * TenantBucket. Útil para leer `driver`, `bucket`, `root`, etc. sin
     * duplicar la lógica de discriminación `tb:{id}` vs nombre plano.
     */
    public function configFor(string $disk): array
    {
        if (str_starts_with($disk, 'tb:')) {
            $bucketId = (int) substr($disk, 3);
            $bucket = TenantBucket::find($bucketId);

            if (!$bucket || !$bucket->is_active) {
                throw new \RuntimeException("TenantBucket {$bucketId} no disponible (no existe o is_active=false).");
            }

            return $bucket->toDiskConfig();
        }

        return config("filesystems.disks.{$disk}", []);
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
        return (bool) $this->resolveDisk($disk)->put($key, $content, $options);
    }

    /**
     * Borra del disk.
     */
    public function deleteFromBackend(string $disk, string $key): bool
    {
        return (bool) $this->resolveDisk($disk)->delete($key);
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

        // Fallback para disks no-S3 (local): copy + delete vía Storage.
        $disk_ = $this->resolveDisk($disk);
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
     * URL presignada para upload directo cliente → backend.
     * S3 usa createPresignedRequest. Local usa una ruta firmada de Laravel.
     */
    public function presignedUpload(string $disk, string $key, string $mime, ?int $maxSize = null, int $minutes = 15): array
    {
        $client = $this->s3ClientOf($disk);

        if ($client) {
            // El disk puede tener `root` configurado (ej. 'dev/'). Laravel lo aplica
            // automáticamente en read/write vía PathPrefixer, pero al firmar un
            // PUT directo de cliente lo tenemos que aplicar a mano — si no, el
            // archivo se sube SIN prefix y luego ningún read del disk lo encuentra.
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
                'key'        => $key,   // devolvemos la key RELATIVA (sin prefix) para que la DB la guarde así
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
     *
     * $morphAlias permite aplicar el bloque per-model (`models.{alias}`) si
     * la colección lo declara. Si la colección está restringida y el alias
     * no encaja, lanza CollectionNotAllowedForModel.
     */
    public function getCollectionConfig(string $collection, array $modelOverride = [], ?string $morphAlias = null): array
    {
        $base = CollectionConfig::resolve($collection, $morphAlias);
        return array_replace_recursive($base, $modelOverride);
    }

    /**
     * Config efectiva de un type dentro de una colección.
     *
     * Estrategia de merge entre `laracrate.defaults.{type}` y el override
     * declarado en la colección:
     *
     *   - Scalars y mapas de config (`max_file_size`, `quality`, `format`,
     *     `max_width`, ...): merge por key — la collection sobrescribe el
     *     valor del default solo para las keys que declara.
     *
     *   - Listas/sets de items con nombre (`variants`, `accepted_mime_types`,
     *     `accepted_extensions`): si la collection las declara, REEMPLAZAN
     *     completamente las de defaults. No tiene sentido mergear "variants"
     *     o "accepted_extensions" key por key — el usuario quiere su lista,
     *     no una unión silenciosa con los defaults.
     *
     *     Es la convención estándar en el ecosistema (Spatie Media Library,
     *     Filament, Glide, LiipImagineBundle): los presets/conversions
     *     declarados son los únicos que se usan, sin merge implícito.
     *
     * Si la colección está restringida por modelo y se pasa $morphAlias,
     * aplica primero el bloque per-model.
     *
     * Devuelve [] si la colección no acepta ese type.
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

        // Listas/sets: si la collection las declara, reemplazan enteras.
        foreach (['variants', 'accepted_mime_types', 'accepted_extensions'] as $listKey) {
            if (array_key_exists($listKey, $types[$type])) {
                $merged[$listKey] = $types[$type][$listKey];
            }
        }

        return $merged;
    }

    /**
     * ¿La colección acepta este type? Honra el bloque per-model si la
     * colección está restringida y se pasa $morphAlias.
     */
    public function acceptsType(string $collection, string $type, ?string $morphAlias = null): bool
    {
        $col   = CollectionConfig::resolve($collection, $morphAlias);
        $types = $this->normalizeTypes($col['types'] ?? []);
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
     * Devuelve el driver del disk. Soporta nombres planos (lookup en
     * filesystems.php) y `tb:{id}` (lookup en TenantBucket).
     */
    public function driverOf(string $disk): string
    {
        $driver = $this->configFor($disk)['driver'] ?? null;

        if (!$driver) {
            throw new \RuntimeException("Disk '{$disk}' no resolvible (ni en config/filesystems.php ni TenantBucket).");
        }

        return $driver;
    }
}
