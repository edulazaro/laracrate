<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Carpeta para organizar files. Árbol parent/child con path denormalizado.
 *
 * Source of truth = parent_id. El campo path se recalcula vía observer al
 * save desde parent.path + name; un mutator a mano sobre path se sobrescribe.
 *
 * Polimórfico: folderable apunta a quien posee el árbol (User para Drive
 * personal, Organization para Drive de despacho, etc.). Igual patrón que
 * files.fileable.
 */
class Folder extends Model
{
    use SoftDeletes;

    protected $table = 'laracrate_folders';

    protected $fillable = [
        'folderable_type',
        'folderable_id',
        'parent_id',
        'name',
        'path',
        'creator_type',
        'creator_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function folderable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Hijos directos (un nivel). Para descendientes profundos usa
     * descendants() o queries por path LIKE.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * Files directamente dentro de esta carpeta (no recursivo).
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id')
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Cadena de ancestros desde raíz hasta esta carpeta (inclusive).
     * Útil para breadcrumbs.
     */
    public function breadcrumb(): array
    {
        $chain = [];
        $cursor = $this;
        while ($cursor) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent;
        }
        return $chain;
    }

    /**
     * True si $candidate es esta carpeta o uno de sus descendientes.
     * Lo usa moveTo() para evitar ciclos en el árbol.
     */
    public function isDescendantOf(self $candidate): bool
    {
        $cursor = $this;
        while ($cursor) {
            if ($cursor->id === $candidate->id) {
                return true;
            }
            $cursor = $cursor->parent;
        }
        return false;
    }

    /**
     * Cambia el parent. El observer recalcula el path en cascada para
     * todos los descendientes. Lanza si crearía ciclo o si cambia de
     * folderable (mover entre árboles distintos no se permite).
     */
    public function moveTo(?self $newParent): void
    {
        if ($newParent) {
            if ($newParent->folderable_type !== $this->folderable_type
                || (string) $newParent->folderable_id !== (string) $this->folderable_id) {
                throw new \InvalidArgumentException(
                    'No se puede mover una carpeta entre folderables distintos.'
                );
            }
            if ($newParent->isDescendantOf($this)) {
                throw new \InvalidArgumentException(
                    'El movimiento crearía un ciclo en el árbol.'
                );
            }
        }

        $this->parent_id = $newParent?->id;
        $this->save();
    }

    /**
     * Path completo desde la raíz hasta esta carpeta. Coincide con la
     * columna denormalizada `path` salvo que estés en un estado intermedio
     * antes del save (el observer la regenerará).
     */
    public function computePath(): string
    {
        $parentPath = $this->parent?->path;
        return $parentPath ? $parentPath . '/' . $this->name : $this->name;
    }
}
