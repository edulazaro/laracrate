<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Actions\Multipart\AbortMultipartUploadAction;
use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aborta sesiones multipart que pasaron expires_at sin completar.
 *
 * CRÍTICO en producción: sin esto, las partes ya subidas a S3/R2 quedan
 * ocupando storage y facturándote indefinidamente. Programar en
 * `routes/console.php` o equivalente:
 *
 *   Schedule::command('laracrate:abort-stale-multipart')->hourly();
 */
class AbortStaleMultipartCommand extends Command
{
    protected $signature = 'laracrate:abort-stale-multipart
                            {--dry-run : Solo lista, no aborta}
                            {--limit=500 : Máximo de sesiones a procesar por ejecución}';

    protected $description = 'Aborta sesiones multipart de S3/R2 que han expirado';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry   = (bool) $this->option('dry-run');

        $stale = MultipartUpload::stale()->limit($limit)->get();

        if ($stale->isEmpty()) {
            $this->info('No hay sesiones multipart expiradas.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d sesion(es) expirada(s)%s.',
            $dry ? 'Encontradas' : 'Procesando',
            $stale->count(),
            $dry ? ' (dry-run)' : '',
        ));

        $aborted = 0;
        $failed  = 0;

        foreach ($stale as $upload) {
            $this->line(sprintf(
                '  - upload_id=%s disk=%s key=%s expires_at=%s',
                $upload->upload_id,
                $upload->disk,
                $upload->key,
                $upload->expires_at?->toIso8601String() ?? '-',
            ));

            if ($dry) {
                continue;
            }

            try {
                AbortMultipartUploadAction::create()->run([
                    'upload' => $upload,
                    'reason' => MultipartUploadStatus::EXPIRED,
                ]);
                $aborted++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("    fallo: {$e->getMessage()}");
            }
        }

        if (!$dry) {
            $this->info("Abortadas: {$aborted}. Fallidas: {$failed}.");
        }

        return self::SUCCESS;
    }
}
