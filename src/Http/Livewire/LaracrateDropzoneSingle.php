<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Concerns\UploaderHasFolderTarget;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Single-file dropzone. Identical to `laracrate-dropzone` in logic
 * (same registerUploaded via addFile, same batchCompleted), but:
 *   - maxFiles forced to 1
 *   - multiple=false
 *   - uses views in `dropzone-single/themes/*` (in-place preview vs queue)
 *   - `hideExisting` prop for cases where the caller renders its own preview
 */
class LaracrateDropzoneSingle extends Component
{
    use UploaderHasFolderTarget;

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
        ?string $contextKey = null,
        bool $hideExisting = false,
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
        $this->folderId     = $folderId;

        // Resolve owner: explicit ownerType/ownerId wins over a Model.
        if ($ownerType && $ownerId) {
            $this->ownerType = $ownerType;
            $this->ownerId   = (int) $ownerId;
        } elseif ($owner instanceof Model) {
            $this->ownerType = $owner->getMorphClass();
            $this->ownerId   = (int) $owner->getKey();
        }
    }

    /** Register an uploaded file as a File of the model. */
    public function registerUploaded(string $key, string $name, string $mime, int $size): ?int
    {
        $upload = FileUpload::fromArray([
            'disk'          => $this->disk(),
            'key'           => $key,
            'mime_type'     => $mime,
            'original_name' => $name,
            'size'          => $size,
        ]);

        // Resolve owner override if passed via prop.
        $owner = null;
        if ($this->ownerType && $this->ownerId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->ownerType)
                ?? $this->ownerType;
            if (class_exists($class)) {
                $owner = $class::find($this->ownerId);
            }
        }

        $file = $this->model->addFile($upload, $this->collection, owner: $owner, folder: $this->folder());

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

    /** Render the single dropzone theme view. */
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
