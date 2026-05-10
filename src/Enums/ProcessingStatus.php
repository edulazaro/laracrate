<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Estado del pipeline de procesamiento de un File.
 *
 *   pending     → recién creado, en cola.
 *   processing  → ProcessFileAction está corriendo los steps.
 *   completed   → todos los steps que aplican corrieron sin error.
 *   failed      → un step lanzó; processing_error contiene el mensaje.
 *
 * Aplica solo al File top-level. Las variants nacen ya con `completed`
 * (su action las marca) o sin estado de pipeline.
 */
enum ProcessingStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED => true,
            default => false,
        };
    }

    public function isInProgress(): bool
    {
        return match ($this) {
            self::PENDING, self::PROCESSING => true,
            default => false,
        };
    }
}
