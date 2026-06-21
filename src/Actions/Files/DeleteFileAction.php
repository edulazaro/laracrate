<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laractions\Action;

/**
 * Deletes a File. The FK cascade takes care of the children (variants/preview).
 * The FileObserver takes care of deleting the physical asset of each one.
 */
class DeleteFileAction extends Action
{
    /** Delete the file (soft or force). */
    public function handle(File $file, bool $forceDelete = false): bool
    {
        return $forceDelete
            ? (bool) $file->forceDelete()
            : (bool) $file->delete();
    }
}
