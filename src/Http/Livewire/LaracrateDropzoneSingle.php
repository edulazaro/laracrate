<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dropzone single-file con upload directo a R2/S3 vía presigned PUT.
 *
 * Diferencia con `laracrate-dropzone`:
 *   - Solo acepta UN archivo. No hay cola. El archivo subido se muestra
 *     IN-PLACE (preview + nombre + tamaño + botón quitar) en lugar de
 *     debajo del área de drop. UX más limpio para formularios donde
 *     cada campo es 1 archivo.
 *   - Si el modelo ya tiene un File en esta colección, lo muestra como
 *     "ya subido" y permite reemplazarlo.
 *
 *   <livewire:laracrate-dropzone-single :model="$user" collection="avatar" />
 *   <livewire:laracrate-dropzone-single :model="$user" collection="cover" theme="studio" />
 *
 * Flujo presigned idéntico al dropzone multi: PUT directo a R2, el binario
 * no pasa por PHP. Tras `registerUploaded` dispatcha `laracrate-file-uploaded`.
 */
class LaracrateDropzoneSingle extends Component
{
    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    /**
     * Identificador opaco que el caller asocia al widget. Se incluye en el
     * evento `laracrate-file-uploaded` para que el caller pueda routear
     * el File al destino correcto cuando hay varios widgets en la misma página.
     */
    #[Locked]
    public ?string $contextKey = null;

    /**
     * Si true, en lugar de mostrar el File ya existente (recuperado del modelo),
     * siempre se muestra el dropzone vacío. Útil cuando el caller gestiona el
     * estado del file fuera del widget (ej. fileable temporal de un draft).
     */
    #[Locked]
    public bool $hideExisting = false;

    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        ?string $contextKey = null,
        bool $hideExisting = false,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->contextKey   = $contextKey;
        $this->hideExisting = $hideExisting;
    }

    /**
     * Registra un archivo ya subido a R2 (key) como File del modelo.
     * Llamado por el JS del blade después de que el PUT a R2 haya terminado.
     */
    public function registerUploaded(string $key, string $name, string $mime, int $size): ?int
    {
        $upload = FileUpload::fromArray([
            'disk'          => $this->disk(),
            'key'           => $key,
            'mime_type'     => $mime,
            'original_name' => $name,
            'size'          => $size,
        ]);

        // setFile en lugar de addFile: reemplaza el existente.
        $file = $this->model->setFile($this->collection, $upload);

        if (! $file) {
            return null;
        }

        $this->dispatch(
            'laracrate-file-uploaded',
            collection: $this->collection,
            fileId: $file->id,
            contextKey: $this->contextKey,
        );

        return $file->id;
    }

    /**
     * Elimina el File actual del modelo en esta colección. Útil para el botón
     * "Quitar" cuando el archivo ya está subido y registrado.
     */
    public function removeFile(): void
    {
        $existing = $this->model->files($this->collection)->first();
        if ($existing) {
            $existing->forceDelete();
        }

        $this->dispatch(
            'laracrate-file-removed',
            collection: $this->collection,
            contextKey: $this->contextKey,
        );
    }

    public function acceptedExtensions(): array
    {
        $manager = app(StorageManager::class);
        $config  = $this->model->getCollectionConfig($this->collection);
        $types   = array_keys($this->normalizeTypes($config['types'] ?? []));
        $exts    = [];

        foreach ($types as $type) {
            $typeCfg = $manager->getTypeConfig($this->collection, $type, $this->model->getMorphClass());
            foreach ($typeCfg['accepted_extensions'] ?? [] as $ext) {
                $exts[] = strtolower($ext);
            }
        }

        return array_values(array_unique($exts));
    }

    public function acceptedMimeTypes(): array
    {
        $manager = app(StorageManager::class);
        $config  = $this->model->getCollectionConfig($this->collection);
        $types   = array_keys($this->normalizeTypes($config['types'] ?? []));
        $mimes   = [];

        foreach ($types as $type) {
            $typeCfg = $manager->getTypeConfig($this->collection, $type, $this->model->getMorphClass());
            foreach ($typeCfg['accepted_mime_types'] ?? [] as $mime) {
                $mimes[] = $mime;
            }
        }

        return array_values(array_unique($mimes));
    }

    public function maxSizeKb(): int
    {
        $manager = app(StorageManager::class);
        $config  = $this->model->getCollectionConfig($this->collection);
        $types   = array_keys($this->normalizeTypes($config['types'] ?? []));

        $max = 0;
        foreach ($types as $type) {
            $typeCfg = $manager->getTypeConfig($this->collection, $type, $this->model->getMorphClass());
            $max = max($max, (int) ($typeCfg['max_file_size'] ?? 0));
        }

        return $max ?: 10240;
    }

    public function disk(): string
    {
        return $this->model->getDiskFor($this->collection);
    }

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
     * Detecta la categoría visual del dropzone a partir de las extensiones
     * aceptadas. Para que el theme elija el icono correcto.
     */
    protected function detectIconCategory(array $extensions): string
    {
        if (empty($extensions)) return 'mixed';

        $imageExt = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif', 'svg', 'avif'];
        $videoExt = ['mp4', 'mov', 'webm', 'avi', 'mkv', 'm4v'];
        $audioExt = ['mp3', 'm4a', 'wav', 'ogg', 'webm', 'aac', 'flac'];
        $docExt   = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'csv'];

        $exts = array_map('strtolower', $extensions);
        $allInCategory = fn(array $pool) => !empty(array_intersect($exts, $pool))
            && empty(array_diff($exts, $pool));

        if ($allInCategory($imageExt)) return 'image';
        if ($allInCategory($videoExt)) return 'video';
        if ($allInCategory($audioExt)) return 'audio';
        if ($allInCategory($docExt))   return 'document';
        return 'mixed';
    }

    public function render()
    {
        $theme = $this->theme ?? config('laracrate.ui.default_theme', 'default');

        $view = view()->exists("laracrate::dropzone-single.themes.{$theme}")
            ? "laracrate::dropzone-single.themes.{$theme}"
            : 'laracrate::dropzone-single.themes.default';

        $extensions = $this->acceptedExtensions();
        $existing   = $this->hideExisting ? null : $this->model->files($this->collection)->first();

        return view($view, [
            'config'       => $this->model->getCollectionConfig($this->collection),
            'collection'   => $this->collection,
            'disk'         => $this->disk(),
            'fileableType' => $this->model->getMorphClass(),
            'fileableId'   => $this->model->getKey(),
            'acceptAttr'   => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'extensions'   => $extensions,
            'iconCategory' => $this->detectIconCategory($extensions),
            'maxSizeKb'    => $this->maxSizeKb(),
            'existing'     => $existing,
        ]);
    }
}
