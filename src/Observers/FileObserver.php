<?php

namespace EduLazaro\Laracrate\Observers;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Events\VariantGenerated;
use EduLazaro\Laracrate\Jobs\ProcessFileJob;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use Throwable;

/**
 * Cuando se borra una fila File (force delete o cascade FK), borramos el asset
 * en el backend. Single source of truth: si no hay row, no hay asset.
 */
class FileObserver
{
    public function created(File $file): void
    {
        // Variants: solo evento, sin pipeline (su action ya las marca COMPLETED).
        if ($file->parent_id !== null) {
            VariantGenerated::dispatch($file, $file->parent);
            return;
        }

        if ($file->processing_status !== null) {
            return;
        }

        if (!in_array($file->type?->value, ['image', 'video', 'document', 'audio'], true)) {
            return;
        }

        $file->forceFill(['processing_status' => ProcessingStatus::PENDING])->saveQuietly();

        ProcessFileJob::dispatch($file);
    }

    /**
     * Antes de borrar el padre, force-delete cada hijo vía Eloquent. La FK con
     * cascadeOnDelete elimina las filas en cascada pero NO dispara los observers
     * de los hijos, dejando los assets físicos huérfanos en el backend. Iterando
     * a mano garantizamos que cada hijo pase por su forceDeleted y purge.
     */
    public function deleting(File $file): void
    {
        if (method_exists($file, 'isForceDeleting') && !$file->isForceDeleting()) {
            return;
        }

        foreach ($file->children()->get() as $child) {
            $child->forceDelete();
        }
    }

    public function deleted(File $file): void
    {
        // Soft delete no toca el backend (el archivo se mantiene por si se restaura).
        if (method_exists($file, 'isForceDeleting') && !$file->isForceDeleting()) {
            return;
        }

        $this->purgeFromBackend($file);
    }

    public function forceDeleted(File $file): void
    {
        $this->purgeFromBackend($file);
    }

    protected function purgeFromBackend(File $file): void
    {
        if (!$file->disk || !$file->path) {
            return;
        }

        $key = $file->key;

        try {
            app(StorageManager::class)->deleteFromBackend($file->disk, $key);
        } catch (Throwable $e) {
            // No reventamos por fallo del backend en deletes. Loggeamos.
            logger()->warning('Laracrate: fallo al borrar asset del backend', [
                'file_id' => $file->id,
                'disk'    => $file->disk,
                'key'     => $key,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
