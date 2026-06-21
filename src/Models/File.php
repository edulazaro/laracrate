<?php

namespace EduLazaro\Laracrate\Models;

use EduLazaro\Laracrate\Enums\FileAccess;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\CollectionConfig;
use Illuminate\Support\Facades\URL;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A stored file: top-level asset or variant, with polymorphic ownership,
 * tenant scope, folders, chunks, and processing state.
 */
class File extends Model
{
    use SoftDeletes;

    protected $table = 'laracrate_files';

    protected $fillable = [
        'slug',
        'parent_id', 'variant',
        'fileable_type', 'fileable_id',
        'folder_id',
        'creator_type', 'creator_id',
        'owner_type', 'owner_id',
        'tenant_type', 'tenant_id',
        'disk', 'path', 'name', 'original_name', 'extension', 'mime_type', 'size', 'digest',
        'context', 'collection', 'type', 'category',
        'access', 'visibility', 'sensitive', 'is_encrypted',
        'title', 'description', 'label', 'default', 'position', 'published', 'is_verified',
        'duration', 'width', 'height', 'bitrate', 'sample_rate',
        'metadata',
        'processing_status', 'processing_error', 'processing_started_at',
        'processing_extractor', 'processing_provider', 'processing_model',
        'summary',
        'mysql_indexed_at', 'meili_indexed_at', 'storage_indexed_at',
        'downloads_count', 'last_downloaded_at',
    ];

    protected $casts = [
        'sensitive'             => 'boolean',
        'is_encrypted'          => 'boolean',
        'default'               => 'boolean',
        'published'             => 'boolean',
        'is_verified'           => 'boolean',
        'position'              => 'integer',
        'size'                  => 'integer',
        'downloads_count'       => 'integer',
        'duration'              => 'integer',
        'width'                 => 'integer',
        'height'                => 'integer',
        'bitrate'               => 'integer',
        'sample_rate'           => 'integer',
        'metadata'              => 'array',
        'access'                => FileAccess::class,
        'type'                  => FileType::class,
        'processing_status'     => ProcessingStatus::class,
        'last_downloaded_at'    => 'datetime',
        'processing_started_at' => 'datetime',
        'mysql_indexed_at'      => 'datetime',
        'meili_indexed_at'      => 'datetime',
        'storage_indexed_at'    => 'datetime',
    ];

    /** Route the file by its slug. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ------------------------------------------------------------------
     | Relations
     * ------------------------------------------------------------------ */

