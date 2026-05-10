<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * Un step del pipeline lanzó. El File queda FAILED y processing_error
 * tiene el mensaje. La queue reintentará si el job aún tiene tries.
 */
class FileProcessingFailed
{
    use Dispatchable;

    public function __construct(
        public File $file,
        public Throwable $exception,
    ) {}
}
