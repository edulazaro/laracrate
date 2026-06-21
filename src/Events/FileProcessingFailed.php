<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * A pipeline step threw. The File is left FAILED and processing_error
 * holds the message. The queue will retry if the job still has tries left.
 */
class FileProcessingFailed
{
    use Dispatchable;

    /** Create the event for the failed file and its exception. */
    public function __construct(
        public File $file,
        public Throwable $exception,
    ) {}
}
