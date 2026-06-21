<?php

namespace EduLazaro\Laracrate\Policies;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard Laravel policy for `File` that delegates to the `PolicyRegistry`.
 * It lets apps use the native Gate ergonomics without abandoning the registry
 * pattern for declaring the logic:
 *
 *   @can('view', $file)               // blade
 *   $user->can('update', $file)       // helper
 *   $this->authorize('delete', $file) // controller
 *   Route::middleware('can:view,file')  // route binding
 *
 * The logic is still declared in the registry from the AppServiceProvider:
 *
 *   app(PolicyRegistry::class)->viewable('case', fn($f, $u) => ...);
 *
 * Mapping to Laravel's canonical methods:
 *   Gate `view`   -> `canView`
 *   Gate `update` -> `canEdit`
 *   Gate `delete` -> `canDelete`
 */
class FilePolicy
{
    /** Whether the user can view the file. */
    public function view(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canView($file, $user);
    }

    /** Whether the user can update the file. */
    public function update(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canEdit($file, $user);
    }

    /** Whether the user can delete the file. */
    public function delete(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canDelete($file, $user);
    }
}
