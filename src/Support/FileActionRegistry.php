<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;

/**
 * Global registry of actions for the File processing pipeline.
 *
 * The package registers default actions in `LaracrateServiceProvider`; apps
 * can add more globally from their own ServiceProvider:
 *
 *   $registry = app(FileActionRegistry::class);
 *   $registry->add(new MyVirusScanAction());
 *   $registry->remove(OptimizeImageAction::class);
 *
 * For collection-specific actions (without touching the ServiceProvider),
 * declare them in `config/laracrate.php` under `collections.*.actions`.
 * `ProcessFileAction` merges them at runtime.
 *
 * Execution order is computed by ascending `priority()`. Insertion order in
 * the array does not fix the order, so apps can register at any time and the
 * declared priority wins.
 */
class FileActionRegistry
{
    /** @var FileActionInterface[] */
    protected array $actions = [];

    /** Register an action in the pipeline. */
    public function add(FileActionInterface $action): static
    {
        $this->actions[] = $action;
        return $this;
    }

    /**
     * Remove an action by its FQCN. Useful for apps to disable defaults.
     */
    public function remove(string $actionClass): static
    {
        $this->actions = array_values(array_filter(
            $this->actions,
            fn (FileActionInterface $a) => !($a instanceof $actionClass)
        ));
        return $this;
    }

    /**
     * Return all registered actions, ordered by ascending priority.
     *
     * @return FileActionInterface[]
     */
    public function all(): array
    {
        $actions = $this->actions;

        usort($actions, fn (FileActionInterface $a, FileActionInterface $b) => $a->priority() <=> $b->priority());

        return $actions;
    }

    /**
     * Return only the actions that apply to the File at the current moment,
     * in priority order. Useful for introspection/debugging.
     *
     * @return FileActionInterface[]
     */
    public function applicableFor(File $file): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (FileActionInterface $a) => $a->supports($file)
        ));
    }
}
