<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Access mode of a File collection: how its contents are served.
 */
enum FileAccess: string
{
    /**
     * Direct URL to the CDN (Storage::url()). No signature, no audit.
     */
    case PUBLIC = 'public';

    /**
     * Temporary signed URL (Storage::temporaryUrl()), cached server-side.
     */
    case SIGNED = 'signed';

    /**
     * Served by the package controller: audit, per-request permissions,
     * optionally viewer bind (sensitive), encrypt and watermark.
     */
    case STREAM = 'stream';
}
