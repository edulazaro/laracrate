<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;

/**
 * Borra un File. La FK cascade se ocupa de los hijos (variants/preview).
 * El FileObserver se ocupa de borrar el asset físico de cada uno.
 */
class DeleteFileAction extends Action
{
    public function handle(File $file, bool $forceDelete = false): bool
    {
        return $forceDelete
            ? (bool) $file->forceDelete()
            : (bool) $file->delete();
    }
}
