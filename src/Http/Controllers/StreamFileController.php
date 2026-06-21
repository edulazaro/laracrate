<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Actions\Files\DecryptFileAction;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves files with access=stream (including sensitive). Re-validates
 * permissions per request, audits the download and optionally decrypts
 * before serving.
 *
 * The watermark is NOT applied here: it is baked into the binary of the
 * corresponding variant when it is generated, not at stream time. See
 * `ApplyWatermarkAction` and `GenerateImageVariantAction`.
 *
 * Never exposes the backend URL to the client.
 */
class StreamFileController extends Controller
{
    /** Stream the file inline and count it as a download. */
    public function stream(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: true, attachment: false);
    }

    /** Stream the file inline without counting a download. */
    public function preview(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: false, attachment: false);
    }

    /** Stream the file as an attachment (forced download). */
    public function download(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: true, attachment: true);
    }

    /**
     * "link" mode: persistent Laravel URL resolved on the fly based on the
     * File's actual access. Validates access (HMAC + policy) and redirects (302)
     * to the appropriate URL:
     *
     *   public -> public bucket URL (does not expire; redirect for consistency)
     *   signed -> R2 signed URL generated at this instant (~30s, enough for
     *             the browser to follow the redirect)
     *   stream -> internal stream route signed on the fly (proxied via Laravel)
     *
     * The URL in the HTML is always this `laracrate.files.link` route, so the
     * HTML does not expire even if the real R2 TTL is short.
     */
    public function link(Request $request, File $file): RedirectResponse|StreamedResponse
    {
        $this->validateAccess($request, $file);

        $manager = app(StorageManager::class);
        $disk    = $manager->diskFor($file);
        $key     = $file->key;

        if (!$disk->exists($key)) {
            abort(404);
        }

        $this->audit($request, $file, increment: true, attachment: false);

        $access = $file->access?->value ?? $file->access;

        // public -> public bucket URL, does not expire
        if ($access === 'public') {
            return redirect()->away($disk->url($key), 302);
        }

        // stream -> proxy via Laravel (the binary passes through us)
        if ($access === 'stream') {
            return $this->sendFile($request, $file, increment: false, attachment: false);
        }

        // signed (default) -> sign R2 on the fly and redirect
        $driver = config("filesystems.disks.{$file->disk}.driver");
        if ($driver === 's3') {
            $redirectTtl = (int) config('laracrate.urls.link_redirect_ttl_seconds', 30);
            $r2Url = $disk->temporaryUrl($key, now()->addSeconds($redirectTtl));
            return redirect()->away($r2Url, 302);
        }

        // Fallback for non-s3 disks (local dev): serve the binary.
        return $this->sendFile($request, $file, increment: false, attachment: false);
    }

    /* ------------------------------------------------------------------
     | Validation: signature + viewer bind + policy
     * ------------------------------------------------------------------ */

    /** Validate the request signature, viewer binding and view policy. */
    protected function validateAccess(Request $request, File $file): void
    {
        // 1. Laravel signature (signed routes).
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired signature.');
        }

        // 2. If sensitive, bind the URL to the user that generated it.
        if ($file->isSensitive() && config('laracrate.urls.bind_to_user', true)) {
            if (!Auth::check()) {
                abort(401);
            }
            $expectedUser = $request->query('u');
            if ($expectedUser !== null && (int) Auth::id() !== (int) $expectedUser) {
                abort(403, 'This URL is not valid for your session.');
            }
        }

        // 3. Policy chain (PolicyRegistry).
        if (!$file->canView($request->user())) {
            abort(403);
        }
    }

    /* ------------------------------------------------------------------
     | Serve the binary (with decryption if applicable)
     * ------------------------------------------------------------------ */

    /** Stream the binary to the client, decrypting and auditing as needed. */
    protected function sendFile(Request $request, File $file, bool $increment, bool $attachment): StreamedResponse
    {
        $manager = app(StorageManager::class);
        $key     = $file->key;
        $disk    = $manager->diskFor($file);

        if (!$disk->exists($key)) {
            abort(404);
        }

        $this->audit($request, $file, $increment, $attachment);

        // Read the binary (decrypted if is_encrypted).
        $content = $file->is_encrypted
            ? DecryptFileAction::create()->run(['file' => $file])
            : (string) $disk->get($key);

        $disposition = $attachment ? 'attachment' : 'inline';
        $filename    = $file->original_name ?: $file->name;

        return response()->stream(
            function () use ($content) {
                echo $content;
            },
            200,
            [
                'Content-Type'        => $file->mime_type,
                'Content-Length'      => (string) strlen($content),
                'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, addslashes($filename)),
                'Cache-Control'       => 'private, no-store, no-cache, must-revalidate, max-age=0',
                'Pragma'              => 'no-cache',
            ]
        );
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
