<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Http\Livewire\Concerns\HasAppearance;
use EduLazaro\Laracrate\Concerns\UploaderHasFolderTarget;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use EduLazaro\Laracrate\Http\Livewire\Concerns\IssuesUploadGrants;
use Livewire\Component;

/**
 * Single-file dropzone, straight to the bucket: an upload replaces the current file.
 * Same upload queue as `laracrate-dropzone`, but one file shown in place.
 *
 *   <livewire:laracrate-dropzone-single :model="$user" collection="cover" />
 *   <livewire:laracrate-dropzone-single :model="$user" collection="cover" :deferred="true" />
 *
 * `deferred` stages the file until the user presses Upload (or the page sends a
 * `laracrate-start-batch` event with a matching `{ contextKey }`). `hideExisting` is for
 * a page that shows the current file itself.
 */
class LaracrateDropzoneSingle extends Component
{
    use IssuesUploadGrants;
    use HasAppearance;
    use UploaderHasFolderTarget;

    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $contextKey = null;

    #[Locked]
    public bool $hideExisting = false;

    /** Stage the file and upload it when the user confirms, instead of at once. */
    #[Locked]
    public bool $deferred = false;

    /**
     * Hides the Upload/Cancel buttons of the staged file: the page starts it with
     * `dispatch('laracrate-start-batch', { contextKey })`.
     */
    #[Locked]
    public bool $hideActions = false;

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

    /** Livewire mount: initialize the component props. */
    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        bool $deferred = false,
        ?string $contextKey = null,
        bool $hideExisting = false,
        bool $hideActions = false,
        ?int $folderId = null,
        mixed $owner = null,
        ?string $ownerType = null,
        ?int $ownerId = null,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->contextKey   = $contextKey;
        $this->hideExisting = $hideExisting;
        $this->deferred     = $deferred;
        $this->hideActions  = $hideActions;
        $this->folderId     = $folderId;

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

    /** Notify that the batch finished (all files processed). */
    public function batchCompleted(int $ok, int $error): void
    {
        $this->dispatch(
            'laracrate-batch-completed',
            collection: $this->collection,
            ok: $ok,
            error: $error,
        );
    }

    /** Single file: an upload replaces the current one, not adds a second. */
    protected function uploadReplaces(): bool
    {
        return true;
    }

    /** Remove the current file of the collection and notify the caller. */
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

    /** Disk configured for this collection. */
    public function disk(): string
    {
        return $this->model->getDiskFor($this->collection);
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

    /** Detect the visual category (image, video, audio, document, mixed) from extensions. */
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
        $extensions = $this->acceptedExtensions();
        $existing   = $this->hideExisting ? null : $this->model->files($this->collection)->first();

        return view('laracrate::dropzone-single', [
            'deferred'     => $this->deferred,
            'hideActions'  => $this->hideActions,
            'appearance'   => $this->appearance(),
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
