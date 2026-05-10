<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro central de policies del paquete. Las apps registran callbacks
 * por morph alias del fileable (case, property, horse, ...) en su boot()
 * del AppServiceProvider:
 *
 *   app(PolicyRegistry::class)->viewable('case', fn ($file, $user) => ...);
 *
 * Si no hay policy registrada para un type, aplican defaults: el creador
 * humano siempre puede ver/editar/borrar; el resto depende de access.
 */
class PolicyRegistry
{
    /** @var array<string, callable(File, ?Model): bool> */
    protected array $viewPolicies = [];

    /** @var array<string, callable(File, ?Model): bool> */
    protected array $editPolicies = [];

    /** @var array<string, callable(File, ?Model): bool> */
    protected array $deletePolicies = [];

    public function viewable(string $fileableType, callable $callback): self
    {
        $this->viewPolicies[$fileableType] = $callback;
        return $this;
    }

    public function editable(string $fileableType, callable $callback): self
    {
        $this->editPolicies[$fileableType] = $callback;
        return $this;
    }

    public function deletable(string $fileableType, callable $callback): self
    {
        $this->deletePolicies[$fileableType] = $callback;
        return $this;
    }

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

    protected function isCreator(File $file, ?Model $user): bool
    {
        if (!$user || $file->creator_type !== 'user' || !$file->creator_id) {
            return false;
        }
        return (int) $user->getKey() === (int) $file->creator_id;
    }
}
