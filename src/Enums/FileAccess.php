<?php

namespace EduLazaro\Laracrate\Enums;

enum FileAccess: string
{
    /**
     * URL directa al CDN (Storage::url()). Sin firma, sin audit.
     */
    case PUBLIC = 'public';

    /**
     * URL firmada temporal (Storage::temporaryUrl()), cacheada server-side.
     */
    case SIGNED = 'signed';

    /**
     * Sirve por controller del paquete: audit, permisos por request,
     * opcionalmente bind viewer (sensitive), encrypt y watermark.
     */
    case STREAM = 'stream';
}
