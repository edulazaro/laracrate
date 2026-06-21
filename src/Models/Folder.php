<?php

namespace EduLazaro\Laracrate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
// File is in the same namespace, no explicit use needed (auto-resolution).

/**
 * Folder to organize files. A parent/child tree with a denormalized path.
 *
 * Source of truth = parent_id. The path field is recalculated via an observer
 * on save from parent.path + name; a manual mutator on path is overwritten.
 *
 * Polymorphic: folderable points to who owns the tree (User for a personal
 * Drive, Organization for an office Drive, etc.). Same pattern as
 * files.fileable.
 */
class Folder extends Model
{
    use SoftDeletes;

    protected $table = 'laracrate_folders';

    protected $fillable = [
        'folderable_type',
        'folderable_id',
        'parent_id',
        'name',
        'path',
        'creator_type',
        'creator_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /** The model that owns this folder tree. */
    public function folderable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The model that created this folder. */
    public function creator(): MorphTo
    {
        return $this->morphTo();
    }

    /** Parent folder. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct children (one level). For deep descendants use
     * descendants() or path LIKE queries.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    /**
     * Files directly inside this folder (non-recursive).
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id')
            ->whereNull('parent_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Chain of ancestors from the root to this folder (inclusive).
     * Useful for breadcrumbs.
     */
    public function breadcrumb(): array
    {
        $chain = [];
        $cursor = $this;
        while ($cursor) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent;
        }
        return $chain;
    }

    /**
     * True if $candidate is this folder or one of its descendants.
     * Used by moveTo() to avoid cycles in the tree.
     */
    public function isDescendantOf(self $candidate): bool
    {
        $cursor = $this;
        while ($cursor) {
            if ($cursor->id === $candidate->id) {
                return true;
            }
            $cursor = $cursor->parent;
        }
        return false;
    }

    /**
     * Changes the parent. The observer recalculates the path in cascade for
     * all descendants. Throws if it would create a cycle or if it changes the
     * folderable (moving between different trees is not allowed).
     */
    public function moveTo(?self $newParent): void
    {
        if ($newParent) {
            if ($newParent->folderable_type !== $this->folderable_type
                || (string) $newParent->folderable_id !== (string) $this->folderable_id) {
                throw new \InvalidArgumentException(
                    'A folder cannot be moved between different folderables.'
                );
            }
            if ($newParent->isDescendantOf($this)) {
                throw new \InvalidArgumentException(
                    'The move would create a cycle in the tree.'
                );
            }
        }

        $this->parent_id = $newParent?->id;
        $this->save();
    }

    /**
     * Full path from the root to this folder. Matches the denormalized
     * `path` column unless you are in an intermediate state before the save
     * (the observer will regenerate it).
     */
    public function computePath(): string
    {
        $parentPath = $this->parent?->path;
        return $parentPath ? $parentPath . '/' . $this->name : $this->name;
    }

    /**
     * All descendants (recursive). Uses the denormalized path to avoid SQL
     * recursion: a single indexed query.
     */
    public function descendants()
    {
        return self::query()
            ->where('folderable_type', $this->folderable_type)
            ->where('folderable_id', $this->folderable_id)
            ->where('path', 'LIKE', $this->path . '/%');
    }

    /**
     * All files of the subtree (recursive): those of this folder + those of
     * its descendants. A single query with whereIn over folder_id.
     */
    public function allFiles()
    {
        $folderIds = $this->descendants()->pluck('id')->push($this->id)->all();

        return File::query()
            ->whereIn('folder_id', $folderIds)
            ->whereNull('parent_id'); // top-level (excludes variants)
    }

    /**
     * Sum of sizes in bytes of all files of the subtree. The UI uses it to
     * show the weight of a folder.
     */
    public function sizeBytes(): int
    {
        return (int) $this->allFiles()->sum('size');
    }

    /**
     * Hard delete of the whole tree: descendants + files of each one go
     * through forceDelete, which triggers the FileObserver and purges R2 +
     * chunks. Use from "empty trash" or "delete permanently".
     */
    public function forceDeleteRecursive(): void
    {
        // 1) Files first (all of them in the subtree).
        File::query()
            ->whereIn('folder_id', $this->descendants()->pluck('id')->push($this->id)->all())
            ->whereNull('parent_id')
            ->get()
            ->each(fn (File $f) => $f->forceDelete());

        // 2) Descendants (deepest-first so as not to break FK during the delete).
        $this->descendants()
            ->orderByDesc('path')
            ->get()
            ->each(fn (self $f) => $f->forceDelete());

        // 3) This folder.
        $this->forceDelete();
    }
}
