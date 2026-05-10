<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pipeline terminó OK. El File ya está marcado como COMPLETED.
 * Las apps suelen escuchar esto para refrescar UI, invalidar caches,
 * notificar al usuario, etc.
 */
class FileProcessed
{
    use Dispatchable;

    public function __construct(public File $file) {}
}
