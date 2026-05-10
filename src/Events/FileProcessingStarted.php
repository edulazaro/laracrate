<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara justo antes de recorrer el pipeline. El File ya está marcado
 * como PROCESSING en BD.
 */
class FileProcessingStarted
{
    use Dispatchable;

    public function __construct(public File $file) {}
}
