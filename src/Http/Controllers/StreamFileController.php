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
 * Sirve files con access=stream (incluye sensitive). Re-valida permisos
 * por request, audita la descarga y opcionalmente desencripta antes de
 * servir.
 *
 * El watermark NO se aplica aquí — se incrusta en el binario de la
 * variant correspondiente cuando se genera, no en stream-time. Ver
 * `ApplyWatermarkAction` y `GenerateImageVariantAction`.
 *
 * Nunca expone la URL del backend al cliente.
 */
class StreamFileController extends Controller
{
    public function stream(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: true, attachment: false);
    }

    public function preview(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: false, attachment: false);
    }

    public function download(Request $request, File $file): StreamedResponse
    {
        $this->validateAccess($request, $file);
        return $this->sendFile($request, $file, increment: true, attachment: true);
    }

    /**
     * Modo "link": URL Laravel persistente que se resuelve al vuelo según
     * el access real del File. Valida acceso (HMAC + policy) y redirige (302)
     * a la URL apropiada:
     *
     *   public → URL pública del bucket (no caduca; redirect por consistencia)
     *   signed → R2 signed URL generada en este instante (~30s, suficiente
     *            para que el navegador siga el redirect)
     *   stream → ruta interna de stream firmada al vuelo (proxea via Laravel)
     *
     * La URL en el HTML siempre es esta ruta `laracrate.files.link`, así
     * el HTML no caduca aunque el TTL real de R2 sea corto.
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

        // public → URL del bucket público, no caduca
        if ($access === 'public') {
            return redirect()->away($disk->url($key), 302);
        }

        // stream → proxear vía Laravel (binario pasa por nosotros)
        if ($access === 'stream') {
            return $this->sendFile($request, $file, increment: false, attachment: false);
        }

        // signed (default) → firmar R2 al vuelo y redirigir
        $driver = config("filesystems.disks.{$file->disk}.driver");
        if ($driver === 's3') {
            $redirectTtl = (int) config('laracrate.urls.link_redirect_ttl_seconds', 30);
            $r2Url = $disk->temporaryUrl($key, now()->addSeconds($redirectTtl));
            return redirect()->away($r2Url, 302);
        }

        // Fallback para disks no-s3 (local dev): servir el binario.
        return $this->sendFile($request, $file, increment: false, attachment: false);
    }

    /* ------------------------------------------------------------------
     | Validación: firma + viewer bind + policy
     * ------------------------------------------------------------------ */

    protected function validateAccess(Request $request, File $file): void
    {
        // 1. Firma de Laravel (signed routes).
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired signature.');
        }

        // 2. Si sensitive, ligar la URL al usuario que la generó.
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
     | Servir el binario (con encrypt + watermark si aplica)
     * ------------------------------------------------------------------ */

    protected function sendFile(Request $request, File $file, bool $increment, bool $attachment): StreamedResponse
    {
        $manager = app(StorageManager::class);
        $key     = $file->key;
        $disk    = $manager->diskFor($file);

        if (!$disk->exists($key)) {
            abort(404);
        }

        $this->audit($request, $file, $increment, $attachment);

        // Leer binario (desencriptado si is_encrypted).
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
