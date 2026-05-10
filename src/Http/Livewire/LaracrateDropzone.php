<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dropzone multi-archivo con upload directo a R2/S3 vía presigned PUT.
 *
 *   <livewire:laracrate-dropzone :model="$organization" collection="gallery" />
 *   <livewire:laracrate-dropzone :model="$user" collection="documents" theme="studio" />
 *
 * Flujo (todo en navegador, JS en el blade del tema):
 *   1. usuario suelta o selecciona archivos
 *   2. por cada archivo: POST /laracrate/uploads/presign
 *   3. PUT directo a R2 con la URL firmada
 *   4. al terminar, $wire.registerUploaded(key, name, mime, size) crea la fila
 *      File vía $model->addFile() — el Observer dispara variants en cola
 *   5. dispatch laracrate-file-uploaded por cada archivo + laracrate-batch-completed
 *
 * El binario NO pasa por PHP. El servidor solo firma la URL y registra metadata.
 */
class LaracrateDropzone extends Component
{
    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    /**
     * Permite múltiples archivos. Default true (es el caso típico de dropzone).
     */
    #[Locked]
    public bool $multiple = true;

    /**
     * Si true, los items "done" se quedan visibles en la cola al terminar.
     * Si false (default), desaparecen tras 1.5s. Errores siempre persisten.
     */
    #[Locked]
    public bool $persistQueue = false;

    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        bool $multiple = true,
        bool $persistQueue = false,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->multiple     = $multiple;
        $this->persistQueue = $persistQueue;
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

    /**
     * Notifica que el lote terminó (todos los archivos procesados).
     * El JS la llama una vez tras el último archivo del lote.
     */
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

        $view = view()->exists("laracrate::dropzone.themes.{$theme}")
            ? "laracrate::dropzone.themes.{$theme}"
            : 'laracrate::dropzone.themes.default';

        return view($view, [
            'config'       => $this->model->getCollectionConfig($this->collection),
            'disk'         => $this->disk(),
            'fileableType' => $this->model->getMorphClass(),
            'fileableId'   => $this->model->getKey(),
            'acceptAttr'   => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'extensions'   => $this->acceptedExtensions(),
            'maxSizeKb'    => $this->maxSizeKb(),
            'multiple'     => $this->multiple,
            'persistQueue' => $this->persistQueue,
        ]);
    }
}
