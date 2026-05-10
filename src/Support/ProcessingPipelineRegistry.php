<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\ProcessingStep;
use EduLazaro\Laracrate\Models\File;

/**
 * Registry de pasos del pipeline de procesamiento de Files.
 *
 * El paquete registra los pasos default en LaracrateServiceProvider; las apps
 * pueden añadir o quitar pasos en su propio ServiceProvider.
 *
 *   $registry = app(ProcessingPipelineRegistry::class);
 *   $registry->add(new MyVirusScanStep());
 *   $registry->remove(OptimizeImageStep::class);
 *
 * El orden de ejecución se calcula por `priority()` ascendente. La inserción
 * en el array no fija el orden, así que las apps pueden registrar en cualquier
 * momento y la prioridad declarada gana.
 */
class ProcessingPipelineRegistry
{
    /** @var ProcessingStep[] */
    protected array $steps = [];

    public function add(ProcessingStep $step): static
    {
        $this->steps[] = $step;
        return $this;
    }

    /**
     * Quita un step por su FQCN. Útil para que la app desactive defaults.
     */
    public function remove(string $stepClass): static
    {
        $this->steps = array_values(array_filter(
            $this->steps,
            fn (ProcessingStep $s) => !($s instanceof $stepClass)
        ));
        return $this;
    }

    /**
     * Devuelve todos los steps registrados, ordenados por prioridad ascendente.
     *
     * @return ProcessingStep[]
     */
    public function all(): array
    {
        $steps = $this->steps;

        usort($steps, fn (ProcessingStep $a, ProcessingStep $b) => $a->priority() <=> $b->priority());

        return $steps;
    }

    /**
     * Devuelve solo los steps que aplican al File en el momento actual,
     * en orden de prioridad. Útil para introspección/debug.
     *
     * @return ProcessingStep[]
     */
    public function applicableFor(File $file): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ProcessingStep $s) => $s->supports($file)
        ));
    }
}
