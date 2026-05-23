<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * FileSlot: un "hueco" con reglas que un usuario rellena con archivos.
 *
 * Casos típicos:
 *   - Requisito en un proceso de admisión: "Sube tu DNI (slot 'DNI', max 1, .pdf/.jpg)"
 *   - Cupo definido: "Sube hasta 3 facturas de junio"
 *   - Limitar tipos: "Sube fotos (solo .jpg/.png)"
 *
 * No es un sistema de clasificación — eso queda al desarrollador (categorías,
 * tags libres, jerarquías, lo que necesite). El slot solo define **dónde
 * encajan los archivos y bajo qué reglas**.
 *
 * Scope:
 *   - tenant_type/tenant_id  → multi-tenancy (igual que File)
 *   - context_type/context_id → scope opcional dentro del tenant
 *     (ej. context='case' para slots solo de un caso concreto)
 *
 * Relación con archivos: m2m simple vía `laracrate_file_slot_pivot`.
 * El contexto del slot actúa como ámbito natural del límite.
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
            'laracrate_file_slot_pivot',
            'file_slot_id',
            'file_id'
        )->withTimestamps();
    }

    /**
     * Cuenta archivos en el slot. Opcionalmente filtra por creator polimórfico
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
     * Determina si el slot acepta más archivos. Devuelve detalle del por qué
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
     * Comprueba si la extensión está permitida por este slot.
     * Lista vacía = todo permitido.
     */
    public function acceptsExtension(string $extension): bool
    {
        $extension = strtolower(trim($extension, '.'));
        if (empty($this->allowed_extensions)) return true;
        return in_array($extension, array_map('strtolower', $this->allowed_extensions), true);
    }

    /**
     * Comprueba si el FileType (document/image/video/audio) está permitido
     * por este slot. Lista vacía/null = todo permitido.
     */
    public function acceptsType(string $type): bool
    {
        if (empty($this->allowed_types)) return true;
        return in_array($type, $this->allowed_types, true);
    }

    /**
     * Validación completa del file contra el slot. Aplica AND entre extension
     * y type: si ambas restricciones están declaradas, el file debe cumplir las
     * dos. Si solo una está declarada, solo se valida esa.
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
