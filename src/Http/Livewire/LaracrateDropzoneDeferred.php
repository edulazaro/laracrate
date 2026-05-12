<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dropzone multi-archivo con confirmación explícita: el usuario suelta o
 * selecciona archivos, los ve en la cola con previews, y arranca el lote
 * pulsando un botón "Subir todo".
 *
 *   <livewire:laracrate-dropzone-deferred :model="$organization" collection="gallery" />
 *   <livewire:laracrate-dropzone-deferred :model="$user" collection="documents" theme="studio" />
 *
 * A diferencia de `laracrate-dropzone` (instant), seleccionar archivos NO los
 * sube automáticamente. Permite revisar la cola, eliminar items antes de subir
 * y confirmar el lote completo. El binario tampoco pasa por PHP — cuando se
 * pulsa Subir, el JS hace presigned PUT directo a R2 igual que el instant.
 */
class LaracrateDropzoneDeferred extends Component
{
    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public bool $multiple = true;

    /**
     * Si true, los items "done" se quedan visibles tras la subida.
     * Si false (default), desaparecen tras 1.5s. Errores siempre persisten.
     */
    #[Locked]
    public bool $persistQueue = false;

    /**
     * Oculta los botones internos de "Subir/Cancelar" del tema. Útil cuando el
     * trigger del batch vive fuera (footer de un modal). Para arrancar desde
     * fuera dispatch `laracrate-start-batch` con detail `{ collection, fileableId }`.
     */
    #[Locked]
    public bool $hideActions = false;

    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        bool $multiple = true,
        bool $persistQueue = false,
        bool $hideActions = false,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->multiple     = $multiple;
        $this->persistQueue = $persistQueue;
        $this->hideActions  = $hideActions;
    }

    public function registerUploaded(string $key, string $name, string $mime, int $size): ?int
    {
        $upload = FileUpload::fromArray([
            'disk'          => $this->disk(),
            'key'           => $key,
            'mime_type'     => $mime,
            'original_name' => $name,
            'size'          => $size,
        ]);

        $file = $this->model->addFile($upload, $this->collection);

        if (! $file) {
            return null;
        }

        $this->dispatch(
            'laracrate-file-uploaded',
            collection: $this->collection,
            fileId: $file->id,
        );

        return $file->id;
    }

    public function batchCompleted(int $ok, int $error): void
    {
        $this->dispatch(
            'laracrate-batch-completed',
            collection: $this->collection,
            ok: $ok,
            error: $error,
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

    public function render()
    {
        $theme = $this->theme ?? config('laracrate.ui.default_theme', 'default');

        $view = view()->exists("laracrate::dropzone-deferred.themes.{$theme}")
            ? "laracrate::dropzone-deferred.themes.{$theme}"
            : 'laracrate::dropzone-deferred.themes.default';

        return view($view, [
            'config'       => $this->model->getCollectionConfig($this->collection),
            'collection'   => $this->collection,
            'disk'         => $this->disk(),
            'fileableType' => $this->model->getMorphClass(),
            'fileableId'   => $this->model->getKey(),
            'acceptAttr'   => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'extensions'   => $this->acceptedExtensions(),
            'maxSizeKb'    => $this->maxSizeKb(),
            'multiple'     => $this->multiple,
            'persistQueue' => $this->persistQueue,
            'hideActions'  => $this->hideActions,
        ]);
    }
}
