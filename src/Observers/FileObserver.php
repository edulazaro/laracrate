<?php

namespace EduLazaro\Laracrate\Observers;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Events\VariantGenerated;
use EduLazaro\Laracrate\Jobs\ProcessFileJob;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\Folderable;
use EduLazaro\Laracrate\Services\StorageManager;
use Throwable;

/**
 * When a File row is deleted (force delete or cascade FK), we delete the asset
 * in the backend. Single source of truth: if there is no row, there is no asset.
 */
class FileObserver
{
    /** Track usage and dispatch processing when a file is created. */
    public function created(File $file): void
    {
        // Usage counter: top-level only (variants do not count twice).
        $this->incrementUsage($file);

        // Variants: event only, no pipeline (their action already marks them COMPLETED).
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
     * Before deleting the parent, force-delete each child via Eloquent. The FK
     * with cascadeOnDelete removes the rows in cascade but does NOT trigger the
     * children's observers, leaving the physical assets orphaned in the backend.
     * By iterating manually we guarantee each child goes through its forceDeleted
     * and purge.
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

    /** Purge the backend asset on a hard delete (skips soft deletes). */
    public function deleted(File $file): void
    {
        // Soft delete does not touch the backend (the file is kept in case it is restored).
        if (method_exists($file, 'isForceDeleting') && !$file->isForceDeleting()) {
            return;
        }

        $this->purgeFromBackend($file);
    }

    /** Decrement usage and purge backend + chunks on a force delete. */
    public function forceDeleted(File $file): void
    {
        $this->decrementUsage($file);
        $this->purgeFromBackend($file);
        $this->purgeChunksFromStore($file);
    }

    /**
     * If the file's collection has `track_usage = true` and is top-level
     * (not a variant), adds its size + count to the corresponding Folderable
     * row. Atomic upsert: if the row does not exist, it creates it.
     */
    protected function incrementUsage(File $file): void
    {
        if (! $this->shouldTrackUsage($file)) {
            return;
        }

        try {
            $row = Folderable::firstOrNew([
                'folderable_type' => $file->fileable_type,
                'folderable_id'   => $file->fileable_id,
                'collection'      => $file->collection,
            ]);
            $row->total_size_bytes = (int) $row->total_size_bytes + (int) $file->size;
            $row->files_count      = (int) $row->files_count + 1;
            $row->save();
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to increment usage', [
                'file_id'    => $file->id,
                'collection' => $file->collection,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /** Subtract this file's size + count from the Folderable usage row. */
    protected function decrementUsage(File $file): void
    {
        if (! $this->shouldTrackUsage($file)) {
            return;
        }

        try {
            $row = Folderable::query()
                ->where('folderable_type', $file->fileable_type)
                ->where('folderable_id', $file->fileable_id)
                ->where('collection', $file->collection)
                ->first();

            if (! $row) return;

            $row->total_size_bytes = max(0, (int) $row->total_size_bytes - (int) $file->size);
            $row->files_count      = max(0, (int) $row->files_count - 1);
            $row->save();
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to decrement usage', [
                'file_id'    => $file->id,
                'collection' => $file->collection,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * We only track top-level files (variants do not count, they are already
     * in the parent) and collections with the `track_usage = true` flag in config.
     */
    protected function shouldTrackUsage(File $file): bool
    {
        if ($file->parent_id !== null) return false;
        if (! $file->fileable_type || ! $file->fileable_id) return false;

        return Folderable::isTracked((string) $file->collection);
    }

    /**
     * Purges chunks from the active backend (Meili / Qdrant / etc). In MySQL
     * mode the FK cascade of `laracrate_file_chunks.file_id` already deletes the
     * rows; MysqlChunkStore runs it for idempotency (re-builds, tests).
     */
    protected function purgeChunksFromStore(File $file): void
    {
        try {
            app(\EduLazaro\Laracrate\Contracts\ChunkStore::class)->deleteByFile($file);
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to delete chunks from the ChunkStore', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /** Delete the main binary and its extraction/chunk sidecars from the backend. */
    protected function purgeFromBackend(File $file): void
    {
        if (!$file->disk || !$file->path) {
            return;
        }

        $key = $file->key;
        $manager = app(StorageManager::class);

        // We delete the main binary + extraction/chunk sidecars.
        // The extracted content and JSONL chunks live adjacent to the binary:
        //   {path}.json         -> structured extracted content (full_text + pages)
        //   {path}.chunks.jsonl -> chunks + embeddings (backup)
        // We do not throw on failure: log and continue (best-effort).
        foreach ([$key, $key . '.json', $key . '.chunks.jsonl'] as $assetKey) {
            try {
                $manager->deleteFromBackend($file->disk, $assetKey);
            } catch (Throwable $e) {
                logger()->warning('Laracrate: failed to delete asset from the backend', [
                    'file_id' => $file->id,
                    'disk'    => $file->disk,
                    'key'     => $assetKey,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
