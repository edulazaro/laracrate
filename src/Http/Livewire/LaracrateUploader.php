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
 * Uploader single-file de propósito general.
 *
 * Lee la config de la collection del paquete (mime aceptados, tamaño máximo,
 * placeholder, component...) y delega en `setFile()` del trait HasFiles del
 * modelo. La capa visual se elige con la prop `theme` (8 temas built-in,
 * publicables y overridables por la app).
 *
 *   <livewire:laracrate-uploader :model="$user" collection="avatar" />
 *   <livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" variant="medium" />
 */
class LaracrateUploader extends Component
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
     * Buffer del upload temporal de Livewire. Cuando se asigna, `updatedUpload`
     * dispara la validación y la persistencia vía `setFile()`.
     */
    public $upload = null;

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

    /**
     * Devuelve la clase Tailwind correspondiente al valor de la prop `rounded`,
     * o cadena vacía si no se ha pasado (cada tema fallback a su propio default).
     *
     * Las clases aparecen literales en el match() para que Tailwind las
     * detecte al escanear los archivos PHP del paquete.
     */
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

    public function updatedUpload(): void
    {
        if (! $this->upload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate(['upload' => $this->validationRules()]);

        $file = $this->model->setFile($this->collection, $this->upload);

        $this->reset('upload');

        if ($file) {
            $this->dispatch(
                'laracrate-file-uploaded',
                collection: $this->collection,
                fileId: $file->id,
            );
        }
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

    /**
     * File top-level actual de la (model + collection). Single mode → 0 o 1.
     */
    public function currentFile(): ?File
    {
        return $this->model->file($this->collection);
    }

    /**
     * 'idle' (sin file) | 'pending' | 'processing' | 'completed' | 'failed'.
     * Driver del polling y del badge de estado en los temas.
     */
    public function processingState(): string
    {
        $file = $this->currentFile();
        if (! $file) {
            return 'idle';
        }

        return $file->processing_status?->value ?? 'completed';
    }

    /**
     * URL del File para preview (variant si está disponible, sino master,
     * sino placeholder configurado de la collection).
     */
    public function previewUrl(): ?string
    {
        return $this->model->fileLink($this->collection, $this->variant);
    }

    /**
     * Reglas Livewire para `upload`. Se construyen desde el config de la
     * collection: mimes (mapeados a extensiones), tamaño máx en KB.
     */
    protected function validationRules(): array
    {
        $rules = ['file', 'max:' . $this->maxSizeKb()];

        if ($extensions = $this->acceptedExtensions()) {
            $rules[] = 'mimes:' . implode(',', $extensions);
        }

        return $rules;
    }

    /**
     * Extensiones aceptadas por TODOS los types declarados en la collection.
     * Para el atributo `accept=` del input y la validación `mimes:`.
     */
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

    /**
     * Mime types aceptados (para el atributo `accept=` HTML5, más estricto que
     * extensiones porque los browsers respetan mime types del file picker).
     */
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

    /**
     * Tamaño máximo en KB del primer type declarado. Para single uploaders
     * con un solo type (avatar, logo, cv...) es suficiente; collections
     * multi-tipo tendrían que extender el componente.
     */
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

        // Resolución: themes/{theme}/{layout}.blade.php → themes/{theme}.blade.php
        // (back-compat con temas single-file).
        $view = view()->exists("laracrate::uploader.themes.{$theme}.{$layout}")
            ? "laracrate::uploader.themes.{$theme}.{$layout}"
            : "laracrate::uploader.themes.{$theme}";

        return view($view, [
            'config'       => $this->model->getCollectionConfig($this->collection),
            'file'         => $this->currentFile(),
            'state'        => $this->processingState(),
            'previewUrl'   => $this->previewUrl(),
            'acceptAttr'   => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'maxSizeKb'    => $this->maxSizeKb(),
            'pollMs'       => $this->pollMs,
            'roundedClass' => $this->roundedClass(),
        ]);
    }
}
