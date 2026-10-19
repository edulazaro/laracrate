<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Actions\Files\DecryptFileAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Support\RangeResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file through PHP: `stream` shows it in the browser, `download` saves it and
 * counts it. The routes sit behind the session and every request checks the file's view
 * policy, so their links never expire. Honours Range, decrypts an encrypted file and
 * logs each access.
 *
 * The watermark is NOT applied here: it is baked into the binary of the
 * corresponding variant when it is generated, not at stream time. See
 * `ApplyWatermarkAction` and `GenerateImageVariantAction`.
 *
 * Never exposes the backend URL to the client.
 */
class StreamFileController extends Controller
{
    /** Show the file inline. */
    public function stream(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: false, attachment: false);
    }

    /** Download the file (forced attachment) and count it. */
    public function download(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: true, attachment: true);
    }

    /** Whoever asks must be allowed to see the file. */
    protected function validateAccess(Request $request, File $file): void
    {
        abort_unless($file->canView($request->user()), 403);
    }

    /* ------------------------------------------------------------------
     | Serve the binary (with decryption if applicable)
     * ------------------------------------------------------------------ */

    /**
     * Stream the binary to the client, decrypting and auditing as needed. Honours
     * `Range`, so players can seek and start before the whole file has arrived.
     */
    protected function sendFile(Request $request, File $file, bool $increment, bool $attachment): StreamedResponse
    {
        $manager = app(StorageManager::class);
        $key     = $file->key;
        $disk    = $manager->diskFor($file);

        if (!$disk->exists($key)) {
            abort(404);
        }

        // A player asks for many ranges of the same file: only the first one is an access.
        if (! $this->isRangeContinuation($request)) {
            $this->audit($request, $file, $increment, $attachment);
        }

        // An encrypted binary has to be decrypted whole; anything else is read by range.
        $content = $file->is_encrypted
            ? DecryptFileAction::create()->run(['file' => $file])
            : null;

        $disposition = $attachment ? 'attachment' : 'inline';
        $filename    = $file->original_name ?: $file->name;

        return RangeResponse::make($request, $disk, $key, [
            'Content-Type'        => $file->mime_type,
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($filename)),
            'Cache-Control'       => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
        ], $content);
    }

    /** Whether the request asks for a range that does not start at the first byte. */
    protected function isRangeContinuation(Request $request): bool
    {
        $range = (string) $request->header('Range');

        return $range !== '' && ! str_starts_with($range, 'bytes=0-');
    }

    /** Increment download counters and optionally log the access. */
    protected function audit(Request $request, File $file, bool $increment, bool $attachment): void
    {
        if ($increment && config('laracrate.stream.increment_downloads', true)) {
            $file->forceFill([
                'downloads_count'    => (int) $file->downloads_count + 1,
                'last_downloaded_at' => now(),
            ])->saveQuietly();
        }

        if (config('laracrate.stream.log_access', true)) {
            logger()->info('Laracrate: file accessed', [
                'file_id'    => $file->id,
                'collection' => $file->collection,
                'user_id'    => $request->user()?->getAuthIdentifier(),
                'ip'         => $request->ip(),
                'method'     => $attachment ? 'download' : 'stream',
            ]);
        }
    }
}
