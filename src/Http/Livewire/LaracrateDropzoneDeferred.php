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
 * Multi-file dropzone with explicit confirmation: the user drops or selects
 * files, sees them in the queue with previews, and starts the batch by
 * pressing an "Upload all" button.
 *
 *   <livewire:laracrate-dropzone-deferred :model="$organization" collection="gallery" />
 *   <livewire:laracrate-dropzone-deferred :model="$user" collection="documents" theme="studio" />
 *
 * Unlike `laracrate-dropzone` (instant), selecting files does NOT upload them
 * automatically. It allows reviewing the queue, removing items before upload
 * and confirming the whole batch. The binary does not pass through PHP either:
 * when Upload is pressed, the JS does a presigned PUT directly to R2 like the instant one.
 */
class LaracrateDropzoneDeferred extends Component
{
    use UploaderHasFolderTarget;

    #[Locked]
    public Model $model;

    #[Locked]
    public string $collection;

    #[Locked]
    public ?string $theme = null;

    #[Locked]
    public bool $multiple = true;

    /**
     * If true, "done" items stay visible after the upload.
     * If false (default), they disappear after 1.5s. Errors always persist.
     */
    #[Locked]
    public bool $persistQueue = false;

    /**
     * Hides the theme's internal "Upload/Cancel" buttons. Useful when the
     * batch trigger lives outside (the footer of a modal). To start from
     * outside, dispatch `laracrate-start-batch` with detail `{ collection, fileableId }`.
     */
    #[Locked]
    public bool $hideActions = false;

    /**
     * Queue layout. 'grid' (default) or 'list'. Each theme may implement it
     * its own way; those that do not declare it use grid.
     */
    #[Locked]
    public string $layout = 'grid';

    /**
     * Maximum cap of accepted files in the queue. 0 or null = unlimited.
     * The Alpine factory rejects extras and notifies via the `laracrate-max-files` event.
     */
    #[Locked]
    public ?int $maxFiles = null;

    /**
     * IDs of the FileSlot each uploaded file will be attached to. If passed,
     * CreateFileAction validates acceptsExtension + canAcceptMore before creating,
     * and the front auto-derives maxFiles and acceptedExtensions from the slot.
     */
    #[Locked]
    public array $slots = [];

    /**
     * Polymorphic creator ("who the file is attributed to"). If not passed,
     * defaults to auth()->user(). Useful when an admin uploads on behalf of
     * another user: the slot quotas are counted against that user.
     */
    #[Locked]
    public ?string $creatorType = null;
    #[Locked]
    public ?int $creatorId = null;

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
     * Opaque identifier the caller associates with the widget. It is included
     * in the `laracrate-file-uploaded` event so the caller can route the File
     * to the right target when there are several widgets on the same page
     * (e.g. one field per instance in a form-builder).
     */
    #[Locked]
    public ?string $contextKey = null;

    /**
     * Options for the slot selector integrated in the component.
     * If empty, the selector is not rendered and the behavior is the classic
     * one (cap derived from `$slots` passed as a prop).
     *
     * Format: array of arrays with keys `id`, `name`, optionally `color`,
     * `description`. Example:
     *   [
     *       ['id' => 60, 'name' => 'DNI'],
     *       ['id' => 61, 'name' => 'Contract', 'color' => '#7C2D12'],
     *   ]
     *
     * When an option is selected, `$slots = [id]` automatically and
     * `computeEffective()` recomputes maxFiles + allowed extensions.
     */
    #[Locked]
    public array $slotOptions = [];

    /**
     * Label above the selector. If null, no visible label is rendered.
     * E.g.: "Document type", "Category", "Tag".
     */
    #[Locked]
    public ?string $slotLabel = null;

    /**
     * Placeholder text for the selector when no slot is chosen. If the UI
     * allows it, it also works as a "no slot" option. Default: "Unclassified" (i18n).
     */
    #[Locked]
    public ?string $slotPlaceholder = null;

    /**
     * If true, the selector allows "no slot" as an explicit option; if false,
     * it forces choosing one before files can be dropped (the dropzone stays
     * disabled while there is no selection). Default: true.
     */
    #[Locked]
    public bool $slotOptional = true;

    /**
     * ID of the currently selected slot. Reactive: it changes from the theme's
     * selector via wire:model.live. It triggers `updatedSelectedSlotId`, which
     * syncs `$this->slots = [id]`.
     */
    public ?int $selectedSlotId = null;

