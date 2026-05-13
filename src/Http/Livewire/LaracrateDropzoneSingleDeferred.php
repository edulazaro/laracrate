<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dropzone single-file con confirmación explícita antes de subir.
 *
 * Diferencia con `laracrate-dropzone-single`:
 *   - El usuario selecciona/arrastra el archivo, lo ve "staged" (preview
 *     pendiente de confirmar) y pulsa el botón "Subir" para lanzar el PUT.
 *   - Sin confirmación, puede quitarlo y elegir otro.
 *   - Para arrancar desde fuera, dispatch `laracrate-start-batch` con
 *     `{ contextKey }` matching.
 *
 * Igual que `laracrate-dropzone-deferred` pero limitado a 1 archivo, sin
 * lista de queue: el archivo se muestra IN-PLACE.
 *
 *   <livewire:laracrate-dropzone-single-deferred :model="$user" collection="cover" />
 */
class LaracrateDropzoneSingleDeferred extends Component
{
    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public ?string $contextKey = null;

    #[Locked]
    public bool $hideExisting = false;

    /**
     * Oculta el botón "Subir/Cancelar" interno (el caller arranca el batch
     * desde fuera con `dispatch('laracrate-start-batch', { contextKey })`).
     */
    #[Locked]
    public bool $hideActions = false;

    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        ?string $contextKey = null,
        bool $hideExisting = false,
        bool $hideActions = false,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->contextKey   = $contextKey;
        $this->hideExisting = $hideExisting;
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

    /**
     * Callback que el shared `dropzone._script` invoca al terminar el batch.
     * No-op en single — registerUploaded() ya disparó el evento al caller.
     */
    public function batchCompleted(int $ok, int $error): void
    {
        // No-op.
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

        $view = view()->exists("laracrate::dropzone-single-deferred.themes.{$theme}")
            ? "laracrate::dropzone-single-deferred.themes.{$theme}"
            : 'laracrate::dropzone-single-deferred.themes.default';

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
            'hideActions'  => $this->hideActions,
            'existing'     => $existing,
        ]);
    }
}
