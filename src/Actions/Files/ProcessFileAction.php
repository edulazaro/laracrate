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
 * Orchestrator of the processing pipeline.
 *
 * Iterates the FileActionRegistry steps in priority order.
 * Each step decides whether it applies via `supports($file)` and runs its
 * work via `handle($file)`. Apps register their own steps in their
 * ServiceProvider:
 *
 *   app(FileActionRegistry::class)
 *       ->add(new MyVirusScanStep())
 *       ->add(new MyOcrStep())
 *       ->remove(\EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep::class);
 *
 * Additionally, each collection can declare specific steps in its config
 * under the `actions` key. These are resolved via the container and merged
 * with the global ones before the sort by priority:
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
 * Only processes top-level files. Internally generated variants mark
 * processing_status=COMPLETED on creation, without going through here.
 *
 * Dispatched events:
 *   - FileProcessingStarted  (after marking PROCESSING, before the first step)
 *   - FileProcessed          (when finishing OK, after marking COMPLETED)
 *   - FileProcessingFailed   (when a step throws, after marking FAILED)
 *
 * Error policy: fail-fast. If a step throws, the File stays FAILED and the
 * queue retries with the backoff configured in ProcessFileJob. The later
 * steps are not executed.
 */
class ProcessFileAction extends Action
{
    /** Run the ordered pipeline steps for a top-level file. */
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
                // `supports()` is optional. If the action declares it, we
                // respect it; otherwise `handle()` is invoked directly.
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
     * Steps that apply to the file: global (registry) + collection-specific
     * (`actions` in config). Resolved via the container and ordered by
     * ascending priority.
     *
     * @return FileActionInterface[]
     */
    protected function resolveSteps(File $file): array
    {
        $global = app(FileActionRegistry::class)->all();

        // Collection actions: top-level + model-specific (cumulative).
        // Top-level applies to all fileables; those in the models.{alias} block
        // are ADDED (not replaced), allowing general collective actions plus
        // morph-type-specific actions.
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
