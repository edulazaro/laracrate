<?php

namespace EduLazaro\Laracrate\Http\Controllers;

use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Endpoint para que el cliente consulte el estado de procesamiento de uno o
 * varios archivos. Pensado para polling tras un upload async.
 *
 *   GET  /laracrate/files/{file:slug}/status   → un archivo
 *   POST /laracrate/files/status               → batch (varios slugs)
 *
 * Cada respuesta incluye:
 *   - status:   pending | processing | completed | failed
 *   - ready:    bool (true si completed)
 *   - url:      URL final si está ready, null si no
 *   - variants: { name => url } de los variants ya generados
 *   - preview:  URL del variant 'preview.thumbnail' si existe
 *   - error:    processing_error si status=failed
 */
class FileStatusController extends Controller
{
    public function show(Request $request, File $file): JsonResponse
    {
        if (!$file->canView($request->user())) {
            abort(403);
        }

        return response()->json($this->payload($file));
    }

    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slugs'   => 'required|array|min:1|max:200',
            'slugs.*' => 'required|string|max:50',
        ]);

        $files = File::whereIn('slug', $data['slugs'])
            ->whereNull('parent_id')
            ->get();

        $user = $request->user();
        $results = [];

        foreach ($files as $file) {
            if (!$file->canView($user)) continue;
            $results[$file->slug] = $this->payload($file);
        }

        return response()->json($results);
    }

    protected function payload(File $file): array
    {
        $status = $file->processing_status;

        $ready  = $status === ProcessingStatus::COMPLETED || $status === null;
        $failed = $status === ProcessingStatus::FAILED;

        return [
            'slug'     => $file->slug,
            'status'   => $status?->value ?? ProcessingStatus::COMPLETED->value,
            'ready'    => $ready,
            'url'      => $ready ? $file->url() : null,
            'preview'  => $ready ? $file->preview_link : null,
            'variants' => $ready ? $this->collectVariants($file) : [],
            'error'    => $failed ? $file->processing_error : null,
        ];
    }

    /**
     * Mapa nombre→URL de los variants/children de primer nivel.
     * Para preview nested (preview.thumbnail) la app navega con
     * $file->variant() si lo necesita; aquí damos los planos.
     */
    protected function collectVariants(File $file): array
    {
        $out = [];

        foreach ($file->children as $child) {
            if (!$child->variant) continue;

            $out[$child->variant] = $child->url();

            // Si el child es un preview con sus propios variants, los exponemos
            // anidados con dot notation: preview.thumbnail, preview.medium...
            foreach ($child->children as $grandchild) {
                if ($grandchild->variant) {
                    $out["{$child->variant}.{$grandchild->variant}"] = $grandchild->url();
                }
            }
        }

        return $out;
    }
}
