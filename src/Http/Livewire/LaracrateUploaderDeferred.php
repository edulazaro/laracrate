<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Uploader single-file con confirmación explícita (preview + Subir).
 *
 * A diferencia de `LaracrateUploader`, seleccionar un archivo NO lo persiste:
 * queda en `$pending` mostrando un preview hasta que el usuario pulsa
 * `submit()`. `cancel()` descarta el buffer sin tocar el File actual.
 *
 *   <livewire:laracrate-uploader-deferred :model="$user" collection="avatar" />
 *   <livewire:laracrate-uploader-deferred :model="$user" collection="avatar" theme="studio" layout="portrait" />
 */
class LaracrateUploaderDeferred extends Component
{
    use WithFileUploads;

    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $variant = null;

    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public string $layout = 'row';

    /**
     * Override de redondeo del preview de la imagen. Valores estándar Tailwind:
     * 'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | 'full'.
     * Si null, cada tema usa su default propio.
     */
    #[Locked]
    public ?string $rounded = null;

    /**
     * Buffer del upload temporal de Livewire. NO se persiste hasta `submit()`.
     */
    public $pending = null;

    /**
     * Cuando el File queda en `pending`/`processing`, el view activa
     * `wire:poll.{ms}ms="$refresh"` para refrescar hasta `completed`/`failed`.
     */
    public int $pollMs = 1500;

    public function mount(
        Model $model,
        string $collection,
        ?string $variant = null,
        ?string $theme = null,
        string $layout = 'row',
        ?string $rounded = null,
    ): void {
        $this->model      = $model;
        $this->collection = $collection;
        $this->variant    = $variant;
        $this->theme      = $theme;
        $this->layout     = $layout;
        $this->rounded    = $rounded;
    }

    public function roundedClass(): string
    {
        return match ($this->rounded) {
            'none'  => 'rounded-none',
            'sm'    => 'rounded-sm',
            'md'    => 'rounded-md',
            'lg'    => 'rounded-lg',
            'xl'    => 'rounded-xl',
            '2xl'   => 'rounded-2xl',
            '3xl'   => 'rounded-3xl',
            'full'  => 'rounded-full',
            default => '',
        };
    }

    /**
     * Validación temprana del buffer cuando Livewire termina de subir el tmp.
     * Si falla, dispara la excepción de validación y resetea el buffer.
     */
    public function updatedPending(): void
    {
        if (! $this->pending instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $this->validate(['pending' => $this->validationRules()]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('pending');

            throw $e;
        }
    }

    /**
     * Persiste el buffer en el modelo. Reemplaza el File actual de la collection.
     */
    public function submit(): void
    {
        if (! $this->pending instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate(['pending' => $this->validationRules()]);

        $file = $this->model->setFile($this->collection, $this->pending);

        $this->reset('pending');

        if ($file) {
            $this->dispatch(
                'laracrate-file-uploaded',
                collection: $this->collection,
                fileId: $file->id,
            );
        }
    }

    /**
     * Descarta el buffer sin tocar el File actual.
     */
    public function cancel(): void
    {
        $this->reset('pending');
        $this->resetErrorBag('pending');
    }

    public function delete(): void
    {
        $file = $this->currentFile();
        if (! $file) {
            return;
        }

        $this->model->setFile($this->collection, null);

        $this->dispatch('laracrate-file-deleted', collection: $this->collection);
    }

    public function currentFile(): ?File
    {
        return $this->model->file($this->collection);
    }

    /**
     * 'idle' (sin file ni buffer) | 'staged' (buffer pendiente de submit)
     * | 'pending' | 'processing' | 'completed' | 'failed'.
     */
    public function processingState(): string
    {
        if ($this->pending instanceof TemporaryUploadedFile) {
            return 'staged';
        }

        $file = $this->currentFile();
        if (! $file) {
            return 'idle';
        }

        return $file->processing_status?->value ?? 'completed';
    }

    /**
     * URL del File actual (si lo hay) o null. El preview del buffer staged
     * se construye en la vista vía `$pending->temporaryUrl()`.
     */
    public function previewUrl(): ?string
    {
        return $this->model->fileLink($this->collection, $this->variant);
    }

    public function pendingPreviewUrl(): ?string
    {
        if (! $this->pending instanceof TemporaryUploadedFile) {
            return null;
        }

        try {
            return $this->pending->temporaryUrl();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function validationRules(): array
    {
        $rules = ['file', 'max:' . $this->maxSizeKb()];

        if ($extensions = $this->acceptedExtensions()) {
            $rules[] = 'mimes:' . implode(',', $extensions);
        }

        return $rules;
    }

    public function acceptedExtensions(): array
    {
        $manager = app(StorageManager::class);
        $config  = $this->model->getCollectionConfig($this->collection);
        $types   = array_keys($this->normalizeTypes($config['types'] ?? []));
        $exts    = [];

        foreach ($types as $type) {
            $typeCfg = $manager->getTypeConfig($this->collection, $type);
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
            $typeCfg = $manager->getTypeConfig($this->collection, $type);
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
            $typeCfg = $manager->getTypeConfig($this->collection, $type);
            $max = max($max, (int) ($typeCfg['max_file_size'] ?? 0));
        }

        return $max ?: 10240;
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
        $theme  = $this->theme ?? config('laracrate.ui.default_theme', 'default');
        $layout = $this->layout;

        // Resolución: uploader-deferred/themes/{theme}/{layout}.blade.php
        // → uploader-deferred/themes/{theme}.blade.php (back-compat single-file).
        $view = view()->exists("laracrate::uploader-deferred.themes.{$theme}.{$layout}")
            ? "laracrate::uploader-deferred.themes.{$theme}.{$layout}"
            : "laracrate::uploader-deferred.themes.{$theme}";

        return view($view, [
            'config'            => $this->model->getCollectionConfig($this->collection),
            'file'              => $this->currentFile(),
            'state'             => $this->processingState(),
            'previewUrl'        => $this->previewUrl(),
            'pendingPreviewUrl' => $this->pendingPreviewUrl(),
            'acceptAttr'        => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'maxSizeKb'         => $this->maxSizeKb(),
            'pollMs'            => $this->pollMs,
            'roundedClass'      => $this->roundedClass(),
        ]);
    }
}
