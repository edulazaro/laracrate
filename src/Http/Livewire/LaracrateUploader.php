<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Http\Livewire\Concerns\HasAppearance;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use EduLazaro\Laracrate\Http\Livewire\Concerns\IssuesUploadGrants;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * General-purpose single-file uploader: an avatar, a logo. An upload replaces the
 * current file of the collection, whose rules (mimes, max size) it reads.
 *
 *   <livewire:laracrate-uploader :model="$user" collection="avatar" />
 *   <livewire:laracrate-uploader :model="$user" collection="avatar" theme="ios" variant="medium" />
 *   <livewire:laracrate-uploader :model="$user" collection="avatar" :deferred="true" layout="portrait" />
 *
 * By default the file goes to the page's upload queue as soon as it is picked, so it
 * finishes after a wire:navigate. `deferred` stages it with Livewire's temporary
 * upload instead, shows its preview and only saves it on submit(); cancel() drops it.
 */
class LaracrateUploader extends Component
{
    use IssuesUploadGrants;
    use HasAppearance;
    use WithFileUploads;

    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $variant = null;

    #[Locked]
    public string $layout = 'row';

    /** Stage the file with a preview and save it on submit(), instead of at once. */
    #[Locked]
    public bool $deferred = false;

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
     * Deferred only: the file staged with Livewire's temporary upload, not saved until
     * submit(). It is the file input's wire:model, so the browser sets it.
     */
    public $pending = null;

    /**
     * When the File stays in `pending`/`processing`, the view activates
     * `wire:poll.{ms}ms="$refresh"` to refresh until `completed`/`failed`.
     */
    #[Locked]
    public int $pollMs = 1500;

    /** Single file: an upload replaces the current one. */
    protected function uploadReplaces(): bool
    {
        return true;
    }

    /**
     * Through PHP, as with wire:model before: no CORS needed on the bucket, and the
     * collections that encrypt (identity documents) can only be uploaded this way.
     */
    protected function uploadTransport(): string
    {
        return 'server';
    }

    /**
     * Called by the upload engine when the server refuses the file, so the themes'
     * @error('upload') shows the reason as it did with wire:model.
     */
    public function reportUploadError(string $message): void
    {
        $this->addError('upload', mb_substr($message, 0, 300));
    }

    public function mount(
        Model $model,
        string $collection,
        ?string $variant = null,
        ?string $theme = null,
        bool $deferred = false,
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
        $this->deferred   = $deferred;
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

        $this->refreshUploadGrant();
    }

    /** Deferred: checks the staged file as soon as Livewire has it, and drops it if refused. */
    public function updatedPending(): void
    {
        if (! $this->deferred || ! $this->pending instanceof TemporaryUploadedFile) {
            $this->reset('pending');

            return;
        }

        try {
            $this->validate(['pending' => $this->validationRules()]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->reset('pending');

            throw $e;
        }
    }

    /** Deferred: saves the staged file, replacing the current one. */
    public function submit(): void
    {
        if (! $this->deferred || ! $this->pending instanceof TemporaryUploadedFile) {
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

    /** Deferred: drops the staged file without touching the current one. */
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

    /**
     * Current top-level File of the (model + collection). Single mode -> 0 or 1.
     */
    public function currentFile(): ?File
    {
        return $this->model->file($this->collection);
    }

    /**
     * 'staged' (deferred, waiting for submit) | 'idle' (no file) | 'pending' |
     * 'processing' | 'completed' | 'failed'. Drives the polling and the status badge.
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
     * File URL for preview (variant if available, otherwise master, otherwise
     * the collection's configured placeholder).
     */
    public function previewUrl(): ?string
    {
        return $this->model->fileLink($this->collection, $this->variant);
    }

    /** Deferred: the staged file's temporary URL, for its preview. */
    public function pendingPreviewUrl(): ?string
    {
        if (! $this->pending instanceof TemporaryUploadedFile) {
            return null;
        }

        try {
            return $this->pending->temporaryUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Livewire rules for the staged file. Built from the collection config: mimes
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

    /** `rounded` rounds the preview (an avatar in a circle), not the card around it. */
    protected function roundedVariable(): string
    {
        return '--wire-laracrate-preview-radius';
    }

    public function render()
    {
        return view('laracrate::uploader', [
            'deferred'          => $this->deferred,
            'appearance'        => $this->appearance(),
            'config'            => $this->model->getCollectionConfig($this->collection),
            'file'              => $this->currentFile(),
            'state'             => $this->processingState(),
            'previewUrl'        => $this->previewUrl(),
            'pendingPreviewUrl' => $this->pendingPreviewUrl(),
            'acceptAttr'        => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'maxSizeKb'         => $this->maxSizeKb(),
            'pollMs'            => $this->pollMs,
        ]);
    }
}
