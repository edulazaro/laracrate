<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired right before running the pipeline. The File is already marked
 * as PROCESSING in the database.
 */
class FileProcessingStarted
{
    use Dispatchable;

    /** Create the event for the file about to be processed. */
    public function __construct(public File $file) {}
}
