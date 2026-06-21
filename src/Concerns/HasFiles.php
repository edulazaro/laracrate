<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Actions\Files\CreateFileAction;
use EduLazaro\Laracrate\Actions\Files\DeleteFileAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\Folder;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Plugs file management (add, set, link, render, reorder) into an app model.
 */
trait HasFiles
{
    /**
     * Files of this model. By default only top-level, ordered by position.
     * Variants are accessed via $file->variant('thumbnail') on each File.
     */
    public function files(?string $collection = null): MorphMany
    {
        $query = $this->morphMany(File::class, 'fileable')
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('id');

        return $collection ? $query->where('collection', $collection) : $query;
    }

    /** Returns the primary File of a collection (default first, then latest). */
    public function file(string $collection): ?File
    {
        return $this->files($collection)
            ->orderByDesc('default')
            ->latest()
            ->first();
    }

    /** Returns the File flagged as default in the collection, if any. */
    public function defaultFile(string $collection): ?File
    {
        return $this->files($collection)->where('default', true)->first();
    }

    /** Returns the image Files of this model, optionally scoped to a collection. */
    public function images(?string $collection = null): MorphMany
    {
        return $this->files($collection)->where('type', 'image');
    }

    /**
     * Adds a file to the collection.
     *
     * `$data` accepts keys that are distributed as follows:
     *   - Dedicated columns: `title`, `description`, `category`, `visibility`,
     *     `label`, `default`, `position`. Each one goes to its model column.
     *   - `metadata`: array serialized as-is to the JSON `metadata` column.
     *
     * Any other key in `$data` throws InvalidArgumentException, to avoid silent
     * drops from a typo. If you need to store arbitrary data, put it under
     * `data['metadata']`.
     */
    public function addFile(
        UploadedFile|\EduLazaro\Laracrate\Support\Binary|FileUpload|string $file,
        string $collection,
        array $data = [],
        array $slots = [],
        ?Model $creator = null,
        ?Model $owner = null,
        ?Folder $folder = null,
    ): ?File {
        $config = $this->getCollectionConfig($collection);
        $tenant = $this->resolveFileTenant();

        // Override the disk if the tenant has a dedicated bucket for the
        // base_disk used by this collection. Granularity is per config disk
        // (document/media/attachment), not per individual collection.
        $config['disk'] = $this->resolveTenantBucketDisk($tenant, $config['disk']) ?? $config['disk'];

        // Defense: the target folder must belong to the same fileable.
        // Attaching files to another owner's folders is not allowed.
        if ($folder && (
            $folder->folderable_type !== $this->getMorphClass()
            || (string) $folder->folderable_id !== (string) $this->getKey()
        )) {
            throw new \InvalidArgumentException(
                'The target folder belongs to another fileable.'
            );
        }

        return CreateFileAction::create()->run([
            'fileable'   => $this,
            'collection' => $collection,
            'config'     => $config,
            'upload'     => $file,
            'data'       => $data,
            'creator'    => $creator ?? auth()->user(),
            'owner'      => $owner,
            'tenant'     => $tenant,
            'slots'      => $slots,
            'folder'     => $folder,
        ]);
    }

    /**
     * Returns 'tb:{id}' if the tenant has an active dedicated bucket for the
     * given base_disk. Null = use the flat config disk (shared).
     */
    protected function resolveTenantBucketDisk(?Model $tenant, string $baseDisk): ?string
    {
        if (!$tenant) {
            return null;
        }

        $bucket = \EduLazaro\Laracrate\Models\TenantBucket::query()
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey())
            ->where('base_disk', $baseDisk)
            ->where('is_active', true)
            ->first();

