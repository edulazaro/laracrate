<?php

namespace EduLazaro\Laracrate\Http\Livewire;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Dropzone multi-archivo con confirmación explícita: el usuario suelta o
 * selecciona archivos, los ve en la cola con previews, y arranca el lote
 * pulsando un botón "Subir todo".
 *
 *   <livewire:laracrate-dropzone-deferred :model="$organization" collection="gallery" />
 *   <livewire:laracrate-dropzone-deferred :model="$user" collection="documents" theme="studio" />
 *
 * A diferencia de `laracrate-dropzone` (instant), seleccionar archivos NO los
 * sube automáticamente. Permite revisar la cola, eliminar items antes de subir
 * y confirmar el lote completo. El binario tampoco pasa por PHP — cuando se
 * pulsa Subir, el JS hace presigned PUT directo a R2 igual que el instant.
 */
class LaracrateDropzoneDeferred extends Component
{
    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public bool $multiple = true;

    /**
     * Si true, los items "done" se quedan visibles tras la subida.
     * Si false (default), desaparecen tras 1.5s. Errores siempre persisten.
     */
    #[Locked]
    public bool $persistQueue = false;

    /**
     * Oculta los botones internos de "Subir/Cancelar" del tema. Útil cuando el
     * trigger del batch vive fuera (footer de un modal). Para arrancar desde
     * fuera dispatch `laracrate-start-batch` con detail `{ collection, fileableId }`.
     */
    #[Locked]
    public bool $hideActions = false;

    /**
     * Disposición de la cola. 'grid' (default) o 'list'. Cada tema puede
     * implementarlo a su manera; los que no lo declaran usan grid.
     */
    #[Locked]
    public string $layout = 'grid';

    /**
     * Tope máximo de archivos aceptados en la cola. 0 o null = ilimitado.
     * El Alpine factory rechaza extras y notifica vía evento `laracrate-max-files`.
     */
    #[Locked]
    public ?int $maxFiles = null;

    /**
     * IDs de FileSlot a los que se atará cada archivo subido. Si se pasa,
     * CreateFileAction valida acceptsExtension + canAcceptMore antes de crear,
     * y el front auto-deriva maxFiles y acceptedExtensions del slot.
     */
    #[Locked]
    public array $slots = [];

    /**
     * Creator polimórfico ("a quien se le atribuye el archivo"). Si no se
     * pasa, default a auth()->user(). Útil cuando un admin sube en nombre
     * de otro usuario: las quotas del slot se cuentan contra ese usuario.
     */
    #[Locked]
    public ?string $creatorType = null;
    #[Locked]
    public ?int $creatorId = null;

    public function mount(
        Model $model,
        string $collection,
        ?string $theme = null,
        bool $multiple = true,
        bool $persistQueue = false,
        bool $hideActions = false,
        string $layout = 'grid',
        ?int $maxFiles = null,
        array $slots = [],
        mixed $creator = null,
        ?string $creatorType = null,
        ?int $creatorId = null,
    ): void {
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->multiple     = $multiple;
        $this->persistQueue = $persistQueue;
        $this->hideActions  = $hideActions;
        $this->layout       = in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
        $this->maxFiles     = ($maxFiles !== null && $maxFiles > 0) ? $maxFiles : null;
        $this->slots        = array_values(array_filter(array_map('intval', $slots)));

        // Resolver creator: prioriza creatorType/creatorId explícitos (más
        // fiables que serializar un Model entre componentes Livewire).
        if ($creatorType && $creatorId) {
            $this->creatorType = $creatorType;
            $this->creatorId   = (int) $creatorId;
        } elseif ($creator instanceof Model) {
            $this->creatorType = $creator->getMorphClass();
            $this->creatorId   = (int) $creator->getKey();
        }
    }

    /**
     * Permite cambiar dinámicamente los slots desde el front (ej. al cambiar
     * el selector de slot en una modal). Envía evento Livewire `set-slots`
     * con `slots: [id, id, ...]` y el componente actualiza el listado.
     */
    public function setSlots(array $slots): void
    {
        $this->slots = array_values(array_filter(array_map('intval', $slots)));
    }

    public function registerUploaded(string $key, string $name, string $mime, int $size): ?int
    {
        $upload = FileUpload::fromArray([
            'disk'          => $this->disk(),
            'key'           => $key,
            'mime_type'     => $mime,
            'original_name' => $name,
            'size'          => $size,
        ]);

        // Resolver creator override si se pasó vía prop
        $creator = null;
        if ($this->creatorType && $this->creatorId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->creatorType)
                ?? $this->creatorType;
            if (class_exists($class)) {
                $creator = $class::find($this->creatorId);
            }
        }

