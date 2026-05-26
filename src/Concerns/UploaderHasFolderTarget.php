<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Models\Folder;
use Livewire\Attributes\Locked;

/**
 * Trait para componentes Livewire de upload (dropzones / uploaders) que
 * quieran aterrizar los archivos dentro de una carpeta concreta. Sin esto
 * el archivo va a la raíz del fileable, como toda la vida.
 *
 * Uso en el componente:
 *   use UploaderHasFolderTarget;
 *
 *   public function mount(..., ?int $folderId = null): void {
 *       $this->folderId = $folderId;
 *   }
 *
 *   // En registerUploaded(): $this->model->addFile($upload, $coll, folder: $this->folder())
 */
trait UploaderHasFolderTarget
{
    /**
     * ID de la carpeta destino. Lockeado para que el cliente no lo cambie
     * desde el front. Si null, el file aterriza en la raíz del fileable.
     */
    #[Locked]
    public ?int $folderId = null;

    /**
     * Resuelve la Folder destino. Validación cross-fileable se hace en
     * HasFiles::addFile (defensa en profundidad). Aquí solo cargamos.
     */
    protected function folder(): ?Folder
    {
        return $this->folderId ? Folder::find($this->folderId) : null;
    }
}