        return $bucket ? 'tb:' . $bucket->id : null;
    }

    /**
     * Replaces the content of a collection. Force-deletes the existing ones
     * (including their variants via the FileObserver cascade): semantically
     * "set" means replace, not archive.
     */
    public function setFile(
        string $collection,
        UploadedFile|\EduLazaro\Laracrate\Support\Binary|FileUpload|string|null $file,
        array $data = [],
        ?Model $creator = null,
        ?Model $owner = null,
    ): ?File {
        $existing = $this->files($collection)->get();

        foreach ($existing as $current) {
            $this->deleteFile($current, forceDelete: true);
        }

        if ($file === null) {
            return null;
        }

        return $this->addFile($file, $collection, $data, creator: $creator, owner: $owner);
    }

    /** Marks the given File as the collection default and unsets the others. */
    public function setDefaultFile(File $file): File
    {
        $this->files($file->collection)
            ->where('id', '!=', $file->id)
            ->update(['default' => false]);

        $file->update(['default' => true]);

        return $file->fresh();
    }

    /** Deletes a File (soft by default, force-delete when requested). */
    public function deleteFile(File $file, bool $forceDelete = false): bool
    {
        return (bool) DeleteFileAction::create()->run([
            'file'        => $file,
            'forceDelete' => $forceDelete,
        ]);
    }

    /**
     * URL for rendering: variant of the real File, configured placeholder, or null.
     *
     *   $user->fileLink('avatar')                          → File URL or placeholder
     *   $user->fileLink('avatar', 'medium')                → medium variant or placeholder
     *   $user->fileLink('cover', 'preview.thumbnail')      → navigation + fallback
     *   $user->fileLink('cover', 'preview.small', 'image') → force type
     *
     * If the collection declares only ONE type in config('types'), $forceType is
     * inferred automatically: it only needs to be passed in multi-type
     * collections (gallery with image+video, identity with image+document, ...).
     */
    public function fileLink(string $collection, ?string $variant = null, ?string $forceType = null): ?string
    {
        $type = $forceType ?? $this->inferCollectionType($collection);
        $file = $this->file($collection);

        if ($file) {
            $resolved = $variant ? $file->variant($variant) : $file;
            if ($url = $resolved->url($type)) {
                return $url;
            }
        }

        return $this->collectionPlaceholder($collection, $type ?? 'image');
    }

    /**
     * Infers the single type declared in config('laracrate.collections.X.types').
     * Returns the type name if the collection accepts only one, null if it accepts several.
     */
    protected function inferCollectionType(string $collection): ?string
    {
        $types = $this->getCollectionConfig($collection)['types'] ?? [];

        if (empty($types)) {
            return null;
        }

        $names = [];
        foreach ($types as $key => $value) {
            // Supports both ['image' => [...]] and ['image', 'video']
            $names[] = is_int($key) ? $value : $key;
        }

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * HTML of the blade component configured in the collection. The component
     * receives `$model` (this model) and `$url` (can be null if there is no file).
     * Any extra attr is passed to the component via $attributes.
     *
     *   $user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12'])
     *
     * If the collection does not declare 'component', it returns a plain <img>.
     */
    public function fileRender(string $collection, ?string $variant = null, array $attrs = []): HtmlString
    {
        $config    = $this->getCollectionConfig($collection);
        $component = $config['component'] ?? null;
        $url       = $this->fileLink($collection, $variant);

        if (!$component) {
            if (!$url) {
                return new HtmlString('');
            }
            $attrString = $this->renderAttrs(array_merge(['src' => $url], $attrs));
            return new HtmlString("<img {$attrString}>");
        }

        // Renders <x-{component} :model :url ...attrs />
        $attrBindings = '';
        foreach ($attrs as $k => $v) {
            $attrBindings .= ' ' . $k . '="' . e($v) . '"';
        }

        $html = Blade::render(
            "<x-{$component} :model=\$model :url=\$url{$attrBindings} />",
            ['model' => $this, 'url' => $url]
        );

        return new HtmlString($html);
    }

    /** Resolves the placeholder URL for a collection/type, supporting callables. */
    protected function collectionPlaceholder(string $collection, string $type): ?string
    {
        $resolve = fn ($v) => is_callable($v) ? $v($collection, $type, $this) : $v;
        $config  = $this->getCollectionConfig($collection);

        return $resolve($config['placeholder'] ?? null)
            ?? $resolve(config("laracrate.placeholders.{$type}"))
            ?? $resolve(config('laracrate.placeholders.default'));
    }

    /** Builds an escaped HTML attribute string from a key/value array. */
    protected function renderAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '="' . e($v) . '"';
        }
        return implode(' ', $parts);
    }

    /**
     * Reorders files of a collection in bulk (drag-and-drop).
     * Receives the array of IDs in the desired order; positions are assigned
     * by their index (0, 1, 2, ...).
     */
    public function reorderFiles(string $collection, array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            File::query()
                ->where('id', $id)
                ->where('fileable_type', $this->getMorphClass())
                ->where('fileable_id', $this->getKey())
                ->where('collection', $collection)
                ->whereNull('parent_id')
                ->update(['position' => (int) $position]);
        }
    }

    /**
     * Resolution of the effective configuration of a collection.
     *
     * Three layers, in order of precedence (the last one wins):
     *   1. base (`config('laracrate.collections.X')`)
     *   2. per-model block (`config('laracrate.collections.X.models.{alias}')`)
     *   3. model override (`$this->fileCollections[X]`)
     *
     * If the collection declares `models` and this model is not listed, it
     * throws CollectionNotAllowedForModel.
     */
    public function getCollectionConfig(string $collection): array
    {
        $base     = CollectionConfig::resolve($collection, $this->getMorphClass());
        $override = $this->fileCollections[$collection] ?? [];

        return array_replace_recursive($base, $override);
    }

    /** Returns the disk declared for a collection, or throws if none is set. */
    public function getDiskFor(string $collection): string
    {
        $config = $this->getCollectionConfig($collection);

        if (empty($config['disk'])) {
            throw new \RuntimeException(
                "Laracrate collection [{$collection}] does not declare a disk. ".
                "Set it in config('laracrate.collections.{$collection}.disk') ".
                "or override via \$fileCollections on the model."
            );
        }

        return $config['disk'];
    }

    /** Alias of file(): returns the primary File of a collection. */
    public function getFile(string $collection): ?File
    {
        return $this->file($collection);
    }

    /**
     * Resolution of the file tenant when created from this model.
     * Each app can override this method to point to its own tenant model.
     *
     * By default, it tries in order:
     *   1. relation $this->tenant() if it exists
     *   2. relation $this->organization() if it exists
     *   3. attribute organization_id (with class resolved via morphMap or FQCN)
     *   4. null (single-tenant or without scope)
     */
    public function resolveFileTenant(): ?Model
    {
        if (method_exists($this, 'tenant')) {
            $tenant = $this->tenant;
            if ($tenant instanceof Model) {
                return $tenant;
            }
        }

        if (method_exists($this, 'organization')) {
            $org = $this->organization;
            if ($org instanceof Model) {
                return $org;
            }
        }

        return null;
    }
}
