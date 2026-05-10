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
 * Wrapper queueable de ProcessFileAction. Lo dispatcha el FileObserver
 * cuando se crea un File top-level que necesita procesamiento.
 */
class ProcessFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 600;

    /**
     * Si el File se borró antes de que el worker llegue al job (típico cuando
     * `setFile()` reemplaza el avatar y arrastra al ProcessFileJob viejo a la
     * orfandad), Laravel descarta el job en silencio en vez de fallar 3 veces
     * con `ModelNotFoundException` y ensuciar `failed_jobs`.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public File $file) {}

    public function handle(): void
    {
        ProcessFileAction::create()->run(['file' => $this->file]);
    }

    public function viaQueue(): string
    {
        return config('laracrate.queue.name', 'default');
    }

    public function viaConnection(): ?string
    {
        return config('laracrate.queue.connection');
    }
}
