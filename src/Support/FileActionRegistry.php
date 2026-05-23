<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Models\File;

/**
 * Registry global de acciones del pipeline de procesamiento de Files.
 *
 * El paquete registra acciones default en `LaracrateServiceProvider`; las
 * apps pueden añadir más globalmente desde su propio ServiceProvider:
 *
 *   $registry = app(FileActionRegistry::class);
 *   $registry->add(new MyVirusScanAction());
 *   $registry->remove(OptimizeImageAction::class);
 *
 * Para acciones específicas de una collection (sin tocar el ServiceProvider),
 * se declaran en `config/laracrate.php` bajo `collections.*.actions`. Las
 * fusiona `ProcessFileAction` en runtime.
 *
 * El orden de ejecución se calcula por `priority()` ascendente. La inserción
 * en el array no fija el orden, así que las apps pueden registrar en cualquier
 * momento y la prioridad declarada gana.
 */
class FileActionRegistry
{
    /** @var FileActionInterface[] */
    protected array $actions = [];

    public function add(FileActionInterface $action): static
    {
        $this->actions[] = $action;
        return $this;
    }

    /**
     * Quita una acción por su FQCN. Útil para que la app desactive defaults.
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
     * Devuelve todas las acciones registradas, ordenadas por prioridad ascendente.
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
     * Devuelve solo las acciones que aplican al File en el momento actual,
     * en orden de prioridad. Útil para introspección/debug.
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
