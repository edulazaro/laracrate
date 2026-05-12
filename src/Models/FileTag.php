<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Tag de archivos: clasificador + filtro de extensiones + quota.
 *
 * Estructura de scope:
 *   - tenant_type/tenant_id  → multi-tenancy (igual que File). Típicamente
 *     'organization' o lo que tu app considere tenant.
 *   - context_type/context_id → scope opcional dentro del tenant. Ej:
 *     context='case' para tags que aplican solo a un caso concreto,
 *     o null/context='organization' para tags globales del tenant.
 *
 * Relación con archivos: m2m simple vía `laracrate_file_tag_pivot`.
 * Quota: el contexto del tag actúa como ámbito natural del límite.
 *   - Tag con context=null → cuenta files en toda la org
 *   - Tag con context=case/X → cuenta files dentro de ese case
 */
class FileTag extends Model
{
    protected $table = 'laracrate_file_tags';

    protected $fillable = [
        'tenant_type',
        'tenant_id',
        'context_type',
        'context_id',
        'name',
        'description',
        'color',
        'allowed_extensions',
        'max_files_per_creator',
        'max_files_total',
        'position',
    ];

    protected $casts = [
        'allowed_extensions'    => 'array',
        'max_files_per_creator' => 'integer',
        'max_files_total'       => 'integer',
        'position'              => 'integer',
    ];

    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(
            File::class,
            'laracrate_file_tag_pivot',
            'file_tag_id',
            'file_id'
        )->withTimestamps();
    }

    /**
     * Cuenta archivos asociados al tag. Opcionalmente filtra por creator
     * (creator_type='user', creator_id=X).
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
     * Determina si el tag acepta más archivos. Devuelve detalle del por qué
     * cuando no acepta:
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
     * Comprueba si la extensión está permitida por este tag.
     */
    public function acceptsExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension, '.'));
        if (empty($this->allowed_extensions)) return true;
        return in_array($extension, array_map('strtolower', $this->allowed_extensions), true);
    }
}
