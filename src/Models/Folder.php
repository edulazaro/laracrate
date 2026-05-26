<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
// File está en el mismo namespace, no necesita use explícito (autoresolución).

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

    /**
     * Todos los descendientes (recursivo). Usa el path denormalizado para
     * evitar recursión SQL: una sola query indexada.
     */
    public function descendants()
    {
        return self::query()
            ->where('folderable_type', $this->folderable_type)
            ->where('folderable_id', $this->folderable_id)
            ->where('path', 'LIKE', $this->path . '/%');
    }

    /**
     * Todos los files del subárbol (recursivo): los de esta carpeta + los
     * de sus descendientes. Una sola query con whereIn sobre folder_id.
     */
    public function allFiles()
    {
        $folderIds = $this->descendants()->pluck('id')->push($this->id)->all();

        return File::query()
            ->whereIn('folder_id', $folderIds)
            ->whereNull('parent_id'); // top-level (excluye variants)
    }

    /**
     * Suma de tamaños en bytes de todos los files del subárbol. Lo usa la
     * UI para mostrar el peso de una carpeta.
     */
    public function sizeBytes(): int
    {
        return (int) $this->allFiles()->sum('size');
    }

    /**
     * Hard delete del árbol completo: descendientes + files de cada uno
     * pasan por forceDelete, lo que dispara el FileObserver y purga R2 +
     * chunks. Usar desde el "vaciar papelera" o "borrar definitivamente".
     */
    public function forceDeleteRecursive(): void
    {
        // 1) Files primero (todos los del subárbol).
        File::query()
            ->whereIn('folder_id', $this->descendants()->pluck('id')->push($this->id)->all())
            ->whereNull('parent_id')
            ->get()
            ->each(fn (File $f) => $f->forceDelete());

        // 2) Descendientes (deepest-first para no romper FK durante el delete).
        $this->descendants()
            ->orderByDesc('path')
            ->get()
            ->each(fn (self $f) => $f->forceDelete());

        // 3) Esta carpeta.
        $this->forceDelete();
    }
}
