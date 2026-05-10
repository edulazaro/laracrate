<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Estado de una sesión de upload multipart.
 *
 *   active     → en curso, partes subiéndose o pendientes.
 *   completed  → CompleteMultipartUpload OK, file_id apunta al File creado.
 *   aborted    → cancelado explícitamente (usuario o expiración por cron).
 *   expired    → expires_at pasó sin completar; el cron lo marca y aborta en S3.
 */
enum MultipartUploadStatus: string
{
    case ACTIVE    = 'active';
    case COMPLETED = 'completed';
    case ABORTED   = 'aborted';
    case EXPIRED   = 'expired';

    public function isTerminal(): bool
    {
        return $this !== self::ACTIVE;
    }
}
