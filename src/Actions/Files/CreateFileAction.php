<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\Binary;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laractions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orquestador. Acepta el upload (UploadedFile, Binary, FileUpload, o key string),
 * decide la estrategia según el driver del disk de la colección, ejecuta
 * la subida al backend si hace falta, y persiste el File model.
 */
class CreateFileAction extends Action
{
    /**
     * Keys aceptadas dentro de `$data`. Cualquier otra lanza error explícito
     * para evitar descartes silenciosos por typo.
     */
    private const ALLOWED_DATA_KEYS = [
        'title', 'description', 'category', 'visibility',
        'label', 'default', 'position', 'metadata',
    ];

    public function handle(
        ?Model $fileable,
        string $collection,
        array $config,
        UploadedFile|Binary|FileUpload|string $upload,
        array $data = [],
        ?Model $creator = null,
        ?Model $owner = null,
        ?Model $tenant = null,
        ?File $parent = null,
        ?string $variant = null,
        array $slots = [],
    ): ?File {
        // Validación: keys inesperadas en $data son typos o conceptos perdidos.
        $unknown = array_diff(array_keys($data), self::ALLOWED_DATA_KEYS);
        if (!empty($unknown)) {
            throw new \InvalidArgumentException(
                "Unknown key(s) in \$data: " . implode(', ', $unknown) .
                ". Allowed: " . implode(', ', self::ALLOWED_DATA_KEYS) .
                ". Para datos arbitrarios usa \$data['metadata']."
            );
        }
        // Si vienen slots como IDs, los resolvemos a modelos
        $slotModels = collect($slots)
            ->map(fn ($s) => $s instanceof \EduLazaro\Laracrate\Models\FileSlot
                ? $s
                : \EduLazaro\Laracrate\Models\FileSlot::find($s))
            ->filter()
            ->values();
        $disk   = $config['disk']   ?? 'documents';
        $access = $config['access'] ?? 'private';
        $sensitive = (bool) ($config['sensitive'] ?? false);
        $encrypt   = (bool) ($config['encrypt'] ?? false);

        // Validación crítica: encrypt requiere que PHP tenga el binario (modo
        // server-side: UploadedFile o Binary). Modo presigned (FileUpload/key)
        // llega ya raw al backend — sin posibilidad de cifrar a posteriori.
        $hasServerSideBinary = $upload instanceof UploadedFile || $upload instanceof Binary;
        if ($encrypt && !$hasServerSideBinary && !$parent) {
            throw new \InvalidArgumentException(
                "La colección '{$collection}' tiene encrypt=true. Sube el archivo " .
                "directamente vía servidor (UploadedFile o Binary), no via presigned."
            );
        }

        $manager = app(StorageManager::class);

        // 1. Validar PRIMERO con metadata declarada (sin tocar el binario aún).
        //    Esto evita dejar binarios huérfanos en R2 si la collection rechaza
        //    por type/mime/size/slot.
        $declared = $this->declaredMetadata($upload);
        $type = FileType::fromMime($declared['mime_type']);
        $this->validateAgainstCollection(
            $collection, $type, $declared,
            $fileable, $parent, $manager, $slotModels, $creator
        );

        // 2. Validación pasada: ahora sí mover/subir el binario.
        $resolved = $this->resolveUpload($upload, $disk, $collection, $fileable, $manager, $encrypt, $tenant);

        // Auto-position al final si no viene declarada explícitamente.
        if (!isset($data['position']) && !$parent) {
            $data['position'] = (int) (File::query()
                ->where('fileable_type', $fileable?->getMorphClass())
                ->where('fileable_id', $fileable?->getKey())
                ->where('collection', $collection)
                ->whereNull('parent_id')
                ->max('position') ?? -1) + 1;
        }

        // 3. Persistir File model. Si algo falla aquí (BD lock, encrypt, etc.)
        //    cleanup defensivo del binario que ya escribimos en el backend.
        try {
            $file = File::create([
                'slug'            => (string) Str::ulid(),
                'parent_id'       => $parent?->getKey(),
                'variant'         => $variant,
                'fileable_type'   => $fileable?->getMorphClass(),
                'fileable_id'     => $fileable?->getKey(),
                'creator_type'    => $creator?->getMorphClass(),
                'creator_id'      => $creator?->getKey(),
                'owner_type'      => $owner?->getMorphClass(),
                'owner_id'        => $owner?->getKey(),
                'tenant_type'     => $tenant?->getMorphClass(),
                'tenant_id'       => $tenant?->getKey(),
                'disk'            => $disk,
                'path'            => $resolved['path'],
                'name'            => $resolved['name'],
                'original_name'   => $resolved['original_name'],
                'extension'       => $resolved['extension'],
                'mime_type'       => $resolved['mime_type'],
                'size'            => $resolved['size'],
                'digest'          => $resolved['digest'] ?? null,
                'context'         => $config['context'] ?? $disk,
                'collection'      => $collection,
                'type'            => $type,
                'category'        => $data['category'] ?? null,
                'access'          => $access,
                'visibility'      => $data['visibility'] ?? null,
                'sensitive'       => $sensitive,
                'is_encrypted'    => $encrypt,
                'title'           => $data['title'] ?? $resolved['original_name'],
                'description'     => $data['description'] ?? null,
                'label'           => $data['label'] ?? null,
                'default'         => $data['default'] ?? false,
                'position'        => $data['position'] ?? 0,
                'duration'        => $resolved['duration'] ?? null,
                'width'           => $resolved['width'] ?? null,
                'height'          => $resolved['height'] ?? null,
                'metadata'        => $data['metadata'] ?? [],
                'processing_status' => $resolved['needs_processing'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk($disk)->delete($resolved['path']);
            throw $e;
        }

        if ($upload instanceof FileUpload) {
            $upload->bindTo($file);
        }

        // Attach slots si vinieron en la llamada (ya validados arriba).
        if ($slotModels->isNotEmpty()) {
            $file->slots()->syncWithoutDetaching($slotModels->pluck('id')->all());
        }

        // TODO (siguiente fase): si la colección define variants y el tipo es image,
        // encolar GenerateVariantsAction. Si encrypt=true, encolar EncryptFileAction.

        return $file;
    }

    /**
     * Extrae metadata declarada del upload SIN tocar el binario en el backend.
     * Devuelve mime_type, size y extension. Para validación pre-move.
     */
    protected function declaredMetadata(UploadedFile|Binary|FileUpload|string $upload): array
    {
        if ($upload instanceof UploadedFile) {
            return [
                'mime_type' => $upload->getClientMimeType() ?: 'application/octet-stream',
                'size'      => (int) $upload->getSize(),
                'extension' => strtolower($upload->getClientOriginalExtension() ?: 'bin'),
            ];
        }

        if ($upload instanceof Binary) {
            return [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size(),
                'extension' => $upload->extension(),
            ];
        }

        if ($upload instanceof FileUpload) {
            return [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size,
                'extension' => strtolower(pathinfo($upload->originalName, PATHINFO_EXTENSION) ?: 'bin'),
            ];
        }

        // string key: backend ya tiene el archivo, no podemos saber mime/size sin un HEAD.
        // No validamos (asumimos confianza). Caller responsable.
        return [
            'mime_type' => 'application/octet-stream',
            'size'      => 0,
            'extension' => strtolower(pathinfo($upload, PATHINFO_EXTENSION) ?: 'bin'),
        ];
    }

    /**
     * Valida que la collection acepte el tipo declarado, su mime y tamaño,
     * y que los slots seleccionados acepten la extensión y tengan quota.
     * Sin tocar el binario en el backend.
     */
    protected function validateAgainstCollection(
        string $collection,
        FileType $type,
        array $declared,
        ?Model $fileable,
        ?File $parent,
        StorageManager $manager,
        \Illuminate\Support\Collection $slotModels,
        ?Model $creator,
    ): void {
        if (!$parent) {
            $morphAlias = $fileable?->getMorphClass();

            if (!$manager->acceptsType($collection, $type->value, $morphAlias)) {
                throw new \InvalidArgumentException(
                    "La colección '{$collection}' no acepta archivos de tipo '{$type->value}'."
                );
            }

            $typeConfig = $manager->getTypeConfig($collection, $type->value, $morphAlias);

            $acceptedMimes = $typeConfig['accepted_mime_types'] ?? [];
            if (!empty($acceptedMimes) && !in_array($declared['mime_type'], $acceptedMimes, true)) {
                throw new \InvalidArgumentException(
                    "MIME '{$declared['mime_type']}' no aceptado por la colección '{$collection}'. Permitidos: " . implode(', ', $acceptedMimes)
                );
            }

            $maxSizeKb = $typeConfig['max_file_size'] ?? null;
            if ($maxSizeKb && $declared['size'] > $maxSizeKb * 1024) {
                throw new \InvalidArgumentException(
                    "El archivo excede el tamaño máximo de {$maxSizeKb} KB para la colección '{$collection}'."
                );
            }
        }

        if ($slotModels->isNotEmpty()) {
            $extension = $declared['extension'];
            $creatorType = $creator?->getMorphClass();
            $creatorId   = $creator?->getKey();

            foreach ($slotModels as $slot) {
                if (!$slot->acceptsExtension($extension)) {
                    $allowed = implode(', ', array_map('strtoupper', $slot->allowed_extensions ?? []));
                    throw new \InvalidArgumentException(
                        "El slot '{$slot->name}' no acepta archivos .{$extension}. Permitidos: {$allowed}"
                    );
                }

                $check = $slot->canAcceptMore($creatorType, $creatorId);
                if (!$check['can']) {
                    $reason = $check['reason'] === 'global'
                        ? "límite global de {$check['limit']} archivos"
                        : "tu límite de {$check['limit']} archivos";
                    throw new \InvalidArgumentException(
                        "El slot '{$slot->name}' ha alcanzado {$reason}."
                    );
                }
            }
        }
    }

    /**
     * Determina dónde queda el archivo en el backend y devuelve los datos
     * finales para crear el File model.
     *
     * Convención del array devuelto: `path` = key entera del objeto en disk;
     * `name` = denormalización (basename) por comodidad de queries/display.
     */
    protected function resolveUpload(
        UploadedFile|Binary|FileUpload|string $upload,
        string $disk,
        string $collection,
        ?Model $fileable,
        StorageManager $manager,
        bool $encrypt = false,
        ?Model $tenant = null,
    ): array {
        // Caso A: presigned upload completado por el cliente.
        if ($upload instanceof FileUpload) {
            $key = ltrim($upload->key, '/');

            // Si la key vive en temp/ y conocemos el fileable, movemos
            // server-side al path canónico (cero descarga al PHP).
            if (str_starts_with($key, 'temp/') && $fileable) {
                $name     = basename($key);
                $finalKey = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');
                $manager->moveServerSide($disk, $key, $finalKey);
                $key      = $finalKey;
            }

            return $this->makeRow($key, $upload->originalName, [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size,
                'digest'    => $upload->digest,
                'width'     => $upload->width,
                'height'    => $upload->height,
                'duration'  => $upload->duration,
            ]);
        }

        // Caso B: ya está en el backend, key suelto.
        if (is_string($upload)) {
            $key = ltrim($upload, '/');

            return $this->makeRow($key, basename($key), [
                'mime_type' => 'application/octet-stream',
                'size'      => 0,
            ]);
        }

        // Caso D: Binary — contenido en memoria generado server-side. Escribimos
        // al disk directamente; el paquete elige path canónico, el caller jamás
        // toca Storage::*.
        if ($upload instanceof Binary) {
            $name = time() . '_' . Str::random(24) . '.' . $upload->extension();
            $key  = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');

            $binary = $encrypt
                ? EncryptFileAction::create()->run(['binary' => $upload->content])
                : $upload->content;

            $manager->writeBinary($disk, $key, $binary, $upload->mimeType);

            return $this->makeRow($key, $upload->originalName, [
                'mime_type' => $upload->mimeType,
                'size'      => $upload->size(),
                'width'     => $upload->width,
                'height'    => $upload->height,
                'duration'  => $upload->duration,
            ]);
        }

        // Caso C: UploadedFile — hay que subirlo al backend ahora.
        $extension = $upload->getClientOriginalExtension() ?: 'bin';
        $name      = time() . '_' . Str::random(24) . '.' . $extension;
        $key       = trim($this->buildPath($collection, $fileable, $tenant) . '/' . $name, '/');

        $binary = $upload->get();
        if ($encrypt) {
            $binary = EncryptFileAction::create()->run(['binary' => $binary]);
        }

        $manager->writeBinary($disk, $key, $binary, $upload->getMimeType());

        return $this->makeRow($key, $upload->getClientOriginalName(), [
            'mime_type' => $upload->getMimeType(),
            'size'      => $upload->getSize(),
        ]);
    }

    /**
     * Construye el array que `CreateFileAction::handle` pasa a `File::create`.
     * Encapsula el contrato `path = key entera`, `name = basename(key)` y
     * deriva `extension` de `original_name` por defecto. El caller solo
     * pasa los campos específicos del upload.
     */
    protected function makeRow(string $key, string $originalName, array $extras): array
    {
        return array_merge([
            'path'          => $key,
            'name'          => basename($key),
            'original_name' => $originalName,
            'extension'     => pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin',
        ], $extras);
    }

    /**
     * Construye el path canónico de un file dentro del bucket. Si hay tenant
     * resuelto, su id se usa como prefix raíz — aísla por tenant dentro del
     * mismo bucket compartido, facilita auditoría y borrado RGPD ("rm -rf
     * /{tenant_id}/*"), y prepara migración a bucket dedicado preservando
     * estructura.
     *
     * Resultados típicos:
     *   sin tenant:     case/123/documents
     *   con tenant=42:  42/case/123/documents
     */
    protected function buildPath(string $collection, ?Model $fileable, ?Model $tenant = null): string
    {
        $base = $fileable
            ? $fileable->getMorphClass() . '/' . $fileable->getKey() . '/' . $collection
            : $collection;

        if ($tenant) {
            return $tenant->getKey() . '/' . $base;
        }

        return $base;
    }

}
