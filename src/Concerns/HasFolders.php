<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Models\Folder;
use EduLazaro\Laracrate\Models\Folderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait paralelo a HasFiles para modelos que tienen árbol de carpetas
 * (User Drive personal, Organization Drive, etc.).
 *
 * Patrón típico:
 *   $user->folders();                 // todas las carpetas del user
 *   $user->rootFolders();             // solo raíces (parent_id null)
 *   $user->addFolder('Contratos');    // crea carpeta raíz
 *   $folder->children()->addFolder('2025'); // (usa el trait via $folder->...)
 */
trait HasFolders
{
    /**
     * Todas las folders de este modelo, en cualquier nivel del árbol.
     */
    public function folders(): MorphMany
    {
        return $this->morphMany(Folder::class, 'folderable');
    }

    /**
     * Solo carpetas raíz (top-level).
     */
    public function rootFolders()
    {
        return $this->folders()->whereNull('parent_id')->orderBy('name');
    }

    /**
     * Crea una carpeta. Si $parent es null, queda en raíz. El observer
     * recalcula y guarda el path denormalizado.
     *
     * @param  Model|null  $creator  Quien la creó (audit). Si null y la auth
     *                               está disponible, cae al user autenticado.
     */
    public function addFolder(
        string $name,
        ?Folder $parent = null,
        ?Model $creator = null,
        array $metadata = []
    ): Folder {
        $creator = $creator ?? (auth()->check() ? auth()->user() : null);

        if ($parent && (
            $parent->folderable_type !== $this->getMorphClass()
            || (string) $parent->folderable_id !== (string) $this->getKey()
        )) {
            throw new \InvalidArgumentException(
                'El parent pertenece a otro folderable.'
            );
        }

        $folder = new Folder([
            'folderable_type' => $this->getMorphClass(),
            'folderable_id'   => $this->getKey(),
            'parent_id'       => $parent?->id,
            'name'            => $name,
            'metadata'        => $metadata ?: null,
        ]);

        if ($creator) {
            $folder->creator_type = $creator->getMorphClass();
            $folder->creator_id   = $creator->getKey();
        }

        $folder->save();

        return $folder;
    }

    /**
     * Devuelve la fila Folderable de este modelo para la collection dada.
     * Null si la collection no tiene `track_usage` o aún no hay archivos
     * trackeados (la fila se crea lazy en el primer `created`).
     */
    public function usage(string $collection): ?Folderable
    {
        return Folderable::query()
            ->where('folderable_type', $this->getMorphClass())
            ->where('folderable_id', $this->getKey())
            ->where('collection', $collection)
            ->first();
    }

    /**
     * Shortcut: bytes ocupados por este modelo en la collection dada.
     * 0 si aún no hay nada o si la collection no se trackea.
     */
    public function usageBytes(string $collection): int
    {
        return (int) ($this->usage($collection)?->total_size_bytes ?? 0);
    }
}
