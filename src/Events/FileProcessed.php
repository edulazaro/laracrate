<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pipeline finished OK. The File is already marked as COMPLETED.
 * Apps usually listen to this to refresh the UI, invalidate caches,
 * notify the user, etc.
 */
class FileProcessed
{
    use Dispatchable;

    /** Create the event for the successfully processed file. */
    public function __construct(public File $file) {}
}
