<?php

namespace EduLazaro\Laracrate\Policies;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy estándar de Laravel para `File` que delega al `PolicyRegistry`.
 * Permite a las apps usar las ergonomías nativas de Gate sin abandonar el
 * patrón del registry para declarar la lógica:
 *
 *   @can('view', $file)               // blade
 *   $user->can('update', $file)       // helper
 *   $this->authorize('delete', $file) // controller
 *   Route::middleware('can:view,file')  // route binding
 *
 * La lógica sigue declarándose en el registry desde el AppServiceProvider:
 *
 *   app(PolicyRegistry::class)->viewable('case', fn($f, $u) => ...);
 *
 * Mapping a métodos canónicos de Laravel:
 *   Gate `view`   → `canView`
 *   Gate `update` → `canEdit`
 *   Gate `delete` → `canDelete`
 */
class FilePolicy
{
    public function view(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canView($file, $user);
    }

    public function update(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canEdit($file, $user);
    }

    public function delete(?Model $user, File $file): bool
    {
        return app(PolicyRegistry::class)->canDelete($file, $user);
    }
}
