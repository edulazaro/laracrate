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
 * Single-file uploader with explicit confirmation (preview + Upload).
 *
 * Unlike `LaracrateUploader`, selecting a file does NOT persist it: it stays
 * in `$pending` showing a preview until the user presses `submit()`. `cancel()`
 * discards the buffer without touching the current File.
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
     * Override for the image preview rounding. Standard Tailwind values:
     * 'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | 'full'.
     * If null, each theme uses its own default.
     */
    #[Locked]
    public ?string $rounded = null;

    /**
     * Polymorphic owner ("semantic owner / recipient of the file"). Differs from the
     * creator when someone uploads on behalf of another (e.g. a lawyer uploads a
     * document that belongs to a client). If not passed, the file has no explicit
     * owner and effectiveOwner() falls back to the creator.
     */
    #[Locked]
    public ?string $ownerType = null;
    #[Locked]
    public ?int $ownerId = null;

    /**
     * Buffer for the Livewire temporary upload. NOT persisted until `submit()`.
     */
    public $pending = null;

    /**
     * When the File stays in `pending`/`processing`, the view activates
     * `wire:poll.{ms}ms="$refresh"` to refresh until `completed`/`failed`.
     */
    public int $pollMs = 1500;

    /** Livewire mount: initialize the component props. */
    public function mount(
        Model $model,
        string $collection,
        ?string $variant = null,
        ?string $theme = null,
        string $layout = 'row',
        ?string $rounded = null,
        mixed $owner = null,
        ?string $ownerType = null,
        ?int $ownerId = null,
    ): void {
        $this->model      = $model;
        $this->collection = $collection;
        $this->variant    = $variant;
        $this->theme      = $theme;
        $this->layout     = $layout;
        $this->rounded    = $rounded;

        // Resolve owner: explicit ownerType/ownerId wins over a Model.
        if ($ownerType && $ownerId) {
            $this->ownerType = $ownerType;
            $this->ownerId   = (int) $ownerId;
        } elseif ($owner instanceof Model) {
            $this->ownerType = $owner->getMorphClass();
            $this->ownerId   = (int) $owner->getKey();
        }
    }

    /** Tailwind rounding class for the `rounded` prop, or empty string if unset. */
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
     * Early validation of the buffer when Livewire finishes uploading the tmp.
     * If it fails, it throws the validation exception and resets the buffer.
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
     * Persists the buffer to the model. Replaces the current File of the collection.
     */
    public function submit(): void
    {
        if (! $this->pending instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate(['pending' => $this->validationRules()]);

        // Resolve owner override if passed via prop.
        $owner = null;
        if ($this->ownerType && $this->ownerId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->ownerType)
                ?? $this->ownerType;
            if (class_exists($class)) {
                $owner = $class::find($this->ownerId);
            }
        }

        $file = $this->model->setFile($this->collection, $this->pending, owner: $owner);

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
     * Discards the buffer without touching the current File.
     */
    public function cancel(): void
    {
        $this->reset('pending');
        $this->resetErrorBag('pending');
    }

    /** Delete the current file of the collection. */
    public function delete(): void
    {
        $file = $this->currentFile();
        if (! $file) {
            return;
        }

        $this->model->setFile($this->collection, null);

        $this->dispatch('laracrate-file-deleted', collection: $this->collection);
    }

    /** Current top-level File of the (model + collection), or null. */
    public function currentFile(): ?File
    {
        return $this->model->file($this->collection);
    }

    /**
     * 'idle' (no file or buffer) | 'staged' (buffer pending submit)
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
     * URL of the current File (if any) or null. The staged buffer preview is
     * built in the view via `$pending->temporaryUrl()`.
     */
    public function previewUrl(): ?string
    {
        return $this->model->fileLink($this->collection, $this->variant);
    }

    /** Temporary preview URL for the staged buffer, or null. */
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

    /** Livewire validation rules for the pending buffer (mimes + max size). */
    protected function validationRules(): array
    {
        $rules = ['file', 'max:' . $this->maxSizeKb()];

        if ($extensions = $this->acceptedExtensions()) {
            $rules[] = 'mimes:' . implode(',', $extensions);
        }

        return $rules;
    }

    /** Extensions accepted across all types declared in the collection. */
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

    /** Mime types accepted across all types declared in the collection. */
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

    /** Largest max file size (in KB) across the collection's types. */
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

    /** Normalize the collection types config to an associative map. */
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

    /** Render the deferred uploader theme view. */
    public function render()
    {
        $theme  = $this->theme ?? config('laracrate.ui.default_theme', 'default');
        $layout = $this->layout;

        // Resolution: uploader-deferred/themes/{theme}/{layout}.blade.php
        // -> uploader-deferred/themes/{theme}.blade.php (back-compat single-file).
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
