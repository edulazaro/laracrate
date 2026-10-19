<?php

namespace EduLazaro\Laracrate\Concerns;

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
 *   // IssuesUploadGrants puts $this->folderId in the upload grant.
 */
trait UploaderHasFolderTarget
{
    /**
     * Target folder ID. Locked so the client cannot change it from the front
     * end. If null, the file lands at the root of the fileable.
     */
    #[Locked]
    public ?int $folderId = null;
}
