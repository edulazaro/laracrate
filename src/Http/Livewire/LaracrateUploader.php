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
 * General-purpose single-file uploader.
 *
 * Reads the package's collection config (accepted mimes, max size, placeholder,
 * component...) and delegates to the model's HasFiles trait `setFile()`. The
 * visual layer is chosen with the `theme` prop (8 built-in themes, publishable
 * and overridable by the app).
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
     * Buffer for the Livewire temporary upload. When assigned, `updatedUpload`
     * triggers the validation and persistence via `setFile()`.
     */
    public $upload = null;

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

    /**
     * Returns the Tailwind class matching the `rounded` prop value, or an empty
     * string if not passed (each theme falls back to its own default).
     *
     * The classes appear literal in the match() so Tailwind detects them when
     * scanning the package's PHP files.
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

    /** Lifecycle hook: validate and persist the file when the temp upload completes. */
    public function updatedUpload(): void
    {
        if (! $this->upload instanceof TemporaryUploadedFile) {
            return;
        }

        $this->validate(['upload' => $this->validationRules()]);

        // Resolve owner override if passed via prop.
        $owner = null;
        if ($this->ownerType && $this->ownerId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->ownerType)
                ?? $this->ownerType;
            if (class_exists($class)) {
                $owner = $class::find($this->ownerId);
            }
        }

        $file = $this->model->setFile($this->collection, $this->upload, owner: $owner);

        $this->reset('upload');

        if ($file) {
            $this->dispatch(
                'laracrate-file-uploaded',
                collection: $this->collection,
                fileId: $file->id,
            );
        }
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

    /**
     * Current top-level File of the (model + collection). Single mode -> 0 or 1.
     */
    public function currentFile(): ?File
    {
        return $this->model->file($this->collection);
    }

    /**
     * 'idle' (no file) | 'pending' | 'processing' | 'completed' | 'failed'.
     * Drives the polling and the status badge in the themes.
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
     * File URL for preview (variant if available, otherwise master, otherwise
     * the collection's configured placeholder).
     */
    public function previewUrl(): ?string
    {
        return $this->model->fileLink($this->collection, $this->variant);
    }

    /**
     * Livewire rules for `upload`. Built from the collection config: mimes
     * (mapped to extensions), max size in KB.
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
     * Extensions accepted across ALL types declared in the collection.
     * For the input's `accept=` attribute and the `mimes:` validation.
     */
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

    /**
     * Accepted mime types (for the HTML5 `accept=` attribute, stricter than
     * extensions because browsers respect the file picker's mime types).
     */
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

    /**
     * Max size in KB. For single uploaders with one type (avatar, logo, cv...)
     * it is enough; multi-type collections would need to extend the component.
     */
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

    /** Render the uploader theme view. */
    public function render()
    {
        $theme  = $this->theme ?? config('laracrate.ui.default_theme', 'default');
        $layout = $this->layout;

        // Resolution: themes/{theme}/{layout}.blade.php -> themes/{theme}.blade.php
        // (back-compat with single-file themes).
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
