<?php

namespace EduLazaro\Laracrate\Console\Commands;

use EduLazaro\Laracrate\Actions\Multipart\AbortMultipartUploadAction;
use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use Illuminate\Console\Command;
use Throwable;

/**
 * Aborts multipart sessions that passed expires_at without completing.
 *
 * CRITICAL in production: without this, the parts already uploaded to S3/R2
 * keep occupying storage and billing you indefinitely. Schedule it in
 * `routes/console.php` or equivalent:
 *
 *   Schedule::command('laracrate:abort-stale-multipart')->hourly();
 */
class AbortStaleMultipartCommand extends Command
{
    protected $signature = 'laracrate:abort-stale-multipart
                            {--dry-run : Only list, do not abort}
                            {--limit=500 : Maximum sessions to process per run}';

    protected $description = 'Aborts expired S3/R2 multipart sessions';

    /** Aborts stale multipart sessions, or lists them in dry-run mode. */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry   = (bool) $this->option('dry-run');

        $stale = MultipartUpload::stale()->limit($limit)->get();

        if ($stale->isEmpty()) {
            $this->info('No expired multipart sessions.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d expired session(s)%s.',
            $dry ? 'Found' : 'Processing',
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
                $this->error("    failed: {$e->getMessage()}");
            }
        }

        if (!$dry) {
            $this->info("Aborted: {$aborted}. Failed: {$failed}.");
        }

        return self::SUCCESS;
    }
}
