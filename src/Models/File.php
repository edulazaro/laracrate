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

class File extends Model
{
    use SoftDeletes;

    protected $table = 'laracrate_files';

    protected $fillable = [
        'slug',
        'parent_id', 'variant',
        'fileable_type', 'fileable_id',
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
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ------------------------------------------------------------------
     | Relaciones
     * ------------------------------------------------------------------ */

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Destinatario / dueño semántico del archivo. Distinto del creator cuando
     * un usuario sube/genera en nombre de otro. NULL cuando coincide con creator.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Devuelve el owner real: explícito si está, en caso contrario el creator.
     */
    public function effectiveOwner(): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->owner_id ? $this->owner : $this->creator;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(FileContent::class)->orderBy('chunk_index');
    }

    /**
     * Acceso 1:1 al primer chunk (chunk_index=0). Útil para apps que NO usan
     * chunking y guardan todo el texto extraído en una sola fila por archivo.
     */
    public function content(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FileContent::class)->where('chunk_index', 0);
    }

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
     * Key del archivo en su disk. `path` ya almacena la key entera; este
     * accessor solo defensiviza contra `null` y un `/` inicial accidental.
     *
     * Usar SIEMPRE `$file->key` en vez de leer `$file->path` directo —
     * encapsula el contrato y evita que un caller olvide el ltrim.
     */
    public function getKeyAttribute(): string
    {
        return ltrim($this->path ?? '', '/');
    }

    /**
     * Construye la key de un sibling (mismo directorio, nombre distinto).
     * Útil para versiones transcodificadas/optimizadas que reemplazan al
     * original (`foo.mov` → `foo.mp4`, `foo.jpg` → `foo.webp`).
     */
    public function siblingKey(string $newName): string
    {
        $parentDir = (string) Str::beforeLast($this->path ?? '', '/');
        return trim($parentDir . '/' . $newName, '/');
    }

    /**
     * Construye la key de un variant (subcarpeta `variants/` hermana,
     * nombre distinto). Útil para previews y derivados que conviven con
     * el original sin reemplazarlo.
     */
    public function variantKey(string $newName): string
    {
        $parentDir = (string) Str::beforeLast($this->path ?? '', '/');
        return trim($parentDir . '/variants/' . $newName, '/');
    }

    /**
     * Factory para crear un variant heredando los campos de scope del padre
     * (fileable, creator, tenant, disk, context, collection, access,
     * visibility, sensitive, is_encrypted) y con `parent_id` ya enlazado.
     *
     * El caller pasa los campos específicos del variant en `$overrides`:
     *   path / name / original_name / extension / mime_type / size /
     *   type / width / height / duration ...
     *
     * Si el variant tiene su propio pipeline de procesado, override
     * `processing_status` en `$overrides` (default: COMPLETED).
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
     * Devuelve el texto extraído reuniendo todos los chunks en orden.
     */
    public function extractedText(): ?string
    {
        $chunks = $this->contents()->whereNotNull('text')->pluck('text');
        if ($chunks->isEmpty()) return null;
        return $chunks->implode("\n");
    }

    /**
     * True si todos los chunks tienen embedding generado.
     */
    public function hasEmbeddings(): bool
    {
        $total = $this->contents()->count();
        if ($total === 0) return false;

        $embedded = $this->contents()->whereNotNull('embedding')->count();
        return $embedded === $total;
    }

    /* ------------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------------ */

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeWithDescendants(Builder $query, int $depth = 2): Builder
    {
        $relation = rtrim(str_repeat('children.', $depth), '.');
        return $query->with($relation);
    }

    public function scopeForTenant(Builder $query, Model $tenant): Builder
    {
        return $query
            ->where('tenant_type', $tenant->getMorphClass())
            ->where('tenant_id', $tenant->getKey());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function scopeUnpublished(Builder $query): Builder
    {
        return $query->where('published', false);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('default', true);
    }

    /* ------------------------------------------------------------------
     | Helpers de estado
     * ------------------------------------------------------------------ */

    public function publish(): self
    {
        $this->update(['published' => true]);
        return $this;
    }

    public function unpublish(): self
    {
        $this->update(['published' => false]);
        return $this;
    }

    /**
     * Marca este file como el default de su (fileable + collection),
     * desmarcando cualquier otro default previo del mismo grupo.
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
     | Variant navigation (dot notation con fallback al ancestro)
     * ------------------------------------------------------------------ */

    /**
     * Navega a un variant descendiente usando notación con punto.
     * Cae al ancestro real más cercano si la cadena se rompe — nunca devuelve null.
     *
     *   $video->variant('preview.small')
     *     1. Busca 'preview' → si no existe, devuelve $video.
     *     2. Busca 'small' en preview → si no existe, devuelve $preview.
     *     3. Encontrado → devuelve $small.
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
     * Igual que `variant()` pero lanza si la cadena se rompe. Para código
     * donde ese fallback silencioso al ancestro es un bug, no una feature.
     *
     *   $file->variantOrFail('preview.thumbnail')
     *     → File del thumbnail si existe
     *     → \RuntimeException si falta cualquier eslabón
     *
     * @throws \RuntimeException si algún variant del path no existe.
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
                    "Variant '{$name}' no encontrado en file #{$this->id} ".
                    "(path solicitado: '{$path}', resuelto hasta: '{$partial}')."
                );
            }

            $traversed[] = $name;
            $current = $next;
        }

        return $current;
    }

    /* ------------------------------------------------------------------
     | URL de render
     * ------------------------------------------------------------------ */

    /**
     * URL del File. Devuelve la URL real (pública/signed/stream según access)
     * a menos que se fuerce un tipo y el File no coincida — en ese caso
     * devuelve el placeholder configurado para ese tipo.
     *
     *   $file->url()              → URL real (o null si no hay backend válido)
     *   $file->url('image')       → URL real si type=image, si no placeholder image
     */
    public function url(?string $forceType = null): ?string
    {
        if ($forceType !== null && $this->type?->value !== $forceType) {
            return $this->placeholderFor($forceType);
        }

        return app(StorageManager::class)->urlFor($this)
            ?? ($forceType !== null ? $this->placeholderFor($forceType) : null);
    }

    /**
     * Resuelve el placeholder en cadena: per-collection → per-type → default.
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
     | Accessors convenientes para blade
     * ------------------------------------------------------------------ */

    /**
     * Alias de url() — accesible como $file->link en blade.
     */
    public function getLinkAttribute(): ?string
    {
        return $this->url();
    }

    /**
     * URL del preview en tamaño thumbnail. Para vídeos/PDF/audio con variant
     * 'preview' fuerza tipo 'image' (devuelve placeholder image si la cadena
     * cae a un ancestro de tipo distinto).
     */
    public function getPreviewLinkAttribute(): string
    {
        return $this->variant('preview.thumbnail')->url('image') ?? '';
    }

    /* ------------------------------------------------------------------
     | Stream / download (rutas firmadas del paquete)
     |
     | El controlador (StreamFileController) exige hasValidSignature(), así
     | que estas URLs se emiten siempre firmadas con TTL. TTL configurable
     | en `laracrate.urls.signed_ttl_minutes` (defecto 15).
     * ------------------------------------------------------------------ */

    public function streamUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.download',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    public function previewUrl(): string
    {
        return URL::temporarySignedRoute(
            'laracrate.files.preview',
            now()->addMinutes((int) config('laracrate.urls.route_signed_ttl', 15)),
            ['file' => $this->slug]
        );
    }

    /* ------------------------------------------------------------------
     | Permisos — delega al PolicyRegistry
     * ------------------------------------------------------------------ */

    public function canView(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canView($this, $user);
    }

    public function canEdit(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canEdit($this, $user);
    }

    public function canDelete(?Model $user): bool
    {
        return app(\EduLazaro\Laracrate\Support\PolicyRegistry::class)->canDelete($this, $user);
    }

    /* ------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    public function isVariant(): bool
    {
        return $this->parent_id !== null;
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    public function isSensitive(): bool
    {
        return (bool) $this->sensitive;
    }

    public function createdByUser(): bool
    {
        return $this->creator_type === 'user';
    }

    public function createdByAgent(): bool
    {
        return $this->creator_type === 'agent';
    }

    public function createdAutomatically(): bool
    {
        return $this->creator_type === null && $this->creator_id === null;
    }

    public function isMultiTenant(): bool
    {
        return $this->tenant_type !== null;
    }

    public function isImage(): bool
    {
        return $this->type === FileType::IMAGE;
    }

    public function isVideo(): bool
    {
        return $this->type === FileType::VIDEO;
    }

    public function isAudio(): bool
    {
        return $this->type === FileType::AUDIO;
    }

    public function isDocument(): bool
    {
        return $this->type === FileType::DOCUMENT;
    }

    /**
     * PDF check específico. Útil porque las apps suelen tener un extractor
     * de PDF distinto al resto de documentos (escaneados vs nativos, OCR,
     * extracción de texto con smalot/pdfparser, etc.).
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || strtolower($this->extension ?? '') === 'pdf';
    }
}