    /** Livewire mount: initialize props, resolve creator and slot picker. */
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
        mixed $owner = null,
        ?string $ownerType = null,
        ?int $ownerId = null,
        array $slotOptions = [],
        ?string $slotLabel = null,
        ?string $slotPlaceholder = null,
        bool $slotOptional = true,
        ?int $selectedSlotId = null,
        ?string $contextKey = null,
        ?int $folderId = null,
    ): void {
        $this->contextKey = $contextKey;
        $this->folderId   = $folderId;
        $this->model        = $model;
        $this->collection   = $collection;
        $this->theme        = $theme;
        $this->multiple     = $multiple;
        $this->persistQueue = $persistQueue;
        $this->hideActions  = $hideActions;
        $this->layout       = in_array($layout, ['grid', 'list'], true) ? $layout : 'grid';
        $this->maxFiles     = ($maxFiles !== null && $maxFiles > 0) ? $maxFiles : null;
        $this->slots        = array_values(array_filter(array_map('intval', $slots)));

        // Resolve creator: prioritize explicit creatorType/creatorId (more
        // reliable than serializing a Model between Livewire components).
        if ($creatorType && $creatorId) {
            $this->creatorType = $creatorType;
            $this->creatorId   = (int) $creatorId;
        } elseif ($creator instanceof Model) {
            $this->creatorType = $creator->getMorphClass();
            $this->creatorId   = (int) $creator->getKey();
        }

        // Resolve owner the same way: explicit ownerType/ownerId wins over a Model.
        if ($ownerType && $ownerId) {
            $this->ownerType = $ownerType;
            $this->ownerId   = (int) $ownerId;
        } elseif ($owner instanceof Model) {
            $this->ownerType = $owner->getMorphClass();
            $this->ownerId   = (int) $owner->getKey();
        }

        // Integrated slot picker
        $this->slotOptions     = $this->normalizeSlotOptions($slotOptions);
        $this->slotLabel       = $slotLabel;
        $this->slotPlaceholder = $slotPlaceholder;
        $this->slotOptional    = $slotOptional;

        if ($selectedSlotId !== null) {
            $this->selectedSlotId = (int) $selectedSlotId;
            $this->slots = [$this->selectedSlotId];
        } elseif (count($this->slotOptions) === 1) {
            // Only one possible option: auto-select and do NOT show the selector.
            // The dropzone directly reflects that slot's cap/extensions.
            $this->selectedSlotId = $this->slotOptions[0]['id'];
            $this->slots = [$this->selectedSlotId];
        }
    }

    /**
     * The theme uses this to decide whether to render the visible selector.
     * Rule: it is only shown if there are 2+ options AND there is no slot fixed
     * externally (e.g. via a single slot or a slot preselected in mount with empty slotOptions).
     */
    public function showsSlotPicker(): bool
    {
        return count($this->slotOptions) >= 2;
    }

    /**
     * Lifecycle hook: when the user changes the integrated selector, syncs
     * `$this->slots` so `computeEffective()` uses the new slot in render().
     */
    public function updatedSelectedSlotId($value): void
    {
        if ($value === null || $value === '' || $value === '0') {
            $this->selectedSlotId = null;
            $this->slots = [];
        } else {
            $this->selectedSlotId = (int) $value;
            $this->slots = [$this->selectedSlotId];
        }
    }

    /**
     * Allows changing the slots dynamically from the front (legacy: the parent
     * orchestrates the slot picker outside the component). For new uses, prefer
     * the integrated `slotOptions` + `selectedSlotId`.
     */
    public function setSlots(array $slots): void
    {
        $this->slots = array_values(array_filter(array_map('intval', $slots)));
    }

    /**
     * Normalizes the slot picker options to a consistent shape.
     * Accepts:
     *   - array of arrays with keys (id, name, color?, description?)
     *   - array of Eloquent models (takes id + name)
     *   - Eloquent collection
     */
    protected function normalizeSlotOptions(array|\Illuminate\Support\Collection $options): array
    {
        $out = [];
        foreach ($options as $opt) {
            if ($opt instanceof Model) {
                $out[] = [
                    'id'    => (int) $opt->getKey(),
                    'name'  => (string) ($opt->name ?? $opt->label ?? '#' . $opt->getKey()),
                    'color' => $opt->color ?? null,
                ];
                continue;
            }
            if (is_array($opt) && isset($opt['id'])) {
                $out[] = [
                    'id'    => (int) $opt['id'],
                    'name'  => (string) ($opt['name'] ?? $opt['label'] ?? '#' . $opt['id']),
                    'color' => $opt['color'] ?? null,
                ];
            }
        }
        return $out;
    }

    /** Register an uploaded file (resolving creator/slots) as a File of the model. */
    public function registerUploaded(string $key, string $name, string $mime, int $size): ?int
    {
        $upload = FileUpload::fromArray([
            'disk'          => $this->disk(),
            'key'           => $key,
            'mime_type'     => $mime,
            'original_name' => $name,
            'size'          => $size,
        ]);

        // Resolve creator override if passed via prop
        $creator = null;
        if ($this->creatorType && $this->creatorId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->creatorType)
                ?? $this->creatorType;
            if (class_exists($class)) {
                $creator = $class::find($this->creatorId);
            }
        }

        // Resolve owner override if passed via prop (same scheme as creator).
        $owner = null;
        if ($this->ownerType && $this->ownerId) {
            $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($this->ownerType)
                ?? $this->ownerType;
            if (class_exists($class)) {
                $owner = $class::find($this->ownerId);
            }
        }

        // CreateFileAction may throw InvalidArgumentException for:
        //   - MIME/extension not accepted by the collection or the slot
        //   - Slot quota exhausted (per_creator or global)
        //   - Size exceeded
        // We catch it and return null so the front treats the item as a
        // normal error (without a 500). The message is sent in the event for UX.
        try {
            $file = $this->model->addFile(
                $upload,
                $this->collection,
                slots: $this->slots,
                creator: $creator,
                owner: $owner,
                folder: $this->folder(),
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

    /**
     * Computes the effective cap and effective extensions from the slots.
     * The most restrictive cap wins. Counts the files already uploaded by this
     * creator in each slot to subtract them from the per_creator limit.
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

    /**
     * Detects the dropzone's visual category (image, video, audio, document,
     * mixed) from the accepted extensions. Used by the theme to pick the
     * appropriate icon.
     */
    protected function detectIconCategory(array $extensions): string
    {
        if (empty($extensions)) {
            return 'mixed';
        }

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

    /** Render the deferred dropzone theme view. */
    public function render()
    {
        $theme = $this->theme ?? config('laracrate.ui.default_theme', 'default');

        $view = view()->exists("laracrate::dropzone-deferred.themes.{$theme}")
            ? "laracrate::dropzone-deferred.themes.{$theme}"
            : 'laracrate::dropzone-deferred.themes.default';

        $effective = $this->computeEffective();

        // Notify the front of the effective cap on each render. The theme listens
        // to `laracrate-deferred-config` with `{ fileableType, fileableId, collection,
        // maxFiles }` and updates `cfg.maxFiles` reactively, without depending on the
        // remount by key. A solution for cases where Livewire morphdom keeps the
        // Alpine state stable across slot changes.
        $this->dispatch(
            'laracrate-deferred-config',
            fileableType: $this->model->getMorphClass(),
            fileableId: (string) $this->model->getKey(),
            collection: $this->collection,
            maxFiles: $effective['maxFiles'],
        );

        return view($view, [
            'config'            => $this->model->getCollectionConfig($this->collection),
            'collection'        => $this->collection,
            'disk'              => $this->disk(),
            'fileableType'      => $this->model->getMorphClass(),
            'fileableId'        => $this->model->getKey(),
            'acceptAttr'        => implode(',', $this->acceptedMimeTypes() ?: ['*/*']),
            'extensions'        => $effective['extensions'],
            'iconCategory'      => $this->detectIconCategory($effective['extensions']),
            'maxSizeKb'         => $this->maxSizeKb(),
            // 'multiple' and 'maxFiles' are public props of the component and are
            // exposed automatically to the view by Livewire. We rename them here so
            // the view variables win over the public prop (otherwise they get
            // shadowed when the prop is null and the effective value is computed in render).
            'multipleAllowed'   => $this->multiple,
            'persistQueue'      => $this->persistQueue,
            'hideActions'       => $this->hideActions,
            'layout'            => $this->layout,
            'effectiveMaxFiles' => $effective['maxFiles'],
            'slotInfo'          => $effective['slotInfo'],
            // Slot picker integrado
            'showSlotPicker'    => $this->showsSlotPicker(),
            'pickerOptions'     => $this->slotOptions,
            'pickerLabel'       => $this->slotLabel,
            'pickerPlaceholder' => $this->slotPlaceholder ?: __('laracrate::uploader.slot_placeholder'),
            'pickerOptional'    => $this->slotOptional,
            'requiresSlot'      => !$this->slotOptional && $this->selectedSlotId === null,
        ]);
    }
}