    /** The model this file belongs to (Property, User, Service...). */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The model that created this file. */
    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Recipient / semantic owner of the file. Differs from the creator when a
     * user uploads/generates on behalf of another. NULL when it matches the creator.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** The tenant that scopes this file (multi-tenancy). */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Returns the real owner: explicit if present, otherwise the creator.
     */
    public function effectiveOwner(): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->owner_id ? $this->owner : $this->creator;
    }

    /** Parent file (set for variants). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Child files (variants). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Folder this file belongs to (optional). Null = it is at the root of the
     * fileable. See Folder + HasFolders.
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(\EduLazaro\Laracrate\Models\Folder::class, 'folder_id');
    }

    /**
     * Moves the file to a folder (or to the root if null). Validates that the
     * folder belongs to the same fileable: mixing owners is not allowed.
     * The binary in R2 is NOT moved (its key does not change); the "move" is
     * logical, it lives in folder_id.
     */
    public function moveToFolder(?\EduLazaro\Laracrate\Models\Folder $folder): void
    {
        if ($folder) {
            if ($folder->folderable_type !== $this->fileable_type
                || (string) $folder->folderable_id !== (string) $this->fileable_id) {
                throw new \InvalidArgumentException(
                    'The destination folder belongs to a different fileable.'
                );
            }
        }

        $this->folder_id = $folder?->id;
        $this->save();
    }

    /**
     * File chunks (registry). 1 row per chunk with chunk_index, status,
     * metadata. The heavy payload (text + embedding) lives in `FileChunkData`
     * via `$chunk->data` (HasOne).
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(FileChunk::class)->orderBy('chunk_index');
    }

    /**
     * 1:1 access to the first chunk (chunk_index=0). Useful for apps that do
     * NOT use chunking and store all the text in a single row per file.
     */
    public function chunk(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FileChunk::class)->where('chunk_index', 0);
    }

    /**
     * @deprecated Use `chunks()` instead of `contents()`. Temporary alias
     *             for apps migrating from the old name.
     */
    public function contents(): HasMany
    {
        return $this->chunks();
    }

    /**
     * @deprecated Use `chunk()` instead of `content()`.
     */
    public function content(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->chunk();
    }

    /** Slots this file is attached to (many-to-many). */
    public function slots(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            FileSlot::class,
            'laracrate_file_slot_pivot',
            'file_id',
            'file_slot_id'
        )->withTimestamps();
    }

    /**
     * Key of the file in its disk. `path` already stores the whole key; this
     * accessor only guards against `null` and an accidental leading `/`.
     *
     * ALWAYS use `$file->key` instead of reading `$file->path` directly: it
     * encapsulates the contract and avoids a caller forgetting the ltrim.
     */
    public function getKeyAttribute(): string
    {
        return ltrim($this->path ?? '', '/');
    }

    /**
     * Builds the key of a sibling (same directory, different name).
     * Useful for transcoded/optimized versions that replace the
     * original (`foo.mov` -> `foo.mp4`, `foo.jpg` -> `foo.webp`).
     */
    public function siblingKey(string $newName): string
    {
        $parentDir = (string) Str::beforeLast($this->path ?? '', '/');
        return trim($parentDir . '/' . $newName, '/');
    }

    /**
     * Builds the key of a variant (sibling `variants/` subfolder, different
     * name). Useful for previews and derivatives that coexist with the
     * original without replacing it.
     */
    public function variantKey(string $newName): string
    {
        $parentDir = (string) Str::beforeLast($this->path ?? '', '/');
        return trim($parentDir . '/variants/' . $newName, '/');
    }

    /**
     * Factory to create a variant inheriting the scope fields from the parent
     * (fileable, creator, tenant, disk, context, collection, access,
     * visibility, sensitive, is_encrypted) and with `parent_id` already linked.
     *
     * The caller passes the variant-specific fields in `$overrides`:
     *   path / name / original_name / extension / mime_type / size /
     *   type / width / height / duration ...
     *
     * If the variant has its own processing pipeline, override
     * `processing_status` in `$overrides` (default: COMPLETED).
     */
    public function createVariant(string $variantName, array $overrides): self
    {
        return self::create(array_merge([
            'slug'              => (string) Str::ulid(),
            'parent_id'         => $this->id,
            'variant'           => $variantName,
            'fileable_type'     => $this->fileable_type,
            'fileable_id'       => $this->fileable_id,
            'creator_type'      => $this->creator_type,
            'creator_id'        => $this->creator_id,
            'tenant_type'       => $this->tenant_type,
            'tenant_id'         => $this->tenant_id,
            'disk'              => $this->disk,
            'context'           => $this->context,
            'collection'        => $this->collection,
            'access'            => $this->access,
            'visibility'        => $this->visibility,
            'sensitive'         => $this->sensitive,
            'is_encrypted'      => $this->is_encrypted,
            'processing_status' => ProcessingStatus::COMPLETED,
        ], $overrides));
    }

    /**
     * True if all chunks have an embedding generated.
     */
    public function hasEmbeddings(): bool
    {
        $total = $this->chunks()->count();
        if ($total === 0) return false;

        $embedded = $this->chunks()->whereHas('data', fn ($q) => $q->whereNotNull('embedding'))->count();
        return $embedded === $total;
    }

    /* ------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    /** Limit the query to top-level files (no parent). */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Eager-load nested children down to the given depth. */
    public function scopeWithDescendants(Builder $query, int $depth = 2): Builder
    {
        $relation = rtrim(str_repeat('children.', $depth), '.');
        return $query->with($relation);
    }

    /**
     * Loads the full variant tree of the file: preview, its thumbnails, any
     * variant derived further down. By default it goes 3 levels deep, which
     * covers the typical chain `file -> preview -> thumbnail|small|medium|large`
     * with room for an extra layer (watermarked, etc).
     *
     *   File::withVariants()->get()
     *   File::withVariants(2)->get()  // only file -> preview -> variants
     *
     * Then navigate it with `$file->variant('preview.thumbnail')` etc.
     */
    public function scopeWithVariants(Builder $query, int $depth = 3): Builder
    {
        $relation = rtrim(str_repeat('children.', $depth), '.');
        return $query->with($relation);
    }

    /** Limit the query to files scoped to the given tenant. */
    public function scopeForTenant(Builder $query, Model $tenant): Builder
    {
        return $query
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey());
    }

    /** Order by position then id. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Limit the query to published files. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    /** Limit the query to unpublished files. */
    public function scopeUnpublished(Builder $query): Builder
    {
        return $query->where('published', false);
    }

    /** Limit the query to default files. */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('default', true);
    }

    /* ------------------------------------------------------------------
     | State helpers
     * ------------------------------------------------------------------ */

    /** Mark this file as published. */
    public function publish(): self
    {
        $this->update(['published' => true]);
        return $this;
    }

    /** Mark this file as unpublished. */
    public function unpublish(): self
    {
        $this->update(['published' => false]);
        return $this;
    }

    /**
     * Marks this file as the default of its (fileable + collection),
     * unmarking any other previous default of the same group.
     */
    public function makeDefault(): self
    {
        self::query()
            ->where('fileable_type', $this->fileable_type)
            ->where('fileable_id', $this->fileable_id)
            ->where('collection', $this->collection)
            ->whereNull('parent_id')
            ->where('id', '!=', $this->id)
            ->where('default', true)
            ->update(['default' => false]);

        $this->update(['default' => true]);
        return $this;
    }

    /* ------------------------------------------------------------------
     | Variant navigation (dot notation with fallback to the ancestor)
     * ------------------------------------------------------------------ */

    /**
     * Navigates to a descendant variant using dot notation.
     * Falls back to the nearest real ancestor if the chain breaks: never returns null.
     *
     *   $video->variant('preview.small')
     *     1. Looks for 'preview' -> if it does not exist, returns $video.
     *     2. Looks for 'small' in preview -> if it does not exist, returns $preview.
     *     3. Found -> returns $small.
     */
    public function variant(string $path): self
    {
        $current = $this;

        foreach (explode('.', $path) as $name) {
            $next = $current->children->firstWhere('variant', $name)
                ?? $current->children()->where('variant', $name)->first();

            if ($next === null) {
                return $current;
            }

            $current = $next;
        }

        return $current;
    }

    /**
     * Same as `variant()` but throws if the chain breaks. For code where that
     * silent fallback to the ancestor is a bug, not a feature.
     *
     *   $file->variantOrFail('preview.thumbnail')
     *     -> thumbnail File if it exists
     *     -> \RuntimeException if any link is missing
     *
     * @throws \RuntimeException if any variant of the path does not exist.
     */
    public function variantOrFail(string $path): self
    {
        $current = $this;
        $traversed = [];

        foreach (explode('.', $path) as $name) {
            $next = $current->children->firstWhere('variant', $name)
                ?? $current->children()->where('variant', $name)->first();

            if ($next === null) {
                $partial = $traversed === [] ? '<root>' : implode('.', $traversed);
                throw new \RuntimeException(
                    "Variant '{$name}' not found in file #{$this->id} ".
                    "(requested path: '{$path}', resolved up to: '{$partial}')."
                );
            }

            $traversed[] = $name;
            $current = $next;
        }

        return $current;
    }

    /* ------------------------------------------------------------------
     | Render URL
     * ------------------------------------------------------------------ */

    /**
     * URL of the File. Returns the real URL (public/signed/stream depending on
     * access) unless a type is forced and the File does not match: in that case
     * it returns the placeholder configured for that type.
     *
     *   $file->url()              -> real URL (or null if there is no valid backend)
     *   $file->url('image')       -> real URL if type=image, otherwise image placeholder
     */
    public function url(?string $forceType = null): ?string
    {
        if ($forceType !== null && $this->type?->value !== $forceType) {
            return $this->placeholderFor($forceType);
        }

        try {
            $url = app(StorageManager::class)->urlFor($this);
        } catch (\Throwable) {
            $url = null;
        }

        return $url ?? ($forceType !== null ? $this->placeholderFor($forceType) : null);
    }

    /**
     * Resolves the placeholder in a chain: per-collection -> per-type -> default.
     */
    public function placeholderFor(string $type): string
    {
        $config = CollectionConfig::resolve($this->collection, $this->fileable_type);

        return ($config['placeholder'] ?? null)
            ?? config("laracrate.placeholders.{$type}")
            ?? config('laracrate.placeholders.default')
            ?? '';
    }

    /* ------------------------------------------------------------------
     | Convenient accessors for blade
     * ------------------------------------------------------------------ */

    /**
     * Alias of url(): accessible as $file->link in blade.
     */
    public function getLinkAttribute(): ?string
    {
        return $this->url();
    }

    /**
     * URL of the preview at thumbnail size. For videos/PDF/audio with a
     * 'preview' variant it forces type 'image' (returns the image placeholder
     * if the chain falls back to an ancestor of a different type).
     *
     * Any exception during URL generation (broken disk, unreachable S3, bad
     * credentials) falls back to a placeholder instead of propagating: a file
     * that fails should not break the entire page.
     */
    public function getPreviewLinkAttribute(): string
    {
        try {
            return $this->variant('preview.thumbnail')->url('image') ?? $this->placeholderFor('image');
        } catch (\Throwable) {
            return $this->placeholderFor('image');
        }
    }

    /* ------------------------------------------------------------------
     | Stream / download (the package's signed routes)
     |
     | The controller (StreamFileController) requires hasValidSignature(), so
     | these URLs are always emitted signed with a TTL. The TTL is configurable
     | in `laracrate.urls.signed_ttl_minutes` (default 15).
     * ------------------------------------------------------------------ */

    /** Signed URL to stream the file. */
    public function streamUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    /** Signed URL to download the file. */
    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.download',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    /** Signed URL to preview the file. */
    public function previewUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.preview',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    /* ------------------------------------------------------------------
     | Permissions: delegate to the PolicyRegistry
     * ------------------------------------------------------------------ */

    /** Whether the given user can view this file. */
    public function canView(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canView($this, $user);
    }

    /** Whether the given user can edit this file. */
    public function canEdit(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canEdit($this, $user);
    }

    /** Whether the given user can delete this file. */
    public function canDelete(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canDelete($this, $user);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /** True if this file is a variant (has a parent). */
    public function isVariant(): bool
    {
        return $this->parent_id !== null;
    }

    /** True if this file is top-level (has no parent). */
    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /** True if this file is marked sensitive. */
    public function isSensitive(): bool
    {
        return (bool) $this->sensitive;
    }

    /** True if the creator is a user. */
    public function createdByUser(): bool
    {
        return $this->creator_type === 'user';
    }

    /** True if the creator is an agent. */
    public function createdByAgent(): bool
    {
        return $this->creator_type === 'agent';
    }

    /** True if the file was created automatically (no creator). */
    public function createdAutomatically(): bool
    {
        return $this->creator_type === null && $this->creator_id === null;
    }

    /** True if the file is scoped to a tenant. */
    public function isMultiTenant(): bool
    {
        return $this->tenant_type !== null;
    }

    /** True if the file is an image. */
    public function isImage(): bool
    {
        return $this->type === FileType::IMAGE;
    }

    /** True if the file is a video. */
    public function isVideo(): bool
    {
        return $this->type === FileType::VIDEO;
    }

    /** True if the file is audio. */
    public function isAudio(): bool
    {
        return $this->type === FileType::AUDIO;
    }

    /** True if the file is a document. */
    public function isDocument(): bool
    {
        return $this->type === FileType::DOCUMENT;
    }

    /**
     * Specific PDF check. Useful because apps usually have a PDF extractor
     * different from the rest of documents (scanned vs native, OCR, text
     * extraction with smalot/pdfparser, etc.).
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || strtolower($this->extension ?? '') === 'pdf';
    }

    /**
     * Structured extracted content. Lives in storage as `{path}.json`:
     * `{full_text, pages: [{page_number, text}], metadata}`.
     *
     * Returns null if extraction has not run.
     */
    public function extractedContent(): ?\EduLazaro\Laracrate\Support\ExtractedContent
    {
        if (! $this->disk || ! $this->path) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($this->disk);
        $jsonPath = $this->path . '.json';

        if (! $disk->exists($jsonPath)) {
            return null;
        }

        $data = json_decode((string) $disk->get($jsonPath), true);
        if (! is_array($data)) {
            return null;
        }

        return \EduLazaro\Laracrate\Support\ExtractedContent::fromArray($data);
    }

    /**
     * Full extracted text (shortcut to `extractedContent()->fullText`).
     */
    public function extractedText(): ?string
    {
        return $this->extractedContent()?->fullText;
    }

    /**
     * Text of a specific chunk. Reads from the JSONL sidecar.
     *
     * For multiple chunks, prefer `$this->chunksJsonl()` and reuse it.
     */
    public function chunkText(int $chunkIndex): ?string
    {
        $chunks = $this->chunksJsonl();
        return $chunks[$chunkIndex]['text'] ?? null;
    }

    /**
     * All chunks from the JSONL as an array indexed by chunk_index.
     * Each element: ['chunk_index', 'text', 'tokens', 'page_number',
     * 'page_numbers', 'embedding'?].
     *
     * Returns [] if the JSONL does not exist.
     */
    public function chunksJsonl(): array
    {
        if (! $this->disk || ! $this->path) {
            return [];
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($this->disk);
        $jsonlPath = $this->path . '.chunks.jsonl';

        if (! $disk->exists($jsonlPath)) {
            return [];
        }

        $chunks = [];
        foreach (explode("\n", trim($disk->get($jsonlPath))) as $line) {
            if (empty($line)) continue;
            $parsed = json_decode($line, true);
            if (is_array($parsed) && isset($parsed['chunk_index'])) {
                $chunks[$parsed['chunk_index']] = $parsed;
            }
        }

        return $chunks;
    }
}
