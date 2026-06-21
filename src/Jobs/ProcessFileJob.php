<?php

namespace EduLazaro\Laracrate\Jobs;

use EduLazaro\Laracrate\Actions\Files\ProcessFileAction;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queueable wrapper of ProcessFileAction. Dispatched by the FileObserver
 * when a top-level File that needs processing is created.
 */
class ProcessFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 600;

    /**
     * If the File was deleted before the worker reaches the job (typical when
     * `setFile()` replaces the avatar and drags the old ProcessFileJob into
     * orphanhood), Laravel discards the job silently instead of failing 3 times
     * with `ModelNotFoundException` and polluting `failed_jobs`.
     */
    public bool $deleteWhenMissingModels = true;

    /** @param File $file The file to process. */
    public function __construct(public File $file) {}

    /** Run the processing pipeline for the file. */
    public function handle(): void
    {
        ProcessFileAction::create()->run(['file' => $this->file]);
    }

    /** Queue name this job runs on. */
    public function viaQueue(): string
    {
        return config('laracrate.queue.name', 'default');
    }

    /** Queue connection this job runs on. */
    public function viaConnection(): ?string
    {
        return config('laracrate.queue.connection');
    }
}
