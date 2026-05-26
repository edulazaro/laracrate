<?php

namespace EduLazaro\Laracrate\Observers;

use EduLazaro\Laracrate\Models\Folder;

/**
 * Mantiene `path` denormalizado coherente con `parent_id` + `name`. Cuando
 * cambia el nombre o el parent, recalcula el path de la propia carpeta y
 * propaga el cambio a todos los descendientes (rename de "Contratos" →
 * "Acuerdos" actualiza también "Contratos/2025" → "Acuerdos/2025").
 *
 * No toca el binario en R2 — los files almacenan su propio `path` con la key
 * real, independiente del path de la folder. La folder es organización lógica.
 */
class FolderObserver
{
    /**
     * Antes de crear o actualizar: recalcular path desde parent + name.
     */
    public function saving(Folder $folder): void
    {
        $folder->path = $folder->computePath();
    }

    /**
     * Después de actualizar: si cambió el path, propagar a descendientes.
     * Cargamos solo los hijos directos y dejamos que su propio observer
     * propague hacia abajo recursivamente (cada save dispara saving + saved
     * en cada nivel).
     */
    public function updated(Folder $folder): void
    {
        if (! $folder->wasChanged('path')) {
            return;
        }

        // Force-refresh para que los hijos lean el path nuevo del padre.
        $folder->children()->get()->each(function (Folder $child) {
            $child->save();
        });
    }
}
