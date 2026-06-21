<?php

namespace EduLazaro\Laracrate\Concerns;

use EduLazaro\Laracrate\Models\Folder;
use EduLazaro\Laracrate\Models\Folderable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait parallel to HasFiles for models that have a folder tree
 * (personal User Drive, Organization Drive, etc.).
 *
 * Typical pattern:
 *   $user->folders();                 // all the user's folders
 *   $user->rootFolders();             // only roots (parent_id null)
 *   $user->addFolder('Contracts');    // creates a root folder
 *   $folder->children()->addFolder('2025'); // (uses the trait via $folder->...)
 */
trait HasFolders
{
    /**
     * All folders of this model, at any level of the tree.
     */
    public function folders(): MorphMany
    {
        return $this->morphMany(Folder::class, 'folderable');
    }

    /**
     * Only root folders (top-level).
     */
    public function rootFolders()
    {
        return $this->folders()->whereNull('parent_id')->orderBy('name');
    }

    /**
     * Creates a folder. If $parent is null, it stays at the root. The observer
     * recomputes and stores the denormalized path.
     *
     * @param  Model|null  $creator  Who created it (audit). If null and auth is
     *                               available, falls back to the authenticated user.
     */
    public function addFolder(
        string $name,
        ?Folder $parent = null,
        ?Model $creator = null,
        array $metadata = []
    ): Folder {
        $creator = $creator ?? (auth()->check() ? auth()->user() : null);

        if ($parent && (
            $parent->folderable_type !== $this->getMorphClass()
            || (string) $parent->folderable_id !== (string) $this->getKey()
        )) {
            throw new \InvalidArgumentException(
                'The parent belongs to another folderable.'
            );
        }

        $folder = new Folder([
            'folderable_type' => $this->getMorphClass(),
            'folderable_id'   => $this->getKey(),
            'parent_id'       => $parent?->id,
            'name'            => $name,
            'metadata'        => $metadata ?: null,
        ]);

        if ($creator) {
            $folder->creator_type = $creator->getMorphClass();
            $folder->creator_id   = $creator->getKey();
        }

        $folder->save();

        return $folder;
    }

    /**
     * Returns the Folderable row of this model for the given collection.
     * Null if the collection does not have `track_usage` or there are no
     * tracked files yet (the row is created lazily on the first `created`).
     */
    public function usage(string $collection): ?Folderable
    {
        return Folderable::query()
            ->where('folderable_type', $this->getMorphClass())
            ->where('folderable_id', $this->getKey())
            ->where('collection', $collection)
            ->first();
    }

    /**
     * Shortcut: bytes occupied by this model in the given collection.
     * 0 if there is nothing yet or if the collection is not tracked.
     */
    public function usageBytes(string $collection): int
    {
        return (int) ($this->usage($collection)?->total_size_bytes ?? 0);
    }
}
