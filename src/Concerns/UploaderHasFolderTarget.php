<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Models\Folder;
use Livewire\Attributes\Locked;

/**
 * Trait for upload Livewire components (dropzones / uploaders) that want to
 * land files inside a specific folder. Without this the file goes to the root
 * of the fileable, as it always has.
 *
 * Usage in the component:
 *   use UploaderHasFolderTarget;
 *
 *   public function mount(..., ?int $folderId = null): void {
 *       $this->folderId = $folderId;
 *   }
 *
 *   // In registerUploaded(): $this->model->addFile($upload, $coll, folder: $this->folder())
 */
trait UploaderHasFolderTarget
{
    /**
     * Target folder ID. Locked so the client cannot change it from the front
     * end. If null, the file lands at the root of the fileable.
     */
    #[Locked]
    public ?int $folderId = null;

    /**
     * Resolves the target Folder. Cross-fileable validation is done in
     * HasFiles::addFile (defense in depth). Here we only load it.
     */
    protected function folder(): ?Folder
    {
        return $this->folderId ? Folder::find($this->folderId) : null;
    }
}
