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
 * Multi-file dropzone with direct upload to R2/S3 via presigned PUT.
 *
 *   <livewire:laracrate-dropzone :model="$organization" collection="gallery" />
 *   <livewire:laracrate-dropzone :model="$user" collection="documents" theme="studio" />
 *
 * Flow (all in the browser, JS in the theme blade):
 *   1. the user drops or selects files
 *   2. for each file: POST /laracrate/uploads/presign
 *   3. PUT directly to R2 with the signed URL
 *   4. on finish, $wire.registerUploaded(key, name, mime, size) creates the
 *      File row via $model->addFile(): the Observer queues the variants
 *   5. dispatch laracrate-file-uploaded per file + laracrate-batch-completed
 *
 * The binary does NOT pass through PHP. The server only signs the URL and records metadata.
 */
class LaracrateDropzone extends Component
{
    use UploaderHasFolderTarget;

    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    /**
     * Allows multiple files. Default true (the typical dropzone case).
     */
    #[Locked]
    public bool $multiple = true;

    /**
     * If true, "done" items stay visible in the queue when finished.
     * If false (default), they disappear after 1.5s. Errors always persist.
     */
    #[Locked]
    public bool $persistQueue = false;

    /**
     * Visual cap of accepted files in the queue. 0/null = unlimited.
     */
    #[Locked]
    public ?int $maxFiles = null;

    /**
     * Opaque identifier the caller associates with the widget. It is included
     * in the `laracrate-file-uploaded` event so the caller can route the File
     * to the right target when there are several widgets on the same page.
     */
    #[Locked]
    public ?string $contextKey = null;

    /** Livewire mount: initialize the component props. */
    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        bool $multiple = true,
        bool $persistQueue = false,
        ?int $maxFiles = null,
        ?string $contextKey = null,
        ?int $folderId = null,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->multiple     = $multiple;
        $this->persistQueue = $persistQueue;
        $this->maxFiles     = ($maxFiles !== null && $maxFiles > 0) ? $maxFiles : null;
        $this->contextKey   = $contextKey;
        $this->folderId     = $folderId;
    }

    /**
     * Registers a file already uploaded to R2 (key) as a File of the model.
     * Called by the blade JS after the PUT to R2 has finished.
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

        $file = $this->model->addFile($upload, $this->collection, folder: $this->folder());

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

    /**
     * Notifies that the batch finished (all files processed).
     * The JS calls it once after the last file of the batch.
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

    /** Render the dropzone theme view. */
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
