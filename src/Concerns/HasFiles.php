<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Actions\Files\CreateFileAction;
use EduLazaro\Laracrate\Actions\Files\DeleteFileAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

trait HasFiles
{
    /**
     * Files de este modelo. Por defecto solo top-level, ordenados por position.
     * Las variants se acceden vía $file->variant('thumbnail') sobre cada File.
     */
    public function files(?string $collection = null): MorphMany
    {
        $query = $this->morphMany(File::class, 'fileable')
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('id');

        return $collection ? $query->where('collection', $collection) : $query;
    }

    public function file(string $collection): ?File
    {
        return $this->files($collection)
            ->orderByDesc('default')
            ->latest()
            ->first();
    }

    public function defaultFile(string $collection): ?File
    {
        return $this->files($collection)->where('default', true)->first();
    }

    public function images(?string $collection = null): MorphMany
    {
        return $this->files($collection)->where('type', 'image');
    }

    public function addFile(
        UploadedFile|FileUpload|string $file,
        string $collection,
        array $metadata = [],
        array $slots = []
    ): ?File {
        $config = $this->getCollectionConfig($collection);

        return CreateFileAction::create()->run([
            'fileable'   => $this,
            'collection' => $collection,
            'config'     => $config,
            'upload'     => $file,
            'metadata'   => $metadata,
            'creator'    => auth()->user(),
            'tenant'     => $this->resolveFileTenant(),
            'slots'      => $slots,
        ]);
    }

    /**
     * Reemplaza el contenido de una collection. Force-deletea los existentes
     * (incluidos sus variants vía cascade del FileObserver) — semánticamente
     * "set" significa sustituir, no archivar.
     */
    public function setFile(
        string $collection,
        UploadedFile|FileUpload|string|null $file,
        array $metadata = []
    ): ?File {
        $existing = $this->files($collection)->get();

        foreach ($existing as $current) {
            $this->deleteFile($current, forceDelete: true);
        }

        if ($file === null) {
            return null;
        }

        return $this->addFile($file, $collection, $metadata);
    }

    public function setDefaultFile(File $file): File
    {
        $this->files($file->collection)
            ->where('id', '!=', $file->id)
            ->update(['default' => false]);

        $file->update(['default' => true]);

        return $file->fresh();
    }

    public function deleteFile(File $file, bool $forceDelete = false): bool
    {
        return (bool) DeleteFileAction::create()->run([
            'file'        => $file,
            'forceDelete' => $forceDelete,
        ]);
    }

    /**
     * URL para render: variant del File real, placeholder configurado, o null.
     *
     *   $user->fileLink('avatar')                          → URL del File o placeholder
     *   $user->fileLink('avatar', 'medium')                → variant medium o placeholder
     *   $user->fileLink('cover', 'preview.thumbnail')      → navegación + fallback
     *   $user->fileLink('cover', 'preview.small', 'image') → forzar tipo
     *
     * Si la colección sólo declara UN tipo en config('types'), $forceType se
     * infiere automáticamente — sólo hace falta pasarlo en colecciones
     * multi-tipo (gallery con image+video, identity con image+document, ...).
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
     * Infiere el tipo único declarado en config('laracrate.collections.X.types').
     * Devuelve el nombre del tipo si la colección sólo acepta uno, null si acepta varios.
     */
    protected function inferCollectionType(string $collection): ?string
    {
        $types = $this->getCollectionConfig($collection)['types'] ?? [];

        if (empty($types)) {
            return null;
        }

        $names = [];
        foreach ($types as $key => $value) {
            // Soporta tanto ['image' => [...]] como ['image', 'video']
            $names[] = is_int($key) ? $value : $key;
        }

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * HTML del componente blade configurado en la colección. El componente
     * recibe `$model` (este modelo) y `$url` (puede ser null si no hay file).
     * Cualquier attr extra se pasa al componente vía $attributes.
     *
     *   $user->fileRender('avatar', 'medium', ['class' => 'w-12 h-12'])
     *
     * Si la colección no declara 'component', devuelve un <img> simple.
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

        // Renderiza <x-{component} :model :url ...attrs />
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

    protected function collectionPlaceholder(string $collection, string $type): ?string
    {
        $resolve = fn ($v) => is_callable($v) ? $v($collection, $type, $this) : $v;
        $config  = $this->getCollectionConfig($collection);

        return $resolve($config['placeholder'] ?? null)
            ?? $resolve(config("laracrate.placeholders.{$type}"))
            ?? $resolve(config('laracrate.placeholders.default'));
    }

    protected function renderAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $k => $v) {
            $parts[] = $k . '="' . e($v) . '"';
        }
        return implode(' ', $parts);
    }

    /**
     * Reordena files de una colección en lote (drag-and-drop).
     * Recibe el array de IDs en el orden deseado; las posiciones se asignan
     * por su índice (0, 1, 2, ...).
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
     * Resolución de la configuración efectiva de una colección.
     *
     * Tres capas, en orden de precedencia (la última gana):
     *   1. base (`config('laracrate.collections.X')`)
     *   2. bloque per-model (`config('laracrate.collections.X.models.{alias}')`)
     *   3. override del modelo (`$this->fileCollections[X]`)
     *
     * Si la colección declara `models` y este modelo no está listado, lanza
     * CollectionNotAllowedForModel.
     */
    public function getCollectionConfig(string $collection): array
    {
        $base     = CollectionConfig::resolve($collection, $this->getMorphClass());
        $override = $this->fileCollections[$collection] ?? [];

        return array_replace_recursive($base, $override);
    }

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

    public function getFile(string $collection): ?File
    {
        return $this->file($collection);
    }

    /**
     * Resolución del tenant del archivo cuando se crea desde este modelo.
     * Cada app puede sobrescribir este método para apuntar a su propio modelo de tenant.
     *
     * Por defecto, intenta en orden:
     *   1. relación $this->tenant() si existe
     *   2. relación $this->organization() si existe
     *   3. atributo organization_id (con clase resuelta vía morphMap o FQCN)
     *   4. null (single-tenant o sin scope)
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
