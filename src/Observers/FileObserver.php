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
        $this->purgeChunksFromStore($file);
    }

    /**
     * Purga chunks del backend activo (Meili / Qdrant / etc). En modo MySQL
     * la cascade FK de `laracrate_file_chunks.file_id` ya borra las filas;
     * MysqlChunkStore lo ejecuta por idempotencia (re-builds, tests).
     */
    protected function purgeChunksFromStore(File $file): void
    {
        try {
            app(\EduLazaro\Laracrate\Contracts\ChunkStore::class)->deleteByFile($file);
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al borrar chunks del ChunkStore', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    protected function purgeFromBackend(File $file): void
    {
        if (!$file->disk || !$file->path) {
            return;
        }

        $key = $file->key;
        $manager = app(StorageManager::class);

        // Borramos el binario principal + sidecars de extracción/chunks.
        // El contenido extraído y los chunks JSONL viven adyacentes al binario:
        //   {path}.json         → contenido extraído estructurado (full_text + pages)
        //   {path}.chunks.jsonl → chunks + embeddings (backup)
        // No lanzamos si fallan: log y seguimos (best-effort).
        foreach ([$key, $key . '.json', $key . '.chunks.jsonl'] as $assetKey) {
            try {
                $manager->deleteFromBackend($file->disk, $assetKey);
            } catch (Throwable $e) {
                logger()->warning('Laracrate: fallo al borrar asset del backend', [
                    'file_id' => $file->id,
                    'disk'    => $file->disk,
                    'key'     => $assetKey,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