        // CreateFileAction puede lanzar InvalidArgumentException por:
        //   - MIME/extensión no aceptada por la colección o el slot
        //   - Slot quota agotada (per_creator o global)
        //   - Tamaño excedido
        // Lo capturamos y devolvemos null para que el front trate el item como
        // error normal (sin 500). El mensaje se manda en el evento para UX.
        try {
            $file = $this->model->addFile(
                $upload,
                $this->collection,
                slots: $this->slots,
                creator: $creator,
            );
        } catch (\InvalidArgumentException $e) {
            $this->dispatch(
                'laracrate-file-rejected',
                collection: $this->collection,
                reason: $e->getMessage(),
                fileName: $name,
            );
            return null;
        }

        if (! $file) {
            return null;
        }

        $this->dispatch(
            'laracrate-file-uploaded',
            collection: $this->collection,
            fileId: $file->id,
        );

        return $file->id;
    }

    public function batchCompleted(int $ok, int $error): void
    {
        $this->dispatch(
            'laracrate-batch-completed',
            collection: $this->collection,
            ok: $ok,
            error: $error,
        );
    }

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

    public function disk(): string
    {
        return $this->model->getDiskFor($this->collection);
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

    /**
     * Calcula el cap efectivo y las extensiones efectivas a partir de los slots.
     * El cap más restrictivo gana. Cuenta los archivos ya subidos por este creator
     * en cada slot para descontarlos del límite per_creator.
     *
     * @return array{maxFiles: ?int, extensions: array, slotInfo: array}
     */
    protected function computeEffective(): array
    {
        $effectiveMaxFiles    = $this->maxFiles;
        $effectiveExtensions  = $this->acceptedExtensions();
        $slotInfo             = [];

        if (!empty($this->slots)) {
            $slotModels = \EduLazaro\Laracrate\Models\FileSlot::whereIn('id', $this->slots)->get();

            foreach ($slotModels as $slot) {
                if ($slot->max_files_per_creator !== null) {
                    $used = $slot->uploadedCount($this->creatorType, $this->creatorId);
                    $remaining = max(0, $slot->max_files_per_creator - $used);
                    $effectiveMaxFiles = $effectiveMaxFiles === null
                        ? $remaining
                        : min($effectiveMaxFiles, $remaining);
                }

                if (!empty($slot->allowed_extensions)) {
                    $slotExts = array_map('strtolower', $slot->allowed_extensions);
                    $effectiveExtensions = empty($effectiveExtensions)
                        ? $slotExts
                        : array_values(array_intersect($effectiveExtensions, $slotExts));
                }

                $slotInfo[] = [
                    'id'    => $slot->id,
                    'name'  => $slot->name,
                    'color' => $slot->color,
                ];
            }
        }

        return [
            'maxFiles'   => $effectiveMaxFiles,
            'extensions' => $effectiveExtensions,
            'slotInfo'   => $slotInfo,
        ];
    }

    public function render()
    {
        $theme = $this->theme ?? config('laracrate.ui.default_theme', 'default');

        $view = view()->exists("laracrate::dropzone-deferred.themes.{$theme}")
            ? "laracrate::dropzone-deferred.themes.{$theme}"
            : 'laracrate::dropzone-deferred.themes.default';

        $effective = $this->computeEffective();

        // Notifica al front el cap efectivo en cada render. El theme escucha
        // `laracrate-deferred-config` con `{ fileableType, fileableId, collection,
        // maxFiles }` y actualiza `cfg.maxFiles` reactivamente, sin depender del
        // remount por key. Solución a casos donde Livewire morphdom mantiene el
        // Alpine state estable entre cambios de slot.
        $this->dispatch(
            'laracrate-deferred-config',
            fileableType: $this->model->getMorphClass(),
            fileableId: (string) $this->model->getKey(),
            collection: $this->collection,
            maxFiles: $effective['maxFiles'],
        );

        return view($view, [
            'config'       => $this->model->getCollectionConfig($this->collection),
            'collection'   => $this->collection,
            'disk'         => $this->disk(),
            'fileableType' => $this->model->getMorphClass(),
            'fileableId'   => $this->model->getKey(),
            'acceptAttr'   => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'extensions'   => $effective['extensions'],
            'maxSizeKb'    => $this->maxSizeKb(),
            'multiple'     => $this->multiple,
            'persistQueue' => $this->persistQueue,
            'hideActions'  => $this->hideActions,
            'layout'       => $this->layout,
            'maxFiles'     => $effective['maxFiles'],
            'slotInfo'     => $effective['slotInfo'],
        ]);
    }
}
