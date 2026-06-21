<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Status of a File's processing pipeline.
 *
 *   pending     → just created, queued.
 *   processing  → ProcessFileAction is running the steps.
 *   completed   → all applicable steps ran without error.
 *   failed      → a step threw; processing_error holds the message.
 *
 * Applies only to the top-level File. Variants are born already `completed`
 * (their action marks them) or without a pipeline status.
 */
enum ProcessingStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';

    /** Whether this status is final (completed or failed). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED => true,
            default => false,
        };
    }

    /** Whether this status means the pipeline is still running (pending or processing). */
    public function isInProgress(): bool
    {
        return match ($this) {
            self::PENDING, self::PROCESSING => true,
            default => false,
        };
    }
}
