<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Database\Eloquent\Model;

/**
 * Central policy registry for the package. Apps register callbacks by the
 * fileable's morph alias (case, property, horse, ...) in their
 * AppServiceProvider boot():
 *
 *   app(PolicyRegistry::class)->viewable('case', fn ($file, $user) => ...);
 *
 * If no policy is registered for a type, defaults apply: the human creator
 * can always view/edit/delete; everything else depends on access.
 */
class PolicyRegistry
{
    /** @var array<string, callable(File, ?Model): bool> */
    protected array $viewPolicies = [];

    /** @var array<string, callable(File, ?Model): bool> */
    protected array $editPolicies = [];

    /** @var array<string, callable(File, ?Model): bool> */
    protected array $deletePolicies = [];

    /** Register the view policy for a fileable type. */
    public function viewable(string $fileableType, callable $callback): self
    {
        $this->viewPolicies[$fileableType] = $callback;
        return $this;
    }

    /** Register the edit policy for a fileable type. */
    public function editable(string $fileableType, callable $callback): self
    {
        $this->editPolicies[$fileableType] = $callback;
        return $this;
    }

    /** Register the delete policy for a fileable type. */
    public function deletable(string $fileableType, callable $callback): self
    {
        $this->deletePolicies[$fileableType] = $callback;
        return $this;
    }

    /** Whether the user can view the file. */
    public function canView(File $file, ?Model $user): bool
    {
        if ($this->isCreator($file, $user)) {
            return true;
        }

        if ($file->access?->value === 'public') {
            return true;
        }

        $callback = $this->viewPolicies[$file->fileable_type] ?? null;
        if ($callback) {
            return (bool) $callback($file, $user);
        }

        return false;
    }

    /** Whether the user can edit the file. */
    public function canEdit(File $file, ?Model $user): bool
    {
        if ($this->isCreator($file, $user)) {
            return true;
        }

        $callback = $this->editPolicies[$file->fileable_type] ?? null;
        if ($callback) {
            return (bool) $callback($file, $user);
        }

        return false;
    }

    /** Whether the user can delete the file. */
    public function canDelete(File $file, ?Model $user): bool
    {
        if ($this->isCreator($file, $user)) {
            return true;
        }

        $callback = $this->deletePolicies[$file->fileable_type] ?? null;
        if ($callback) {
            return (bool) $callback($file, $user);
        }

        return false;
    }

    /** Whether the user is the human creator of the file. */
    protected function isCreator(File $file, ?Model $user): bool
    {
        if (!$user || $file->creator_type !== 'user' || !$file->creator_id) {
            return false;
        }
        return (int) $user->getKey() === (int) $file->creator_id;
    }
}
