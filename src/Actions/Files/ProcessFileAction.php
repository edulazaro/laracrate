<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Events\FileProcessed;
use EduLazaro\Laracrate\Events\FileProcessingFailed;
use EduLazaro\Laracrate\Events\FileProcessingStarted;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laracrate\Support\FileActionRegistry;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Orquestador del pipeline de procesamiento.
 *
 * Recorre los pasos del FileActionRegistry en orden de prioridad.
 * Cada step decide si aplica via `supports($file)` y ejecuta su trabajo via
 * `handle($file)`. Las apps registran sus propios steps en su ServiceProvider:
 *
 *   app(FileActionRegistry::class)
 *       ->add(new MyVirusScanStep())
 *       ->add(new MyOcrStep())
 *       ->remove(\EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep::class);
 *
 * Adicionalmente, cada collection puede declarar steps específicos en su
 * config bajo la clave `actions`. Estos se resuelven via container y se
 * fusionan con los globales antes del sort por priority:
 *
 *   'collections' => [
 *       'documents' => [
 *           ...
 *           'actions' => [
 *               \App\Pipeline\Steps\DetectDeadlinesStep::class,
 *           ],
 *       ],
 *   ],
 *
 * Solo procesa top-level files. Los variants generados internamente marcan
 * processing_status=COMPLETED al crearse, sin pasar por aquí.
 *
 * Eventos disparados:
 *   - FileProcessingStarted  (tras marcar PROCESSING, antes del primer step)
 *   - FileProcessed          (al terminar OK, tras marcar COMPLETED)
 *   - FileProcessingFailed   (cuando un step lanza, tras marcar FAILED)
 *
 * Política de errores: fail-fast. Si un step lanza, el File queda FAILED
 * y la queue reintenta con el backoff configurado en ProcessFileJob. Los
 * pasos posteriores no se ejecutan.
 */
class ProcessFileAction extends Action
{
    public function handle(File $file): File
    {
        if ($file->isVariant()) {
            return $file;
        }

        $file->forceFill([
            'processing_status'     => ProcessingStatus::PROCESSING,
            'processing_started_at' => now(),
        ])->save();

        FileProcessingStarted::dispatch($file);

        try {
            foreach ($this->resolveSteps($file) as $step) {
                // `supports()` es opcional. Si la action lo declara, lo
                // respetamos; si no, se invoca `handle()` directamente.
                if (method_exists($step, 'supports') && ! $step->supports($file)) {
                    continue;
                }
                $step->handle($file);
            }

            $file->forceFill([
                'processing_status' => ProcessingStatus::COMPLETED,
                'processing_error'  => null,
            ])->save();

            FileProcessed::dispatch($file);
        } catch (Throwable $e) {
            $file->forceFill([
                'processing_status' => ProcessingStatus::FAILED,
                'processing_error'  => $e->getMessage(),
            ])->save();

            logger()->error('Laracrate: ProcessFileAction failed', [
                'file_id' => $file->id,
                'type'    => $file->type?->value,
                'error'   => $e->getMessage(),
            ]);

            FileProcessingFailed::dispatch($file, $e);

            throw $e;
        }

        return $file->refresh();
    }

    /**
     * Steps que aplican al file: globales (registry) + específicos de la
     * collection (`actions` en config). Resueltos via container y ordenados
     * por priority ascendente.
     *
     * @return FileActionInterface[]
     */
    protected function resolveSteps(File $file): array
    {
        $global = app(FileActionRegistry::class)->all();

        // Acciones de la collection: top-level + model-specific (acumulativas).
        // Top-level aplica a todos los fileables; las del bloque models.{alias}
        // se SUMAN (no reemplazan) — permite declarar actions generales del
        // colectivo más actions específicas por morph type.
        //
        //   'documents' => [
        //       'actions' => [ClassifyDocumentAction::class],   ← todos los docs
        //       'models'  => [
        //           'case'    => ['actions' => [DetectDeadlinesAction::class]],
        //           'lawsuit' => ['actions' => [AutofillLawsuitAction::class]],
        //       ],
        //   ]
        $rawCollection = config("laracrate.collections.{$file->collection}", []);
        $topLevelActions = $rawCollection['actions'] ?? [];
        $modelActions    = $rawCollection['models'][$file->fileable_type]['actions'] ?? [];
        $collectionActions = array_merge($topLevelActions, $modelActions);

        $custom = [];
        foreach ($collectionActions as $actionClass) {
            $resolved = app($actionClass);
            if ($resolved instanceof FileActionInterface) {
                $custom[] = $resolved;
            } else {
                logger()->warning('Laracrate: collection action does not implement FileActionInterface', [
                    'file_id'    => $file->id,
                    'collection' => $file->collection,
                    'class'      => $actionClass,
                ]);
            }
        }

        $steps = array_merge($global, $custom);
        usort($steps, fn (FileActionInterface $a, FileActionInterface $b) => $a->priority() <=> $b->priority());

        return $steps;
    }
}
