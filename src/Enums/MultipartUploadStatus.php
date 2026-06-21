<?php

namespace EduLazaro\Laracrate\Enums;

/**
 * Status of a multipart upload session.
 *
 *   active     → in progress, parts uploading or pending.
 *   completed  → CompleteMultipartUpload OK, file_id points to the created File.
 *   aborted    → explicitly cancelled (user or cron expiration).
 *   expired    → expires_at passed without completing; the cron marks it and aborts in S3.
 */
enum MultipartUploadStatus: string
{
    case ACTIVE    = 'active';
    case COMPLETED = 'completed';
    case ABORTED   = 'aborted';
    case EXPIRED   = 'expired';

    /** Whether this status is final (anything other than active). */
    public function isTerminal(): bool
    {
        return $this !== self::ACTIVE;
    }
}
