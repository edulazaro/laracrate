<?php

namespace EduLazaro\Laracrate\Actions\Files;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Jobs\ProcessFileJob;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use EduLazaro\Laractions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * Requests the on-demand processing of a file (`extract`, `embed` or `visual`
 * set to 'on_demand' for its type).
 *
 * Records who asked and when in the file's metadata, which is what makes the
 * resolver treat the on-demand modes as active for this file, and runs the
 * text pipeline again from the semantic extraction band (priority 60), so the
 * original is not transcoded nor its variants regenerated.
 */
class RequestExtractionAction extends Action
{
    /** First priority of the semantic extraction band, see FileActionInterface. */
    public const FROM_PRIORITY = 60;

    /**
     * Mark the file as requested and process it, in the queue unless `$sync`.
     * Returns false when there is nothing to request for this file.
     */
    public function handle(File $file, ?Model $requester = null, bool $sync = false): bool
    {
        if (! ExtractionResolver::canRequest($file)) {
            return false;
        }

        $file->forceFill([
            'metadata' => array_merge((array) ($file->metadata ?? []), [
                ExtractionResolver::REQUESTED_AT => now()->toIso8601String(),
                'extraction_requested_by_type'   => $requester?->getMorphClass(),
                'extraction_requested_by_id'     => $requester?->getKey(),
            ]),
            'processing_status' => ProcessingStatus::PENDING,
            'processing_error'  => null,
        ])->save();

        $job = new ProcessFileJob($file, self::FROM_PRIORITY);

        $sync ? dispatch_sync($job) : dispatch($job);

        return true;
    }
}
