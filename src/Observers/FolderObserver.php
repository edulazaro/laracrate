<?php

namespace EduLazaro\Laracrate\Observers;

use EduLazaro\Laracrate\Models\Folder;

/**
 * Keeps the denormalized `path` consistent with `parent_id` + `name`. When the
 * name or the parent changes, it recalculates the path of the folder itself and
 * propagates the change to all descendants (renaming "Contratos" ->
 * "Acuerdos" also updates "Contratos/2025" -> "Acuerdos/2025").
 *
 * It does not touch the binary in R2: files store their own `path` with the real
 * key, independent of the folder path. The folder is logical organization.
 */
class FolderObserver
{
    /**
     * Before creating or updating: recalculate path from parent + name.
     */
    public function saving(Folder $folder): void
    {
        $folder->path = $folder->computePath();
    }

    /**
     * After updating: if the path changed, propagate to descendants.
     * We load only the direct children and let their own observer propagate
     * downward recursively (each save triggers saving + saved at each level).
     */
    public function updated(Folder $folder): void
    {
        if (! $folder->wasChanged('path')) {
            return;
        }

        // Force-refresh so the children read the parent's new path.
        $folder->children()->get()->each(function (Folder $child) {
            $child->save();
        });
    }
}
