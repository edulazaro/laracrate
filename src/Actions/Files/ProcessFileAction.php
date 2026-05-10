<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Events\FileProcessed;
use EduLazaro\Laracrate\Events\FileProcessingFailed;
use EduLazaro\Laracrate\Events\FileProcessingStarted;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ProcessingPipelineRegistry;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Orquestador del pipeline de procesamiento.
 *
 * Recorre los pasos del ProcessingPipelineRegistry en orden de prioridad.
 * Cada step decide si aplica via `supports($file)` y ejecuta su trabajo via
 * `handle($file)`. Las apps registran sus propios steps en su ServiceProvider:
 *
 *   app(ProcessingPipelineRegistry::class)
 *       ->add(new MyVirusScanStep())
 *       ->add(new MyOcrStep())
 *       ->remove(\EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep::class);
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
            foreach (app(ProcessingPipelineRegistry::class)->all() as $step) {
                if ($step->supports($file)) {
                    $step->handle($file);
                }
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
}
