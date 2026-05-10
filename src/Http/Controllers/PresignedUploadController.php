<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Endpoints genéricos del paquete para upload directo a R2/S3.
 *
 * Flujo:
 *   1. Cliente POST /laracrate/uploads/presign  → recibe { url, key, ... }
 *   2. Cliente PUT directo a `url` con el binario.
 *   3. Cliente POST a su propio endpoint de la app, que llama
 *      $model->addFile($collection, $key) — la action mueve el binario
 *      de temp/ a path canónico.
 *   4. Si el usuario cancela: DELETE /laracrate/uploads/{disk}/{key}.
 *
 * Las rutas las protege el middleware configurable en
 * config('laracrate.uploads.middleware'). La app es responsable de la
 * autorización (auth, throttle, validación de disk permitido).
 */
class PresignedUploadController extends Controller
{
    public function presign(Request $request)
    {
        $data = $request->validate([
            'disk'           => 'required|string',
            'mime'           => 'required|string|max:255',
            'file_name'      => 'nullable|string|max:255',
            'max_size_kb'    => 'nullable|integer|min:1',
            'minutes'        => 'nullable|integer|min:1|max:60',

            // Opcionales para key canónica directa (cero move después).
            // Si vienen, el archivo se sube DIRECTO al path final.
            // Si no vienen, va a temp/ y se mueve al confirm.
            'fileable_type'  => 'nullable|string',
            'fileable_id'    => 'nullable',
            'collection'     => 'nullable|string',
        ]);

        $disk     = $data['disk'];
        $mime     = $data['mime'];
        $fileName = $data['file_name'] ?? 'upload';
        $minutes  = $data['minutes']   ?? 15;
        $maxBytes = isset($data['max_size_kb']) ? $data['max_size_kb'] * 1024 : null;

        $allowedDisks = config('laracrate.uploads.allowed_disks', []);
        if (!empty($allowedDisks) && !in_array($disk, $allowedDisks, true)) {
            abort(403, "Disk '{$disk}' no permitido para uploads directos.");
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fileName);
        $name     = Str::ulid() . '_' . $safeName;

        // Key canónica si se conoce el modelo + collection (preferido).
        // Si no, fallback a temp/ (caso típico: form de creación sin modelo aún).
        if (!empty($data['fileable_type']) && !empty($data['fileable_id']) && !empty($data['collection'])) {
            $key = trim("{$data['fileable_type']}/{$data['fileable_id']}/{$data['collection']}/{$name}", '/');
        } else {
            $key = "temp/{$name}";
        }

        $presigned = app(StorageManager::class)->presignedUpload($disk, $key, $mime, $maxBytes, $minutes);

        return response()->json($presigned);
    }

    public function cancel(string $disk, string $encodedKey)
    {
        $key = base64_decode(urldecode($encodedKey));

        if (!str_starts_with($key, 'temp/')) {
            return response()->json(['error' => 'Solo se pueden cancelar archivos temp/.'], 422);
        }

        $allowedDisks = config('laracrate.uploads.allowed_disks', []);
        if (!empty($allowedDisks) && !in_array($disk, $allowedDisks, true)) {
            abort(403);
        }

        if (Storage::disk($disk)->exists($key)) {
            Storage::disk($disk)->delete($key);
            return response()->json(['deleted' => true]);
        }

        return response()->json(['deleted' => false, 'message' => 'No encontrado'], 404);
    }
}
