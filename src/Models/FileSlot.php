<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * FileSlot: a "slot" with rules that a user fills with files.
 *
 * Typical cases:
 *   - Requirement in an admission process: "Upload your ID (slot 'DNI', max 1, .pdf/.jpg)"
 *   - Defined quota: "Upload up to 3 June invoices"
 *   - Limit types: "Upload photos (only .jpg/.png)"
 *
 * It is not a classification system: that is left to the developer (categories,
 * free tags, hierarchies, whatever is needed). The slot only defines **where
 * files fit and under which rules**.
 *
 * Scope:
 *   - tenant_type/tenant_id  -> multi-tenancy (same as File)
 *   - context_type/context_id -> optional scope within the tenant
 *     (e.g. context='case' for slots of a specific case only)
 *
 * Relationship with files: simple m2m via `laracrate_file_slot_pivot`.
 * The slot context acts as the natural scope of the limit.
 */
class FileSlot extends Model
{
    protected $table = 'laracrate_file_slots';

    protected $fillable = [
        'tenant_type',
        'tenant_id',
        'context_type',
        'context_id',
        'name',
        'description',
        'color',
        'allowed_extensions',
        'allowed_types',
        'max_files_per_creator',
        'max_files_total',
        'position',
    ];

    protected $casts = [
        'allowed_extensions'    => 'array',
        'allowed_types'         => 'array',
        'max_files_per_creator' => 'integer',
        'max_files_total'       => 'integer',
        'position'              => 'integer',
    ];

    /** The tenant that scopes this slot. */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /** Optional context within the tenant. */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    /** Files attached to this slot (many-to-many). */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(
            File::class,
            'laracrate_file_slot_pivot',
            'file_slot_id',
            'file_id'
        )->withTimestamps();
    }

    /**
     * Counts files in the slot. Optionally filters by polymorphic creator
     * (creator_type, creator_id).
     */
    public function uploadedCount(?string $creatorType = null, ?int $creatorId = null): int
    {
        $q = $this->files();
        if ($creatorType !== null && $creatorId !== null) {
            $q->where('laracrate_files.creator_type', $creatorType)
              ->where('laracrate_files.creator_id', $creatorId);
        }
        return $q->count();
    }

    /**
     * Determines whether the slot accepts more files. Returns detail of why
     * when it does not:
     *   ['can' => bool, 'reason' => 'global'|'per_creator'|null, 'limit' => int|null]
     */
    public function canAcceptMore(?string $creatorType = null, ?int $creatorId = null): array
    {
        if ($this->max_files_total !== null) {
            $total = $this->uploadedCount();
            if ($total >= $this->max_files_total) {
                return ['can' => false, 'reason' => 'global', 'limit' => $this->max_files_total];
            }
        }

        if ($this->max_files_per_creator !== null && $creatorType !== null && $creatorId !== null) {
            $byCreator = $this->uploadedCount($creatorType, $creatorId);
            if ($byCreator >= $this->max_files_per_creator) {
                return ['can' => false, 'reason' => 'per_creator', 'limit' => $this->max_files_per_creator];
            }
        }

        return ['can' => true, 'reason' => null, 'limit' => null];
    }

    /**
     * Checks whether the extension is allowed by this slot.
     * Empty list = everything allowed.
     */
    public function acceptsExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension, '.'));
        if (empty($this->allowed_extensions)) return true;
        return in_array($extension, array_map('strtolower', $this->allowed_extensions), true);
    }

    /**
     * Checks whether the FileType (document/image/video/audio) is allowed
     * by this slot. Empty/null list = everything allowed.
     */
    public function acceptsType(string $type): bool
    {
        if (empty($this->allowed_types)) return true;
        return in_array($type, $this->allowed_types, true);
    }

    /**
     * Full validation of the file against the slot. Applies AND between
     * extension and type: if both restrictions are declared, the file must
     * satisfy both. If only one is declared, only that one is validated.
     */
    public function accepts(File $file): bool
    {
        $extension = strtolower((string) $file->extension);
        $type      = (string) ($file->type?->value ?? $file->type);

        if (! $this->acceptsExtension($extension)) return false;
        if (! $this->acceptsType($type)) return false;

        return true;
    }
}
